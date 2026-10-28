<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** Resolve one applicable control per quote line, technical context and effect.
 * Product/service beats category, which beats the offer fallback. Assignment rank
 * only breaks ties inside a scope. Equal-rank overlapping rules remain conflicts.
 */
class LmdbSalesCommissionMarginScope
{
	/** @param list<array<string,mixed>> $policies Active assignments
	 * @param list<array<string,mixed>> $lines Commercial input lines
	 * @param array<int,list<int>> $categoriesByProduct Live native category links
	 * @param float|null $kwc PV capacity
	 * @param float|null $kwh Storage capacity
	 * @return list<array<string,mixed>> Selected policies with line indexes and conflict state */
	public static function select(array $policies, array $lines, array $categoriesByProduct, $kwc, $kwh)
	{
		$context = $kwc === null || $kwh === null ? null : ($kwc > 0 ? ($kwh > 0 ? 'mixed' : 'pv') : ($kwh > 0 ? 'storage' : 'none'));
		$groups = array();
		foreach ($policies as $policy) {
			if ($policy['context'] !== 'general' && $context !== null && $policy['context'] !== $context) { continue; }
			$groups[$policy['context'].':'.$policy['effect']][] = $policy;
		}
		$selected = array();
		foreach ($groups as $key => $candidates) {
			foreach ($lines as $index => $line) {
				$productId = (int) ($line['fk_product'] ?? 0);
				$categories = $categoriesByProduct[$productId] ?? array();
				$matches = array();
				foreach ($candidates as $policy) {
					$scope = $policy['scope'] ?? 'offer';
					if ($scope === 'product' && $productId > 0 && in_array($productId, $policy['products'] ?? array(), true)) { $matches[] = $policy; }
					elseif ($scope === 'category' && $productId > 0 && array_intersect($categories, $policy['categories'] ?? array())) { $matches[] = $policy; }
					elseif ($scope === 'offer') { $matches[] = $policy; }
				}
				if (!$matches) { continue; }
				$priority = array('product' => 1, 'category' => 2, 'offer' => 3);
				$bestScope = min(array_map(static function ($policy) use ($priority) { return $priority[$policy['scope'] ?? 'offer']; }, $matches));
				$matches = array_values(array_filter($matches, static function ($policy) use ($priority, $bestScope) { return $priority[$policy['scope'] ?? 'offer'] === $bestScope; }));
				$bestRank = min(array_column($matches, 'rank'));
				$matches = array_values(array_filter($matches, static function ($policy) use ($bestRank) { return $policy['rank'] === $bestRank; }));
				$distinct = array();
				foreach ($matches as $policy) { $distinct[$policy['rule_id']] = $policy; }
				foreach ($distinct as $policy) {
					$id = $key.':'.$policy['rule_id'];
					if (!isset($selected[$id])) { $selected[$id] = $policy + array('line_indexes' => array(), 'scope_conflict' => false); }
					$selected[$id]['line_indexes'][] = $index;
					if (count($distinct) > 1) { $selected[$id]['scope_conflict'] = true; }
				}
			}
			// Preserve the old unknown-cost result on an empty offer.
			if (!$lines) {
				$offers = array_values(array_filter($candidates, static function ($policy) { return ($policy['scope'] ?? 'offer') === 'offer'; }));
				if ($offers) {
					$rank = min(array_column($offers, 'rank'));
					$distinctOffers = array();
					foreach ($offers as $policy) { if ($policy['rank'] === $rank) { $distinctOffers[$policy['rule_id']] = $policy; } }
					foreach ($distinctOffers as $policy) {
						$id = $key.':'.$policy['rule_id'];
						$selected[$id] = $policy + array('line_indexes' => array(), 'scope_conflict' => count($distinctOffers) > 1);
					}
				}
			}
		}
		return array_values($selected);
	}
}
