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
$search_event_type = GETPOST('search_event_type', 'aZ09');
$search_source_ref = GETPOST('search_source_ref', 'alpha');
$search_mode = GETPOST('search_mode', 'aZ09');
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTINT('page');
if ($page < 0) {
	$page = 0;
}
$limit = getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 20);
$offset = $limit * $page;
if (empty($sortfield)) {
	$sortfield = 'd.date_paid';
}
if (empty($sortorder)) {
	$sortorder = 'DESC';
}

if (!isModEnabled('lmdbsalescommissions')) {
	accessforbidden();
}

if (!lmdbsalescommissionsCanDo($user, 'due', 'read')) {
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
if ($search_event_type !== '') {
	$param .= '&search_event_type='.urlencode($search_event_type);
}
if ($search_source_ref !== '') {
	$param .= '&search_source_ref='.urlencode($search_source_ref);
}
if ($search_mode !== '') {
	$param .= '&search_mode='.urlencode($search_mode);
}

$sqlselect = 'SELECT d.rowid, d.event_type, d.amount, d.date_paid, d.fk_user_paid,';
$sqlselect .= ' l.fk_user, l.fk_soc, l.source_type, l.fk_source, l.source_ref, l.mode,';
$sqlselect .= ' u.lastname, u.firstname, u.login, up.lastname AS paid_lastname, up.firstname AS paid_firstname, up.login AS paid_login, s.nom AS thirdparty_name';
$sqlfrom = ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d';
$sqlfrom .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l ON l.rowid = d.fk_commission_line AND l.entity = d.entity';
$sqlfrom .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = l.fk_user';
$sqlfrom .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS up ON up.rowid = d.fk_user_paid';
$sqlfrom .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = l.fk_soc';
$sqlwhere = ' WHERE d.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_due')).')';
$sqlwhere .= ' AND d.status = '.LmdbSalesCommissionDueService::STATUS_PAID;
$sqlwhere .= lmdbsalescommissionsBuildCommissionScopeSql($db, $user, 'l');
if ($fk_user > 0) {
	$sqlwhere .= ' AND l.fk_user = '.((int) $fk_user);
}
if ($fk_usergroup > 0) {
	$sqlwhere .= ' AND EXISTS (SELECT ugu.rowid FROM '.MAIN_DB_PREFIX.'usergroup_user AS ugu WHERE ugu.fk_user = l.fk_user AND ugu.fk_usergroup = '.((int) $fk_usergroup).' AND ugu.entity IN ('.$db->sanitize(getEntity('usergroup')).'))';
}
if ($search_event_type !== '') {
	$sqlwhere .= " AND d.event_type = '".$db->escape($search_event_type)."'";
}
if ($search_source_ref !== '') {
	$sqlwhere .= natural_search('l.source_ref', $search_source_ref);
}
if ($search_mode !== '') {
	$sqlwhere .= " AND l.mode = '".$db->escape($search_mode)."'";
}

$sqlcount = 'SELECT COUNT(*) AS nb'.$sqlfrom.$sqlwhere;
$rescount = $db->query($sqlcount);
$num = 0;
if ($rescount && is_object($objcount = $db->fetch_object($rescount))) {
	$num = (int) $objcount->nb;
	$db->free($rescount);
}

$sqltotal = 'SELECT SUM(d.amount) AS amount_total'.$sqlfrom.$sqlwhere;
$restotal = $db->query($sqltotal);
$sum_paid = 0.0;
if ($restotal && is_object($objtotal = $db->fetch_object($restotal))) {
	$sum_paid = (float) $objtotal->amount_total;
	$db->free($restotal);
}

$sql = $sqlselect.$sqlfrom.$sqlwhere;
$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
if (!$resql) {
	setEventMessages($db->lasterror(), null, 'errors');
}

llxHeader('', $langs->trans('LmdbSalesCommissionsPaid'));

