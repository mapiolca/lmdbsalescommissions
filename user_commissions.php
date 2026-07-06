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

require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/usergroups.lib.php';
require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissiondueservice.class.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionobjectiveresolver.class.php', 0);

/**
 * Sum signed amount for objective period.
 *
 * @param DoliDB $db        Database handler
 * @param int    $fkUser    User id
 * @param string $type      monthly or yearly
 * @param int    $year      Year
 * @param int    $month     Month
 * @param int    $entitySql Entity SQL list
 * @return float
 */
function lmdbsalescommissions_user_sum_realized($db, $fkUser, $type, $year, $month, $entitySql)
{
	$dateStart = $type === 'monthly' ? dol_mktime(0, 0, 0, $month, 1, $year) : dol_mktime(0, 0, 0, 1, 1, $year);
	$dateEnd = $type === 'monthly' ? dol_time_plus_duree(dol_time_plus_duree($dateStart, 1, 'm'), -1, 's') : dol_mktime(23, 59, 59, 12, 31, $year);

	$sql = 'SELECT SUM(src.amount_base) AS realized';
	$sql .= ' FROM (';
	$sql .= ' SELECT entity, fk_user, source_type, fk_source, MAX(amount_base) AS amount_base';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
	$sql .= ' WHERE entity IN ('.$entitySql.')';
	$sql .= ' AND fk_user = '.((int) $fkUser);
	$sql .= " AND source_type = 'proposal'";
	$sql .= ' AND status = 1';
	$sql .= " AND date_acquired >= '".$db->idate($dateStart)."'";
	$sql .= " AND date_acquired <= '".$db->idate($dateEnd)."'";
	$sql .= ' GROUP BY entity, fk_user, source_type, fk_source';
	$sql .= ') AS src';

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return 0.0;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);

	return is_object($obj) ? (float) $obj->realized : 0.0;
}

$langs->loadLangs(array('users', 'lmdbsalescommissions@lmdbsalescommissions'));

$id = GETPOSTINT('id');

if (!isModEnabled('lmdbsalescommissions')) {
	accessforbidden();
}
if ($id <= 0 || !lmdbsalescommissionsCanReadUserScope($user, $id)) {
	accessforbidden();
}

$object = new User($db);
if ($object->fetch($id) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}

$entitySql = $db->sanitize(getEntity('lmdbsalescommissions_line'));

$sql = 'SELECT';
$sql .= " SUM(CASE WHEN mode = 'margin' AND status = 0 THEN commission_total ELSE 0 END) AS margin_estimated,";
$sql .= " SUM(CASE WHEN mode = 'margin' AND status = 1 THEN commission_total ELSE 0 END) AS margin_acquired,";
$sql .= " SUM(CASE WHEN mode = 'tier' AND status = 1 THEN commission_total ELSE 0 END) AS tier_acquired,";
$sql .= ' SUM(CASE WHEN status = 1 THEN commission_total ELSE 0 END) AS acquired_total,';
$sql .= ' SUM(payable_total) AS payable_total,';
$sql .= ' SUM(paid_total) AS paid_total';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
$sql .= ' WHERE entity IN ('.$entitySql.')';
$sql .= ' AND fk_user = '.((int) $id);
$resql = $db->query($sql);
$summary = array(
	'margin_estimated' => 0.0,
	'margin_acquired' => 0.0,
	'tier_acquired' => 0.0,
	'acquired_total' => 0.0,
	'payable_total' => 0.0,
	'paid_total' => 0.0,
);
if ($resql && is_object($obj = $db->fetch_object($resql))) {
	foreach ($summary as $key => $value) {
		$summary[$key] = (float) $obj->{$key};
	}
	$db->free($resql);
}

$now = dol_now();
$year = (int) date('Y', $now);
$month = (int) date('n', $now);
$resolver = new LmdbSalesCommissionObjectiveResolver($db);
$monthlyObjective = lmdbsalescommissionsCanReadObjectiveUserScope($user, $id) ? $resolver->resolveForUser($id, 'monthly', $year, $month, $now, (int) $conf->entity) : null;
$yearlyObjective = lmdbsalescommissionsCanReadObjectiveUserScope($user, $id) ? $resolver->resolveForUser($id, 'yearly', $year, 0, $now, (int) $conf->entity) : null;
$monthlyRealized = lmdbsalescommissions_user_sum_realized($db, $id, 'monthly', $year, $month, $entitySql);
$yearlyRealized = lmdbsalescommissions_user_sum_realized($db, $id, 'yearly', $year, 0, $entitySql);

