<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Module descriptor for Commissions commerciales.
 */
class modLmdbSalesCommissions extends DolibarrModules
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		$this->db = $db;

		$this->numero = 450024;
		$this->rights_class = 'lmdbsalescommissions';
		$this->family = 'Les Métiers du Bâtiment';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'LmdbSalesCommissionsDesc';
		$this->descriptionlong = 'LmdbSalesCommissionsDescLong';
		$this->version = '0.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-percent';
		$this->editor_name = 'Pierre Ardoin';
		$this->editor_url = '';

		$this->module_parts = array(
			'triggers' => 1,
			'hooks' => array(
				'propalcard',
				'notification',
			),
			'substitutions' => 1,
		);
		$this->dirs = array();
		$this->config_page_url = array(
			'setup.php@lmdbsalescommissions',
		);
		$this->langfiles = array('lmdbsalescommissions@lmdbsalescommissions');
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->phpmin = array(8, 0);
		$this->need_dolibarr_version = array(20, 0);
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();
		$this->const = array();
		$this->tabs = array(
			'user:+lmdbsalescommissions:LmdbSalesCommissions:lmdbsalescommissions@lmdbsalescommissions:$user->admin || $user->hasRight("lmdbsalescommissions", "commission", "readown") || $user->hasRight("lmdbsalescommissions", "commission", "readall") || $user->hasRight("lmdbsalescommissions", "commission", "readgroup"):/lmdbsalescommissions/user_commissions.php?id=__ID__',
		);
		$this->boxes = array(
			array(
				'file' => 'box_lmdbsalescommissions_agent.php@lmdbsalescommissions',
				'enabledbydefaulton' => 'Home',
			),
			array(
				'file' => 'box_lmdbsalescommissions_manager.php@lmdbsalescommissions',
				'enabledbydefaulton' => 'Home',
			),
		);
		$this->cronjobs = array(
			0 => array(
				'label' => 'LmdbSalesCommissionsCronArchiveMonthly',
				'jobtype' => 'method',
				'class' => '/lmdbsalescommissions/class/lmdbsalescommissioncron.class.php',
				'objectname' => 'LmdbSalesCommissionCron',
				'method' => 'archiveMonthlyObjectives',
				'parameters' => '',
				'comment' => 'LmdbSalesCommissionsCronArchiveMonthlyDesc',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 0,
				'test' => 'isModEnabled("lmdbsalescommissions")',
				'priority' => 50,
			),
			1 => array(
				'label' => 'LmdbSalesCommissionsCronArchiveYearly',
				'jobtype' => 'method',
				'class' => '/lmdbsalescommissions/class/lmdbsalescommissioncron.class.php',
				'objectname' => 'LmdbSalesCommissionCron',
				'method' => 'archiveYearlyObjectives',
				'parameters' => '',
				'comment' => 'LmdbSalesCommissionsCronArchiveYearlyDesc',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 0,
				'test' => 'isModEnabled("lmdbsalescommissions")',
				'priority' => 50,
			),
			2 => array(
				'label' => 'LmdbSalesCommissionsCronGenerateDueDates',
				'jobtype' => 'method',
				'class' => '/lmdbsalescommissions/class/lmdbsalescommissioncron.class.php',
				'objectname' => 'LmdbSalesCommissionCron',
				'method' => 'generateMissingDueDates',
				'parameters' => '',
				'comment' => 'LmdbSalesCommissionsCronGenerateDueDatesDesc',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'status' => 0,
				'test' => 'isModEnabled("lmdbsalescommissions")',
				'priority' => 50,
			),
			3 => array(
				'label' => 'LmdbSalesCommissionsCronDetectPayableDueDates',
				'jobtype' => 'method',
				'class' => '/lmdbsalescommissions/class/lmdbsalescommissioncron.class.php',
				'objectname' => 'LmdbSalesCommissionCron',
				'method' => 'detectPayableDueDates',
				'parameters' => '',
				'comment' => 'LmdbSalesCommissionsCronDetectPayableDueDatesDesc',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'status' => 0,
				'test' => 'isModEnabled("lmdbsalescommissions")',
				'priority' => 50,
			),
		);

		if (!isset($langs) || !is_object($langs)) {
			$langs = null;
		}

		$this->rights = array();
		$r = 0;

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbSalesCommissionsPermissionReadOwnCommissions';
		$this->rights[$r][4] = 'commission';
		$this->rights[$r][5] = 'readown';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbSalesCommissionsPermissionReadAllCommissions';
		$this->rights[$r][4] = 'commission';
		$this->rights[$r][5] = 'readall';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbSalesCommissionsPermissionReadGroupCommissions';
		$this->rights[$r][4] = 'commission';
		$this->rights[$r][5] = 'readgroup';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbSalesCommissionsPermissionReadDueCommissions';
		$this->rights[$r][4] = 'due';
		$this->rights[$r][5] = 'read';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbSalesCommissionsPermissionMarkCommissionPaid';
		$this->rights[$r][4] = 'due';
		$this->rights[$r][5] = 'pay';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbSalesCommissionsPermissionExportOwnCommissions';
		$this->rights[$r][4] = 'export';
		$this->rights[$r][5] = 'own';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbSalesCommissionsPermissionExportAllCommissions';
		$this->rights[$r][4] = 'export';
		$this->rights[$r][5] = 'all';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbSalesCommissionsPermissionReadOwnObjectives';
		$this->rights[$r][4] = 'objective';
		$this->rights[$r][5] = 'readown';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbSalesCommissionsPermissionReadAllObjectives';
		$this->rights[$r][4] = 'objective';
		$this->rights[$r][5] = 'readall';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbSalesCommissionsPermissionConfigureModule';
		$this->rights[$r][4] = 'admin';
		$this->rights[$r][5] = 'configure';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbSalesCommissionsPermissionRecalculateCommissions';
		$this->rights[$r][4] = 'maintenance';
		$this->rights[$r][5] = 'recalculate';

		$r++;
		$this->rights[$r][0] = $this->numero * 100 + $r;
		$this->rights[$r][1] = 'LmdbSalesCommissionsPermissionArchiveObjectives';
		$this->rights[$r][4] = 'objective';
		$this->rights[$r][5] = 'archive';

		$this->menu = array();
		$r = 0;

		$readperms = '$user->admin || $user->hasRight("lmdbsalescommissions", "commission", "readown") || $user->hasRight("lmdbsalescommissions", "commission", "readall") || $user->hasRight("lmdbsalescommissions", "commission", "readgroup")';
		$dueperms = '$user->admin || $user->hasRight("lmdbsalescommissions", "due", "read")';
		$exportperms = '$user->admin || $user->hasRight("lmdbsalescommissions", "export", "own") || $user->hasRight("lmdbsalescommissions", "export", "all")';

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing',
			'type' => 'left',
			'titre' => 'LmdbSalesCommissionsMenu',
			'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'lmdbsalescommissions',
			'url' => '/lmdbsalescommissions/index.php',
			'langs' => 'lmdbsalescommissions@lmdbsalescommissions',
			'position' => 1000,
			'enabled' => 'isModEnabled("lmdbsalescommissions")',
			'perms' => $readperms,
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=lmdbsalescommissions',
			'type' => 'left',
			'titre' => 'LmdbSalesCommissionsDashboard',
			'mainmenu' => 'billing',
			'leftmenu' => 'lmdbsalescommissions_dashboard',
			'url' => '/lmdbsalescommissions/index.php',
			'langs' => 'lmdbsalescommissions@lmdbsalescommissions',
			'position' => 1001,
			'enabled' => 'isModEnabled("lmdbsalescommissions")',
			'perms' => $readperms,
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=lmdbsalescommissions',
			'type' => 'left',
			'titre' => 'LmdbSalesCommissionsDue',
			'mainmenu' => 'billing',
			'leftmenu' => 'lmdbsalescommissions_due',
			'url' => '/lmdbsalescommissions/due.php',
			'langs' => 'lmdbsalescommissions@lmdbsalescommissions',
			'position' => 1002,
			'enabled' => 'isModEnabled("lmdbsalescommissions")',
			'perms' => $dueperms,
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=lmdbsalescommissions',
			'type' => 'left',
			'titre' => 'LmdbSalesCommissionsTracking',
			'mainmenu' => 'billing',
			'leftmenu' => 'lmdbsalescommissions_tracking',
			'url' => '/lmdbsalescommissions/list.php',
			'langs' => 'lmdbsalescommissions@lmdbsalescommissions',
			'position' => 1003,
			'enabled' => 'isModEnabled("lmdbsalescommissions")',
			'perms' => $readperms,
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=lmdbsalescommissions',
			'type' => 'left',
			'titre' => 'LmdbSalesCommissionsPaid',
			'mainmenu' => 'billing',
			'leftmenu' => 'lmdbsalescommissions_paid',
			'url' => '/lmdbsalescommissions/paid.php',
			'langs' => 'lmdbsalescommissions@lmdbsalescommissions',
			'position' => 1004,
			'enabled' => 'isModEnabled("lmdbsalescommissions")',
			'perms' => $dueperms,
			'target' => '',
			'user' => 2,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=lmdbsalescommissions',
			'type' => 'left',
			'titre' => 'LmdbSalesCommissionsExports',
			'mainmenu' => 'billing',
			'leftmenu' => 'lmdbsalescommissions_exports',
			'url' => '/lmdbsalescommissions/export.php',
			'langs' => 'lmdbsalescommissions@lmdbsalescommissions',
			'position' => 1005,
			'enabled' => 'isModEnabled("lmdbsalescommissions")',
			'perms' => $exportperms,
			'target' => '',
			'user' => 2,
		);
	}

	/**
	 * Initialize module.
	 *
	 * @param string $options Options
	 * @return int
	 */
	public function init($options = '')
	{
		$sql = array();
		$result = $this->_load_tables('/lmdbsalescommissions/sql/');
		if ($result < 0) {
			return -1;
		}

		return $this->_init($sql, $options);
	}

	/**
	 * Remove module.
	 *
	 * @param string $options Options
	 * @return int
	 */
	public function remove($options = '')
	{
		$sql = array();

		return $this->_remove($sql, $options);
	}
}
