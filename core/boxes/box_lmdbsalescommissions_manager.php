<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';
require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);

/**
 * Manager commission dashboard widget.
 */
class box_lmdbsalescommissions_manager extends ModeleBoxes
{
	public $boxcode = 'lmdbsalescommissions_manager';
	public $boximg = 'fa-percent';
	public $boxlabel = 'LmdbSalesCommissionsWidgetManager';
	public $depends = array('lmdbsalescommissions');

	/** @var DoliDB Database handler */
	public $db;

	/** @var array<string, mixed> Box parameters */
	public $param;

	/** @var array<string, mixed> Head content */
	public $info_box_head = array();

	/** @var array<int, array<int, array<string, mixed>>> Box content */
	public $info_box_contents = array();

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db    Database handler
	 * @param string $param Parameters
	 */
	public function __construct($db, $param = '')
	{
		$this->db = $db;
		$this->param = array('params' => $param);
	}

	/**
	 * Load box data.
	 *
	 * @param int $max Maximum rows
	 * @return void
	 */
	public function loadBox($max = 5)
	{
		global $langs, $user;

		unset($max);

		$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions'));
		$this->info_box_head = array('text' => $langs->trans('LmdbSalesCommissionsWidgetManager'));
		$this->info_box_contents = array();

		if (!isModEnabled('lmdbsalescommissions') || (!$user->admin && !$user->hasRight('lmdbsalescommissions', 'commission', 'readall'))) {
			$this->info_box_contents[0][0] = array('td' => '', 'text' => $langs->trans('NotEnoughPermissions'), 'asis' => 0);
			return;
		}

		require_once dol_buildpath('/lmdbsalescommissions/class/LmdbSalesCommissionDashboardService.class.php', 0);

		$service = new LmdbSalesCommissionDashboardService($this->db);
		$filters = $service->getDefaultFilters($user);
		$summary = $service->getKpiSummary($filters, $user);
		$values = array(
			'LmdbSalesCommissionsCommissionTotal' => (float) $summary['commission_total'],
			'LmdbSalesCommissionsPayableTotal' => (float) $summary['commission_due'],
			'LmdbSalesCommissionsPaidTotal' => (float) $summary['commission_paid'],
			'AmountHT' => (float) $summary['turnover_signed'],
			'Margin' => (float) $summary['margin_signed'],
		);

		$i = 0;
		foreach ($values as $label => $value) {
			$this->info_box_contents[$i][0] = array('td' => 'class="tdoverflowmax200"', 'text' => $langs->trans($label), 'asis' => 0);
			$this->info_box_contents[$i][1] = array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($value), 'asis' => 0);
			$i++;
		}
	}

	/**
	 * Show box.
	 *
	 * @param array<string, mixed>|null $head     Head
	 * @param array<int, mixed>|null    $contents Contents
	 * @param int                       $nooutput No output
	 * @return string
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}
}
