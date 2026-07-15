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
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissiondueservice.class.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/LmdbSalesCommissionDashboardService.class.php', 0);
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions'));

$action = GETPOST('action', 'aZ09');
$dataset = GETPOST('dataset', 'aZ09');
$fk_user = GETPOSTINT('fk_user');
$fk_usergroup = GETPOSTINT('fk_usergroup');
$year = GETPOSTINT('year');
$month = GETPOSTINT('month');
$source = GETPOST('source', 'aZ09');
$commission_type = GETPOST('commission_type', 'aZ09');
$status = GETPOST('status', 'aZ09');
$objective_type = GETPOST('objective_type', 'aZ09');

if (!isModEnabled('lmdbsalescommissions')) {
	accessforbidden();
}

if (!lmdbsalescommissionsCanExport($user)) {
	accessforbidden();
}

if (!lmdbsalescommissionsCanExportUserScope($user, $fk_user)) {
	accessforbidden();
}

if ($action === 'export') {
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}

	$scope = lmdbsalescommissionsBuildExportScopeSql($user, 'l');
	$userfilter = $fk_user > 0 ? ' AND l.fk_user = '.((int) $fk_user) : '';
	$groupfilter = '';
	if ($fk_usergroup > 0) {
		$groupfilter = ' AND EXISTS (SELECT ugu.rowid FROM '.MAIN_DB_PREFIX.'usergroup_user AS ugu WHERE ugu.fk_user = l.fk_user AND ugu.fk_usergroup = '.((int) $fk_usergroup).' AND ugu.entity IN ('.$db->sanitize(getEntity('usergroup')).'))';
	}
	$linePeriodFilter = '';
	if ($year > 0) {
		$linePeriodFilter .= ' AND YEAR(l.date_acquired) = '.((int) $year);
	}
	if ($month > 0) {
		$linePeriodFilter .= ' AND MONTH(l.date_acquired) = '.((int) $month);
	}
	$lineExtraFilter = '';
	if (in_array($source, array('proposal', 'order', 'contract'), true)) {
		$lineExtraFilter .= " AND l.source_type = '".$db->escape($source)."'";
	}
	if (in_array($commission_type, array('margin', 'tier', 'dispatch'), true)) {
		$lineExtraFilter .= " AND l.mode = '".$db->escape($commission_type)."'";
	}
	$statusMap = array(
		'estimated' => 0,
		'acquired' => 1,
		'cancelled' => 6,
		'blocked' => 7,
	);
	if (isset($statusMap[$status])) {
		$lineExtraFilter .= ' AND l.status = '.((int) $statusMap[$status]);
	} elseif ($status === 'payable') {
		$lineExtraFilter .= ' AND l.payable_total > l.paid_total';
	} elseif ($status === 'paid') {
		$lineExtraFilter .= ' AND l.paid_total > 0';
	}
	$paidPeriodFilter = '';
	if ($year > 0) {
		$paidPeriodFilter .= ' AND YEAR(d.date_paid) = '.((int) $year);
	}
	if ($month > 0) {
		$paidPeriodFilter .= ' AND MONTH(d.date_paid) = '.((int) $month);
	}

	$filename = 'lmdbsalescommissions-'.$dataset.'-'.dol_print_date(dol_now(), '%Y%m%d%H%M%S').'.csv';
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	print "\xEF\xBB\xBF";
	$output = fopen('php://output', 'w');
	if ($output === false) {
		exit;
	}

	if ($dataset === 'lines') {
		fputcsv($output, array('date', 'agent', 'client', 'source_type', 'source_ref', 'mode', 'dispatch_base_type', 'dispatch_value_type', 'dispatch_value', 'amount_base', 'margin_base', 'rate', 'commission_total', 'payable_total', 'paid_total', 'status'), ';');
		$sql = 'SELECT l.date_acquired, l.source_type, l.source_ref, l.mode, l.snapshot_base_type, l.snapshot_value_type, l.snapshot_value, l.amount_base, l.margin_base, l.rate, l.commission_total, l.payable_total, l.paid_total, l.status,';
		$sql .= ' u.lastname, u.firstname, u.login, s.nom AS thirdparty_name';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = l.fk_user';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = l.fk_soc';
		$sql .= ' WHERE l.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_line')).')'.$scope.$userfilter.$groupfilter.$linePeriodFilter.$lineExtraFilter;
		$sql .= ' ORDER BY l.date_acquired DESC, l.rowid DESC';
		$resql = $db->query($sql);
		if ($resql) {
			while (is_object($obj = $db->fetch_object($resql))) {
				$agent = trim((string) $obj->firstname.' '.(string) $obj->lastname);
				if ($agent === '') {
					$agent = (string) $obj->login;
				}
				fputcsv($output, array(dol_print_date($db->jdate($obj->date_acquired), 'day'), $agent, (string) $obj->thirdparty_name, lmdbsalescommissionsGetSourceTypeLabel($langs, (string) $obj->source_type), (string) $obj->source_ref, lmdbsalescommissionsGetModeLabel($langs, (string) $obj->mode), (string) $obj->snapshot_base_type, (string) $obj->snapshot_value_type, $obj->snapshot_value !== null ? (float) $obj->snapshot_value : '', price2num($obj->amount_base, 'MT'), price2num($obj->margin_base, 'MT'), price2num($obj->rate, 'MT'), price2num($obj->commission_total, 'MT'), price2num($obj->payable_total, 'MT'), price2num($obj->paid_total, 'MT'), lmdbsalescommissionsGetLineStatusLabel($langs, (int) $obj->status)), ';');
			}
			$db->free($resql);
		}
	} elseif ($dataset === 'due' || $dataset === 'paid') {
		fputcsv($output, array('event', 'agent', 'client', 'source_type', 'source_ref', 'mode', 'percentage', 'amount', 'status', 'date_due', 'date_paid'), ';');
		$wantedStatus = $dataset === 'paid' ? LmdbSalesCommissionDueService::STATUS_PAID : LmdbSalesCommissionDueService::STATUS_DUE;
		$sql = 'SELECT d.event_type, d.percentage, d.amount, d.status, d.date_due, d.date_paid, l.source_type, l.source_ref, l.mode,';
		$sql .= ' u.lastname, u.firstname, u.login, s.nom AS thirdparty_name';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l ON l.rowid = d.fk_commission_line AND l.entity = d.entity';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = l.fk_user';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = l.fk_soc';
		$sql .= ' WHERE d.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_due')).')';
		$sql .= ' AND d.status = '.((int) $wantedStatus).$scope.$userfilter.$groupfilter.($dataset === 'paid' ? $paidPeriodFilter : $linePeriodFilter).$lineExtraFilter;
		$sql .= ' ORDER BY '.($dataset === 'paid' ? 'd.date_paid DESC' : 'd.date_due ASC').', d.rowid DESC';
		$resql = $db->query($sql);
		if ($resql) {
			while (is_object($obj = $db->fetch_object($resql))) {
				$agent = trim((string) $obj->firstname.' '.(string) $obj->lastname);
				if ($agent === '') {
					$agent = (string) $obj->login;
				}
				fputcsv($output, array(lmdbsalescommissionsGetDueEventLabel($langs, (string) $obj->event_type), $agent, (string) $obj->thirdparty_name, lmdbsalescommissionsGetSourceTypeLabel($langs, (string) $obj->source_type), (string) $obj->source_ref, lmdbsalescommissionsGetModeLabel($langs, (string) $obj->mode), price2num($obj->percentage, 'MT'), price2num($obj->amount, 'MT'), lmdbsalescommissionsGetDueStatusLabel($langs, (int) $obj->status), dol_print_date($db->jdate($obj->date_due), 'day'), dol_print_date($db->jdate($obj->date_paid), 'day')), ';');
			}
			$db->free($resql);
		}
	} elseif ($dataset === 'objectives') {
		fputcsv($output, array('entity', 'assignment_type', 'user', 'group', 'objective_type', 'year', 'month', 'base_type', 'target_value', 'active', 'priority'), ';');
		$sql = 'SELECT o.entity, o.assignment_type, o.objective_type, o.year, o.month, o.base_type, o.target_value, o.active, o.priority, u.lastname, u.firstname, u.login, g.nom AS group_name';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective AS o';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = o.fk_user';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'usergroup AS g ON g.rowid = o.fk_usergroup';
		$sql .= ' WHERE o.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_objective')).')';
		if (empty($user->admin) && !$user->hasRight('lmdbsalescommissions', 'export', 'all')) {
			$sql .= " AND o.assignment_type = 'user' AND o.fk_user = ".((int) $user->id);
		}
		if ($fk_user > 0) {
			$sql .= ' AND o.fk_user = '.((int) $fk_user);
		}
		if ($fk_usergroup > 0) {
			$sql .= ' AND o.fk_usergroup = '.((int) $fk_usergroup);
		}
		if ($year > 0) {
			$sql .= ' AND o.year = '.((int) $year);
		}
		if ($month > 0) {
			$sql .= ' AND o.month = '.((int) $month);
		}
		if (in_array($objective_type, array('monthly', 'yearly'), true)) {
			$sql .= " AND o.objective_type = '".$db->escape($objective_type)."'";
		}
		$sql .= ' ORDER BY o.year DESC, o.month DESC, o.rowid DESC';
		$resql = $db->query($sql);
		if ($resql) {
			while (is_object($obj = $db->fetch_object($resql))) {
				$agent = trim((string) $obj->firstname.' '.(string) $obj->lastname);
				if ($agent === '') {
					$agent = (string) $obj->login;
				}
				fputcsv($output, array((int) $obj->entity, (string) $obj->assignment_type, $agent, (string) $obj->group_name, (string) $obj->objective_type, (int) $obj->year, $obj->month !== null ? (int) $obj->month : '', (string) $obj->base_type, price2num($obj->target_value, 'MT'), (int) $obj->active, (int) $obj->priority), ';');
			}
			$db->free($resql);
		}
	} elseif ($dataset === 'archives') {
		fputcsv($output, array('entity', 'user', 'objective_type', 'year', 'month', 'target_value', 'realized_value', 'achievement_rate', 'status', 'objective_source', 'date_archive'), ';');
		$sql = 'SELECT a.entity, a.objective_type, a.year, a.month, a.target_value, a.realized_value, a.achievement_rate, a.status, a.objective_source, a.date_archive, u.lastname, u.firstname, u.login';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective_archive AS a';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = a.fk_user';
		$sql .= ' WHERE a.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_objective_archive')).')';
		if (empty($user->admin) && !$user->hasRight('lmdbsalescommissions', 'export', 'all')) {
			$sql .= ' AND a.fk_user = '.((int) $user->id);
		}
		if ($fk_user > 0) {
			$sql .= ' AND a.fk_user = '.((int) $fk_user);
		}
		if ($year > 0) {
			$sql .= ' AND a.year = '.((int) $year);
		}
		if ($month > 0) {
			$sql .= ' AND a.month = '.((int) $month);
		}
		if (in_array($objective_type, array('monthly', 'yearly'), true)) {
			$sql .= " AND a.objective_type = '".$db->escape($objective_type)."'";
		}
		$sql .= ' ORDER BY a.year DESC, a.month DESC, a.rowid DESC';
		$resql = $db->query($sql);
		if ($resql) {
			while (is_object($obj = $db->fetch_object($resql))) {
				$agent = trim((string) $obj->firstname.' '.(string) $obj->lastname);
				if ($agent === '') {
					$agent = (string) $obj->login;
				}
				fputcsv($output, array((int) $obj->entity, $agent, (string) $obj->objective_type, (int) $obj->year, $obj->month !== null ? (int) $obj->month : '', price2num($obj->target_value, 'MT'), price2num($obj->realized_value, 'MT'), $obj->achievement_rate !== null ? price2num($obj->achievement_rate, 'MT') : '', lmdbsalescommissionsGetObjectiveArchiveStatusLabel($langs, (int) $obj->status), (string) $obj->objective_source, dol_print_date($db->jdate($obj->date_archive), 'day')), ';');
			}
			$db->free($resql);
		}
	} elseif (in_array($dataset, array('agents_near_tier', 'late_objectives', 'top_deals', 'due_aging', 'anomalies'), true)) {
		$dashboardService = new LmdbSalesCommissionDashboardService($db);
		$dashboardService->setScopeMode('export');
		$filters = $dashboardService->normalizeFilters(array(
			'fk_user' => $fk_user,
			'fk_usergroup' => $fk_usergroup,
			'year' => $year,
			'month' => $month,
			'date_start' => 0,
			'date_end' => 0,
			'source' => $source ?: 'all',
			'commission_type' => $commission_type ?: 'all',
			'status' => $status ?: 'all',
			'objective_status' => 'all',
		), $user);
		if ($dataset === 'agents_near_tier') {
			fputcsv($output, array('agent', 'turnover', 'reached_tier', 'next_tier', 'remaining', 'potential_bonus', 'progress_rate'), ';');
			foreach ($dashboardService->getAgentsNearTier($filters, $user, 1000) as $row) {
				$agent = trim((string) $row['firstname'].' '.(string) $row['lastname']);
				if ($agent === '') {
					$agent = (string) $row['login'];
				}
				fputcsv($output, array($agent, price2num($row['turnover'], 'MT'), price2num($row['reached_threshold'], 'MT'), price2num($row['next_threshold'], 'MT'), price2num($row['remaining'], 'MT'), price2num($row['potential_bonus'], 'MT'), price2num($row['rate'], 'MT')), ';');
			}
		} elseif ($dataset === 'late_objectives') {
			fputcsv($output, array('agent', 'objective_type', 'period', 'objective', 'realized', 'achievement_rate', 'gap'), ';');
			foreach ($dashboardService->getLateObjectives($filters, $user, 1000) as $row) {
				$agent = trim((string) $row['firstname'].' '.(string) $row['lastname']);
				if ($agent === '') {
					$agent = (string) $row['login'];
				}
				fputcsv($output, array($agent, (string) $row['objective_type'], (string) $row['period'], price2num($row['objective'], 'MT'), price2num($row['realized'], 'MT'), price2num($row['rate'], 'MT'), price2num($row['gap'], 'MT')), ';');
			}
		} elseif ($dataset === 'top_deals') {
			fputcsv($output, array('agent', 'client', 'source_type', 'source_ref', 'date_signature', 'turnover', 'margin', 'margin_rate', 'margin_commission', 'tier_bonus', 'commission_total', 'status'), ';');
			foreach ($dashboardService->getTopCommissionedDeals($filters, $user, 1000) as $row) {
				$agent = trim((string) $row['firstname'].' '.(string) $row['lastname']);
				if ($agent === '') {
					$agent = (string) $row['login'];
				}
				$amount = (float) $row['amount_base'];
				$margin = (float) $row['margin_base'];
				fputcsv($output, array($agent, (string) $row['thirdparty_name'], lmdbsalescommissionsGetSourceTypeLabel($langs, (string) $row['source_type']), (string) $row['source_ref'], dol_print_date($db->jdate($row['date_acquired']), 'day'), price2num($amount, 'MT'), price2num($margin, 'MT'), $amount > 0 ? price2num(($margin / $amount) * 100, 'MT') : '', price2num($row['margin_commission'], 'MT'), price2num($row['tier_commission'], 'MT'), price2num($row['commission_total'], 'MT'), lmdbsalescommissionsGetLineStatusLabel($langs, (int) $row['status'])), ';');
			}
		} elseif ($dataset === 'due_aging') {
			fputcsv($output, array('bucket', 'count', 'amount'), ';');
			foreach ($dashboardService->getDueCommissionsAging($filters, $user) as $row) {
				fputcsv($output, array($langs->trans((string) $row['label']), (int) $row['count'], price2num($row['amount'], 'MT')), ';');
			}
		} elseif ($dataset === 'anomalies') {
			fputcsv($output, array('severity', 'type', 'element', 'description', 'action'), ';');
			foreach ($dashboardService->getAnomalies($filters, $user, 1000) as $row) {
				fputcsv($output, array((string) $row['severity'], $langs->trans((string) $row['type']), (string) $row['element'], $langs->trans((string) $row['description']), $langs->trans((string) $row['action'])), ';');
			}
		}
	} else {
		fputcsv($output, array('error'), ';');
		fputcsv($output, array($langs->trans('ErrorBadParameters')), ';');
	}
	fclose($output);
	exit;
} elseif ($action !== '') {
	accessforbidden($langs->trans('LmdbSalesCommissionsActionNotAvailableYet'));
}

