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

$contextpage = 'lmdbsalescommissions_tracking_list';
$arrayfields = array(
	'source' => array('label' => 'Source', 'checked' => 1, 'position' => 10),
	'date' => array('label' => 'Date', 'checked' => 1, 'position' => 20),
	'salesrep' => array('label' => 'SalesRepresentative', 'checked' => 1, 'position' => 30),
	'thirdparty' => array('label' => 'ThirdParty', 'checked' => 1, 'position' => 40),
	'mode' => array('label' => 'Mode', 'checked' => 1, 'position' => 50),
	'amount_base' => array('label' => 'AmountHT', 'checked' => 1, 'position' => 60),
	'margin_base' => array('label' => 'Margin', 'checked' => 1, 'position' => 70),
	'rate' => array('label' => 'Rate', 'checked' => 1, 'position' => 80),
	'commission_total' => array('label' => 'LmdbSalesCommissionsCommissionTotal', 'checked' => 1, 'position' => 90),
	'payable_total' => array('label' => 'LmdbSalesCommissionsPayableTotal', 'checked' => 1, 'position' => 100),
	'paid_total' => array('label' => 'LmdbSalesCommissionsPaidTotal', 'checked' => 1, 'position' => 110),
	'status' => array('label' => 'Status', 'checked' => 1, 'position' => 120),
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
$sqlselect .= ' u.lastname, u.firstname, u.login, u.statut AS user_status, u.photo AS user_photo, u.email AS user_email, s.nom AS thirdparty_name';
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

llxHeader('', $langs->trans('LmdbSalesCommissionsTracking'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());

print_barre_liste($langs->trans('LmdbSalesCommissionsTracking'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $num, 'fa-percent', 0, '', '', $limit, 0, 0, 1);

$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $contextpage, 1);
$visibleColumnCount = lmdbsalescommissionsCountVisibleColumns($arrayfields, 1);

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" value="list">';
print '<input type="hidden" name="contextpage" value="'.dol_escape_htmltag($contextpage).'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print '<input type="hidden" name="limit" value="'.((int) $limit).'">';
print '<table class="tagtable liste centpercent" id="lmdbsalescommissions-tracking-list">';
print '<tr class="liste_titre">';
print_liste_field_titre($selectedfields, $_SERVER['PHP_SELF'], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
if (!empty($arrayfields['source']['checked'])) print_liste_field_titre($arrayfields['source']['label'], $_SERVER['PHP_SELF'], 'l.source_ref', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['date']['checked'])) print_liste_field_titre($arrayfields['date']['label'], $_SERVER['PHP_SELF'], 'l.date_acquired', $param, '', '', $sortfield, $sortorder, 'center ');
if (!empty($arrayfields['salesrep']['checked'])) print_liste_field_titre($arrayfields['salesrep']['label'], $_SERVER['PHP_SELF'], 'u.lastname', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['thirdparty']['checked'])) print_liste_field_titre($arrayfields['thirdparty']['label'], $_SERVER['PHP_SELF'], 's.nom', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['mode']['checked'])) print_liste_field_titre($arrayfields['mode']['label'], $_SERVER['PHP_SELF'], 'l.mode', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['amount_base']['checked'])) print_liste_field_titre($arrayfields['amount_base']['label'], $_SERVER['PHP_SELF'], 'l.amount_base', $param, '', 'class="right"', $sortfield, $sortorder);
if (!empty($arrayfields['margin_base']['checked'])) print_liste_field_titre($arrayfields['margin_base']['label'], $_SERVER['PHP_SELF'], 'l.margin_base', $param, '', 'class="right"', $sortfield, $sortorder);
if (!empty($arrayfields['rate']['checked'])) print_liste_field_titre($arrayfields['rate']['label'], $_SERVER['PHP_SELF'], 'l.rate', $param, '', 'class="right"', $sortfield, $sortorder);
if (!empty($arrayfields['commission_total']['checked'])) print_liste_field_titre($arrayfields['commission_total']['label'], $_SERVER['PHP_SELF'], 'l.commission_total', $param, '', 'class="right"', $sortfield, $sortorder);
if (!empty($arrayfields['payable_total']['checked'])) print_liste_field_titre($arrayfields['payable_total']['label'], $_SERVER['PHP_SELF'], 'l.payable_total', $param, '', 'class="right"', $sortfield, $sortorder);
if (!empty($arrayfields['paid_total']['checked'])) print_liste_field_titre($arrayfields['paid_total']['label'], $_SERVER['PHP_SELF'], 'l.paid_total', $param, '', 'class="right"', $sortfield, $sortorder);
if (!empty($arrayfields['status']['checked'])) print_liste_field_titre($arrayfields['status']['label'], $_SERVER['PHP_SELF'], 'l.status', $param, '', '', $sortfield, $sortorder, 'center ');
print '</tr>';