print_barre_liste($langs->trans('LmdbSalesCommissionsPaid'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $num, 'fa-percent', 0, '', '', $limit);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print_liste_field_titre('DatePayment', $_SERVER['PHP_SELF'], 'd.date_paid', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('SalesRepresentative', $_SERVER['PHP_SELF'], 'u.lastname', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('ThirdParty', $_SERVER['PHP_SELF'], 's.nom', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('Source', $_SERVER['PHP_SELF'], 'l.source_ref', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('Event', $_SERVER['PHP_SELF'], 'd.event_type', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('Mode', $_SERVER['PHP_SELF'], 'l.mode', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('Amount', $_SERVER['PHP_SELF'], 'd.amount', $param, '', 'class="right"', $sortfield, $sortorder);
print_liste_field_titre('LmdbSalesCommissionsPaidBy', $_SERVER['PHP_SELF'], 'up.lastname', $param, '', '', $sortfield, $sortorder);
print '</tr>';

print '<tr class="liste_titre_filter"><td colspan="8">';
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print $langs->trans('SalesRepresentative').' '.$form->selectarray('fk_user', lmdbsalescommissionsGetUserOptions($db), $fk_user, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150', 1).' ';
print $langs->trans('Group').' '.$form->selectarray('fk_usergroup', lmdbsalescommissionsGetUserGroupOptions($db), $fk_usergroup, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150', 1).' ';
print $langs->trans('Source').' <input type="text" class="flat maxwidth100" name="search_source_ref" value="'.dol_escape_htmltag($search_source_ref).'"> ';
print $langs->trans('Event').' '.$form->selectarray('search_event_type', array('proposal_signed' => $langs->trans('LmdbSalesCommissionsEventProposalSigned'), 'deposit_paid' => $langs->trans('LmdbSalesCommissionsEventDepositPaid'), 'final_invoice_paid' => $langs->trans('LmdbSalesCommissionsEventFinalInvoicePaid')), $search_event_type, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150', 1).' ';
print $langs->trans('Mode').' '.$form->selectarray('search_mode', array('margin' => $langs->trans('LmdbSalesCommissionsRuleTypeMargin'), 'tier' => $langs->trans('LmdbSalesCommissionsRuleTypeTier')), $search_mode, 1, 0, 0, '', 0, 0, 0, '', 'minwidth100', 1).' ';
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
		$paidBy = trim((string) $obj->paid_firstname.' '.(string) $obj->paid_lastname);
		if ($paidBy === '') {
			$paidBy = (string) $obj->paid_login;
		}
		$sourceUrl = lmdbsalescommissionsBuildSourceUrl((string) $obj->source_type, (int) $obj->fk_source);

		print '<tr class="oddeven">';
		print '<td>'.dol_print_date($db->jdate($obj->date_paid), 'day').'</td>';
		print '<td>'.dol_escape_htmltag($agent).'</td>';
		print '<td>'.dol_escape_htmltag((string) $obj->thirdparty_name).'</td>';
		print '<td>';
		if ($sourceUrl !== '') {
			print '<a href="'.dol_escape_htmltag($sourceUrl).'">'.dol_escape_htmltag((string) $obj->source_ref).'</a>';
		} else {
			print dol_escape_htmltag((string) $obj->source_ref);
		}
		print '</td>';
		print '<td>'.dol_escape_htmltag(lmdbsalescommissionsGetDueEventLabel($langs, (string) $obj->event_type)).'</td>';
		print '<td>'.dol_escape_htmltag(lmdbsalescommissionsGetModeLabel($langs, (string) $obj->mode)).'</td>';
		print '<td class="right">'.price((float) $obj->amount).'</td>';
		print '<td>'.dol_escape_htmltag($paidBy).'</td>';
		print '</tr>';
	}
	$db->free($resql);
	if ($nb === 0) {
		lmdbsalescommissionsPrintNoRecordRow($langs, 8);
	} else {
		print '<tr class="liste_total"><td colspan="6">'.$langs->trans('Total').'</td><td class="right">'.price($sum_paid).'</td><td></td></tr>';
	}
} else {
	lmdbsalescommissionsPrintNoRecordRow($langs, 8);
}
print '</table>';

llxFooter();
$db->close();
