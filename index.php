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
$fk_user = GETPOSTINT('fk_user');
$fk_usergroup = GETPOSTINT('fk_usergroup');

if (!isModEnabled('lmdbsalescommissions')) {
	accessforbidden();
}

if (!lmdbsalescommissionsCanReadCommissions($user)) {
	accessforbidden();
}

if (!lmdbsalescommissionsCanReadUserScope($user, $fk_user)) {
	accessforbidden();
}

if ($action !== '') {
	accessforbidden($langs->trans('LmdbSalesCommissionsActionNotAvailableYet'));
}

$form = new Form($db);

$param = '';
if ($fk_user > 0) {
	$param .= '&fk_user='.((int) $fk_user);
}
if ($fk_usergroup > 0) {
	$param .= '&fk_usergroup='.((int) $fk_usergroup);
}

$scope = lmdbsalescommissionsBuildCommissionScopeSql($db, $user, 'l');
$groupfilter = '';
if ($fk_usergroup > 0) {
	$groupfilter = ' AND EXISTS (SELECT ugu.rowid FROM '.MAIN_DB_PREFIX.'usergroup_user AS ugu WHERE ugu.fk_user = l.fk_user AND ugu.fk_usergroup = '.((int) $fk_usergroup).' AND ugu.entity IN ('.$db->sanitize(getEntity('usergroup')).'))';
}
$userfilter = $fk_user > 0 ? ' AND l.fk_user = '.((int) $fk_user) : '';

$sql = 'SELECT';
$sql .= " SUM(CASE WHEN l.mode = 'margin' THEN l.commission_total ELSE 0 END) AS margin_total,";
$sql .= " SUM(CASE WHEN l.mode = 'tier' THEN l.commission_total ELSE 0 END) AS tier_total,";
$sql .= ' SUM(l.commission_total) AS commission_total,';
$sql .= ' SUM(l.payable_total) AS payable_total,';
$sql .= ' SUM(l.paid_total) AS paid_total,';
$sql .= ' SUM(l.amount_base) AS amount_base_total,';
$sql .= ' SUM(l.margin_base) AS margin_base_total';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
$sql .= ' WHERE l.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_line')).')';
$sql .= $scope.$userfilter.$groupfilter;
$resql = $db->query($sql);
$summary = array(
	'margin_total' => 0.0,
	'tier_total' => 0.0,
	'commission_total' => 0.0,
	'payable_total' => 0.0,
	'paid_total' => 0.0,
	'amount_base_total' => 0.0,
	'margin_base_total' => 0.0,
);
if ($resql && is_object($obj = $db->fetch_object($resql))) {
	foreach ($summary as $key => $value) {
		$summary[$key] = (float) $obj->{$key};
	}
	$db->free($resql);
}

$sql = 'SELECT SUM(d.amount) AS amount_due';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l ON l.rowid = d.fk_commission_line AND l.entity = d.entity';
$sql .= ' WHERE d.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_due')).')';
$sql .= ' AND d.status = '.LmdbSalesCommissionDueService::STATUS_DUE;
$sql .= $scope.$userfilter.$groupfilter;
$resdue = $db->query($sql);
$amount_due = 0.0;
if ($resdue && is_object($objdue = $db->fetch_object($resdue))) {
	$amount_due = (float) $objdue->amount_due;
	$db->free($resdue);
}

llxHeader('', $langs->trans('LmdbSalesCommissionsDashboard'));

print load_fiche_titre($langs->trans('LmdbSalesCommissionsDashboard'), '', 'fa-percent');

print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4">'.$langs->trans('Filters').'</td></tr>';
print '<tr class="oddeven">';
print '<td>'.$langs->trans('SalesRepresentative').'</td><td>'.$form->selectarray('fk_user', lmdbsalescommissionsGetUserOptions($db), $fk_user, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1).'</td>';
print '<td>'.$langs->trans('Group').'</td><td>'.$form->selectarray('fk_usergroup', lmdbsalescommissionsGetUserGroupOptions($db), $fk_usergroup, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200', 1).' <button type="submit" class="button small">'.$langs->trans('Search').'</button></td>';
print '</tr>';
print '</table>';
print '</div>';
print '</form>';

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Indicator').'</td><td class="right">'.$langs->trans('Amount').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsRuleTypeMargin').'</td><td class="right">'.price($summary['margin_total']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsRuleTypeTier').'</td><td class="right">'.price($summary['tier_total']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsCommissionTotal').'</td><td class="right">'.price($summary['commission_total']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsDue').'</td><td class="right">'.price($amount_due).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsPaidTotal').'</td><td class="right">'.price($summary['paid_total']).'</td></tr>';
print '</table>';
print '</div>';
print '<div class="fichehalfright">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Indicator').'</td><td class="right">'.$langs->trans('Amount').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('AmountHT').'</td><td class="right">'.price($summary['amount_base_total']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Margin').'</td><td class="right">'.price($summary['margin_base_total']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsPayableTotal').'</td><td class="right">'.price($summary['payable_total']).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsRemainingToPay').'</td><td class="right">'.price(max(0, $summary['payable_total'] - $summary['paid_total'])).'</td></tr>';
print '</table>';
print '</div>';
print '</div>';

llxFooter();
$db->close();
