<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** Pure margin policy evaluation; no persistence, permissions or technical recalculation.
 * @phpstan-type Band array{kwc_min:?float,kwc_max:?float,kwc_inclusive:int,kwh_min:?float,kwh_max:?float,kwh_inclusive:int,threshold:float}
 * @phpstan-type TravelBand array{metric:string,min_value:float,uplift:float}
 * @phpstan-type ComplexSiteUplift array{uplift_without_travel:float,uplift_with_travel:?float}
 * @phpstan-type Policy array{rule_id:int,context:string,effect:string,rank:int,origin:string,origin_type?:string,assignment_id?:int,threshold:?float,bands:list<Band>,travel_bands?:list<TravelBand>,complex_site?:ComplexSiteUplift|null}
 * @phpstan-type Check array{rule_id:int,context:string,effect:string,origin:string,origin_type?:string,assignment_id?:int,threshold:?float,base_threshold:?float,travel_uplift:float,travel_metric:string,travel_value:?float,complex_site_configured:bool,complex_site_state:?bool,complex_site_uplift:float,state:string,reason:string,approval_id?:int,approval?:array{reason:string,approver:int,date:string}}
 */
class LmdbSalesCommissionMarginEngine
{
	/** @param float|null $value Value without preliminary rounding
	 * @param float|null $min Lower bound; null is unbounded
	 * @param float|null $max Inclusive upper bound
	 * @param int $inclusive Include lower bound
	 * @return bool */
	public static function contains($value, $min, $max, $inclusive)
	{
		return $value !== null && ($min === null || ($inclusive ? $value >= $min : $value > $min)) && ($max === null || $value <= $max);
	}

