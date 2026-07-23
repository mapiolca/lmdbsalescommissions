<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/LmdbSalesCommissionDashboardService.class.php', 0);

/**
 * @phpstan-type DashboardWidgetDefinition array{
 *     code:string,
 *     label:string,
 *     type:string,
 *     scope:string,
 *     column:int,
 *     position:int,
 *     url?:string
 * }
 * @phpstan-type DashboardWidgetState array{
 *     code:string,
 *     visible:int,
 *     column:int,
 *     position:int
 * }
 */

/**
 * Dashboard widget layout and rendering manager.
 */
class LmdbSalesCommissionDashboardWidgetManager
{
	/** @var DoliDB Database handler */
	private $db;

	/** @var LmdbSalesCommissionDashboardService Dashboard data service */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->service = new LmdbSalesCommissionDashboardService($db);
	}

	/**
	 * Return all known dashboard widget definitions.
	 *
	 * @return array<string, DashboardWidgetDefinition>
	 */
	public static function getWidgetDefinitions()
	{
		$definitions = array();
		$position = 1;
		foreach (array(
			array('kpi_turnover_signed', 'LmdbSalesCommissionsKpiTurnoverSigned', 'kpi', 'commission', 0, 'list.php'),
			array('kpi_margin_signed', 'LmdbSalesCommissionsKpiMarginSigned', 'kpi', 'commission', 0, 'list.php'),
			array('kpi_margin_rate', 'LmdbSalesCommissionsKpiMarginRate', 'kpi_percent', 'commission', 0, 'list.php'),
			array('kpi_commission_estimated', 'LmdbSalesCommissionsKpiCommissionEstimated', 'kpi', 'commission', 0, 'list.php'),
			array('kpi_commission_acquired', 'LmdbSalesCommissionsKpiCommissionAcquired', 'kpi', 'commission', 0, 'list.php'),
			array('kpi_tier_bonus', 'LmdbSalesCommissionsKpiTierBonus', 'kpi', 'commission', 0, 'list.php'),
			array('kpi_commission_total', 'LmdbSalesCommissionsKpiCommissionTotal', 'kpi', 'commission', 0, 'list.php'),
			array('kpi_commission_due', 'LmdbSalesCommissionsKpiCommissionDue', 'kpi', 'due', 0, 'due.php'),
			array('kpi_commission_paid', 'LmdbSalesCommissionsKpiCommissionPaid', 'kpi', 'due', 0, 'paid.php'),
			array('kpi_remaining_due', 'LmdbSalesCommissionsKpiRemainingDue', 'kpi', 'due', 0, 'due.php'),
			array('kpi_monthly_objective', 'LmdbSalesCommissionsMonthlyObjective', 'kpi_nullable', 'objective', 1, 'admin/objectives.php'),
			array('kpi_monthly_objective_rate', 'LmdbSalesCommissionsMonthlyAchievement', 'kpi_percent_nullable', 'objective', 1, 'admin/objectives.php'),
			array('kpi_annual_objective', 'LmdbSalesCommissionsYearlyObjective', 'kpi_nullable', 'objective', 1, 'admin/objectives.php'),
			array('kpi_annual_objective_rate', 'LmdbSalesCommissionsYearlyAchievement', 'kpi_percent_nullable', 'objective', 1, 'admin/objectives.php'),
			array('chart_commissions_monthly_compare', 'LmdbSalesCommissionsChartMonthlyCompare', 'chart_monthly_compare', 'commission_manager', 0, 'list.php'),
			array('chart_turnover_margin_commissions', 'LmdbSalesCommissionsChartTurnoverMarginCommissions', 'chart_turnover_margin_commissions', 'commission_manager', 1, 'list.php'),
			array('chart_commission_funnel', 'LmdbSalesCommissionsChartCommissionFunnel', 'chart_funnel', 'commission', 0, 'list.php'),
			array('chart_commissions_by_agent', 'LmdbSalesCommissionsChartCommissionsByAgent', 'chart_agents', 'commission_manager', 1, 'list.php'),
			array('chart_monthly_objective_vs_actual', 'LmdbSalesCommissionsChartMonthlyObjectiveVsActual', 'chart_objective_monthly', 'objective', 0, 'admin/objectives.php'),
			array('chart_annual_objective_vs_actual', 'LmdbSalesCommissionsChartAnnualObjectiveVsActual', 'chart_objective_annual', 'objective', 1, 'admin/objectives.php'),
			array('chart_tier_progress', 'LmdbSalesCommissionsChartTierProgress', 'chart_tier_progress', 'commission', 1, 'admin/tiergrids.php'),
			array('table_due_commissions', 'LmdbSalesCommissionsTableDueCommissions', 'table_due', 'due', 0, 'due.php'),
			array('table_agents_near_tier', 'LmdbSalesCommissionsTableAgentsNearTier', 'table_agents_near_tier', 'commission_manager', 1, 'admin/tiergrids.php'),
			array('table_late_objectives', 'LmdbSalesCommissionsTableLateObjectives', 'table_late_objectives', 'objective_manager', 0, 'admin/objectives.php'),
			array('table_top_commissioned_deals', 'LmdbSalesCommissionsTableTopCommissionedDeals', 'table_top_deals', 'commission_manager', 1, 'list.php'),
			array('table_due_commissions_aging', 'LmdbSalesCommissionsTableDueCommissionsAging', 'table_due_aging', 'due', 0, 'due.php'),
			array('table_anomalies', 'LmdbSalesCommissionsTableAnomalies', 'table_anomalies', 'admin', 1, 'admin/checks.php'),
		) as $entry) {
			$code = (string) $entry[0];
			$definitions[$code] = array(
				'code' => $code,
				'label' => (string) $entry[1],
				'type' => (string) $entry[2],
				'scope' => (string) $entry[3],
				'column' => (int) $entry[4],
				'position' => $position++,
				'url' => '/lmdbsalescommissions/'.(string) $entry[5],
			);
		}

		return $definitions;
	}

	/**
	 * Return allowed widget definitions for user.
	 *
	 * @param User $user Current user
	 * @return array<string, DashboardWidgetDefinition>
	 */
	public function getAllowedWidgetDefinitions($user)
	{
		$definitions = self::getWidgetDefinitions();
		foreach ($definitions as $code => $definition) {
			if (!$this->isWidgetAllowed($definition, $user)) {
				unset($definitions[$code]);
			}
		}

		return $definitions;
	}

	/**
	 * Return layout states for current user.
	 *
	 * @param User $user Current user
	 * @return array<string, DashboardWidgetState>
	 */
	public function getUserWidgetStates($user)
	{
		global $conf;

		$definitions = $this->getAllowedWidgetDefinitions($user);
		$states = array();
		foreach ($definitions as $code => $definition) {
			$states[$code] = array(
				'code' => $code,
				'visible' => $this->isVisibleByDefault($definition, $user) ? 1 : 0,
				'column' => (int) $definition['column'],
				'position' => (int) $definition['position'],
			);
		}

		$sql = 'SELECT widget_code, visible, position, column_index';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_dashboard_widget_user';
		$sql .= ' WHERE entity = '.((int) $conf->entity);
		$sql .= ' AND fk_user = '.((int) $user->id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_WARNING);
			return $states;
		}

		while (is_object($obj = $this->db->fetch_object($resql))) {
			$code = (string) $obj->widget_code;
			if (!isset($states[$code])) {
				continue;
			}
			$states[$code]['visible'] = (int) $obj->visible;
			$states[$code]['column'] = (int) $obj->column_index;
			$states[$code]['position'] = (int) $obj->position;
		}
		$this->db->free($resql);
		uasort($states, array($this, 'sortWidgetStates'));

		return $states;
	}

	/**
	 * Save current user widget layout.
	 *
	 * @param User              $user         Current user
	 * @param array<int,string> $leftWidgets  Left column widget codes
	 * @param array<int,string> $rightWidgets Right column widget codes
	 * @return int
	 */
	public function saveUserWidgetLayout($user, array $leftWidgets, array $rightWidgets)
	{
		global $conf;

		if (!is_object($user) || (int) $user->id <= 0) {
			return -1;
		}

		$definitions = $this->getAllowedWidgetDefinitions($user);
		$visibleCodes = array();
		$this->db->begin();
		foreach ($definitions as $code => $definition) {
			$column = 0;
			$position = (int) $definition['position'];
			$visible = 0;
			$leftIndex = array_search($code, $leftWidgets, true);
			$rightIndex = array_search($code, $rightWidgets, true);
			if ($leftIndex !== false) {
				$column = 0;
				$position = (int) $leftIndex + 1;
				$visible = 1;
			} elseif ($rightIndex !== false) {
				$column = 1;
				$position = (int) $rightIndex + 1;
				$visible = 1;
			}
			$visibleCodes[$code] = $visible;
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbsalescommissions_dashboard_widget_user';
			$sql .= ' (entity, fk_user, widget_code, visible, position, column_index, date_creation, fk_user_creat, fk_user_modif)';
			$sql .= ' VALUES ('.((int) $conf->entity).', '.((int) $user->id).", '".$this->db->escape($code)."', ".((int) $visible).', '.((int) $position).', '.((int) $column).", '".$this->db->idate(dol_now())."', ".((int) $user->id).', '.((int) $user->id).')';
			$sql .= ' ON DUPLICATE KEY UPDATE visible = '.((int) $visible).', position = '.((int) $position).', column_index = '.((int) $column).', fk_user_modif = '.((int) $user->id);
			if (!$this->db->query($sql)) {
				dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();

		return count($visibleCodes);
	}

	/**
	 * Render add-box selector using native select styles.
	 *
	 * @param array<string, DashboardWidgetState> $states Current widget states
	 * @param string                              $token  CSRF token
	 * @return string
	 */
	public function renderAddBoxSelector(array $states, $token)
	{
		global $langs, $user, $conf;

		$definitions = $this->getAllowedWidgetDefinitions($user);
		$options = array();
		foreach ($definitions as $code => $definition) {
			// A visible widget is already on the dashboard and must not be proposed again.
			if (isset($states[$code]) && (int) $states[$code]['visible'] === 1) {
				continue;
			}
			$options[$code] = $langs->trans($definition['label']);
		}
		if (empty($options)) {
			return '';
		}

		$html = '<form id="addbox" name="addbox" method="POST" action="'.dol_escape_htmltag($_SERVER['REQUEST_URI']).'">';
		$html .= '<input type="hidden" name="token" value="'.dol_escape_htmltag($token).'">';
		$html .= '<input type="hidden" name="action" value="addwidget">';
		$html .= Form::selectarray('widget_code', $options, '', $langs->trans('ChooseBoxToAdd').'...', 0, 0, '', 0, 0, 0, 'ASC', 'maxwidth300 hideonprint', 0, 'hidden selected', 0, 0);
		if (empty($conf->use_javascript_ajax)) {
			$html .= ' <input type="submit" class="button" value="'.$langs->trans('AddBox').'">';
		}
		$html .= '</form>';
		if (!empty($conf->use_javascript_ajax) && function_exists('ajax_combobox')) {
			$html .= ajax_combobox('widget_code');
		}

		return $html;
	}

	/**
	 * Render widgets for both columns.
	 *
	 * @param array<string, DashboardWidgetState> $states  Widget states
	 * @param array<string, mixed>                $filters Dashboard filters
	 * @param User                                $user    Current user
	 * @return array{left:string, right:string}
	 */
	public function renderWidgetColumns(array $states, array $filters, $user)
	{
		$definitions = $this->getAllowedWidgetDefinitions($user);
		$columns = array('left' => '', 'right' => '');
		foreach ($states as $code => $state) {
			if (empty($state['visible']) || !isset($definitions[$code])) {
				continue;
			}
			$widget = new LmdbSalesCommissionDashboardWidget($this->db, $this->service, $definitions[$code], $filters);
			$widget->box_id = $code;
			$widget->boxcode = $code;
			$widget->loadBox(10);
			if ((int) $state['column'] === 0) {
				$columns['left'] .= $widget->showBox(null, null, 1);
			} else {
				$columns['right'] .= $widget->showBox(null, null, 1);
			}
		}

		return $columns;
	}

	/**
	 * Render native-like sortable dashboard script.
	 *
	 * @param string $token CSRF token
	 * @return string
	 */
	public function renderLayoutScript($token)
	{
		global $conf;

		if (empty($conf->use_javascript_ajax)) {
			return '';
		}
		$pageUrlJson = json_encode($_SERVER['PHP_SELF']);
		$tokenJson = json_encode($token);
		if (!is_string($pageUrlJson) || !is_string($tokenJson)) {
			return '';
		}

		return '<script nonce="'.getNonce().'" type="text/javascript">
function lmdbSalesCommissionsWidgetCodes(selector) {
	var codes = [];
	jQuery(selector).children(".boxdraggable").each(function() {
		var id = jQuery(this).attr("id") || "";
		if (id.indexOf("boxto_") === 0) {
			codes.push(id.substring(6));
		}
	});
	return codes.join(",");
}
function lmdbSalesCommissionsSaveLayout() {
	jQuery.ajax({
		type: "POST",
		url: '.$pageUrlJson.',
		data: {
			token: '.$tokenJson.',
			action: "savewidgetlayout",
			left_widgets: lmdbSalesCommissionsWidgetCodes("#boxhalfleft"),
			right_widgets: lmdbSalesCommissionsWidgetCodes("#boxhalfright")
		}
	});
}
jQuery(document).ready(function() {
	jQuery("#boxhalfleft, #boxhalfright").sortable({
		handle: ".boxhandle",
		revert: "invalid",
		items: ".boxdraggable",
		containment: "document",
		connectWith: "#boxhalfleft, #boxhalfright",
		stop: function() {
			lmdbSalesCommissionsSaveLayout();
		}
	});
	jQuery(document).on("click", ".lmdbsalescommissions-boxclose", function() {
		jQuery(this).closest(".boxdraggable").remove();
		lmdbSalesCommissionsSaveLayout();
		window.location.reload();
	});
	jQuery("#widget_code").change(function() {
		if (jQuery(this).val() !== "") {
			jQuery("#addbox").submit();
		}
	});
});
</script>';
	}

	/**
	 * Check widget permission.
	 *
	 * @param DashboardWidgetDefinition $definition Widget definition
	 * @param User                      $user       Current user
	 * @return bool
	 */
	private function isWidgetAllowed(array $definition, $user)
	{
		if (!is_object($user)) {
			return false;
		}
		if (!isModEnabled('lmdbsalescommissions')) {
			return false;
		}

		$scope = $definition['scope'];
		if ($scope === 'commission') {
			return lmdbsalescommissionsCanReadCommissions($user);
		}
		if ($scope === 'commission_manager') {
			return !empty($user->admin)
				|| $user->hasRight('lmdbsalescommissions', 'commission', 'readall')
				|| $user->hasRight('lmdbsalescommissions', 'commission', 'readgroup');
		}
		if ($scope === 'due') {
			return lmdbsalescommissionsCanDo($user, 'due', 'read');
		}
		if ($scope === 'objective') {
			return lmdbsalescommissionsCanReadObjectives($user);
		}
		if ($scope === 'objective_manager') {
			return !empty($user->admin) || $user->hasRight('lmdbsalescommissions', 'objective', 'readall');
		}
		if ($scope === 'admin') {
			return lmdbsalescommissionsCanConfigure($user) || lmdbsalescommissionsCanDo($user, 'maintenance', 'recalculate');
		}

		return false;
	}

	/**
	 * Decide default widget visibility.
	 *
	 * @param DashboardWidgetDefinition $definition Widget definition
	 * @param User                      $user       Current user
	 * @return bool
	 */
	private function isVisibleByDefault(array $definition, $user)
	{
		if (!$this->isWidgetAllowed($definition, $user)) {
			return false;
		}
		$code = $definition['code'];
		if (in_array($code, array('chart_commissions_by_agent', 'table_anomalies', 'table_due_commissions_aging'), true)) {
			return !empty($user->admin) || $user->hasRight('lmdbsalescommissions', 'commission', 'readall');
		}

		return true;
	}

	/**
	 * Sort widget states.
	 *
	 * @param DashboardWidgetState $a First state
	 * @param DashboardWidgetState $b Second state
	 * @return int
	 */
	private function sortWidgetStates(array $a, array $b)
	{
		if ($a['column'] !== $b['column']) {
			return $a['column'] <=> $b['column'];
		}

		return $a['position'] <=> $b['position'];
	}
}

/**
 * Native-like dashboard widget.
 */
class LmdbSalesCommissionDashboardWidget extends ModeleBoxes
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var LmdbSalesCommissionDashboardService Dashboard service */
	private $service;

	/** @var DashboardWidgetDefinition Widget definition */
	private $definition;

	/** @var array<string, mixed> Dashboard filters */
	private $filters;

	/** @var array<string, mixed> Head content */
	public $info_box_head = array();

	/** @var array<int, array<int, array<string, mixed>>> Box content */
	public $info_box_contents = array();

	/**
	 * Constructor.
	 *
	 * @param DoliDB                              $db         Database handler
	 * @param LmdbSalesCommissionDashboardService $service    Dashboard service
	 * @param DashboardWidgetDefinition           $definition Widget definition
	 * @param array<string, mixed>                $filters    Dashboard filters
	 */
	public function __construct($db, $service, array $definition, array $filters)
	{
		$this->db = $db;
		$this->service = $service;
		$this->definition = $definition;
		$this->filters = $filters;
		$this->boxcode = $definition['code'];
		$this->boximg = 'fa-percent';
		$this->boxlabel = $definition['label'];
	}

	/**
	 * Load widget data.
	 *
	 * @param int $max Maximum rows
	 * @return void
	 */
	public function loadBox($max = 10)
	{
		global $langs, $user;

		$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions'));
		$url = isset($this->definition['url']) ? dol_buildpath($this->definition['url'], 1) : '';
		$this->info_box_head = array(
			'text' => $langs->trans($this->definition['label']),
			'sublink' => $url,
			'subpicto' => 'object_generic',
			'subtext' => $langs->trans('Show'),
		);
		$this->info_box_contents = array();

		$type = $this->definition['type'];
		if (strpos($type, 'kpi') === 0) {
			$this->loadKpi();
		} elseif ($type === 'chart_monthly_compare') {
			$this->loadMonthlyCompare();
		} elseif ($type === 'chart_turnover_margin_commissions') {
			$this->loadTurnoverMarginCommissions();
		} elseif ($type === 'chart_funnel') {
			$this->loadFunnel();
		} elseif ($type === 'chart_agents') {
			$this->loadAgentsChart();
		} elseif ($type === 'chart_objective_monthly') {
			$this->loadObjectiveChart('monthly');
		} elseif ($type === 'chart_objective_annual') {
			$this->loadObjectiveChart('yearly');
		} elseif ($type === 'chart_tier_progress') {
			$this->loadTierProgress();
		} elseif ($type === 'table_due') {
			$this->loadDueTable($max);
		} elseif ($type === 'table_agents_near_tier') {
			$this->loadAgentsNearTierTable($max);
		} elseif ($type === 'table_late_objectives') {
			$this->loadLateObjectivesTable($max);
		} elseif ($type === 'table_top_deals') {
			$this->loadTopDealsTable($max);
		} elseif ($type === 'table_due_aging') {
			$this->loadDueAgingTable();
		} elseif ($type === 'table_anomalies') {
			$this->loadAnomaliesTable($user, $max);
		} else {
			$this->addNoRecordRow(1);
		}
	}

	/**
	 * Show box with native-like markup and no stale filter cache.
	 *
	 * @param array<string, mixed>|null $head     Head
	 * @param array<int, mixed>|null    $contents Contents
	 * @param int                       $nooutput No output
	 * @return string
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		global $langs, $conf;

		unset($head, $contents);
		$out = "\n<!-- Box ".dol_escape_htmltag($this->boxcode)." start -->\n";
		$out .= '<div class="box divboxtable boxdraggable" id="boxto_'.dol_escape_htmltag($this->boxcode).'">'."\n";
		$out .= '<table summary="boxtable'.dol_escape_htmltag($this->boxcode).'" class="noborder boxtable centpercent">'."\n";
		$out .= '<tr class="liste_titre box_titre"><th colspan="'.((int) $this->getMaxColumns()).'">';
		if (!empty($conf->use_javascript_ajax)) {
			$out .= '<div class="tdoverflowmax400 maxwidth250onsmartphone float">';
		}
		$out .= dol_escape_htmltag((string) $this->info_box_head['text']);
		if (!empty($conf->use_javascript_ajax)) {
			$out .= '</div>';
			$out .= '<div class="nocellnopadd boxclose floatright nowraponall">';
			if (!empty($this->info_box_head['sublink'])) {
				$out .= '<a href="'.dol_escape_htmltag((string) $this->info_box_head['sublink']).'">';
				$out .= img_picto($langs->trans('Show'), 'object_generic', 'class="opacitymedium marginleftonly"');
				$out .= '</a>';
			}
			$out .= img_picto($langs->trans('MoveBox', $this->boxcode), 'grip_title', 'class="opacitymedium boxhandle hideonsmartphone cursormove marginleftonly"');
			$out .= img_picto($langs->trans('CloseBox', $this->boxcode), 'close_title', 'class="opacitymedium boxclose lmdbsalescommissions-boxclose cursorpointer marginleftonly" id="imgclose'.dol_escape_htmltag($this->boxcode).'"');
			$out .= '<input type="hidden" id="boxlabelentry'.dol_escape_htmltag($this->boxcode).'" value="'.dol_escape_htmltag((string) $this->info_box_head['text']).'">';
			$out .= '</div>';
		}
		$out .= '</th></tr>'."\n";
		foreach ($this->info_box_contents as $line) {
			$out .= '<tr class="oddeven">';
			foreach ($line as $cell) {
				$td = !empty($cell['td']) ? ' '.$cell['td'] : '';
				$out .= '<td'.$td.'>';
				if (!empty($cell['asis'])) {
					$out .= (string) $cell['text'];
				} else {
					$out .= dol_escape_htmltag((string) $cell['text']);
				}
				$out .= '</td>';
			}
			$out .= '</tr>'."\n";
		}
		$out .= '</table>'."\n";
		$out .= '</div>'."\n";
		$out .= "<!-- Box ".dol_escape_htmltag($this->boxcode)." end -->\n";

		if (!$nooutput) {
			print $out;
		}

		return $out;
	}

	/**
	 * Load KPI content.
	 *
	 * @return void
	 */
	private function loadKpi()
	{
		global $langs, $user;

		$map = array(
			'kpi_turnover_signed' => array('turnover_signed', 'price'),
			'kpi_margin_signed' => array('margin_signed', 'price'),
			'kpi_margin_rate' => array('margin_rate', 'percent_nullable'),
			'kpi_commission_estimated' => array('commission_estimated', 'price'),
			'kpi_commission_acquired' => array('commission_acquired', 'price'),
			'kpi_tier_bonus' => array('tier_bonus', 'price'),
			'kpi_commission_total' => array('commission_total', 'price'),
			'kpi_commission_due' => array('commission_due', 'price'),
			'kpi_commission_paid' => array('commission_paid', 'price'),
			'kpi_remaining_due' => array('remaining_due', 'price'),
			'kpi_monthly_objective' => array('monthly_objective', 'objective'),
			'kpi_monthly_objective_rate' => array('monthly_objective_rate', 'percent_objective'),
			'kpi_annual_objective' => array('annual_objective', 'objective'),
			'kpi_annual_objective_rate' => array('annual_objective_rate', 'percent_objective'),
		);
		if (!isset($map[$this->boxcode])) {
			$this->addNoRecordRow(2);
			return;
		}
		$summary = $this->service->getKpiSummary($this->filters, $user);
		$key = $map[$this->boxcode][0];
		$format = $map[$this->boxcode][1];
		$value = array_key_exists($key, $summary) ? $summary[$key] : null;
		$display = $this->formatValue($value, $format);
		$this->info_box_contents[] = array(
			array('td' => 'class="tdoverflowmax200"', 'text' => $langs->trans($this->definition['label'])),
			array('td' => 'class="right"', 'text' => $display, 'asis' => 1),
		);
	}

	/**
	 * Load monthly comparison.
	 *
	 * @return void
	 */
	private function loadMonthlyCompare()
	{
		global $langs, $user;

		$rows = $this->service->getMonthlyCommissionCompare($this->filters, $user);
		$this->info_box_contents[] = $this->headerRow(array('Month', 'LmdbSalesCommissionsCurrentYear', 'LmdbSalesCommissionsPreviousYear'));
		$hasData = false;
		foreach ($rows as $row) {
			if ((float) $row['current'] != 0.0 || (float) $row['previous'] != 0.0) {
				$hasData = true;
			}
			$this->info_box_contents[] = array(
				array('text' => dol_print_date(dol_mktime(0, 0, 0, (int) $row['month'], 1, 2000), '%b'), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['current']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['previous']), 'asis' => 1),
			);
		}
		if (!$hasData) {
			$this->info_box_contents = array();
			$this->addNoRecordRow(3);
		}
	}

	/**
	 * Load turnover/margin/commission series.
	 *
	 * @return void
	 */
	private function loadTurnoverMarginCommissions()
	{
		global $user;

		$rows = $this->service->getTurnoverMarginCommissionSeries($this->filters, $user);
		$this->info_box_contents[] = $this->headerRow(array('Month', 'AmountHT', 'Margin', 'LmdbSalesCommissionsCommissionTotal'));
		$hasData = false;
		foreach ($rows as $row) {
			if ((float) $row['turnover'] != 0.0 || (float) $row['margin'] != 0.0 || (float) $row['commission'] != 0.0) {
				$hasData = true;
			}
			$this->info_box_contents[] = array(
				array('text' => dol_print_date(dol_mktime(0, 0, 0, (int) $row['month'], 1, 2000), '%b'), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['turnover']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['margin']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['commission']), 'asis' => 1),
			);
		}
		if (!$hasData) {
			$this->info_box_contents = array();
			$this->addNoRecordRow(4);
		}
	}

	/**
	 * Load commission funnel.
	 *
	 * @return void
	 */
	private function loadFunnel()
	{
		global $langs, $user;

		$rows = $this->service->getCommissionFunnel($this->filters, $user);
		$labels = array(
			'estimated' => 'LmdbSalesCommissionsEstimatedCommission',
			'acquired' => 'LmdbSalesCommissionsKpiCommissionAcquired',
			'payable' => 'LmdbSalesCommissionsKpiCommissionDue',
			'paid' => 'LmdbSalesCommissionsKpiCommissionPaid',
			'remaining' => 'LmdbSalesCommissionsRemainingToPay',
		);
		$max = max($rows);
		foreach ($labels as $key => $label) {
			$value = isset($rows[$key]) ? (float) $rows[$key] : 0.0;
			$this->info_box_contents[] = array(
				array('text' => $langs->trans($label), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($value), 'asis' => 1),
				array('td' => 'class="right"', 'text' => $this->progress($max > 0 ? ($value / $max) * 100 : 0), 'asis' => 1),
			);
		}
	}

	/**
	 * Load commissions by agent chart.
	 *
	 * @return void
	 */
	private function loadAgentsChart()
	{
		global $user;

		$rows = $this->service->getCommissionsByAgent($this->filters, $user, 10);
		$this->info_box_contents[] = $this->headerRow(array('SalesRepresentative', 'LmdbSalesCommissionsCommissionTotal'));
		if (empty($rows)) {
			$this->info_box_contents = array();
			$this->addNoRecordRow(2);
			return;
		}
		foreach ($rows as $row) {
			$this->info_box_contents[] = array(
				array('text' => lmdbsalescommissionsBuildUserNomUrl($this->db, (int) $row['fk_user'], (string) $row['lastname'], (string) $row['firstname'], (string) $row['login'], (int) $row['user_status'], (string) $row['user_photo'], (string) $row['user_email']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['commission_total']), 'asis' => 1),
			);
		}
	}

	/**
	 * Load objective chart.
	 *
	 * @param string $objectiveType Objective type
	 * @return void
	 */
	private function loadObjectiveChart($objectiveType)
	{
		global $langs, $user;

		$progress = $this->service->getObjectiveProgress($objectiveType, $this->filters, $user);
		if ($progress['objective'] === null) {
			$this->info_box_contents[] = array(array('td' => 'colspan="2"', 'text' => '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsNoObjectiveForPeriod').'</span>', 'asis' => 1));
			return;
		}
		$this->info_box_contents[] = array(
			array('text' => $langs->trans($objectiveType === 'monthly' ? 'LmdbSalesCommissionsMonthlyObjective' : 'LmdbSalesCommissionsYearlyObjective')),
			array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($progress['objective']), 'asis' => 1),
		);
		$this->info_box_contents[] = array(
			array('text' => $langs->trans('LmdbSalesCommissionsRealizedValue')),
			array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($progress['realized']), 'asis' => 1),
		);
		$this->info_box_contents[] = array(
			array('text' => $langs->trans('LmdbSalesCommissionsAchievementRate')),
			array('td' => 'class="right"', 'text' => $this->formatValue($progress['rate'], 'percent_nullable').' '.$this->progress($progress['rate'] !== null ? (float) $progress['rate'] : 0), 'asis' => 1),
		);
	}

	/**
	 * Load tier progress.
	 *
	 * @return void
	 */
	private function loadTierProgress()
	{
		global $langs, $user;

		$progress = $this->service->getTierProgress($this->filters, $user);
		if ($progress['status'] !== 'ok') {
			$messageKey = 'LmdbSalesCommissionsTierProgressUnavailable';
			if ($progress['status'] === 'scope_requires_user') {
				$messageKey = 'LmdbSalesCommissionsSelectUserForTierProgress';
			} elseif ($progress['status'] === 'no_rule') {
				$messageKey = 'LmdbSalesCommissionsNoTierRuleForPeriod';
			}
			$this->info_box_contents[] = array(
				array('td' => 'colspan="2"', 'text' => '<span class="opacitymedium">'.$langs->trans($messageKey).'</span>', 'asis' => 1),
			);
			return;
		}

		$this->info_box_contents[] = array(
			array('text' => $langs->trans('LmdbSalesCommissionsTierCalculationMode')),
			array('td' => 'class="right"', 'text' => dol_escape_htmltag(lmdbsalescommissionsGetTierCalculationModeLabel($langs, $progress['calculation_mode']))),
		);
		$this->info_box_contents[] = array(
			array('text' => $langs->trans('Period')),
			array('td' => 'class="right"', 'text' => dol_escape_htmltag((string) $progress['period_label'])),
		);

		$rows = array(
			'LmdbSalesCommissionsCurrentTurnover' => $progress['turnover'],
			'LmdbSalesCommissionsReachedTier' => $progress['reached_threshold'],
			'LmdbSalesCommissionsNextTier' => $progress['next_threshold'],
			'LmdbSalesCommissionsRemainingBeforeTier' => $progress['remaining'],
			'LmdbSalesCommissionsCurrentTierCommission' => $progress['current_commission'],
			'LmdbSalesCommissionsCommissionAtNextThreshold' => $progress['commission_at_next_threshold'],
			'LmdbSalesCommissionsAdditionalCommissionAtNextThreshold' => $progress['additional_commission_to_next_threshold'],
		);
		foreach ($rows as $label => $value) {
			$display = $value === null ? '<span class="opacitymedium">'.$langs->trans('None').'</span>' : lmdbsalescommissionsFormatTotalAmount($value);
			if (!empty($progress['open_ended']) && in_array($label, array('LmdbSalesCommissionsNextTier', 'LmdbSalesCommissionsRemainingBeforeTier'), true)) {
				$display = '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsOpenEndedTier').'</span>';
			}
			$this->info_box_contents[] = array(
				array('text' => $langs->trans($label)),
				array('td' => 'class="right"', 'text' => $display, 'asis' => 1),
			);
		}
		if ($progress['active_rate'] !== null) {
			$this->info_box_contents[] = array(
				array('text' => $langs->trans('LmdbSalesCommissionsActiveTierRate')),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($progress['active_rate']).' %', 'asis' => 1),
			);
		}
		$progressDisplay = !empty($progress['open_ended'])
			? '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsOpenEndedTier').'</span>'
			: $this->formatValue($progress['rate'], 'percent_nullable').' '.$this->progress($progress['rate'] !== null ? (float) $progress['rate'] : 0);
		$this->info_box_contents[] = array(
			array('text' => $langs->trans('LmdbSalesCommissionsProgression')),
			array('td' => 'class="right"', 'text' => $progressDisplay, 'asis' => 1),
		);
	}

	/**
	 * Load due commissions table.
	 *
	 * @param int $max Max rows
	 * @return void
	 */
	private function loadDueTable($max)
	{
		global $langs, $user;

		$rows = $this->service->getDueCommissions($this->filters, $user, $max);
		$this->info_box_contents[] = $this->headerRow(array('SalesRepresentative', 'Company', 'Source', 'Amount', 'DateDue', 'Status'));
		if (empty($rows)) {
			$this->info_box_contents = array();
			$this->addNoRecordRow(6);
			return;
		}
		foreach ($rows as $row) {
			$this->info_box_contents[] = array(
				array('text' => lmdbsalescommissionsBuildUserNomUrl($this->db, (int) $row['user_id'], (string) $row['lastname'], (string) $row['firstname'], (string) $row['login'], (int) $row['user_status'], (string) $row['user_photo'], (string) $row['user_email']), 'asis' => 1),
				array('text' => lmdbsalescommissionsBuildThirdpartyNomUrl($this->db, (int) $row['socid'], (string) $row['thirdparty_name']), 'asis' => 1),
				array('text' => lmdbsalescommissionsBuildSourceNomUrl($this->db, (string) $row['source_type'], (int) $row['fk_source'], (string) $row['source_ref']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['amount']), 'asis' => 1),
				array('td' => 'class="center"', 'text' => dol_print_date($this->db->jdate($row['date_due']), 'day'), 'asis' => 1),
				array('td' => 'class="center"', 'text' => lmdbsalescommissionsStatusBadge(lmdbsalescommissionsGetDueStatusLabel($langs, (int) $row['status']), 1), 'asis' => 1),
			);
		}
	}

	/**
	 * Load agents near tier table.
	 *
	 * @param int $max Max rows
	 * @return void
	 */
	private function loadAgentsNearTierTable($max)
	{
		global $langs, $user;

		$rows = $this->service->getAgentsNearTier($this->filters, $user, $max);
		$this->info_box_contents[] = $this->headerRow(array('SalesRepresentative', 'LmdbSalesCommissionsTierCalculationMode', 'AmountHT', 'LmdbSalesCommissionsNextTier', 'LmdbSalesCommissionsRemainingBeforeTier', 'LmdbSalesCommissionsCurrentTierCommission', 'LmdbSalesCommissionsAdditionalCommissionAtNextThreshold', 'Progress'));
		if (empty($rows)) {
			$this->info_box_contents = array();
			$this->addNoRecordRow(8);
			return;
		}
		foreach ($rows as $row) {
			$mode = lmdbsalescommissionsGetTierCalculationModeLabel($langs, isset($row['calculation_mode']) ? (string) $row['calculation_mode'] : null);
			$modeDetails = isset($row['period_label']) ? (string) $row['period_label'] : '';
			if (isset($row['active_rate']) && $row['active_rate'] !== null) {
				$modeDetails .= ($modeDetails !== '' ? ' · ' : '').lmdbsalescommissionsFormatTotalAmount($row['active_rate']).' %';
			}
			$modeDisplay = dol_escape_htmltag($mode);
			if ($modeDetails !== '') {
				$modeDisplay .= '<br><span class="opacitymedium">'.dol_escape_htmltag($modeDetails).'</span>';
			}
			$this->info_box_contents[] = array(
				array('text' => lmdbsalescommissionsBuildUserNomUrl($this->db, (int) $row['fk_user'], (string) $row['lastname'], (string) $row['firstname'], (string) $row['login'], (int) $row['user_status'], (string) $row['user_photo'], (string) $row['user_email']), 'asis' => 1),
				array('text' => $modeDisplay, 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['turnover']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['next_threshold']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['remaining']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['current_commission']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['additional_commission_to_next_threshold']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => $this->formatValue($row['rate'], 'percent_nullable').' '.$this->progress((float) $row['rate']), 'asis' => 1),
			);
		}
	}

	/**
	 * Load late objectives table.
	 *
	 * @param int $max Max rows
	 * @return void
	 */
	private function loadLateObjectivesTable($max)
	{
		global $langs, $user;

		$rows = $this->service->getLateObjectives($this->filters, $user, $max);
		$this->info_box_contents[] = $this->headerRow(array('SalesRepresentative', 'Type', 'Period', 'LmdbSalesCommissionsTargetValue', 'LmdbSalesCommissionsRealizedValue', 'LmdbSalesCommissionsAchievementRate', 'Diff'));
		if (empty($rows)) {
			$this->info_box_contents = array();
			$this->addNoRecordRow(7);
			return;
		}
		foreach ($rows as $row) {
			$this->info_box_contents[] = array(
				array('text' => lmdbsalescommissionsBuildUserNomUrl($this->db, (int) $row['fk_user'], (string) $row['lastname'], (string) $row['firstname'], (string) $row['login'], (int) $row['user_status'], (string) $row['user_photo'], (string) $row['user_email']), 'asis' => 1),
				array('text' => $langs->trans($row['objective_type'] === 'monthly' ? 'LmdbSalesCommissionsMonthlyObjective' : 'LmdbSalesCommissionsYearlyObjective')),
				array('td' => 'class="center"', 'text' => (string) $row['period']),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['objective']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['realized']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => $this->formatValue($row['rate'], 'percent_nullable'), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['gap']), 'asis' => 1),
			);
		}
	}

	/**
	 * Load top deals table.
	 *
	 * @param int $max Max rows
	 * @return void
	 */
	private function loadTopDealsTable($max)
	{
		global $langs, $user;

		$rows = $this->service->getTopCommissionedDeals($this->filters, $user, $max);
		$this->info_box_contents[] = $this->headerRow(array('SalesRepresentative', 'Company', 'Source', 'AmountHT', 'Margin', 'LmdbSalesCommissionsCommissionTotal', 'Status'));
		if (empty($rows)) {
			$this->info_box_contents = array();
			$this->addNoRecordRow(7);
			return;
		}
		foreach ($rows as $row) {
			$this->info_box_contents[] = array(
				array('text' => lmdbsalescommissionsBuildUserNomUrl($this->db, (int) $row['fk_user'], (string) $row['lastname'], (string) $row['firstname'], (string) $row['login'], (int) $row['user_status'], (string) $row['user_photo'], (string) $row['user_email']), 'asis' => 1),
				array('text' => lmdbsalescommissionsBuildThirdpartyNomUrl($this->db, (int) $row['fk_soc'], (string) $row['thirdparty_name']), 'asis' => 1),
				array('text' => lmdbsalescommissionsBuildSourceNomUrl($this->db, (string) $row['source_type'], (int) $row['fk_source'], (string) $row['source_ref']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['amount_base']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['margin_base']), 'asis' => 1),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['commission_total']), 'asis' => 1),
				array('td' => 'class="center"', 'text' => lmdbsalescommissionsStatusBadge(lmdbsalescommissionsGetLineStatusLabel($langs, (int) $row['status']), (int) $row['status']), 'asis' => 1),
			);
		}
	}

	/**
	 * Load due aging table.
	 *
	 * @return void
	 */
	private function loadDueAgingTable()
	{
		global $langs, $user;

		$rows = $this->service->getDueCommissionsAging($this->filters, $user);
		$this->info_box_contents[] = $this->headerRow(array('Period', 'Number', 'Amount'));
		foreach ($rows as $row) {
			$this->info_box_contents[] = array(
				array('text' => $langs->trans($row['label'])),
				array('td' => 'class="right"', 'text' => (string) (int) $row['count']),
				array('td' => 'class="right"', 'text' => lmdbsalescommissionsFormatTotalAmount($row['amount']), 'asis' => 1),
			);
		}
	}

	/**
	 * Load anomalies table.
	 *
	 * @param User $user Current user
	 * @param int  $max  Max rows
	 * @return void
	 */
	private function loadAnomaliesTable($user, $max)
	{
		global $langs;

		$rows = $this->service->getAnomalies($this->filters, $user, $max);
		$this->info_box_contents[] = $this->headerRow(array('Severity', 'Type', 'Element', 'Description', 'Action'));
		if (empty($rows)) {
			$this->info_box_contents = array();
			$this->addNoRecordRow(5);
			return;
		}
		foreach ($rows as $row) {
			$severityLabel = $langs->trans($row['severity'] === 'error' ? 'Error' : 'Warning');
			$this->info_box_contents[] = array(
				array('td' => 'class="center"', 'text' => lmdbsalescommissionsStatusBadge($severityLabel, $row['severity'] === 'error' ? -1 : 0), 'asis' => 1),
				array('text' => $langs->trans((string) $row['type'])),
				array('text' => (string) $row['element']),
				array('text' => '<span class="opacitymedium">'.$langs->trans((string) $row['description']).'</span>', 'asis' => 1),
				array('text' => '<a href="'.dol_escape_htmltag((string) $row['url']).'">'.$langs->trans((string) $row['action']).'</a>', 'asis' => 1),
			);
		}
	}

	/**
	 * Format a widget value.
	 *
	 * @param mixed  $value  Value
	 * @param string $format Format
	 * @return string
	 */
	private function formatValue($value, $format)
	{
		global $langs;

		if (($format === 'objective' || $format === 'percent_objective') && $value === null) {
			return '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsNoObjectiveForPeriod').'</span>';
		}
		if ($format === 'price' || $format === 'objective') {
			return lmdbsalescommissionsFormatTotalAmount($value);
		}
		if ($format === 'percent_nullable' || $format === 'percent_objective') {
			return $value === null ? '<span class="opacitymedium">-</span>' : lmdbsalescommissionsFormatTotalAmount($value).' %';
		}

		return dol_escape_htmltag((string) $value);
	}

	/**
	 * Return native-like progress indicator.
	 *
	 * @param float $value Percent value
	 * @return string
	 */
	private function progress($value)
	{
		$bounded = max(0, min(100, (float) $value));

		return '<progress max="100" value="'.price2num($bounded, 'MT').'">'.lmdbsalescommissionsFormatTotalAmount($bounded).' %</progress>';
	}

	/**
	 * Build a header row.
	 *
	 * @param array<int, string> $labels Translation keys
	 * @return array<int, array<string, mixed>>
	 */
	private function headerRow(array $labels)
	{
		global $langs;

		$row = array();
		foreach ($labels as $label) {
			$row[] = array('td' => 'class="liste_titre"', 'text' => $langs->trans($label), 'asis' => 1);
		}

		return $row;
	}

	/**
	 * Add no-record row.
	 *
	 * @param int $colspan Column span
	 * @return void
	 */
	private function addNoRecordRow($colspan)
	{
		global $langs;

		$this->info_box_contents[] = array(
			array('td' => 'colspan="'.((int) $colspan).'"', 'text' => '<span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span>', 'asis' => 1),
		);
	}

	/**
	 * Return largest row column count.
	 *
	 * @return int
	 */
	private function getMaxColumns()
	{
		$max = 1;
		foreach ($this->info_box_contents as $line) {
			$max = max($max, count($line));
		}

		return $max;
	}
}
