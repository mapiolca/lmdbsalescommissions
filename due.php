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
$id = GETPOSTINT('id');
$fk_user = GETPOSTINT('fk_user');
$search_status = GETPOST('search_status', 'alpha');
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
	$sortfield = 'd.date_due';
}
if (empty($sortorder)) {
	$sortorder = 'ASC';
}

if ($fk_user < 0) {
	$fk_user = 0;
}
if ($search_status === '-1') {
	$search_status = '';
}
if ($search_event_type === '-1') {
	$search_event_type = '';
}
if ($search_mode === '-1') {
	$search_mode = '';
}
if ($button_removefilter) {
	$fk_user = 0;
	$search_status = '';
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

$form = new Form($db);
$mode_options = array(
	'margin' => $langs->trans('LmdbSalesCommissionsRuleTypeMargin'),
	'tier' => $langs->trans('LmdbSalesCommissionsRuleTypeTier'),
	'tracking' => $langs->trans('LmdbSalesCommissionsModeTracking'),
);

$contextpage = 'lmdbsalescommissions_due_list';
$arrayfields = array(
	'source' => array('label' => 'Source', 'checked' => 1, 'position' => 10),
	'date' => array('label' => 'DateDue', 'checked' => 1, 'position' => 20),
	'salesrep' => array('label' => 'SalesRepresentative', 'checked' => 1, 'position' => 30),
	'thirdparty' => array('label' => 'ThirdParty', 'checked' => 1, 'position' => 40),
	'mode' => array('label' => 'Mode', 'checked' => 1, 'position' => 50),
	'event' => array('label' => 'Event', 'checked' => 1, 'position' => 60),
	'commission_total' => array('label' => 'LmdbSalesCommissionsCommissionTotal', 'checked' => 1, 'position' => 70),
	'percentage' => array('label' => 'Percentage', 'checked' => 1, 'position' => 80),
	'amount' => array('label' => 'Amount', 'checked' => 1, 'position' => 90),
	'status' => array('label' => 'Status', 'checked' => 1, 'position' => 100),
);
$arrayfields = dol_sort_array($arrayfields, 'position');

if ($action === 'markpaid') {
	if (!lmdbsalescommissionsCanDo($user, 'due', 'pay')) {
		accessforbidden();
	}
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}
	$date_paid = dol_mktime(0, 0, 0, GETPOSTINT('date_paidmonth'), GETPOSTINT('date_paidday'), GETPOSTINT('date_paidyear'));
	$note_private = GETPOST('note_private', 'restricthtml');

	$sqlscope = 'SELECT d.rowid';
	$sqlscope .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d';
	$sqlscope .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l ON l.rowid = d.fk_commission_line AND l.entity = d.entity';
	$sqlscope .= ' WHERE d.rowid = '.((int) $id);
	$sqlscope .= ' AND d.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_due')).')';
	$sqlscope .= lmdbsalescommissionsBuildCommissionScopeSql($db, $user, 'l');
	$resscope = $db->query($sqlscope);
	if (!$resscope) {
		setEventMessages($db->lasterror(), null, 'errors');
	} elseif ($db->num_rows($resscope) <= 0) {
		$db->free($resscope);
		accessforbidden();
	} else {
		$db->free($resscope);
		$service = new LmdbSalesCommissionDueService($db);
		$result = $service->markAsPaid($id, $date_paid, $note_private, $user);
		if ($result < 0) {
			setEventMessages($langs->trans($service->error), $service->errors, 'errors');
		} else {
			setEventMessages($langs->trans('LmdbSalesCommissionsDueMarkedPaid'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}
	}
} elseif ($action !== '') {
	accessforbidden($langs->trans('LmdbSalesCommissionsActionNotAvailableYet'));
}

if (GETPOST('formfilteraction', 'alphanohtml') === 'listafterchangingselectedfields') {
	include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';
}

$param = '';
if ($fk_user > 0) {
	$param .= '&fk_user='.((int) $fk_user);
}
if (!empty($search_date_start)) {
	$param .= lmdbsalescommissionsBuildDateFilterParams('search_date_start');
}
if (!empty($search_date_end)) {
	$param .= lmdbsalescommissionsBuildDateFilterParams('search_date_end');
}
if ($search_status !== '') {
	$param .= '&search_status='.urlencode($search_status);
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

$sql = 'SELECT d.rowid, d.event_type, d.percentage, d.amount, d.status, d.date_due, d.date_paid,';
$sql .= ' l.rowid AS line_id, l.fk_user, l.fk_soc, l.source_type, l.fk_source, l.source_ref, l.mode, l.commission_total,';
$sql .= ' u.lastname, u.firstname, u.login, u.statut AS user_status, u.photo AS user_photo, u.email AS user_email, s.nom AS thirdparty_name';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l ON l.rowid = d.fk_commission_line AND l.entity = d.entity';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = l.fk_user';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = l.fk_soc';
$sql .= ' WHERE d.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_due')).')';
$sql .= ' AND d.status IN (0, 1)';
$sql .= lmdbsalescommissionsBuildCommissionScopeSql($db, $user, 'l');
if ($fk_user > 0) {
	$sql .= ' AND l.fk_user = '.((int) $fk_user);
}
if (!empty($search_date_start)) {
	$sql .= " AND d.date_due >= '".$db->idate($search_date_start)."'";
}
if (!empty($search_date_end)) {
	$sql .= " AND d.date_due <= '".$db->idate($search_date_end)."'";
}
if ($search_status !== '') {
	$sql .= ' AND d.status = '.((int) $search_status);
}
if ($search_event_type !== '') {
	$sql .= " AND d.event_type = '".$db->escape($search_event_type)."'";
}
if ($search_source_ref !== '') {
	$sql .= natural_search('l.source_ref', $search_source_ref);
}
if ($search_mode !== '') {
	$sql .= " AND l.mode = '".$db->escape($search_mode)."'";
}