llxHeader('', $langs->trans('LmdbSalesCommissions'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());

$head = user_prepare_head($object);
print dol_get_fiche_head($head, 'lmdbsalescommissions', $langs->trans('User'), -1, 'user');
print load_fiche_titre($object->getFullName($langs), '', 'user');

print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('Login').'</td><td>'.dol_escape_htmltag($object->login).'</td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>'.$object->getLibStatut(5).'</td></tr>';
print '</table>';

print '<br>';
print load_fiche_titre($langs->trans('LmdbSalesCommissionsUserTabSummary'), '', 'fa-percent');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('LmdbSalesCommissionsIndicator').'</td><td class="right">'.$langs->trans('Amount').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsEstimatedCommission').'</td><td class="right">'.price($summary['margin_estimated']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsRuleTypeMargin').'</td><td class="right">'.price($summary['margin_acquired']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsRuleTypeTier').'</td><td class="right">'.price($summary['tier_acquired']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsCommissionTotal').'</td><td class="right">'.price($summary['acquired_total']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsPayableTotal').'</td><td class="right">'.price($summary['payable_total']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsPaidTotal').'</td><td class="right">'.price($summary['paid_total']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsRemainingToPay').'</td><td class="right">'.price(max(0, $summary['payable_total'] - $summary['paid_total'])).'</td></tr>';
print '</table>';

print '<br>';
print load_fiche_titre($langs->trans('LmdbSalesCommissionsUserTabObjectives'), '', 'fa-bullseye');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Type').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsTargetValue').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsRealizedValue').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsAchievementRate').'</td></tr>';
foreach (array(
	$langs->trans('LmdbSalesCommissionsMonthlyObjective') => array($monthlyObjective, $monthlyRealized),
	$langs->trans('LmdbSalesCommissionsYearlyObjective') => array($yearlyObjective, $yearlyRealized),
) as $label => $data) {
	$resolution = $data[0];
	$realized = (float) $data[1];
	$selected = is_array($resolution) ? $resolution['selected'] : null;
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($label).'</td>';
	if (is_array($selected)) {
		$target = (float) $selected['target_value'];
		$rate = $target > 0 ? ($realized / $target) * 100 : 0;
		print '<td class="right">'.price($target).'</td><td class="right">'.price($realized).'</td><td class="right">'.price($rate).'%</td>';
	} else {
		print '<td colspan="3"><span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsNoObjectiveForPeriod').'</span></td>';
	}
	print '</tr>';
}
print '</table>';

print '<br>';
print load_fiche_titre($langs->trans('LmdbSalesCommissionsUserTabMargin'), '', 'fa-percent');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('ThirdParty').'</td><td>'.$langs->trans('Source').'</td><td class="right">'.$langs->trans('Margin').'</td><td class="right">'.$langs->trans('Rate').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsCommissionTotal').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsPayableTotal').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsPaidTotal').'</td></tr>';
$sql = 'SELECT l.date_acquired, l.fk_soc, l.source_type, l.fk_source, l.source_ref, l.margin_base, l.rate, l.commission_total, l.payable_total, l.paid_total, s.nom AS thirdparty_name';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = l.fk_soc';
$sql .= ' WHERE l.entity IN ('.$entitySql.') AND l.fk_user = '.((int) $id)." AND l.mode = 'margin'";
$sql .= ' ORDER BY l.date_acquired DESC, l.rowid DESC';
$sql .= $db->plimit(10, 0);
$resmargin = $db->query($sql);
$marginRows = 0;
if ($resmargin) {
	while (is_object($obj = $db->fetch_object($resmargin))) {
		$marginRows++;
		print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($obj->date_acquired), 'day').'</td><td>'.lmdbsalescommissionsBuildThirdpartyNomUrl($db, (int) $obj->fk_soc, (string) $obj->thirdparty_name).'</td><td>';
		print lmdbsalescommissionsBuildSourceNomUrl($db, (string) $obj->source_type, (int) $obj->fk_source, (string) $obj->source_ref);
		print '</td><td class="right">'.price((float) $obj->margin_base).'</td><td class="right">'.price((float) $obj->rate).'%</td><td class="right">'.price((float) $obj->commission_total).'</td><td class="right">'.price((float) $obj->payable_total).'</td><td class="right">'.price((float) $obj->paid_total).'</td></tr>';
	}
	$db->free($resmargin);
}
if ($marginRows === 0) {
	lmdbsalescommissionsPrintNoRecordRow($langs, 8);
}
print '</table>';

