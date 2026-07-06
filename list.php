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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions'));

$action = GETPOST('action', 'aZ09');
$fk_user = GETPOSTINT('fk_user');
$fk_usergroup = GETPOSTINT('fk_usergroup');
$fk_soc = GETPOSTINT('fk_soc');
$search_source_type = GETPOST('search_source_type', 'aZ09');
$search_source_ref = GETPOST('search_source_ref', 'alpha');
$search_status = GETPOST('search_status', 'array:intcomma');
$search_mode = GETPOST('search_mode', 'aZ09');
$search_rule_source = GETPOST('search_rule_source', 'aZ09');
$search_date_start = lmdbsalescommissionsGetDateFilterValue('search_date_start');
$search_date_end = lmdbsalescommissionsGetDateFilterValue('search_date_end', true);
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$button_search = GETPOST('button_search_x', 'alpha') || GETPOST('button_search.x', 'alpha') || GETPOST('button_search', 'alpha');
$button_removefilter = GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha');
$page = GETPOSTINT('page');
if ($page < 0 || $button_search || $button_removefilter) {
	$page = 0;
}
$limit = GETPOSTINT('limit');
if ($limit <= 0) {
	$limit = getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 20);
}
$offset = $limit * $page;
if (empty($sortfield)) {
	$sortfield = 'l.date_acquired';
}
if (empty($sortorder)) {
	$sortorder = 'DESC';
}

if ($fk_user < 0) {
	$fk_user = 0;
}
if ($fk_usergroup < 0) {
	$fk_usergroup = 0;
}
if ($fk_soc < 0) {
	$fk_soc = 0;
}
if ($search_source_type === '-1') {
	$search_source_type = '';
}
if ($search_mode === '-1') {
	$search_mode = '';
}
if ($search_rule_source === '-1') {
	$search_rule_source = '';
}

if ($button_removefilter) {
	$fk_user = 0;
	$fk_usergroup = 0;
	$fk_soc = 0;
	$search_source_type = '';
	$search_source_ref = '';
	$search_status = array();
	$search_mode = '';
	$search_rule_source = '';
	$search_date_start = '';
	$search_date_end = '';
}

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

if (!is_array($search_status)) {
	$search_status = array();
}
$search_status = array_values(array_unique(array_map('intval', $search_status)));
$allowed_status = array(0, 1, 6, 7);
$search_status = array_values(array_filter($search_status, static function ($status) use ($allowed_status) {
	return in_array((int) $status, $allowed_status, true);
}));

$form = new Form($db);

$source_type_options = array(
	'proposal' => $langs->trans('Propal'),
	'order' => $langs->trans('Order'),
	'contract' => $langs->trans('Contract'),
);
$mode_options = array(
	'margin' => $langs->trans('LmdbSalesCommissionsRuleTypeMargin'),
	'tier' => $langs->trans('LmdbSalesCommissionsRuleTypeTier'),
	'tracking' => $langs->trans('LmdbSalesCommissionsModeTracking'),
);
$rule_source_options = array(
	'user' => $langs->trans('User'),
	'group' => $langs->trans('Group'),
	'default' => $langs->trans('Default'),
	'none' => $langs->trans('None'),
);
$status_options = array(
	'0' => $langs->trans('LmdbSalesCommissionsLineStatusEstimated'),
	'1' => $langs->trans('LmdbSalesCommissionsLineStatusAcquired'),
	'6' => $langs->trans('LmdbSalesCommissionsLineStatusCancelled'),
	'7' => $langs->trans('LmdbSalesCommissionsLineStatusBlocked'),
);

$param = '';
if ($fk_user > 0) {
	$param .= '&fk_user='.((int) $fk_user);
}
if ($fk_usergroup > 0) {
	$param .= '&fk_usergroup='.((int) $fk_usergroup);
}
if ($fk_soc > 0) {
	$param .= '&fk_soc='.((int) $fk_soc);
}
if (!empty($search_date_start)) {
	$param .= lmdbsalescommissionsBuildDateFilterParams('search_date_start');
}
if (!empty($search_date_end)) {
	$param .= lmdbsalescommissionsBuildDateFilterParams('search_date_end');
}
if ($search_source_type !== '') {
	$param .= '&search_source_type='.urlencode($search_source_type);
}
if ($search_source_ref !== '') {
	$param .= '&search_source_ref='.urlencode($search_source_ref);
}
foreach ($search_status as $status) {
	$param .= '&search_status[]='.((int) $status);
}
if ($search_mode !== '') {
	$param .= '&search_mode='.urlencode($search_mode);
}
if ($search_rule_source !== '') {
	$param .= '&search_rule_source='.urlencode($search_rule_source);
}