$form = new Form($db);

llxHeader('', $langs->trans('LmdbSalesCommissionsExports'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());

print load_fiche_titre($langs->trans('LmdbSalesCommissionsExports'), '', 'fa-percent');

print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<table class="noborder liste centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('Filters').'</td></tr>';
$canChooseUser = !empty($user->admin) || $user->hasRight('lmdbsalescommissions', 'export', 'all') || $user->hasRight('lmdbsalescommissions', 'commission', 'readgroup');
$exportUserOptions = (!empty($user->admin) || $user->hasRight('lmdbsalescommissions', 'export', 'all')) ? lmdbsalescommissionsGetUserOptions($db, true) : lmdbsalescommissionsGetAccessibleUserOptions($db, $user, $canChooseUser);
print '<tr class="oddeven"><td>'.$langs->trans('SalesRepresentative').'</td><td>'.$form->selectarray('fk_user', $exportUserOptions, $fk_user, $canChooseUser ? 1 : 0, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Group').'</td><td>'.$form->selectarray('fk_usergroup', lmdbsalescommissionsGetAccessibleUserGroupOptions($db, $user, true), $fk_usergroup, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Year').'</td><td><input type="text" class="flat width75 right" name="year" value="'.($year > 0 ? (int) $year : '').'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Month').'</td><td><input type="text" class="flat width75 right" name="month" value="'.($month > 0 ? (int) $month : '').'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Source').'</td><td>'.$form->selectarray('source', array('all' => $langs->trans('All'), 'proposal' => $langs->trans('Propal'), 'order' => $langs->trans('Order'), 'contract' => $langs->trans('Contract')), $source ?: 'all', 0, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsCommissionType').'</td><td>'.$form->selectarray('commission_type', array('all' => $langs->trans('All'), 'margin' => $langs->trans('LmdbSalesCommissionsRuleTypeMargin'), 'tier' => $langs->trans('LmdbSalesCommissionsRuleTypeTier'), 'dispatch' => $langs->trans('LmdbSalesCommissionsModeDispatch')), $commission_type ?: 'all', 0, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Status').'</td><td>'.$form->selectarray('status', array('all' => $langs->trans('All'), 'estimated' => $langs->trans('LmdbSalesCommissionsLineStatusEstimated'), 'acquired' => $langs->trans('LmdbSalesCommissionsLineStatusAcquired'), 'payable' => $langs->trans('LmdbSalesCommissionsDueStatusDue'), 'paid' => $langs->trans('LmdbSalesCommissionsDueStatusPaid'), 'cancelled' => $langs->trans('LmdbSalesCommissionsLineStatusCancelled'), 'blocked' => $langs->trans('LmdbSalesCommissionsLineStatusBlocked')), $status ?: 'all', 0, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsObjectiveType').'</td><td>'.$form->selectarray('objective_type', array('all' => $langs->trans('All'), 'monthly' => $langs->trans('LmdbSalesCommissionsMonthlyObjective'), 'yearly' => $langs->trans('LmdbSalesCommissionsYearlyObjective')), $objective_type ?: 'all', 0, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1).'</td></tr>';
print '<tr class="oddeven"><td></td><td><button type="submit" class="button small">'.$langs->trans('Apply').'</button></td></tr>';
print '</table>';
print '</form>';

$baseExportUrl = $_SERVER['PHP_SELF'].'?action=export&token='.newToken();
if ($fk_user > 0) {
	$baseExportUrl .= '&fk_user='.((int) $fk_user);
}
if ($fk_usergroup > 0) {
	$baseExportUrl .= '&fk_usergroup='.((int) $fk_usergroup);
}
if ($year > 0) {
	$baseExportUrl .= '&year='.((int) $year);
}
if ($month > 0) {
	$baseExportUrl .= '&month='.((int) $month);
}
if ($source !== '' && $source !== 'all') {
	$baseExportUrl .= '&source='.urlencode($source);
}
if ($commission_type !== '' && $commission_type !== 'all') {
	$baseExportUrl .= '&commission_type='.urlencode($commission_type);
}
if ($status !== '' && $status !== 'all') {
	$baseExportUrl .= '&status='.urlencode($status);
}
if ($objective_type !== '' && $objective_type !== 'all') {
	$baseExportUrl .= '&objective_type='.urlencode($objective_type);
}

print '<br>';
print '<table class="noborder liste centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Export').'</td><td>'.$langs->trans('Description').'</td><td class="center">'.$langs->trans('Action').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportLines').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportLinesDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=lines').'">'.$langs->trans('Download').'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportDue').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportDueDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=due').'">'.$langs->trans('Download').'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportPaid').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportPaidDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=paid').'">'.$langs->trans('Download').'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportObjectives').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportObjectivesDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=objectives').'">'.$langs->trans('Download').'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportArchives').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportArchivesDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=archives').'">'.$langs->trans('Download').'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportAgentsNearTier').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportAgentsNearTierDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=agents_near_tier').'">'.$langs->trans('Download').'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportLateObjectives').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportLateObjectivesDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=late_objectives').'">'.$langs->trans('Download').'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportTopDeals').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportTopDealsDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=top_deals').'">'.$langs->trans('Download').'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportDueAging').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportDueAgingDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=due_aging').'">'.$langs->trans('Download').'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportAnomalies').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportAnomaliesDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=anomalies').'">'.$langs->trans('Download').'</a></td></tr>';
print '</table>';

llxFooter();
$db->close();
