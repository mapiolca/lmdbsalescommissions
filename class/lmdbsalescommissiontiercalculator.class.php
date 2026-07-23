<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * Pure tier commission calculator.
 */
class LmdbSalesCommissionTierCalculator
{
	public const MODE_FIXED_BONUS = 'fixed_bonus';
	public const MODE_PROGRESSIVE_RATE = 'progressive_rate';

	/**
	 * Calculate a tier commission.
	 *
	 * @param float $turnover Period turnover
	 * @param string $calculationMode Grid calculation mode
	 * @param array<int, array{rowid:int, threshold_amount:float, bonus_amount:float, commission_rate:float|null, active?:int}> $tiers Tiers
	 * @param callable|null $amountNormalizer Optional amount normalizer, native Dolibarr rounding by default
	 * @return array{
	 *     calculation_mode:string,
	 *     turnover:float,
	 *     current_commission:float,
	 *     commission_at_next_threshold:float|null,
	 *     additional_commission_to_next_threshold:float|null,
	 *     active_rate:float|null,
	 *     progress_to_next_threshold:float|null,
	 *     open_ended:bool,
	 *     reached_tier:array{rowid:int, threshold_amount:float, bonus_amount:float, commission_rate:float|null, active?:int}|null,
	 *     next_tier:array{rowid:int, threshold_amount:float, bonus_amount:float, commission_rate:float|null, active?:int}|null,
	 *     remaining_to_next:float,
	 *     breakdown:array<int, array{tier_id:int, lower_threshold:float, upper_threshold:float|null, rate:float, turnover_base:float, commission:float}>
	 * }
	 */
	public static function calculate($turnover, $calculationMode, array $tiers, $amountNormalizer = null)
	{
		$mode = self::normalizeMode($calculationMode);
		$normalizedTurnover = self::normalizeAmount(max(0.0, (float) $turnover), $amountNormalizer);
		$tiers = array_values(array_filter($tiers, static function (array $tier) {
			return !array_key_exists('active', $tier) || (int) $tier['active'] === 1;
		}));
		usort($tiers, array(__CLASS__, 'compareTiers'));

		$reached = null;
		$next = null;
		foreach ($tiers as $tier) {
			if ($normalizedTurnover >= (float) $tier['threshold_amount']) {
				$reached = $tier;
				continue;
			}
			$next = $tier;
			break;
		}

		if ($mode === self::MODE_PROGRESSIVE_RATE) {
			$calculation = self::calculateProgressiveAmount($normalizedTurnover, $tiers, true, $amountNormalizer);
			$nextCommission = null;
			if (is_array($next)) {
				$nextCalculation = self::calculateProgressiveAmount((float) $next['threshold_amount'], $tiers, false, $amountNormalizer);
				$nextCommission = $nextCalculation['total'];
			}
		} else {
			$calculation = array(
				'total' => is_array($reached) ? self::normalizeAmount((float) $reached['bonus_amount'], $amountNormalizer) : 0.0,
				'breakdown' => array(),
			);
			$nextCommission = is_array($next) ? self::normalizeAmount((float) $next['bonus_amount'], $amountNormalizer) : null;
		}

		$currentCommission = self::normalizeAmount($calculation['total'], $amountNormalizer);
		$additionalCommission = $nextCommission !== null
			? self::normalizeAmount($nextCommission - $currentCommission, $amountNormalizer)
			: null;
		$rangeStart = is_array($reached) ? (float) $reached['threshold_amount'] : 0.0;
		$progressToNext = null;
		if (is_array($next)) {
			$rangeLength = (float) $next['threshold_amount'] - $rangeStart;
			$progressToNext = $rangeLength > 0
				? max(0.0, min(100.0, (($normalizedTurnover - $rangeStart) / $rangeLength) * 100))
				: 100.0;
		}

		return array(
			'calculation_mode' => $mode,
			'turnover' => $normalizedTurnover,
			'current_commission' => $currentCommission,
			'commission_at_next_threshold' => $nextCommission,
			'additional_commission_to_next_threshold' => $additionalCommission,
			'active_rate' => $mode === self::MODE_PROGRESSIVE_RATE && is_array($reached) && $reached['commission_rate'] !== null ? (float) $reached['commission_rate'] : null,
			'progress_to_next_threshold' => $progressToNext,
			'open_ended' => is_array($reached) && !is_array($next),
			'reached_tier' => $reached,
			'next_tier' => $next,
			'remaining_to_next' => is_array($next) ? self::normalizeAmount(max(0.0, (float) $next['threshold_amount'] - $normalizedTurnover), $amountNormalizer) : 0.0,
			'breakdown' => $calculation['breakdown'],
		);
	}

	/**
	 * Normalize a stored calculation mode.
	 *
	 * @param string $calculationMode Calculation mode
	 * @return string
	 */
	public static function normalizeMode($calculationMode)
	{
		return $calculationMode === self::MODE_PROGRESSIVE_RATE ? self::MODE_PROGRESSIVE_RATE : self::MODE_FIXED_BONUS;
	}

