<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';
require_once dol_buildpath('/lmdbsalescommissions/class/LmdbSalesCommissionDashboardWidgetManager.class.php', 0);

/**
 * Shared base for home dashboard boxes.
 */
abstract class box_lmdbsalescommissions_dashboard_base extends ModeleBoxes
{
	public $boximg = 'fa-percent';
	public $depends = array('lmdbsalescommissions');

	/** @var DoliDB Database handler */
	public $db;

	/** @var array<string, mixed> Box parameters */
	public $param;

	/** @var array<string, mixed> Head content */
	public $info_box_head = array();

	/** @var array<int, array<int, array<string, mixed>>> Box content */
	public $info_box_contents = array();

	/** @var string Dashboard widget code reused by this home box */
	protected $dashboardWidgetCode = '';

	/** @var bool Force current user scope */
	protected $forceOwnScope = false;

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

		$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions'));
		$this->info_box_head = array('text' => $langs->trans($this->boxlabel));
		$this->info_box_contents = array();

		$manager = new LmdbSalesCommissionDashboardWidgetManager($this->db);
		$definitions = $manager->getAllowedWidgetDefinitions($user);
		if (!isset($definitions[$this->dashboardWidgetCode])) {
			$this->info_box_contents[0][0] = array('td' => '', 'text' => $langs->trans('NotEnoughPermissions'), 'asis' => 0);
			return;
		}

		$service = new LmdbSalesCommissionDashboardService($this->db);
		$filters = $service->getDefaultFilters($user);
		if ($this->forceOwnScope) {
			$filters['fk_user'] = (int) $user->id;
		}
		$widget = new LmdbSalesCommissionDashboardWidget($this->db, $service, $definitions[$this->dashboardWidgetCode], $filters);
		$widget->box_id = $this->boxcode;
		$widget->boxcode = $this->boxcode;
		$widget->loadBox($max);
		$this->info_box_contents = $widget->info_box_contents;
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
