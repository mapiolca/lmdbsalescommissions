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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions'));

$action = GETPOST('action', 'aZ09');
$dataset = GETPOST('dataset', 'aZ09');
$fk_user = GETPOSTINT('fk_user');
$fk_usergroup = GETPOSTINT('fk_usergroup');
$year = GETPOSTINT('year');
$month = GETPOSTINT('month');

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
		fputcsv($output, array('date', 'agent', 'client', 'source_type', 'source_ref', 'mode', 'amount_base', 'margin_base', 'rate', 'commission_total', 'payable_total', 'paid_total', 'status'), ';');
		$sql = 'SELECT l.date_acquired, l.source_type, l.source_ref, l.mode, l.amount_base, l.margin_base, l.rate, l.commission_total, l.payable_total, l.paid_total, l.status,';
		$sql .= ' u.lastname, u.firstname, u.login, s.nom AS thirdparty_name';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = l.fk_user';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = l.fk_soc';
		$sql .= ' WHERE l.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_line')).')'.$scope.$userfilter.$groupfilter.$linePeriodFilter;
		$sql .= ' ORDER BY l.date_acquired DESC, l.rowid DESC';
		$resql = $db->query($sql);
		if ($resql) {
			while (is_object($obj = $db->fetch_object($resql))) {
				$agent = trim((string) $obj->firstname.' '.(string) $obj->lastname);
				if ($agent === '') {
					$agent = (string) $obj->login;
				}
				fputcsv($output, array(dol_print_date($db->jdate($obj->date_acquired), 'day'), $agent, (string) $obj->thirdparty_name, lmdbsalescommissionsGetSourceTypeLabel($langs, (string) $obj->source_type), (string) $obj->source_ref, lmdbsalescommissionsGetModeLabel($langs, (string) $obj->mode), price2num($obj->amount_base), price2num($obj->margin_base), price2num($obj->rate), price2num($obj->commission_total), price2num($obj->payable_total), price2num($obj->paid_total), lmdbsalescommissionsGetLineStatusLabel($langs, (int) $obj->status)), ';');
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
		$sql .= ' AND d.status = '.((int) $wantedStatus).$scope.$userfilter.$groupfilter.($dataset === 'paid' ? $paidPeriodFilter : $linePeriodFilter);
		$sql .= ' ORDER BY '.($dataset === 'paid' ? 'd.date_paid DESC' : 'd.date_due ASC').', d.rowid DESC';
		$resql = $db->query($sql);
		if ($resql) {
			while (is_object($obj = $db->fetch_object($resql))) {
				$agent = trim((string) $obj->firstname.' '.(string) $obj->lastname);
				if ($agent === '') {
					$agent = (string) $obj->login;
				}
				fputcsv($output, array(lmdbsalescommissionsGetDueEventLabel($langs, (string) $obj->event_type), $agent, (string) $obj->thirdparty_name, lmdbsalescommissionsGetSourceTypeLabel($langs, (string) $obj->source_type), (string) $obj->source_ref, lmdbsalescommissionsGetModeLabel($langs, (string) $obj->mode), price2num($obj->percentage), price2num($obj->amount), lmdbsalescommissionsGetDueStatusLabel($langs, (int) $obj->status), dol_print_date($db->jdate($obj->date_due), 'day'), dol_print_date($db->jdate($obj->date_paid), 'day')), ';');
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
		$sql .= ' ORDER BY o.year DESC, o.month DESC, o.rowid DESC';
		$resql = $db->query($sql);
		if ($resql) {
			while (is_object($obj = $db->fetch_object($resql))) {
				$agent = trim((string) $obj->firstname.' '.(string) $obj->lastname);
				if ($agent === '') {
					$agent = (string) $obj->login;
				}
				fputcsv($output, array((int) $obj->entity, (string) $obj->assignment_type, $agent, (string) $obj->group_name, (string) $obj->objective_type, (int) $obj->year, $obj->month !== null ? (int) $obj->month : '', (string) $obj->base_type, price2num($obj->target_value), (int) $obj->active, (int) $obj->priority), ';');
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
		$sql .= ' ORDER BY a.year DESC, a.month DESC, a.rowid DESC';
		$resql = $db->query($sql);
		if ($resql) {
			while (is_object($obj = $db->fetch_object($resql))) {
				$agent = trim((string) $obj->firstname.' '.(string) $obj->lastname);
				if ($agent === '') {
					$agent = (string) $obj->login;
				}
				fputcsv($output, array((int) $obj->entity, $agent, (string) $obj->objective_type, (int) $obj->year, $obj->month !== null ? (int) $obj->month : '', price2num($obj->target_value), price2num($obj->realized_value), $obj->achievement_rate !== null ? price2num($obj->achievement_rate) : '', lmdbsalescommissionsGetObjectiveArchiveStatusLabel($langs, (int) $obj->status), (string) $obj->objective_source, dol_print_date($db->jdate($obj->date_archive), 'day')), ';');
			}
			$db->free($resql);
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
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('Filters').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('SalesRepresentative').'</td><td>'.$form->selectarray('fk_user', lmdbsalescommissionsGetUserOptions($db), $fk_user, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Group').'</td><td>'.$form->selectarray('fk_usergroup', lmdbsalescommissionsGetUserGroupOptions($db), $fk_usergroup, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Year').'</td><td><input type="text" class="flat width75 right" name="year" value="'.($year > 0 ? (int) $year : '').'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Month').'</td><td><input type="text" class="flat width75 right" name="month" value="'.($month > 0 ? (int) $month : '').'"></td></tr>';
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

print '<br>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Export').'</td><td>'.$langs->trans('Description').'</td><td class="center">'.$langs->trans('Action').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportLines').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportLinesDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=lines').'">'.$langs->trans('Download').'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportDue').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportDueDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=due').'">'.$langs->trans('Download').'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportPaid').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportPaidDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=paid').'">'.$langs->trans('Download').'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportObjectives').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportObjectivesDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=objectives').'">'.$langs->trans('Download').'</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsExportArchives').'</td><td>'.$langs->trans('LmdbSalesCommissionsExportArchivesDesc').'</td><td class="center"><a class="button small" href="'.dol_escape_htmltag($baseExportUrl.'&dataset=archives').'">'.$langs->trans('Download').'</a></td></tr>';
print '</table>';

llxFooter();
$db->close();
