<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * @phpstan-type CompatibilityFeature array{
 *     label: string,
 *     description: string,
 *     min_dolibarr?: string,
 *     core_available_from?: string,
 *     module_available_from?: string,
 *     min_php?: string,
 *     compatibility_check: string,
 *     available: bool,
 *     reason?: string
 * }
 */

/**
 * Centralized compatibility checks for lmdbsalescommissions.
 */
class LmdbSalesCommissionsCompatibility
{
	public const MIN_DOLIBARR_VERSION = '20.0.0';
	public const MIN_PHP_VERSION = '8.0.0';

	/**
	 * Check Dolibarr version.
	 *
	 * @param string $version Minimal version
	 * @return bool
	 */
	public static function isDolibarrVersionAtLeast($version)
	{
		return defined('DOL_VERSION') && version_compare((string) DOL_VERSION, $version, '>=');
	}

	/**
	 * Check PHP version.
	 *
	 * @param string $version Minimal version
	 * @return bool
	 */
	public static function isPhpVersionAtLeast($version)
	{
		return version_compare(PHP_VERSION, $version, '>=');
	}

	/**
	 * Get compatibility feature matrix.
	 *
	 * @return array<string, CompatibilityFeature>
	 */
	public static function getCompatibilityFeatures()
	{
		return array(
			'module_skeleton' => array(
				'label' => 'LmdbSalesCommissionsCompatibilitySkeleton',
				'description' => 'LmdbSalesCommissionsCompatibilitySkeletonDesc',
				'min_dolibarr' => self::MIN_DOLIBARR_VERSION,
				'core_available_from' => self::MIN_DOLIBARR_VERSION,
				'module_available_from' => self::MIN_DOLIBARR_VERSION,
				'min_php' => self::MIN_PHP_VERSION,
				'compatibility_check' => "version_compare(DOL_VERSION, '20.0.0', '>=') && version_compare(PHP_VERSION, '8.0.0', '>=')",
				'available' => self::isDolibarrVersionAtLeast(self::MIN_DOLIBARR_VERSION) && self::isPhpVersionAtLeast(self::MIN_PHP_VERSION),
				'reason' => 'LmdbSalesCommissionsRequiresDolibarr20AndPhp80',
			),
			'native_helpers' => array(
				'label' => 'LmdbSalesCommissionsCompatibilityNativeHelpers',
				'description' => 'LmdbSalesCommissionsCompatibilityNativeHelpersDesc',
				'min_dolibarr' => self::MIN_DOLIBARR_VERSION,
				'core_available_from' => self::MIN_DOLIBARR_VERSION,
				'module_available_from' => self::MIN_DOLIBARR_VERSION,
				'min_php' => self::MIN_PHP_VERSION,
				'compatibility_check' => 'function_exists("getDolGlobalInt") && function_exists("getDolGlobalString") && function_exists("isModEnabled")',
				'available' => function_exists('getDolGlobalInt') && function_exists('getDolGlobalString') && function_exists('isModEnabled'),
				'reason' => 'LmdbSalesCommissionsNativeHelpersUnavailable',
			),
			'native_invoice_payment_detection' => array(
				'label' => 'LmdbSalesCommissionsCompatibilityNativeInvoicePaymentDetection',
				'description' => 'LmdbSalesCommissionsCompatibilityNativeInvoicePaymentDetectionDesc',
				'min_dolibarr' => self::MIN_DOLIBARR_VERSION,
				'core_available_from' => self::MIN_DOLIBARR_VERSION,
				'module_available_from' => self::MIN_DOLIBARR_VERSION,
				'min_php' => self::MIN_PHP_VERSION,
				'compatibility_check' => 'class_exists("Facture") || file_exists(DOL_DOCUMENT_ROOT."/compta/facture/class/facture.class.php")',
				'available' => defined('DOL_DOCUMENT_ROOT') && file_exists(DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php'),
				'reason' => 'LmdbSalesCommissionsNativeInvoicePaymentDetectionUnavailable',
			),
		);
	}

	/**
	 * Check if a feature is available.
	 *
	 * @param string $code Feature code
	 * @return bool
	 */
	public static function isFeatureAvailable($code)
	{
		$features = self::getCompatibilityFeatures();

		return isset($features[$code]) && !empty($features[$code]['available']);
	}

	/**
	 * Get unavailable features.
	 *
	 * @return array<string, CompatibilityFeature>
	 */
	public static function getUnavailableFeatures()
	{
		$unavailable = array();
		foreach (self::getCompatibilityFeatures() as $code => $feature) {
			if (empty($feature['available'])) {
				$unavailable[$code] = $feature;
			}
		}

		return $unavailable;
	}
}
