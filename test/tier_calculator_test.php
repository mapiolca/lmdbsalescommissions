<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

require_once __DIR__.'/../class/lmdbsalescommissiontiercalculator.class.php';

$normalizeAmount = static function ($amount) {
	return (float) $amount;
};

$progressiveTiers = array(
	array('rowid' => 3, 'threshold_amount' => 300000.0, 'bonus_amount' => 0.0, 'commission_rate' => 4.0),
	array('rowid' => 1, 'threshold_amount' => 100000.0, 'bonus_amount' => 0.0, 'commission_rate' => 2.5),
	array('rowid' => 2, 'threshold_amount' => 200000.0, 'bonus_amount' => 0.0, 'commission_rate' => 3.5),
);
$progressiveCases = array(
	0 => 0.0,
	100000 => 0.0,
	150000 => 1250.0,
	200000 => 2500.0,
	250000 => 4250.0,
	300000 => 6000.0,
	350000 => 8000.0,
);
$progressiveNextCommissions = array(
	0 => 0.0,
	100000 => 2500.0,
	150000 => 2500.0,
	200000 => 6000.0,
	250000 => 6000.0,
	300000 => null,
	350000 => null,
);
$progressiveAdditionalCommissions = array(
	0 => 0.0,
	100000 => 2500.0,
	150000 => 1250.0,
	200000 => 3500.0,
	250000 => 1750.0,
	300000 => null,
	350000 => null,
);
$progressiveRates = array(
	0 => null,
	100000 => 2.5,
	150000 => 2.5,
	200000 => 3.5,
	250000 => 3.5,
	300000 => 4.0,
	350000 => 4.0,
);
$progressiveProgress = array(
	0 => 0.0,
	100000 => 0.0,
	150000 => 50.0,
	200000 => 0.0,
	250000 => 50.0,
	300000 => null,
	350000 => null,
);

$errors = array();
foreach ($progressiveCases as $turnover => $expected) {
	$result = LmdbSalesCommissionTierCalculator::calculate(
		(float) $turnover,
		LmdbSalesCommissionTierCalculator::MODE_PROGRESSIVE_RATE,
		$progressiveTiers,
		$normalizeAmount
	);
	if ((float) $result['current_commission'] !== $expected) {
		$errors[] = 'Progressive turnover '.$turnover.': expected '.$expected.', got '.$result['current_commission'];
	}
	$expectedNextCommission = $progressiveNextCommissions[$turnover];
	if ($expectedNextCommission === null) {
		if ($result['commission_at_next_threshold'] !== null) {
			$errors[] = 'Progressive turnover '.$turnover.': expected no next threshold commission.';
		}
	} elseif ((float) $result['commission_at_next_threshold'] !== $expectedNextCommission) {
		$errors[] = 'Progressive turnover '.$turnover.': expected next threshold commission '.$expectedNextCommission.', got '.$result['commission_at_next_threshold'];
	}
	$expectedAdditionalCommission = $progressiveAdditionalCommissions[$turnover];
	if ($expectedAdditionalCommission === null) {
		if ($result['additional_commission_to_next_threshold'] !== null) {
			$errors[] = 'Progressive turnover '.$turnover.': expected no additional commission to next threshold.';
		}
	} elseif ((float) $result['additional_commission_to_next_threshold'] !== $expectedAdditionalCommission) {
		$errors[] = 'Progressive turnover '.$turnover.': expected additional commission '.$expectedAdditionalCommission.', got '.$result['additional_commission_to_next_threshold'];
	}
	$expectedRate = $progressiveRates[$turnover];
	if ($expectedRate === null ? $result['active_rate'] !== null : (float) $result['active_rate'] !== $expectedRate) {
		$errors[] = 'Progressive turnover '.$turnover.': active bracket rate mismatch.';
	}
	$expectedProgress = $progressiveProgress[$turnover];
	if ($expectedProgress === null ? $result['progress_to_next_threshold'] !== null : abs((float) $result['progress_to_next_threshold'] - $expectedProgress) > 0.000001) {
		$errors[] = 'Progressive turnover '.$turnover.': bracket progress mismatch.';
	}
	$expectedOpenEnded = $turnover >= 300000;
	if ((bool) $result['open_ended'] !== $expectedOpenEnded) {
		$errors[] = 'Progressive turnover '.$turnover.': open-ended bracket mismatch.';
	}
}

$tiersWithInactive = $progressiveTiers;
$tiersWithInactive[] = array('rowid' => 4, 'threshold_amount' => 125000.0, 'bonus_amount' => 0.0, 'commission_rate' => 100.0, 'active' => 0);
$inactiveResult = LmdbSalesCommissionTierCalculator::calculate(
	150000.0,
	LmdbSalesCommissionTierCalculator::MODE_PROGRESSIVE_RATE,
	$tiersWithInactive,
	$normalizeAmount
);
if ((float) $inactiveResult['current_commission'] !== 1250.0) {
	$errors[] = 'Inactive tier changed the progressive result.';
}

