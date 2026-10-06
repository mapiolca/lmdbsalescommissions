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
 *     reason?: string,
 *     available_reason?: string
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
			'margin_excess_reward' => array('label' => 'LscReward', 'description' => 'LscRewardHelp', 'min_dolibarr' => '20.0.0', 'min_php' => '8.0.0', 'compatibility_check' => 'nativeMarginGuardCoverage() && LMDBSALESCOMMISSIONS_MARGIN_ENABLED', 'available' => self::nativeMarginGuardCoverage() && (bool) getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_ENABLED'), 'reason' => 'LscRewardUnavailable'),
			'margin_policy_guards' => array('label' => 'LscPolicies', 'description' => 'LscCoverageDescription', 'min_dolibarr' => '20.0.0', 'min_php' => '8.0.0', 'compatibility_check' => 'nativeMarginGuardCoverage()', 'available' => self::nativeMarginGuardCoverage(), 'reason' => 'LscCoverageUnavailable'),
			'travel_margin_uplift' => array('label' => 'LscTravelMargin', 'description' => 'LscTravelCompatibilityDescription', 'min_dolibarr' => '20.0.0', 'min_php' => '8.0.0', 'compatibility_check' => 'isModEnabled("lmdbzoning") && LmdbZoningCompatibility::isTravelAvailable("propal") && LmdbZoningTravelService::read()', 'available' => self::travelMarginAvailable(), 'reason' => 'LscTravelCompatibilityUnavailable', 'available_reason' => 'LscTravelCompatibilityAvailable'),
			'complex_site_margin_uplift' => array('label' => 'LscComplexSiteMargin', 'description' => 'LscComplexSiteCompatibilityDescription', 'min_dolibarr' => '20.0.0', 'min_php' => '8.0.0', 'compatibility_check' => 'LmdbPropalPVComplexSiteService::isAvailable($db)', 'available' => self::complexSiteMarginAvailable(), 'reason' => 'LscComplexSiteCompatibilityUnavailable', 'available_reason' => 'LscComplexSiteCompatibilityAvailable'),
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
			'proposal_dispatch' => array(
				'label' => 'LmdbSalesCommissionsCompatibilityProposalDispatch',
				'description' => 'LmdbSalesCommissionsCompatibilityProposalDispatchDesc',
				'min_dolibarr' => self::MIN_DOLIBARR_VERSION,
				'core_available_from' => self::MIN_DOLIBARR_VERSION,
				'module_available_from' => self::MIN_DOLIBARR_VERSION,
				'min_php' => self::MIN_PHP_VERSION,
				'compatibility_check' => 'file_exists(DOL_DOCUMENT_ROOT."/comm/propal/class/propal.class.php") && function_exists("price2num")',
				'available' => defined('DOL_DOCUMENT_ROOT') && file_exists(DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php') && function_exists('price2num'),
				'reason' => 'LmdbSalesCommissionsProposalDispatchUnavailable',
			),
		);
	}

	/** Optional lmdbzoning 1.3 travel read contract. The route itself may still be pending. */
	public static function travelMarginAvailable(): bool
	{
		if (!self::isDolibarrVersionAtLeast(self::MIN_DOLIBARR_VERSION) || !isModEnabled('lmdbzoning') || !function_exists('dol_include_once')) { return false; }
		if (!class_exists('LmdbZoningCompatibility') && is_file(dol_buildpath('/lmdbzoning/class/lmdbzoningcompatibility.class.php', 0))) { dol_include_once('/lmdbzoning/class/lmdbzoningcompatibility.class.php'); }
		if (!class_exists('LmdbZoningTravelService') && is_file(dol_buildpath('/lmdbzoning/class/lmdbzoningtravelservice.class.php', 0))) { dol_include_once('/lmdbzoning/class/lmdbzoningtravelservice.class.php'); }
		return class_exists('LmdbZoningCompatibility') && class_exists('LmdbZoningTravelService')
			&& method_exists('LmdbZoningTravelService', 'read') && LmdbZoningCompatibility::isTravelAvailable('propal');
	}

	/** Optional lmdbpropalpv qualification in the current entity. */
	public static function complexSiteMarginAvailable(): bool
	{
		global $db;
		if (!self::isDolibarrVersionAtLeast(self::MIN_DOLIBARR_VERSION) || !isModEnabled('lmdbpropalpv') || !function_exists('dol_include_once') || !is_object($db)) { return false; }
		if (!class_exists('LmdbPropalPVComplexSiteService') && is_file(dol_buildpath('/lmdbpropalpv/class/lmdbpropalpvcomplexsiteservice.class.php', 0))) {
			dol_include_once('/lmdbpropalpv/class/lmdbpropalpvcomplexsiteservice.class.php');
		}
		return class_exists('LmdbPropalPVComplexSiteService') && method_exists('LmdbPropalPVComplexSiteService', 'isAvailable')
			&& LmdbPropalPVComplexSiteService::isAvailable($db);
	}

	/** Check the actual installed entrypoints, not only the declared major version.
	 * Static source coverage is not an instance integration test. @return bool */
	public static function nativeMarginGuardCoverage()
	{
		if (!defined('DOL_DOCUMENT_ROOT') || !defined('DOL_VERSION') || version_compare(DOL_VERSION, '20.0.0', '<') || version_compare(DOL_VERSION, '26.0.0', '>=')) { return false; }
		global $conf;
		$hooks = $conf->modules_parts['hooks']['lmdbsalescommissions'] ?? array();
		if (is_string($hooks)) { $hooks = explode(':', $hooks); }
		if (!is_array($hooks) || array_diff(array('propalcard', 'propallist', 'api', 'ajaxonlinesign'), $hooks)) { return false; }
		static $sourceCoverage = null;
		if ($sourceCoverage !== null) { return $sourceCoverage; }
		$contracts = array(
			'/core/ajax/onlineSign.php' => array("initHooks(array('ajaxonlinesign'))", 'file_put_contents('),
			'/comm/propal/card.php' => array("executeHooks('doActions'", '->closeProposal('),
			'/comm/propal/list.php' => array("executeHooks('doActions'", '->closeProposal('),
			'/api/index.php' => array("initHooks(array('api'))", 'new DolibarrApi('),
			'/includes/restler/framework/Luracast/Restler/Restler.php' => array("\$this->dispatch('call')", 'call_user_func_array(array('),
			'/core/class/html.formmargin.class.php' => array('function getMarginInfosArray', "'pa_total'"),
		);
		foreach ($contracts as $path => $needles) {
			$text = is_readable(DOL_DOCUMENT_ROOT.$path) ? file_get_contents(DOL_DOCUMENT_ROOT.$path) : false;
			if (!is_string($text) || strpos($text, $needles[0]) === false || strpos($text, $needles[1], strpos($text, $needles[0])) === false) { $sourceCoverage = false; return false; }
		}
		$sourceCoverage = true;
		return true;
	}

	/**
	 * Check if a feature is available.
	 *
	 * @param string $code Feature code
	 * @return bool
	 */
	public static function isFeatureAvailable($code)
	{
		if ($code === 'margin_policy_guards') { return self::nativeMarginGuardCoverage(); }
		if ($code === 'travel_margin_uplift') { return self::travelMarginAvailable(); }
		if ($code === 'complex_site_margin_uplift') { return self::complexSiteMarginAvailable(); }
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