$sqlcount = preg_replace('/^SELECT\s+.+?\s+FROM\s+/s', 'SELECT COUNT(*) AS nb FROM ', $sql);
$rescount = $db->query($sqlcount);
$num = 0;
if ($rescount && is_object($objcount = $db->fetch_object($rescount))) {
	$num = (int) $objcount->nb;
	$db->free($rescount);
}

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
if (!$resql) {
	setEventMessages($db->lasterror(), null, 'errors');
}

llxHeader('', $langs->trans('LmdbSalesCommissionsDue'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());

$filterFormId = 'lmdbsalescommissionsDueFilter';
$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $contextpage, 1);
$selectedfields = lmdbsalescommissionsAttachFormToControls($selectedfields, $filterFormId);
$visibleColumnCount = lmdbsalescommissionsCountVisibleColumns($arrayfields, 2);

print '<form method="POST" id="'.$filterFormId.'" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" value="list">';
print '<input type="hidden" name="contextpage" value="'.dol_escape_htmltag($contextpage).'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print_barre_liste($langs->trans('LmdbSalesCommissionsDue'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $num, 'fa-percent', 0, '', '', $limit, 0, 0, 1);
print '</form>';

print '<table class="tagtable liste centpercent" id="lmdbsalescommissions-due-list">';
print '<tr class="liste_titre">';
print_liste_field_titre($selectedfields, $_SERVER['PHP_SELF'], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
if (!empty($arrayfields['source']['checked'])) print_liste_field_titre($arrayfields['source']['label'], $_SERVER['PHP_SELF'], 'l.source_ref', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['date']['checked'])) print_liste_field_titre($arrayfields['date']['label'], $_SERVER['PHP_SELF'], 'd.date_due', $param, '', '', $sortfield, $sortorder, 'center ');
if (!empty($arrayfields['salesrep']['checked'])) print_liste_field_titre($arrayfields['salesrep']['label'], $_SERVER['PHP_SELF'], 'u.lastname', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['thirdparty']['checked'])) print_liste_field_titre($arrayfields['thirdparty']['label'], $_SERVER['PHP_SELF'], 's.nom', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['mode']['checked'])) print_liste_field_titre($arrayfields['mode']['label'], $_SERVER['PHP_SELF'], 'l.mode', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['event']['checked'])) print_liste_field_titre($arrayfields['event']['label'], $_SERVER['PHP_SELF'], 'd.event_type', $param, '', '', $sortfield, $sortorder);
if (!empty($arrayfields['commission_total']['checked'])) print_liste_field_titre($arrayfields['commission_total']['label'], $_SERVER['PHP_SELF'], 'l.commission_total', $param, '', 'class="right"', $sortfield, $sortorder);
if (!empty($arrayfields['percentage']['checked'])) print_liste_field_titre($arrayfields['percentage']['label'], $_SERVER['PHP_SELF'], 'd.percentage', $param, '', 'class="right"', $sortfield, $sortorder);
if (!empty($arrayfields['amount']['checked'])) print_liste_field_titre($arrayfields['amount']['label'], $_SERVER['PHP_SELF'], 'd.amount', $param, '', 'class="right"', $sortfield, $sortorder);
if (!empty($arrayfields['status']['checked'])) print_liste_field_titre($arrayfields['status']['label'], $_SERVER['PHP_SELF'], 'd.status', $param, '', '', $sortfield, $sortorder, 'center ');
print '<th class="center">'.$langs->trans('Action').'</th>';
print '</tr>';