	/**
	 * Validate a grid configuration before it is persisted.
	 *
	 * @param string $calculationMode Grid calculation mode
	 * @param array<int, array{threshold_amount:float, bonus_amount:float, commission_rate:float|null, active:int}> $tiers Tier rows in display order
	 * @return array<int, string> Translation keys
	 */
	public static function validateConfiguration($calculationMode, array $tiers)
	{
		$errors = array();
		$thresholds = array();
		$lastThreshold = null;
		$positiveActiveRate = false;

		foreach ($tiers as $tier) {
			$threshold = (float) $tier['threshold_amount'];
			$bonus = (float) $tier['bonus_amount'];
			$rate = $tier['commission_rate'] !== null ? (float) $tier['commission_rate'] : null;
			if ($threshold <= 0) {
				$errors['LmdbSalesCommissionsTierThresholdMustBePositive'] = true;
			}
			if (isset($thresholds[(string) $threshold])) {
				$errors['LmdbSalesCommissionsTierDuplicateThreshold'] = true;
			}
			if ($lastThreshold !== null && $threshold <= $lastThreshold) {
				$errors['LmdbSalesCommissionsTierThresholdsMustBeOrdered'] = true;
			}
			$thresholds[(string) $threshold] = true;
			$lastThreshold = $threshold;

			if ($calculationMode === self::MODE_PROGRESSIVE_RATE) {
				if ($rate === null || $rate < 0 || $rate > 100) {
					$errors['LmdbSalesCommissionsTierRateMustBeValid'] = true;
				} elseif ((int) $tier['active'] === 1 && $rate > 0) {
					$positiveActiveRate = true;
				}
			} elseif ($bonus < 0) {
				$errors['LmdbSalesCommissionsTierBonusMustNotBeNegative'] = true;
			}
		}

		if ($calculationMode === self::MODE_PROGRESSIVE_RATE && !$positiveActiveRate) {
			$errors['LmdbSalesCommissionsTierGridMustHavePositiveRate'] = true;
		}

		return array_keys($errors);
	}

	/**
	 * Calculate the progressive amount for a turnover.
	 *
	 * @param float $turnover Turnover
	 * @param array<int, array{rowid:int, threshold_amount:float, bonus_amount:float, commission_rate:float|null, active?:int}> $tiers Active tiers
	 * @param bool $includeBreakdown Include detailed rows
	 * @param callable|null $amountNormalizer Optional amount normalizer
	 * @return array{total:float, breakdown:array<int, array{tier_id:int, lower_threshold:float, upper_threshold:float|null, rate:float, turnover_base:float, commission:float}>}
	 */
	private static function calculateProgressiveAmount($turnover, array $tiers, $includeBreakdown, $amountNormalizer)
	{
		$total = 0.0;
		$breakdown = array();
		$count = count($tiers);
		for ($index = 0; $index < $count; $index++) {
			$tier = $tiers[$index];
			$lower = self::normalizeAmount($tier['threshold_amount'], $amountNormalizer);
			$upper = $index + 1 < $count ? self::normalizeAmount($tiers[$index + 1]['threshold_amount'], $amountNormalizer) : null;
			$rate = $tier['commission_rate'] !== null ? (float) $tier['commission_rate'] : 0.0;
			$upperTurnover = $upper !== null ? min((float) $turnover, $upper) : (float) $turnover;
			$turnoverBase = self::normalizeAmount(max(0.0, $upperTurnover - $lower), $amountNormalizer);
			$commission = self::normalizeAmount($turnoverBase * $rate / 100, $amountNormalizer);
			$total = self::normalizeAmount($total + $commission, $amountNormalizer);

			if ($includeBreakdown && ($turnoverBase > 0 || (float) $turnover >= $lower)) {
				$breakdown[] = array(
					'tier_id' => (int) $tier['rowid'],
					'lower_threshold' => $lower,
					'upper_threshold' => $upper,
					'rate' => $rate,
					'turnover_base' => $turnoverBase,
					'commission' => $commission,
				);
			}
		}

		return array('total' => $total, 'breakdown' => $breakdown);
	}

	/**
	 * Normalize a monetary amount with Dolibarr or an injected equivalent.
	 *
	 * @param float $amount Amount
	 * @param callable|null $amountNormalizer Optional normalizer
	 * @return float
	 */
	private static function normalizeAmount($amount, $amountNormalizer)
	{
		if (is_callable($amountNormalizer)) {
			return (float) call_user_func($amountNormalizer, (float) $amount);
		}

		return (float) price2num($amount, 'MT');
	}

	/**
	 * Sort tiers by threshold then technical id.
	 *
	 * @param array{rowid:int, threshold_amount:float, bonus_amount:float, commission_rate:float|null, active?:int} $left Left tier
	 * @param array{rowid:int, threshold_amount:float, bonus_amount:float, commission_rate:float|null, active?:int} $right Right tier
	 * @return int
	 */
	private static function compareTiers(array $left, array $right)
	{
		$thresholdComparison = (float) $left['threshold_amount'] <=> (float) $right['threshold_amount'];
		if ($thresholdComparison !== 0) {
			return $thresholdComparison;
		}

		return (int) $left['rowid'] <=> (int) $right['rowid'];
	}
}
