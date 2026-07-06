<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

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

		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissiondueservice.class.php', 0);

		$sql = 'SELECT SUM(l.commission_total) AS commission_total, SUM(l.payable_total) AS payable_total, SUM(l.paid_total) AS paid_total';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
		$sql .= ' WHERE l.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_line')).')';
		$values = array(
			'LmdbSalesCommissionsCommissionTotal' => 0.0,
			'LmdbSalesCommissionsPayableTotal' => 0.0,
			'LmdbSalesCommissionsPaidTotal' => 0.0,
			'AmountHT' => 0.0,
			'Margin' => 0.0,
		);
		$resql = $this->db->query($sql);
		if ($resql && is_object($obj = $this->db->fetch_object($resql))) {
			$values['LmdbSalesCommissionsCommissionTotal'] = (float) $obj->commission_total;
			$values['LmdbSalesCommissionsPayableTotal'] = (float) $obj->payable_total;
			$values['LmdbSalesCommissionsPaidTotal'] = (float) $obj->paid_total;
			$this->db->free($resql);
		}

		$sql = 'SELECT SUM(src.amount_base) AS amount_base_total, SUM(src.margin_base) AS margin_base_total';
		$sql .= ' FROM (';
		$sql .= ' SELECT l.entity, l.fk_user, l.source_type, l.fk_source, MAX(l.amount_base) AS amount_base, MAX(l.margin_base) AS margin_base';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
		$sql .= ' WHERE l.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_line')).')';
		$sql .= " AND l.source_type = 'proposal'";
		$sql .= ' AND l.status = 1';
		$sql .= ' GROUP BY l.entity, l.fk_user, l.source_type, l.fk_source';
		$sql .= ') AS src';
		$resbase = $this->db->query($sql);
		if ($resbase && is_object($objbase = $this->db->fetch_object($resbase))) {
			$values['AmountHT'] = (float) $objbase->amount_base_total;
			$values['Margin'] = (float) $objbase->margin_base_total;
			$this->db->free($resbase);
		}

		$sql = 'SELECT SUM(d.amount) AS amount_due';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l ON l.rowid = d.fk_commission_line AND l.entity = d.entity';
		$sql .= ' WHERE d.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_due')).')';
		$sql .= ' AND d.status = '.LmdbSalesCommissionDueService::STATUS_DUE;
		$resdue = $this->db->query($sql);
		if ($resdue && is_object($objdue = $this->db->fetch_object($resdue))) {
			$values['LmdbSalesCommissionsDue'] = (float) $objdue->amount_due;
			$this->db->free($resdue);
		}

		$i = 0;
		foreach ($values as $label => $value) {
			$this->info_box_contents[$i][0] = array('td' => 'class="tdoverflowmax200"', 'text' => $langs->trans($label), 'asis' => 0);
			$this->info_box_contents[$i][1] = array('td' => 'class="right"', 'text' => price($value), 'asis' => 0);
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
