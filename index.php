<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

$res = 0;
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/LmdbSalesCommissionDashboardService.class.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/LmdbSalesCommissionDashboardWidgetManager.class.php', 0);
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';

/**
 * Parse comma-separated widget codes from layout action.
 *
 * @param string $raw Raw input
 * @return array<int, string>
 */
function lmdbsalescommissions_parse_widget_codes($raw)
{
	$codes = array();
	foreach (explode(',', $raw) as $code) {
		$code = trim($code);
		if ($code !== '' && preg_match('/^[a-z0-9_]+$/', $code)) {
			$codes[] = $code;
		}
	}

	return array_values(array_unique($codes));
}

/**
 * Return dashboard query string.
 *
 * @param array<string, mixed> $filters Dashboard filters
 * @return string
 */
function lmdbsalescommissions_build_dashboard_param(array $filters)
{
	$param = '';
	foreach (array('fk_user', 'fk_usergroup', 'year', 'month') as $key) {
		if (!empty($filters[$key])) {
			$param .= '&'.$key.'='.((int) $filters[$key]);
		}
	}
	foreach (array('source', 'commission_type', 'status', 'objective_status') as $key) {
		if (!empty($filters[$key]) && $filters[$key] !== 'all') {
			$param .= '&'.$key.'='.urlencode((string) $filters[$key]);
		}
	}
	$param .= lmdbsalescommissionsBuildDateFilterParams('date_start');
	$param .= lmdbsalescommissionsBuildDateFilterParams('date_end');

	return $param;
}

$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions'));

$action = GETPOST('action', 'aZ09');

if (!isModEnabled('lmdbsalescommissions')) {
	accessforbidden();
}

if (!lmdbsalescommissionsCanReadCommissions($user)) {
	accessforbidden();
}

$form = new Form($db);
$formother = new FormOther($db);
$dashboardService = new LmdbSalesCommissionDashboardService($db);
$widgetManager = new LmdbSalesCommissionDashboardWidgetManager($db);

$rawFilters = array(
	'fk_user' => GETPOSTINT('fk_user'),
	'fk_usergroup' => GETPOSTINT('fk_usergroup'),
	'year' => GETPOSTINT('year'),
	'month' => GETPOSTINT('month'),
	'date_start' => lmdbsalescommissionsGetDateFilterValue('date_start', false),
	'date_end' => lmdbsalescommissionsGetDateFilterValue('date_end', true),
	'source' => GETPOST('source', 'aZ09'),
	'commission_type' => GETPOST('commission_type', 'aZ09'),
	'status' => GETPOST('status', 'aZ09'),
	'objective_status' => GETPOST('objective_status', 'aZ09'),
);
$filters = $dashboardService->normalizeFilters($rawFilters, $user);
$param = lmdbsalescommissions_build_dashboard_param($filters);

if ($filters['fk_user'] > 0 && !lmdbsalescommissionsCanReadUserScope($user, $filters['fk_user'])) {
	accessforbidden();
}

if ($action === 'savewidgetlayout') {
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}
	$leftWidgets = lmdbsalescommissions_parse_widget_codes(GETPOST('left_widgets', 'alphanohtml'));
	$rightWidgets = lmdbsalescommissions_parse_widget_codes(GETPOST('right_widgets', 'alphanohtml'));
	$result = $widgetManager->saveUserWidgetLayout($user, $leftWidgets, $rightWidgets);
	if ($result < 0) {
		http_response_code(500);
		print 'KO';
	} else {
		print 'OK';
	}
	exit;
}

if ($action === 'addwidget') {
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}
	$widgetCode = GETPOST('widget_code', 'aZ09');
	$states = $widgetManager->getUserWidgetStates($user);
	$leftWidgets = array();
	$rightWidgets = array();
	foreach ($states as $code => $state) {
		if (empty($state['visible'])) {
			continue;
		}
		if ((int) $state['column'] === 0) {
			$leftWidgets[] = $code;
		} else {
			$rightWidgets[] = $code;
		}
	}
	$definitions = $widgetManager->getAllowedWidgetDefinitions($user);
	if (isset($definitions[$widgetCode])) {
		if (count($leftWidgets) <= count($rightWidgets)) {
			$leftWidgets[] = $widgetCode;
		} else {
			$rightWidgets[] = $widgetCode;
		}
		$widgetManager->saveUserWidgetLayout($user, $leftWidgets, $rightWidgets);
	}
	header('Location: '.$_SERVER['PHP_SELF'].($param !== '' ? '?'.preg_replace('/^&/', '', $param) : ''));
	exit;
} elseif ($action !== '') {
	accessforbidden($langs->trans('LmdbSalesCommissionsActionNotAvailableYet'));
}

$states = $widgetManager->getUserWidgetStates($user);
$token = newToken();
$selectboxlist = $widgetManager->renderAddBoxSelector($states, $token);
$columns = $widgetManager->renderWidgetColumns($states, $filters, $user);