	/**
	 * @param Band $a First band
	 * @param Band $b Second band
	 * @param string $axis kwc or kwh
	 * @return bool */
	public static function overlapsAxis(array $a, array $b, $axis)
	{
		foreach (array(array($a, $b), array($b, $a)) as $pair) {
			if ($pair[0][$axis.'_max'] !== null && $pair[1][$axis.'_min'] !== null) {
				if ($pair[0][$axis.'_max'] < $pair[1][$axis.'_min'] || ($pair[0][$axis.'_max'] == $pair[1][$axis.'_min'] && !$pair[1][$axis.'_inclusive'])) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @param list<Band> $bands Grid
	 * @param string $context pv, storage, mixed
	 * @return bool */
	public static function validBands(array $bands, $context)
	{
		$axes = $context === 'mixed' ? array('kwc', 'kwh') : array($context === 'pv' ? 'kwc' : 'kwh');
		foreach ($bands as $i => $band) {
			if (!is_finite($band['threshold']) || $band['threshold'] < 0) {
				return false;
			}
			foreach ($axes as $axis) {
				$min = $band[$axis.'_min'];
				$max = $band[$axis.'_max'];
				if (($min !== null && (!is_finite($min) || $min < 0)) || ($max !== null && (!is_finite($max) || $max < 0)) || ($min !== null && $max !== null && ($min > $max || ($min == $max && !$band[$axis.'_inclusive'])))) {
					return false;
				}
			}
			foreach (array_slice($bands, $i + 1) as $other) {
				$overlaps = true;
				foreach ($axes as $axis) {
					$overlaps = $overlaps && self::overlapsAxis($band, $other, $axis);
				}
				if ($overlaps) {
					return false;
				}
			}
		}
		return true;
	}

	/** One metric per policy. Higher breakpoints may only increase the required margin.
	 * @param list<TravelBand> $bands Travel thresholds in round-trip minutes or kilometres
	 * @return bool */
	public static function validTravelBands(array $bands)
	{
		$metric = null;
		$seen = array();
		$previousUplift = -1.0;
		usort($bands, static function ($a, $b) { return $a['min_value'] <=> $b['min_value']; });
		foreach ($bands as $band) {
			if (!in_array($band['metric'], array('minutes', 'kilometres'), true)
				|| ($metric !== null && $metric !== $band['metric'])
				|| !is_finite($band['min_value']) || $band['min_value'] < 0
				|| !is_finite($band['uplift']) || $band['uplift'] < 0 || $band['uplift'] < $previousUplift
				|| isset($seen[(string) $band['min_value']])) { return false; }
			$metric = $band['metric'];
			$seen[(string) $band['min_value']] = true;
			$previousUplift = $band['uplift'];
		}
		return true;
	}

	/** @param ComplexSiteUplift $uplift Rule's ON configuration
	 * @return bool */
	public static function validComplexSiteUplift(array $uplift)
	{
		$without = $uplift['uplift_without_travel'] ?? null;
		$with = $uplift['uplift_with_travel'] ?? null;
		return is_float($without) && is_finite($without) && $without >= 0
			&& ($with === null || (is_float($with) && is_finite($with) && $with >= 0));
	}

	/** @param list<Policy> $policies Candidates
	 * @param float|null $rate Global markup on cost
	 * @param float|null $kwc Peak power
	 * @param float|null $kwh Useful storage
	 * @param array{minutes:?float,kilometres:?float}|null $travel Round-trip road journey, null if unavailable
	 * @param bool|null $complexSite Proposal qualification; null if unavailable
	 * @return array{sale:string,commission:string,checks:list<Check>} */
	public static function evaluate(array $policies, $rate, $kwc, $kwh, ?array $travel = null, ?bool $complexSite = null)
	{
		$result = array('sale' => 'allow', 'commission' => 'allow', 'checks' => array());
		$context = $kwc === null || $kwh === null ? null : ($kwc > 0 ? ($kwh > 0 ? 'mixed' : 'pv') : ($kwh > 0 ? 'storage' : 'none'));
		$groups = array();
		foreach ($policies as $policy) {
			if ($policy['context'] !== 'general' && $context !== null && $policy['context'] !== $context) {
				continue;
			}
			$key = $policy['context'].':'.$policy['effect'];
			$groups[$key][] = $policy;
		}
		foreach ($groups as $candidates) {
			$rank = min(array_column($candidates, 'rank'));
			$selected = array();
			foreach ($candidates as $candidate) {
				if ($candidate['rank'] === $rank) {
					$selected[$candidate['rule_id']] = $candidate;
				}
			}
			foreach ($selected as $policy) {
				$threshold = $policy['threshold'];
				$reason = '';
				if (count($selected) > 1) {
					$reason = 'conflict';
				} elseif ($policy['context'] !== 'general') {
					$matches = array();
					if ($context === null) {
						$reason = 'technical_missing';
					} elseif (!self::validBands($policy['bands'], $policy['context'])) {
						$reason = 'overlap';
					} else {
						foreach ($policy['bands'] as $band) {
							if (($policy['context'] === 'storage' || self::contains($kwc, $band['kwc_min'], $band['kwc_max'], $band['kwc_inclusive'])) && ($policy['context'] === 'pv' || self::contains($kwh, $band['kwh_min'], $band['kwh_max'], $band['kwh_inclusive']))) {
								$matches[] = $band;
							}
						}
						if (count($matches) !== 1) {
							$reason = count($matches) > 1 ? 'overlap' : 'outside_grid';
						} else {
							$threshold = $matches[0]['threshold'];
						}
					}
				}
				if ($reason === '' && ($rate === null || !is_finite($rate) || $threshold === null || !is_finite($threshold))) {
					$reason = 'cost_missing';
				}
				$baseThreshold = $threshold;
				$travelUplift = 0.0;
				$travelMetric = '';
				$travelValue = null;
				$complexUplift = 0.0;
				$travelBands = $policy['travel_bands'] ?? array();
				if ($reason === '' && $travelBands) {
					if (!self::validTravelBands($travelBands)) {
						$reason = 'travel_invalid';
					} else {
						$travelMetric = $travelBands[0]['metric'];
						$travelValue = $travel[$travelMetric] ?? null;
						if ($travelValue === null || !is_finite($travelValue) || $travelValue < 0) {
							$reason = 'travel_missing';
						} else {
							foreach ($travelBands as $band) {
								if ($travelValue > $band['min_value']) { $travelUplift = max($travelUplift, $band['uplift']); }
							}
							$threshold += $travelUplift;
						}
					}
				}
				$complexConfig = $policy['complex_site'] ?? null;
				if ($reason === '' && $complexConfig !== null) {
					if (!self::validComplexSiteUplift($complexConfig)) {
						$reason = 'complex_site_invalid';
					} elseif ($complexSite === null) {
						$reason = 'complex_site_missing';
					} elseif ($complexSite) {
						$complexUplift = $travelUplift > 0 && $complexConfig['uplift_with_travel'] !== null
							? $complexConfig['uplift_with_travel'] : $complexConfig['uplift_without_travel'];
						$threshold += $complexUplift;
					}
				}
				// Account only for machine precision of the subtraction/division/multiplication.
				// No monetary rounding and no tolerance on technical band boundaries.
				$met = $reason === '' && ($rate >= $threshold || abs($rate - $threshold) <= 4 * PHP_FLOAT_EPSILON * max(1.0, abs($rate), abs($threshold)));
				$state = $reason !== '' ? 'unknown' : ($met ? 'allow' : 'deny');
				$result['checks'][] = array('rule_id' => $policy['rule_id'], 'context' => $policy['context'], 'effect' => $policy['effect'], 'origin' => $policy['origin'], 'origin_type' => $policy['origin_type'] ?? '', 'assignment_id' => $policy['assignment_id'] ?? 0, 'threshold' => $threshold, 'base_threshold' => $baseThreshold, 'travel_uplift' => $travelUplift, 'travel_metric' => $travelMetric, 'travel_value' => $travelValue, 'complex_site_configured' => $complexConfig !== null, 'complex_site_state' => $complexConfig === null ? null : $complexSite, 'complex_site_uplift' => $complexUplift, 'state' => $state, 'reason' => $reason !== '' ? $reason : ($state === 'deny' ? 'below' : 'met'));
			}
		}
		return self::aggregate($result);
	}

	/** @param array{sale:string,commission:string,checks:list<Check>} $result Decisions
	 * @return array{sale:string,commission:string,checks:list<Check>} */
	public static function aggregate(array $result)
	{
		$result['sale'] = $result['commission'] = 'allow';
		foreach ($result['checks'] as $check) {
			$effect = $check['effect'];
			if ($check['state'] === 'unknown' || ($check['state'] === 'deny' && $result[$effect] !== 'unknown')) {
				$result[$effect] = $check['state'];
			}
		}
		return $result;
	}
}