print '<br>';
print load_fiche_titre($langs->trans('LmdbSalesCommissionsUserTabTier'), '', 'fa-layer-group');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Source').'</td><td class="right">'.$langs->trans('AmountHT').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsCommissionTotal').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsPayableTotal').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsPaidTotal').'</td></tr>';
$sql = 'SELECT date_acquired, source_type, fk_source, source_ref, amount_base, commission_total, payable_total, paid_total';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
$sql .= ' WHERE entity IN ('.$entitySql.') AND fk_user = '.((int) $id)." AND mode = 'tier'";
$sql .= ' ORDER BY date_acquired DESC, rowid DESC';
$sql .= $db->plimit(10, 0);
$restier = $db->query($sql);
$tierRows = 0;
if ($restier) {
	while (is_object($obj = $db->fetch_object($restier))) {
		$tierRows++;
		print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($obj->date_acquired), 'day').'</td><td>';
		print lmdbsalescommissionsBuildSourceNomUrl($db, (string) $obj->source_type, (int) $obj->fk_source, (string) $obj->source_ref);
		print '</td><td class="right">'.price((float) $obj->amount_base).'</td><td class="right">'.price((float) $obj->commission_total).'</td><td class="right">'.price((float) $obj->payable_total).'</td><td class="right">'.price((float) $obj->paid_total).'</td></tr>';
	}
	$db->free($restier);
}
if ($tierRows === 0) {
	lmdbsalescommissionsPrintNoRecordRow($langs, 6);
}
print '</table>';

print '<br>';
print load_fiche_titre($langs->trans('LmdbSalesCommissionsUserTabDue'), '', 'fa-calendar-check');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Event').'</td><td>'.$langs->trans('Source').'</td><td class="right">'.$langs->trans('Amount').'</td><td class="center">'.$langs->trans('Status').'</td></tr>';
$sql = 'SELECT d.event_type, d.amount, d.status, l.source_type, l.fk_source, l.source_ref';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l ON l.rowid = d.fk_commission_line AND l.entity = d.entity';
$sql .= ' WHERE d.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_due')).') AND l.fk_user = '.((int) $id);
$sql .= ' AND d.status IN ('.LmdbSalesCommissionDueService::STATUS_WAITING.','.LmdbSalesCommissionDueService::STATUS_DUE.')';
$sql .= ' ORDER BY d.status DESC, d.date_due ASC, d.rowid DESC';
$sql .= $db->plimit(10, 0);
$resdue = $db->query($sql);
$dueRows = 0;
if ($resdue) {
	while (is_object($obj = $db->fetch_object($resdue))) {
		$dueRows++;
		$status = (int) $obj->status;
		print '<tr class="oddeven"><td>'.dol_escape_htmltag(lmdbsalescommissionsGetDueEventLabel($langs, (string) $obj->event_type)).'</td><td>';
		print lmdbsalescommissionsBuildSourceNomUrl($db, (string) $obj->source_type, (int) $obj->fk_source, (string) $obj->source_ref);
		print '</td><td class="right">'.price((float) $obj->amount).'</td><td class="center">'.lmdbsalescommissionsStatusBadge(lmdbsalescommissionsGetDueStatusLabel($langs, $status), $status === LmdbSalesCommissionDueService::STATUS_DUE ? 1 : 0).'</td></tr>';
	}
	$db->free($resdue);
}
if ($dueRows === 0) {
	lmdbsalescommissionsPrintNoRecordRow($langs, 4);
}
print '</table>';

print '<br>';
print load_fiche_titre($langs->trans('LmdbSalesCommissionsUserTabHistory'), '', 'fa-history');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Period').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsTargetValue').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsRealizedValue').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsAchievementRate').'</td><td class="center">'.$langs->trans('Status').'</td></tr>';
$sql = 'SELECT objective_type, year, month, target_value, realized_value, achievement_rate, status';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective_archive';
$sql .= ' WHERE entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_objective_archive')).') AND fk_user = '.((int) $id);
$sql .= ' ORDER BY year DESC, month DESC, rowid DESC';
$sql .= $db->plimit(10, 0);
$reshistory = $db->query($sql);
$historyRows = 0;
if ($reshistory) {
	while (is_object($obj = $db->fetch_object($reshistory))) {
		$historyRows++;
		$period = (string) $obj->year;
		if ((string) $obj->objective_type === 'monthly') {
			$period .= '-'.sprintf('%02d', (int) $obj->month);
		}
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($period).'</td><td class="right">'.price((float) $obj->target_value).'</td><td class="right">'.price((float) $obj->realized_value).'</td><td class="right">'.($obj->achievement_rate !== null ? price((float) $obj->achievement_rate).'%' : '').'</td><td class="center">'.lmdbsalescommissionsStatusBadge(lmdbsalescommissionsGetObjectiveArchiveStatusLabel($langs, (int) $obj->status), (int) $obj->status === 1 ? 1 : 0).'</td></tr>';
	}
	$db->free($reshistory);
}
if ($historyRows === 0) {
	lmdbsalescommissionsPrintNoRecordRow($langs, 5);
}
print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