print '<tr class="liste_titre_filter">';
print '<td class="liste_titre center maxwidthsearch">'.lmdbsalescommissionsAttachFormToControls($form->showFilterButtons('left'), $filterFormId).'</td>';
if (!empty($arrayfields['source']['checked'])) print '<td><input form="'.$filterFormId.'" type="text" class="flat maxwidth100" name="search_source_ref" value="'.dol_escape_htmltag($search_source_ref).'"></td>';
if (!empty($arrayfields['date']['checked'])) print '<td class="liste_titre center">'.lmdbsalescommissionsRenderDateRangeFilter($form, $search_date_start, $search_date_end, 'search_date_start', 'search_date_end', $filterFormId).'</td>';
if (!empty($arrayfields['salesrep']['checked'])) print '<td>'.lmdbsalescommissionsAttachFormToControls($form->selectarray('fk_user', lmdbsalescommissionsGetUserOptions($db), $fk_user, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200', 1), $filterFormId).'</td>';
if (!empty($arrayfields['thirdparty']['checked'])) print '<td></td>';
if (!empty($arrayfields['mode']['checked'])) print '<td>'.lmdbsalescommissionsAttachFormToControls($form->selectarray('search_mode', $mode_options, $search_mode, 1, 0, 0, '', 0, 0, 0, '', 'minwidth125 maxwidth200', 1), $filterFormId).'</td>';
if (!empty($arrayfields['event']['checked'])) print '<td>'.lmdbsalescommissionsAttachFormToControls($form->selectarray('search_event_type', array('proposal_signed' => $langs->trans('LmdbSalesCommissionsEventProposalSigned'), 'deposit_paid' => $langs->trans('LmdbSalesCommissionsEventDepositPaid'), 'final_invoice_paid' => $langs->trans('LmdbSalesCommissionsEventFinalInvoicePaid')), $search_event_type, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150 maxwidth200', 1), $filterFormId).'</td>';
if (!empty($arrayfields['commission_total']['checked'])) print '<td></td>';
if (!empty($arrayfields['percentage']['checked'])) print '<td></td>';
if (!empty($arrayfields['amount']['checked'])) print '<td></td>';
if (!empty($arrayfields['status']['checked'])) print '<td class="center">'.lmdbsalescommissionsAttachFormToControls($form->selectarray('search_status', array('0' => $langs->trans('LmdbSalesCommissionsDueStatusWaiting'), '1' => $langs->trans('LmdbSalesCommissionsDueStatusDue')), $search_status, 1, 0, 0, '', 0, 0, 0, '', 'minwidth100', 1), $filterFormId).'</td>';
print '<td></td>';
print '</tr>';

$total_due = 0.0;
if ($resql) {
	$nb = 0;
	while (is_object($obj = $db->fetch_object($resql))) {
		$nb++;
		if ($nb > $limit) {
			break;
		}
		$status = (int) $obj->status;
		$statusType = $status === LmdbSalesCommissionDueService::STATUS_DUE ? 1 : 0;
		$total_due += (float) $obj->amount;

		print '<tr class="oddeven">';
		print '<td class="center"></td>';
		if (!empty($arrayfields['source']['checked'])) print '<td>'.lmdbsalescommissionsBuildSourceNomUrl($db, (string) $obj->source_type, (int) $obj->fk_source, (string) $obj->source_ref).'</td>';
		if (!empty($arrayfields['date']['checked'])) print '<td class="center">'.(!empty($obj->date_due) ? dol_print_date($db->jdate($obj->date_due), 'day') : '').'</td>';
		if (!empty($arrayfields['salesrep']['checked'])) print '<td>'.lmdbsalescommissionsBuildUserNomUrl($db, (int) $obj->fk_user, (string) $obj->lastname, (string) $obj->firstname, (string) $obj->login, (int) $obj->user_status, (string) $obj->user_photo, (string) $obj->user_email).'</td>';
		if (!empty($arrayfields['thirdparty']['checked'])) print '<td>'.lmdbsalescommissionsBuildThirdpartyNomUrl($db, (int) $obj->fk_soc, (string) $obj->thirdparty_name).'</td>';
		if (!empty($arrayfields['mode']['checked'])) print '<td>'.dol_escape_htmltag(lmdbsalescommissionsGetModeLabel($langs, (string) $obj->mode)).'</td>';
		if (!empty($arrayfields['event']['checked'])) print '<td>'.dol_escape_htmltag(lmdbsalescommissionsGetDueEventLabel($langs, (string) $obj->event_type)).'</td>';
		if (!empty($arrayfields['commission_total']['checked'])) print '<td class="right">'.price((float) $obj->commission_total).'</td>';
		if (!empty($arrayfields['percentage']['checked'])) print '<td class="right">'.price((float) $obj->percentage).'%</td>';
		if (!empty($arrayfields['amount']['checked'])) print '<td class="right">'.price((float) $obj->amount).'</td>';
		if (!empty($arrayfields['status']['checked'])) print '<td class="center">'.lmdbsalescommissionsStatusBadge(lmdbsalescommissionsGetDueStatusLabel($langs, $status), $statusType).'</td>';
		print '<td class="center">';
		if ($status === LmdbSalesCommissionDueService::STATUS_DUE && lmdbsalescommissionsCanDo($user, 'due', 'pay')) {
			print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="inline-block">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="markpaid">';
			print '<input type="hidden" name="id" value="'.((int) $obj->rowid).'">';
			print $form->selectDate(dol_now(), 'date_paid', 0, 0, 0, '', 1, 0);
			print '<input type="text" class="flat maxwidth100" name="note_private" placeholder="'.dol_escape_htmltag($langs->trans('NotePrivate')).'">';
			print '<button type="submit" class="button small">'.$langs->trans('LmdbSalesCommissionsMarkPaid').'</button>';
			print '</form>';
		}
		print '</td>';
		print '</tr>';
	}
	$db->free($resql);

	if ($nb === 0) {
		lmdbsalescommissionsPrintNoRecordRow($langs, $visibleColumnCount);
	} else {
		$totalLabelColspan = 1;
		foreach (array('source', 'date', 'salesrep', 'thirdparty', 'mode', 'event', 'commission_total', 'percentage') as $fieldKey) {
			if (!empty($arrayfields[$fieldKey]['checked'])) {
				$totalLabelColspan++;
			}
		}
		print '<tr class="liste_total">';
		print '<td colspan="'.$totalLabelColspan.'">'.$langs->trans('Total').'</td>';
		if (!empty($arrayfields['amount']['checked'])) print '<td class="right">'.price($total_due).'</td>';
		if (!empty($arrayfields['status']['checked'])) print '<td></td>';
		print '<td></td></tr>';
	}
} else {
	lmdbsalescommissionsPrintNoRecordRow($langs, $visibleColumnCount);
}
print '</table>';

llxFooter();
$db->close();