print '<tr class="liste_titre_filter">';
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons('left').'</td>';
if (!empty($arrayfields['source']['checked'])) {
	print '<td>';
	print '<input type="text" class="flat maxwidth100" name="search_source_ref" value="'.dol_escape_htmltag($search_source_ref).'">';
	print '<br>';
	print $form->selectarray('search_source_type', $source_type_options, $search_source_type, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100', 1);
	print '</td>';
}
if (!empty($arrayfields['date']['checked'])) print '<td class="liste_titre center">'.lmdbsalescommissionsRenderDateRangeFilter($form, $search_date_start, $search_date_end, 'search_date_start', 'search_date_end').'</td>';
if (!empty($arrayfields['salesrep']['checked'])) {
	print '<td>';
	print $form->selectarray('fk_user', lmdbsalescommissionsGetUserOptions($db), $fk_user, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200', 1);
	print '<br>';
	print $form->selectarray('fk_usergroup', lmdbsalescommissionsGetUserGroupOptions($db), $fk_usergroup, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200', 1);
	print '</td>';
}
if (!empty($arrayfields['thirdparty']['checked'])) print '<td><input type="text" class="flat width50" name="fk_soc" value="'.($fk_soc > 0 ? (int) $fk_soc : '').'"></td>';
if (!empty($arrayfields['mode']['checked'])) {
	print '<td>';
	print $form->selectarray('search_mode', $mode_options, $search_mode, 1, 0, 0, '', 0, 0, 0, '', 'minwidth125 maxwidth200', 1);
	print '<br>';
	print $form->selectarray('search_rule_source', $rule_source_options, $search_rule_source, 1, 0, 0, '', 0, 0, 0, '', 'minwidth125 maxwidth200', 1);
	print '</td>';
}
if (!empty($arrayfields['amount_base']['checked'])) print '<td></td>';
if (!empty($arrayfields['margin_base']['checked'])) print '<td></td>';
if (!empty($arrayfields['rate']['checked'])) print '<td></td>';
if (!empty($arrayfields['commission_total']['checked'])) print '<td></td>';
if (!empty($arrayfields['payable_total']['checked'])) print '<td></td>';
if (!empty($arrayfields['paid_total']['checked'])) print '<td></td>';
if (!empty($arrayfields['status']['checked'])) {
	print '<td class="center">';
	print $form->multiselectarray('search_status', $status_options, $search_status, 0, 0, 'search_status width100 onrightofpage', 0, 0, '', '', '', 1);
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
		print '<td class="center"></td>';
		if (!empty($arrayfields['source']['checked'])) print '<td>'.lmdbsalescommissionsBuildSourceNomUrl($db, (string) $obj->source_type, (int) $obj->fk_source, (string) $obj->source_ref).'</td>';
		if (!empty($arrayfields['date']['checked'])) print '<td class="center">'.dol_print_date($db->jdate($obj->date_acquired), 'day').'</td>';
		if (!empty($arrayfields['salesrep']['checked'])) print '<td>'.lmdbsalescommissionsBuildUserNomUrl($db, (int) $obj->fk_user, (string) $obj->lastname, (string) $obj->firstname, (string) $obj->login, (int) $obj->user_status, (string) $obj->user_photo, (string) $obj->user_email).'</td>';
		if (!empty($arrayfields['thirdparty']['checked'])) print '<td>'.lmdbsalescommissionsBuildThirdpartyNomUrl($db, (int) $obj->fk_soc, (string) $obj->thirdparty_name).'</td>';
		if (!empty($arrayfields['mode']['checked'])) print '<td>'.dol_escape_htmltag(lmdbsalescommissionsGetModeLabel($langs, (string) $obj->mode)).'</td>';
		if (!empty($arrayfields['amount_base']['checked'])) print '<td class="right">'.price((float) $obj->amount_base).'</td>';
		if (!empty($arrayfields['margin_base']['checked'])) print '<td class="right">'.($obj->margin_base !== null ? price((float) $obj->margin_base) : '').'</td>';
		if (!empty($arrayfields['rate']['checked'])) print '<td class="right">'.($obj->rate !== null ? price((float) $obj->rate).'%' : '').'</td>';
		if (!empty($arrayfields['commission_total']['checked'])) print '<td class="right">'.price((float) $obj->commission_total).'</td>';
		if (!empty($arrayfields['payable_total']['checked'])) print '<td class="right">'.price((float) $obj->payable_total).'</td>';
		if (!empty($arrayfields['paid_total']['checked'])) print '<td class="right">'.price((float) $obj->paid_total).'</td>';
		if (!empty($arrayfields['status']['checked'])) print '<td class="center">'.lmdbsalescommissionsStatusBadge(lmdbsalescommissionsGetLineStatusLabel($langs, $status), $status).'</td>';
		print '</tr>';
	}
	$db->free($resql);
	if ($nb === 0) {
		lmdbsalescommissionsPrintNoRecordRow($langs, $visibleColumnCount);
	} else {
		$totalLabelColspan = 1;
		foreach (array('source', 'date', 'salesrep', 'thirdparty', 'mode', 'amount_base', 'margin_base', 'rate') as $fieldKey) {
			if (!empty($arrayfields[$fieldKey]['checked'])) {
				$totalLabelColspan++;
			}
		}
		print '<tr class="liste_total">';
		print '<td colspan="'.$totalLabelColspan.'">'.$langs->trans('Total').'</td>';
		if (!empty($arrayfields['commission_total']['checked'])) print '<td class="right">'.price($sum_commission).'</td>';
		if (!empty($arrayfields['payable_total']['checked'])) print '<td class="right">'.price($sum_payable).'</td>';
		if (!empty($arrayfields['paid_total']['checked'])) print '<td class="right">'.price($sum_paid).'</td>';
		if (!empty($arrayfields['status']['checked'])) print '<td></td>';
		print '</tr>';
	}
} else {
	lmdbsalescommissionsPrintNoRecordRow($langs, $visibleColumnCount);
}
print '</table>';
print '</form>';

llxFooter();
$db->close();