$fixedTiers = array(
	array('rowid' => 1, 'threshold_amount' => 100000.0, 'bonus_amount' => 1000.0, 'commission_rate' => null),
	array('rowid' => 2, 'threshold_amount' => 200000.0, 'bonus_amount' => 2500.0, 'commission_rate' => null),
);
$fixedCases = array(
	50000 => 0.0,
	100000 => 1000.0,
	199999 => 1000.0,
	200000 => 2500.0,
	350000 => 2500.0,
);
$fixedProgress = array(
	50000 => 50.0,
	100000 => 0.0,
	199999 => 99.999,
	200000 => null,
	350000 => null,
);
$fixedAdditionalCommissions = array(
	50000 => 1000.0,
	100000 => 1500.0,
	199999 => 1500.0,
	200000 => null,
	350000 => null,
);
foreach ($fixedCases as $turnover => $expected) {
	$result = LmdbSalesCommissionTierCalculator::calculate(
		(float) $turnover,
		LmdbSalesCommissionTierCalculator::MODE_FIXED_BONUS,
		$fixedTiers,
		$normalizeAmount
	);
	if ((float) $result['current_commission'] !== $expected) {
		$errors[] = 'Fixed turnover '.$turnover.': expected '.$expected.', got '.$result['current_commission'];
	}
	$expectedProgress = $fixedProgress[$turnover];
	if ($expectedProgress === null ? $result['progress_to_next_threshold'] !== null : abs((float) $result['progress_to_next_threshold'] - $expectedProgress) > 0.000001) {
		$errors[] = 'Fixed turnover '.$turnover.': tier progress mismatch.';
	}
	$expectedAdditionalCommission = $fixedAdditionalCommissions[$turnover];
	if ($expectedAdditionalCommission === null ? $result['additional_commission_to_next_threshold'] !== null : (float) $result['additional_commission_to_next_threshold'] !== $expectedAdditionalCommission) {
		$errors[] = 'Fixed turnover '.$turnover.': additional commission mismatch.';
	}
	if ($result['active_rate'] !== null) {
		$errors[] = 'Fixed turnover '.$turnover.': fixed mode must not expose an active rate.';
	}
}

$result = LmdbSalesCommissionTierCalculator::calculate(
	350000.0,
	LmdbSalesCommissionTierCalculator::MODE_PROGRESSIVE_RATE,
	$progressiveTiers,
	$normalizeAmount
);
$breakdownTotal = 0.0;
foreach ($result['breakdown'] as $row) {
	$breakdownTotal += (float) $row['commission'];
}
if ($normalizeAmount($breakdownTotal) !== 8000.0) {
	$errors[] = 'Progressive breakdown does not add up to 8000.';
}

$invalidTiers = array(
	array('threshold_amount' => 200000.0, 'bonus_amount' => 0.0, 'commission_rate' => -1.0, 'active' => 1),
	array('threshold_amount' => 100000.0, 'bonus_amount' => 0.0, 'commission_rate' => 101.0, 'active' => 1),
	array('threshold_amount' => 100000.0, 'bonus_amount' => 0.0, 'commission_rate' => 0.0, 'active' => 0),
	array('threshold_amount' => 0.0, 'bonus_amount' => 0.0, 'commission_rate' => 1.0, 'active' => 0),
);
$validationErrors = LmdbSalesCommissionTierCalculator::validateConfiguration(
	LmdbSalesCommissionTierCalculator::MODE_PROGRESSIVE_RATE,
	$invalidTiers
);
foreach (array(
	'LmdbSalesCommissionsTierDuplicateThreshold',
	'LmdbSalesCommissionsTierThresholdMustBePositive',
	'LmdbSalesCommissionsTierThresholdsMustBeOrdered',
	'LmdbSalesCommissionsTierRateMustBeValid',
	'LmdbSalesCommissionsTierGridMustHavePositiveRate',
) as $expectedError) {
	if (!in_array($expectedError, $validationErrors, true)) {
		$errors[] = 'Missing validation error '.$expectedError.'.';
	}
}

$fixedValidationErrors = LmdbSalesCommissionTierCalculator::validateConfiguration(
	LmdbSalesCommissionTierCalculator::MODE_FIXED_BONUS,
	array(
		array('threshold_amount' => 100000.0, 'bonus_amount' => -10.0, 'commission_rate' => null, 'active' => 1),
	)
);
if (!in_array('LmdbSalesCommissionsTierBonusMustNotBeNegative', $fixedValidationErrors, true)) {
	$errors[] = 'Missing fixed bonus validation error.';
}

if (!empty($errors)) {
	fwrite(STDERR, implode(PHP_EOL, $errors).PHP_EOL);
	exit(1);
}

print 'All tier calculator tests passed.'.PHP_EOL;