llxHeader('', $langs->trans('LmdbSalesCommissionsDashboard'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());

print load_fiche_titre($langs->trans('LmdbSalesCommissionsDashboard'), $selectboxlist, 'fa-percent');

print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" id="lmdbsalescommissions-dashboard-filters">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder liste centpercent">';
print '<tr class="liste_titre"><td colspan="4">'.$langs->trans('Filters').'</td></tr>';
print '<tr class="oddeven">';
print '<td>'.$langs->trans('SalesRepresentative').'</td>';
$canChooseUser = !empty($user->admin) || $user->hasRight('lmdbsalescommissions', 'commission', 'readall') || $user->hasRight('lmdbsalescommissions', 'commission', 'readgroup');
$userOptions = lmdbsalescommissionsGetAccessibleUserOptions($db, $user, $canChooseUser);
print '<td>'.$form->selectarray('fk_user', $userOptions, (int) $filters['fk_user'], $canChooseUser ? 1 : 0, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1).'</td>';
print '<td>'.$langs->trans('Group').'</td>';
print '<td>'.$form->selectarray('fk_usergroup', lmdbsalescommissionsGetAccessibleUserGroupOptions($db, $user, true), (int) $filters['fk_usergroup'], 1, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1).'</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td>'.$langs->trans('Year').'</td>';
print '<td>'.$formother->selectyear((int) $filters['year'], 'year', 1, 5, 2, 0, 0, '', 'minwidth100', true).'</td>';
print '<td>'.$langs->trans('Month').'</td>';
$monthOptions = array(0 => '');
for ($month = 1; $month <= 12; $month++) {
	$monthOptions[$month] = dol_print_date(dol_mktime(0, 0, 0, $month, 1, 2000), '%B');
}
print '<td>'.$form->selectarray('month', $monthOptions, (int) $filters['month'], 1, 0, 0, '', 0, 0, 0, '', 'minwidth100', 1).'</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td>'.$langs->trans('Date').'</td>';
print '<td>'.lmdbsalescommissionsRenderDateRangeFilter($form, (int) $filters['date_start'], (int) $filters['date_end'], 'date_start', 'date_end', 'lmdbsalescommissions-dashboard-filters').'</td>';
print '<td>'.$langs->trans('Source').'</td>';
$sourceOptions = array('all' => $langs->trans('All'), 'proposal' => $langs->trans('Propal'), 'order' => $langs->trans('Order'), 'contract' => $langs->trans('Contract'));
print '<td>'.$form->selectarray('source', $sourceOptions, (string) $filters['source'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth150', 1).'</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td>'.$langs->trans('LmdbSalesCommissionsCommissionType').'</td>';
$typeOptions = array('all' => $langs->trans('All'), 'margin' => $langs->trans('LmdbSalesCommissionsRuleTypeMargin'), 'tier' => $langs->trans('LmdbSalesCommissionsRuleTypeTier'), 'dispatch' => $langs->trans('LmdbSalesCommissionsModeDispatch'));
print '<td>'.$form->selectarray('commission_type', $typeOptions, (string) $filters['commission_type'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth150', 1).'</td>';
print '<td>'.$langs->trans('Status').'</td>';
$statusOptions = array(
	'all' => $langs->trans('All'),
	'estimated' => $langs->trans('LmdbSalesCommissionsLineStatusEstimated'),
	'acquired' => $langs->trans('LmdbSalesCommissionsLineStatusAcquired'),
	'payable' => $langs->trans('LmdbSalesCommissionsDueStatusDue'),
	'paid' => $langs->trans('LmdbSalesCommissionsDueStatusPaid'),
	'cancelled' => $langs->trans('LmdbSalesCommissionsLineStatusCancelled'),
	'blocked' => $langs->trans('LmdbSalesCommissionsLineStatusBlocked'),
);
print '<td>'.$form->selectarray('status', $statusOptions, (string) $filters['status'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth150', 1).'</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td>'.$langs->trans('LmdbSalesCommissionsObjectives').'</td>';
$objectiveStatusOptions = array(
	'all' => $langs->trans('All'),
	'achieved' => $langs->trans('LmdbSalesCommissionsObjectiveStatusAchieved'),
	'not_achieved' => $langs->trans('LmdbSalesCommissionsObjectiveStatusNotAchieved'),
	'no_objective' => $langs->trans('LmdbSalesCommissionsObjectiveStatusNoObjective'),
);
print '<td>'.$form->selectarray('objective_status', $objectiveStatusOptions, (string) $filters['objective_status'], 0, 0, 0, '', 0, 0, 0, '', 'minwidth150', 1).'</td>';
print '<td></td>';
print '<td><input type="submit" class="button small" value="'.$langs->trans('Search').'"> ';
print '<a class="button small" href="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">'.$langs->trans('Reset').'</a></td>';
print '</tr>';
print '</table>';
print '</div>';
print '</form>';

print '<br>';
print '<div class="fichecenter">';
print '<div class="fichehalfleft" id="boxhalfleft">';
print $columns['left'];
print '</div>';
print '<div class="fichehalfright" id="boxhalfright">';
print $columns['right'];
print '</div>';
print '</div>';
print $widgetManager->renderLayoutScript($token);

llxFooter();
$db->close();
