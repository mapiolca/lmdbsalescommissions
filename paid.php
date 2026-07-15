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
	$sortfield = 'd.date_paid';
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
if ($search_event_type === '-1') {
	$search_event_type = '';
}
if ($search_mode === '-1') {
	$search_mode = '';
}
if ($button_removefilter) {
	$fk_user = 0;
	$fk_usergroup = 0;
	$search_event_type = '';
	$search_source_ref = '';
	$search_mode = '';
	$search_date_start = '';
	$search_date_end = '';
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

$contextpage = 'lmdbsalescommissions_paid_list';
$arrayfields = array(
	'source' => array('label' => 'Source', 'checked' => 1, 'position' => 10),
	'date' => array('label' => 'DatePayment', 'checked' => 1, 'position' => 20),
	'salesrep' => array('label' => 'SalesRepresentative', 'checked' => 1, 'position' => 30),
	'thirdparty' => array('label' => 'ThirdParty', 'checked' => 1, 'position' => 40),
	'mode' => array('label' => 'Mode', 'checked' => 1, 'position' => 50),
	'event' => array('label' => 'Event', 'checked' => 1, 'position' => 60),
	'amount' => array('label' => 'Amount', 'checked' => 1, 'position' => 70),
	'paid_by' => array('label' => 'LmdbSalesCommissionsPaidBy', 'checked' => 1, 'position' => 80),
);
$arrayfields = dol_sort_array($arrayfields, 'position');

if (GETPOST('formfilteraction', 'alphanohtml') === 'listafterchangingselectedfields') {
	include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';
}

$param = '';
if ($fk_user > 0) {
	$param .= '&fk_user='.((int) $fk_user);
}
if ($fk_usergroup > 0) {
	$param .= '&fk_usergroup='.((int) $fk_usergroup);
}
if (!empty($search_date_start)) {
	$param .= lmdbsalescommissionsBuildDateFilterParams('search_date_start');
}
if (!empty($search_date_end)) {
	$param .= lmdbsalescommissionsBuildDateFilterParams('search_date_end');
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
$sqlselect .= ' u.lastname, u.firstname, u.login, u.statut AS user_status, u.photo AS user_photo, u.email AS user_email,';
$sqlselect .= ' up.lastname AS paid_lastname, up.firstname AS paid_firstname, up.login AS paid_login, up.statut AS paid_user_status, up.photo AS paid_user_photo, up.email AS paid_user_email, s.nom AS thirdparty_name';
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
if (!empty($search_date_start)) {
	$sqlwhere .= " AND d.date_paid >= '".$db->idate($search_date_start)."'";
}
if (!empty($search_date_end)) {
	$sqlwhere .= " AND d.date_paid <= '".$db->idate($search_date_end)."'";
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

llxHeader('', $langs->trans('LmdbSalesCommissionsPaid'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());

$filterFormId = 'lmdbsalescommissionsPaidFilter';
$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $contextpage, 1);
$visibleColumnCount = lmdbsalescommissionsCountVisibleColumns($arrayfields, 1);

print '<form method="POST" id="'.$filterFormId.'" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" value="list">';
print '<input type="hidden" name="contextpage" value="'.dol_escape_htmltag($contextpage).'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print_barre_liste($langs->trans('LmdbSalesCommissionsPaid'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $num, 'fa-percent', 0, '', '', $limit, 0, 0, 1);
print '<table class="tagtable liste centpercent" id="lmdbsalescommissions-paid-list">';
print '<tr class="liste_titre">';
print_liste_field_titre($selectedfields, $_SERVER['PHP_SELF'], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
if (!empty($arrayfields['source']['checked'])) print_liste_field_titre($arrayfields['source']['label'], $_SERVER['PHP_SELF'], 'l.source_ref', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['date']['checked'])) print_liste_field_titre($arrayfields['date']['label'], $_SERVER['PHP_SELF'], 'd.date_paid', $param, '', '', $sortfield, $sortorder, 'center ');
if (!empty($arrayfields['salesrep']['checked'])) print_liste_field_titre($arrayfields['salesrep']['label'], $_SERVER['PHP_SELF'], 'u.lastname', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['thirdparty']['checked'])) print_liste_field_titre($arrayfields['thirdparty']['label'], $_SERVER['PHP_SELF'], 's.nom', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['mode']['checked'])) print_liste_field_titre($arrayfields['mode']['label'], $_SERVER['PHP_SELF'], 'l.mode', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['event']['checked'])) print_liste_field_titre($arrayfields['event']['label'], $_SERVER['PHP_SELF'], 'd.event_type', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['amount']['checked'])) print_liste_field_titre($arrayfields['amount']['label'], $_SERVER['PHP_SELF'], 'd.amount', $param, '', 'class="right"', $sortfield, $sortorder);
if (!empty($arrayfields['paid_by']['checked'])) print_liste_field_titre($arrayfields['paid_by']['label'], $_SERVER['PHP_SELF'], 'up.lastname', $param, '', '', $sortfield, $sortorder);
print '</tr>';