$sqlselect = 'SELECT l.rowid, l.entity, l.fk_user, l.fk_soc, l.source_type, l.fk_source, l.source_ref, l.mode, l.amount_base, l.margin_base, l.rate, l.commission_total, l.payable_total, l.paid_total, l.status, l.date_acquired, l.rule_source, l.snapshot_rule_label,';
$sqlselect .= ' u.lastname, u.firstname, u.login, u.statut AS user_status, s.nom AS thirdparty_name';
$sqlfrom = ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
$sqlfrom .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = l.fk_user';
$sqlfrom .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = l.fk_soc';
$sqlwhere = ' WHERE l.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_line')).')';
$sqlwhere .= lmdbsalescommissionsBuildCommissionScopeSql($db, $user, 'l');
if ($fk_user > 0) {
	$sqlwhere .= ' AND l.fk_user = '.((int) $fk_user);
}
if ($fk_usergroup > 0) {
	$sqlwhere .= ' AND EXISTS (SELECT ugu.rowid FROM '.MAIN_DB_PREFIX.'usergroup_user AS ugu WHERE ugu.fk_user = l.fk_user AND ugu.fk_usergroup = '.((int) $fk_usergroup).' AND ugu.entity IN ('.$db->sanitize(getEntity('usergroup')).'))';
}
if ($fk_soc > 0) {
	$sqlwhere .= ' AND l.fk_soc = '.((int) $fk_soc);
}
if (!empty($search_date_start)) {
	$sqlwhere .= " AND l.date_acquired >= '".$db->idate($search_date_start)."'";
}
if (!empty($search_date_end)) {
	$sqlwhere .= " AND l.date_acquired <= '".$db->idate($search_date_end)."'";
}
if ($search_source_type !== '') {
	$sqlwhere .= " AND l.source_type = '".$db->escape($search_source_type)."'";
}
if ($search_source_ref !== '') {
	$sqlwhere .= natural_search('l.source_ref', $search_source_ref);
}
if (!empty($search_status)) {
	$sqlwhere .= ' AND l.status IN ('.$db->sanitize(implode(',', array_map('intval', $search_status))).')';
}
if ($search_mode !== '') {
	$sqlwhere .= " AND l.mode = '".$db->escape($search_mode)."'";
}
if ($search_rule_source !== '') {
	$sqlwhere .= " AND l.rule_source = '".$db->escape($search_rule_source)."'";
}

$sqlcount = 'SELECT COUNT(*) AS nb'.$sqlfrom.$sqlwhere;
$rescount = $db->query($sqlcount);
$num = 0;
if ($rescount && is_object($objcount = $db->fetch_object($rescount))) {
	$num = (int) $objcount->nb;
	$db->free($rescount);
}

$sqltotal = 'SELECT SUM(l.commission_total) AS commission_total, SUM(l.payable_total) AS payable_total, SUM(l.paid_total) AS paid_total'.$sqlfrom.$sqlwhere;
$restotal = $db->query($sqltotal);
$sum_commission = 0.0;
$sum_payable = 0.0;
$sum_paid = 0.0;
if ($restotal && is_object($objtotal = $db->fetch_object($restotal))) {
	$sum_commission = (float) $objtotal->commission_total;
	$sum_payable = (float) $objtotal->payable_total;
	$sum_paid = (float) $objtotal->paid_total;
	$db->free($restotal);
}

$sql = $sqlselect.$sqlfrom.$sqlwhere;
$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
if (!$resql) {
	setEventMessages($db->lasterror(), null, 'errors');
}

llxHeader('', $langs->trans('LmdbSalesCommissionsTracking'));

