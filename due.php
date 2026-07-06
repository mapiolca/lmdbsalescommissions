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
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTINT('page');
if ($page < 0) {
	$page = 0;
}
$limit = getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 20);
$offset = $limit * $page;
if (empty($sortfield)) {
	$sortfield = 'd.date_due';
}
if (empty($sortorder)) {
	$sortorder = 'ASC';
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

$param = '';
if ($fk_user > 0) {
	$param .= '&fk_user='.((int) $fk_user);
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

$sql = 'SELECT d.rowid, d.event_type, d.percentage, d.amount, d.status, d.date_due, d.date_paid,';
$sql .= ' l.rowid AS line_id, l.fk_user, l.fk_soc, l.source_type, l.fk_source, l.source_ref, l.commission_total,';
$sql .= ' u.lastname, u.firstname, u.login, s.nom AS thirdparty_name';
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
if ($search_status !== '') {
	$sql .= ' AND d.status = '.((int) $search_status);
}
if ($search_event_type !== '') {
	$sql .= " AND d.event_type = '".$db->escape($search_event_type)."'";
}
if ($search_source_ref !== '') {
	$sql .= natural_search('l.source_ref', $search_source_ref);
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

llxHeader('', $langs->trans('LmdbSalesCommissionsDue'));

print load_fiche_titre($langs->trans('LmdbSalesCommissionsDue'), '', 'fa-percent');

print_barre_liste($langs->trans('LmdbSalesCommissionsDue'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $num, 'fa-percent', 0, '', '', $limit);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print_liste_field_titre('SalesRepresentative', $_SERVER['PHP_SELF'], 'u.lastname', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('ThirdParty', $_SERVER['PHP_SELF'], 's.nom', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('Source', $_SERVER['PHP_SELF'], 'l.source_ref', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('Event', $_SERVER['PHP_SELF'], 'd.event_type', $param, '', '', $sortfield, $sortorder);
print_liste_field_titre('LmdbSalesCommissionsCommissionTotal', $_SERVER['PHP_SELF'], 'l.commission_total', $param, '', 'class="right"', $sortfield, $sortorder);
print_liste_field_titre('Percentage', $_SERVER['PHP_SELF'], 'd.percentage', $param, '', 'class="right"', $sortfield, $sortorder);
print_liste_field_titre('Amount', $_SERVER['PHP_SELF'], 'd.amount', $param, '', 'class="right"', $sortfield, $sortorder);
print_liste_field_titre('Status', $_SERVER['PHP_SELF'], 'd.status', $param, '', 'class="center"', $sortfield, $sortorder);
print '<th class="center">'.$langs->trans('Action').'</th>';
print '</tr>';

print '<tr class="liste_titre_filter"><td colspan="9">';
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print $langs->trans('SalesRepresentative').' '.$form->selectarray('fk_user', lmdbsalescommissionsGetUserOptions($db), $fk_user, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150', 1).' ';
print $langs->trans('Source').' <input type="text" class="flat maxwidth100" name="search_source_ref" value="'.dol_escape_htmltag($search_source_ref).'"> ';
print $langs->trans('Event').' '.$form->selectarray('search_event_type', array('proposal_signed' => $langs->trans('LmdbSalesCommissionsEventProposalSigned'), 'deposit_paid' => $langs->trans('LmdbSalesCommissionsEventDepositPaid'), 'final_invoice_paid' => $langs->trans('LmdbSalesCommissionsEventFinalInvoicePaid')), $search_event_type, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150', 1).' ';
print $langs->trans('Status').' '.$form->selectarray('search_status', array('0' => $langs->trans('LmdbSalesCommissionsDueStatusWaiting'), '1' => $langs->trans('LmdbSalesCommissionsDueStatusDue')), $search_status, 1, 0, 0, '', 0, 0, 0, '', 'minwidth100', 1).' ';
print '<button type="submit" class="button small">'.$langs->trans('Search').'</button>';
print '</form>';
print '</td>';
print '</tr>';

$total_due = 0.0;
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
		$status = (int) $obj->status;
		$statusType = $status === LmdbSalesCommissionDueService::STATUS_DUE ? 1 : 0;
		$sourceUrl = lmdbsalescommissionsBuildSourceUrl((string) $obj->source_type, (int) $obj->fk_source);
		$total_due += (float) $obj->amount;

		print '<tr class="oddeven">';
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
		print '<td class="right">'.price((float) $obj->commission_total).'</td>';
		print '<td class="right">'.price((float) $obj->percentage).'%</td>';
		print '<td class="right">'.price((float) $obj->amount).'</td>';
		print '<td class="center">'.lmdbsalescommissionsStatusBadge(lmdbsalescommissionsGetDueStatusLabel($langs, $status), $statusType).'</td>';
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
		lmdbsalescommissionsPrintNoRecordRow($langs, 9);
	} else {
		print '<tr class="liste_total"><td colspan="6">'.$langs->trans('Total').'</td><td class="right">'.price($total_due).'</td><td colspan="2"></td></tr>';
	}
} else {
	lmdbsalescommissionsPrintNoRecordRow($langs, 9);
}
print '</table>';

llxFooter();
$db->close();