print '<tr class="liste_titre_filter">';
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons('left').'</td>';
if (!empty($arrayfields['source']['checked'])) print '<td><input type="text" class="flat maxwidth100" name="search_source_ref" value="'.dol_escape_htmltag($search_source_ref).'"></td>';
if (!empty($arrayfields['date']['checked'])) print '<td class="liste_titre center">'.lmdbsalescommissionsRenderDateRangeFilter($form, $search_date_start, $search_date_end, 'search_date_start', 'search_date_end').'</td>';
if (!empty($arrayfields['salesrep']['checked'])) {
	print '<td>';
	print $form->selectarray('fk_user', lmdbsalescommissionsGetUserOptions($db), $fk_user, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200', 1);
	print '<br>';
	print $form->selectarray('fk_usergroup', lmdbsalescommissionsGetUserGroupOptions($db), $fk_usergroup, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200', 1);
	print '</td>';
}
if (!empty($arrayfields['thirdparty']['checked'])) print '<td></td>';
if (!empty($arrayfields['mode']['checked'])) print '<td>'.$form->selectarray('search_mode', array('margin' => $langs->trans('LmdbSalesCommissionsRuleTypeMargin'), 'tier' => $langs->trans('LmdbSalesCommissionsRuleTypeTier'), 'tracking' => $langs->trans('LmdbSalesCommissionsModeTracking'), 'dispatch' => $langs->trans('LmdbSalesCommissionsModeDispatch')), $search_mode, 1, 0, 0, '', 0, 0, 0, '', 'minwidth125 maxwidth200', 1).'</td>';
if (!empty($arrayfields['event']['checked'])) print '<td>'.$form->selectarray('search_event_type', array('proposal_signed' => $langs->trans('LmdbSalesCommissionsEventProposalSigned'), 'deposit_paid' => $langs->trans('LmdbSalesCommissionsEventDepositPaid'), 'final_invoice_paid' => $langs->trans('LmdbSalesCommissionsEventFinalInvoicePaid')), $search_event_type, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200', 1).'</td>';
if (!empty($arrayfields['amount']['checked'])) print '<td></td>';
if (!empty($arrayfields['paid_by']['checked'])) print '<td></td>';
print '</tr>';

if ($resql) {
	$nb = 0;
	while (is_object($obj = $db->fetch_object($resql))) {
		$nb++;
		if ($nb > $limit) {
			break;
		}

		print '<tr class="oddeven">';
		print '<td class="center"></td>';
		if (!empty($arrayfields['source']['checked'])) print '<td>'.lmdbsalescommissionsBuildSourceNomUrl($db, (string) $obj->source_type, (int) $obj->fk_source, (string) $obj->source_ref).'</td>';
		if (!empty($arrayfields['date']['checked'])) print '<td class="center">'.dol_print_date($db->jdate($obj->date_paid), 'day').'</td>';
		if (!empty($arrayfields['salesrep']['checked'])) print '<td>'.lmdbsalescommissionsBuildUserNomUrl($db, (int) $obj->fk_user, (string) $obj->lastname, (string) $obj->firstname, (string) $obj->login, (int) $obj->user_status, (string) $obj->user_photo, (string) $obj->user_email).'</td>';
		if (!empty($arrayfields['thirdparty']['checked'])) print '<td>'.lmdbsalescommissionsBuildThirdpartyNomUrl($db, (int) $obj->fk_soc, (string) $obj->thirdparty_name).'</td>';
		if (!empty($arrayfields['mode']['checked'])) print '<td>'.dol_escape_htmltag(lmdbsalescommissionsGetModeLabel($langs, (string) $obj->mode)).'</td>';
		if (!empty($arrayfields['event']['checked'])) print '<td>'.dol_escape_htmltag(lmdbsalescommissionsGetDueEventLabel($langs, (string) $obj->event_type)).'</td>';
		if (!empty($arrayfields['amount']['checked'])) print '<td class="right">'.lmdbsalescommissionsFormatTotalAmount($obj->amount).'</td>';
		if (!empty($arrayfields['paid_by']['checked'])) print '<td>'.lmdbsalescommissionsBuildUserNomUrl($db, (int) $obj->fk_user_paid, (string) $obj->paid_lastname, (string) $obj->paid_firstname, (string) $obj->paid_login, (int) $obj->paid_user_status, (string) $obj->paid_user_photo, (string) $obj->paid_user_email).'</td>';
		print '</tr>';
	}
	$db->free($resql);
	if ($nb === 0) {
		lmdbsalescommissionsPrintNoRecordRow($langs, $visibleColumnCount);
	} else {
		$totalLabelColspan = 1;
		foreach (array('source', 'date', 'salesrep', 'thirdparty', 'mode', 'event') as $fieldKey) {
			if (!empty($arrayfields[$fieldKey]['checked'])) {
				$totalLabelColspan++;
			}
		}
		print '<tr class="liste_total">';
		print '<td colspan="'.$totalLabelColspan.'">'.$langs->trans('Total').'</td>';
		if (!empty($arrayfields['amount']['checked'])) print '<td class="right">'.lmdbsalescommissionsFormatTotalAmount($sum_paid).'</td>';
		if (!empty($arrayfields['paid_by']['checked'])) print '<td></td>';
		print '</tr>';
	}
} else {
	lmdbsalescommissionsPrintNoRecordRow($langs, $visibleColumnCount);
}
print '</table>';
print '</form>';

llxFooter();
$db->close();
