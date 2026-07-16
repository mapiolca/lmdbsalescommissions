<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/**
 * Central turnover contribution query semantics.
 */
class LmdbSalesCommissionTurnoverService
{
	/**
	 * Build the grouped SQL expression that reads attributed turnover.
	 *
	 * SUM is used when a group contains several beneficiaries of one proposal;
	 * MAX is used when the group is already restricted to one beneficiary.
	 * Legacy non-dispatch lines remain a fallback until backfill is executed.
	 *
	 * @param string $alias SQL table alias, without punctuation
	 * @param bool   $sumTurnoverContributions Sum beneficiary contributions
	 * @return string
	 */
	public static function buildAttributedAmountExpression($alias, $sumTurnoverContributions)
	{
		$safeAlias = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
		if (!is_string($safeAlias) || $safeAlias === '') {
			$safeAlias = 'l';
		}
		$aggregate = $sumTurnoverContributions ? 'SUM' : 'MAX';

		return "CASE WHEN SUM(CASE WHEN ".$safeAlias.".mode = 'turnover' THEN 1 ELSE 0 END) > 0"
			." THEN ".$aggregate."(CASE WHEN ".$safeAlias.".mode = 'turnover' THEN ".$safeAlias.".amount_base ELSE 0 END)"
			." ELSE MAX(CASE WHEN ".$safeAlias.".mode <> 'dispatch' THEN ".$safeAlias.".amount_base END) END";
	}
}
