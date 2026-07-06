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
$search_status = GETPOST('search_status', 'alpha');
$search_mode = GETPOST('search_mode', 'aZ09');
$search_rule_source = GETPOST('search_rule_source', 'aZ09');
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTINT('page');
if ($page < 0) {
	$page = 0;
}
$limit = getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 20);
$offset = $limit * $page;
if (empty($sortfield)) {
	$sortfield = 'l.date_acquired';
}
if (empty($sortorder)) {
	$sortorder = 'DESC';
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

$form = new Form($db);

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
if ($search_source_type !== '') {
	$param .= '&search_source_type='.urlencode($search_source_type);
}
if ($search_source_ref !== '') {
	$param .= '&search_source_ref='.urlencode($search_source_ref);
}
if ($search_status !== '') {
	$param .= '&search_status='.urlencode($search_status);
}
if ($search_mode !== '') {
	$param .= '&search_mode='.urlencode($search_mode);
}
if ($search_rule_source !== '') {
	$param .= '&search_rule_source='.urlencode($search_rule_source);
}

$sqlselect = 'SELECT l.rowid, l.entity, l.fk_user, l.fk_soc, l.source_type, l.fk_source, l.source_ref, l.mode, l.amount_base, l.margin_base, l.rate, l.commission_total, l.payable_total, l.paid_total, l.status, l.date_acquired, l.rule_source, l.snapshot_rule_label,';
$sqlselect .= ' u.lastname, u.firstname, u.login, s.nom AS thirdparty_name';
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
if ($search_source_type !== '') {
	$sqlwhere .= " AND l.source_type = '".$db->escape($search_source_type)."'";
}
if ($search_source_ref !== '') {
	$sqlwhere .= natural_search('l.source_ref', $search_source_ref);
}
if ($search_status !== '') {
	$sqlwhere .= ' AND l.status = '.((int) $search_status);
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

print_barre_liste($langs->trans('LmdbSalesCommissionsTracking'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $num, 'fa-percent', 0, '', '', $limit);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
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
print '</tr>';

print '<tr class="liste_titre_filter"><td colspan="12">';
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print $langs->trans('SalesRepresentative').' '.$form->selectarray('fk_user', lmdbsalescommissionsGetUserOptions($db), $fk_user, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150', 1).' ';
print $langs->trans('Group').' '.$form->selectarray('fk_usergroup', lmdbsalescommissionsGetUserGroupOptions($db), $fk_usergroup, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150', 1).' ';
print $langs->trans('ThirdParty').' <input type="text" class="flat width50" name="fk_soc" value="'.($fk_soc > 0 ? (int) $fk_soc : '').'"> ';
print $langs->trans('Source').' <input type="text" class="flat maxwidth100" name="search_source_ref" value="'.dol_escape_htmltag($search_source_ref).'"> ';
print $langs->trans('Type').' '.$form->selectarray('search_source_type', array('proposal' => $langs->trans('Propal'), 'order' => $langs->trans('Order'), 'contract' => $langs->trans('Contract')), $search_source_type, 1, 0, 0, '', 0, 0, 0, '', 'minwidth100', 1).' ';
print $langs->trans('Mode').' '.$form->selectarray('search_mode', array('margin' => $langs->trans('LmdbSalesCommissionsRuleTypeMargin'), 'tier' => $langs->trans('LmdbSalesCommissionsRuleTypeTier'), 'tracking' => $langs->trans('LmdbSalesCommissionsModeTracking')), $search_mode, 1, 0, 0, '', 0, 0, 0, '', 'minwidth100', 1).' ';
print $langs->trans('LmdbSalesCommissionsRuleSource').' '.$form->selectarray('search_rule_source', array('user' => $langs->trans('User'), 'group' => $langs->trans('Group'), 'default' => $langs->trans('Default'), 'none' => $langs->trans('None')), $search_rule_source, 1, 0, 0, '', 0, 0, 0, '', 'minwidth100', 1).' ';
print $langs->trans('Status').' '.$form->selectarray('search_status', array('0' => $langs->trans('LmdbSalesCommissionsLineStatusEstimated'), '1' => $langs->trans('LmdbSalesCommissionsLineStatusAcquired'), '6' => $langs->trans('LmdbSalesCommissionsLineStatusCancelled'), '7' => $langs->trans('LmdbSalesCommissionsLineStatusBlocked')), $search_status, 1, 0, 0, '', 0, 0, 0, '', 'minwidth100', 1).' ';
print '<button type="submit" class="button small">'.$langs->trans('Search').'</button>';
print '</form>';
print '</td></tr>';

if ($resql) {
	$nb = 0;
	while (is_object($obj = $db->fetch_object($resql))) {
		$nb++;
		if ($nb > $limit) {
			break;
		}
		$agent = trim((string) $obj->firstname.' '.(string) $obj->lastname);
		if ($agent === '') {
			$agent = (string) $obj->login;
		}
		$sourceUrl = lmdbsalescommissionsBuildSourceUrl((string) $obj->source_type, (int) $obj->fk_source);
		$status = (int) $obj->status;
		$statusType = $status === 1 ? 1 : ($status === 0 ? 0 : -1);

		print '<tr class="oddeven">';
		print '<td>'.dol_print_date($db->jdate($obj->date_acquired), 'day').'</td>';
		print '<td>'.dol_escape_htmltag($agent).'</td>';
		print '<td>'.dol_escape_htmltag((string) $obj->thirdparty_name).'</td>';
		print '<td>';
		if ($sourceUrl !== '') {
			print '<a href="'.dol_escape_htmltag($sourceUrl).'">'.dol_escape_htmltag((string) $obj->source_ref).'</a>';
		} else {
			print dol_escape_htmltag((string) $obj->source_ref);
		}
		print '</td>';
		print '<td>'.dol_escape_htmltag(lmdbsalescommissionsGetModeLabel($langs, (string) $obj->mode)).'</td>';
		print '<td class="right">'.price((float) $obj->amount_base).'</td>';
		print '<td class="right">'.($obj->margin_base !== null ? price((float) $obj->margin_base) : '').'</td>';
		print '<td class="right">'.($obj->rate !== null ? price((float) $obj->rate).'%' : '').'</td>';
		print '<td class="right">'.price((float) $obj->commission_total).'</td>';
		print '<td class="right">'.price((float) $obj->payable_total).'</td>';
		print '<td class="right">'.price((float) $obj->paid_total).'</td>';
		print '<td class="center">'.lmdbsalescommissionsStatusBadge(lmdbsalescommissionsGetLineStatusLabel($langs, $status), $statusType).'</td>';
		print '</tr>';
	}
	$db->free($resql);
	if ($nb === 0) {
		lmdbsalescommissionsPrintNoRecordRow($langs, 12);
	} else {
		print '<tr class="liste_total"><td colspan="8">'.$langs->trans('Total').'</td><td class="right">'.price($sum_commission).'</td><td class="right">'.price($sum_payable).'</td><td class="right">'.price($sum_paid).'</td><td></td></tr>';
	}
} else {
	lmdbsalescommissionsPrintNoRecordRow($langs, 12);
}
print '</table>';

llxFooter();
$db->close();