print_barre_liste($langs->trans('LmdbSalesCommissionsTracking'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $num, 'fa-percent', 0, '', '', $limit, 0, 0, 1);

print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print '<input type="hidden" name="limit" value="'.((int) $limit).'">';
print '<table class="tagtable liste centpercent" id="lmdbsalescommissions-tracking-list">';
print '<tr class="liste_titre">';
if (!empty($conf->main_checkbox_left_column)) {
	print '<th class="liste_titre center maxwidthsearch"></th>';
}
print_liste_field_titre('Date', $_SERVER['PHP_SELF'], 'l.date_acquired', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('SalesRepresentative', $_SERVER['PHP_SELF'], 'u.lastname', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('ThirdParty', $_SERVER['PHP_SELF'], 's.nom', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('Source', $_SERVER['PHP_SELF'], 'l.source_ref', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('Mode', $_SERVER['PHP_SELF'], 'l.mode', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('AmountHT', $_SERVER['PHP_SELF'], 'l.amount_base', $param, '', 'class="right"', $sortfield, $sortorder);
print_liste_field_titre('Margin', $_SERVER['PHP_SELF'], 'l.margin_base', $param, '', 'class="right"', $sortfield, $sortorder);
print_liste_field_titre('Rate', $_SERVER['PHP_SELF'], 'l.rate', $param, '', 'class="right"', $sortfield, $sortorder);
print_liste_field_titre('LmdbSalesCommissionsCommissionTotal', $_SERVER['PHP_SELF'], 'l.commission_total', $param, '', 'class="right"', $sortfield, $sortorder);
print_liste_field_titre('LmdbSalesCommissionsPayableTotal', $_SERVER['PHP_SELF'], 'l.payable_total', $param, '', 'class="right"', $sortfield, $sortorder);
print_liste_field_titre('LmdbSalesCommissionsPaidTotal', $_SERVER['PHP_SELF'], 'l.paid_total', $param, '', 'class="right"', $sortfield, $sortorder);
print_liste_field_titre('Status', $_SERVER['PHP_SELF'], 'l.status', $param, '', 'class="center"', $sortfield, $sortorder);
if (empty($conf->main_checkbox_left_column)) {
	print '<th class="liste_titre center maxwidthsearch"></th>';
}
print '</tr>';

print '<tr class="liste_titre_filter">';
if (!empty($conf->main_checkbox_left_column)) {
	print '<td class="liste_titre center maxwidthsearch">';
	print $form->showFilterButtons('left');
	print '</td>';
}
print '<td class="liste_titre center">'.lmdbsalescommissionsRenderDateRangeFilter($form, $search_date_start, $search_date_end, 'search_date_start', 'search_date_end').'</td>';
print '<td>';
print $form->selectarray('fk_user', lmdbsalescommissionsGetUserOptions($db), $fk_user, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200', 1);
print '<br>';
print $form->selectarray('fk_usergroup', lmdbsalescommissionsGetUserGroupOptions($db), $fk_usergroup, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200', 1);
print '</td>';
print '<td><input type="text" class="flat width50" name="fk_soc" value="'.($fk_soc > 0 ? (int) $fk_soc : '').'"></td>';
print '<td>';
print '<input type="text" class="flat maxwidth100" name="search_source_ref" value="'.dol_escape_htmltag($search_source_ref).'">';
print '<br>';
print $form->selectarray('search_source_type', $source_type_options, $search_source_type, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100', 1);
print '</td>';
print '<td>';
print $form->selectarray('search_mode', $mode_options, $search_mode, 1, 0, 0, '', 0, 0, 0, '', 'minwidth125 maxwidth200', 1);
print '<br>';
print $form->selectarray('search_rule_source', $rule_source_options, $search_rule_source, 1, 0, 0, '', 0, 0, 0, '', 'minwidth125 maxwidth200', 1);
print '</td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td class="center">';
print $form->multiselectarray('search_status', $status_options, $search_status, 0, 0, 'search_status width100 onrightofpage', 0, 0, '', '', '', 1);
print '</td>';
if (empty($conf->main_checkbox_left_column)) {
	print '<td class="liste_titre center maxwidthsearch">';
	print $form->showFilterButtons();
	print '</td>';
}
print '</tr>';

if ($resql) {
	$nb = 0;
	while (is_object($obj = $db->fetch_object($resql))) {
		$nb++;
		if ($nb > $limit) {
			break;
		}
		$status = (int) $obj->status;

		print '<tr class="oddeven">';
		if (!empty($conf->main_checkbox_left_column)) {
			print '<td class="center"></td>';
		}
		print '<td>'.dol_print_date($db->jdate($obj->date_acquired), 'day').'</td>';
		print '<td>'.lmdbsalescommissionsBuildUserNomUrl($db, (int) $obj->fk_user, (string) $obj->lastname, (string) $obj->firstname, (string) $obj->login, (int) $obj->user_status).'</td>';
		print '<td>'.lmdbsalescommissionsBuildThirdpartyNomUrl($db, (int) $obj->fk_soc, (string) $obj->thirdparty_name).'</td>';
		print '<td>'.lmdbsalescommissionsBuildSourceNomUrl($db, (string) $obj->source_type, (int) $obj->fk_source, (string) $obj->source_ref).'</td>';
		print '<td>'.dol_escape_htmltag(lmdbsalescommissionsGetModeLabel($langs, (string) $obj->mode)).'</td>';
		print '<td class="right">'.price((float) $obj->amount_base).'</td>';
		print '<td class="right">'.($obj->margin_base !== null ? price((float) $obj->margin_base) : '').'</td>';
		print '<td class="right">'.($obj->rate !== null ? price((float) $obj->rate).'%' : '').'</td>';
		print '<td class="right">'.price((float) $obj->commission_total).'</td>';
		print '<td class="right">'.price((float) $obj->payable_total).'</td>';
		print '<td class="right">'.price((float) $obj->paid_total).'</td>';
		print '<td class="center">'.lmdbsalescommissionsStatusBadge(lmdbsalescommissionsGetLineStatusLabel($langs, $status), $status).'</td>';
		if (empty($conf->main_checkbox_left_column)) {
			print '<td class="center"></td>';
		}
		print '</tr>';
	}
	$db->free($resql);
	if ($nb === 0) {
		lmdbsalescommissionsPrintNoRecordRow($langs, 13);
	} else {
		print '<tr class="liste_total">';
		if (!empty($conf->main_checkbox_left_column)) {
			print '<td></td>';
		}
		print '<td colspan="8">'.$langs->trans('Total').'</td><td class="right">'.price($sum_commission).'</td><td class="right">'.price($sum_payable).'</td><td class="right">'.price($sum_paid).'</td><td></td>';
		if (empty($conf->main_checkbox_left_column)) {
			print '<td></td>';
		}
		print '</tr>';
	}
} else {
	lmdbsalescommissionsPrintNoRecordRow($langs, 13);
}
print '</table>';
print '</form>';

llxFooter();
$db->close();
