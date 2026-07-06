<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionpaymentterm.class.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionpaymenttermline.class.php', 0);

/**
 * Fetch payment term and verify entity scope.
 *
 * @param DoliDB $db Database handler
 * @param int    $id Payment term id
 * @return LmdbSalesCommissionPaymentTerm|null
 */
function lmdbsalescommissions_fetch_payment_term_for_admin($db, $id)
{
	if ($id <= 0) {
		return null;
	}

	$term = new LmdbSalesCommissionPaymentTerm($db);
	$result = $term->fetch($id);
	if ($result <= 0) {
		return null;
	}

	$allowed = array_map('intval', explode(',', getEntity($term->table_element)));
	if (!in_array((int) $term->entity, $allowed, true)) {
		return null;
	}

	return $term;
}

/**
 * Fetch payment term lines indexed by event type.
 *
 * @param DoliDB $db     Database handler
 * @param int    $termId Payment term id
 * @return array<string, float>
 */
function lmdbsalescommissions_fetch_payment_term_lines($db, $termId)
{
	$lines = array();
	if ($termId <= 0) {
		return $lines;
	}

	$sql = 'SELECT event_type, percentage';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term_line';
	$sql .= ' WHERE entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_payment_term_line')).')';
	$sql .= ' AND fk_payment_term = '.((int) $termId);
	$sql .= ' ORDER BY rang ASC';

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return $lines;
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		$lines[(string) $obj->event_type] = (float) $obj->percentage;
	}
	$db->free($resql);

	return $lines;
}

/**
 * Save payment term lines.
 *
 * @param DoliDB                    $db       Database handler
 * @param User                      $user     Current user
 * @param LmdbSalesCommissionPaymentTerm $term Payment term
 * @param array<string, float>      $lines    Event percentages
 * @return int
 */
function lmdbsalescommissions_save_payment_term_lines($db, $user, $term, array $lines)
{
	$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term_line';
	$sql .= ' WHERE entity = '.((int) $term->entity);
	$sql .= ' AND fk_payment_term = '.((int) $term->id);
	if (!$db->query($sql)) {
		return -1;
	}

	$rang = 0;
	foreach ($lines as $eventType => $percentage) {
		$rang++;
		$line = new LmdbSalesCommissionPaymentTermLine($db);
		$line->entity = (int) $term->entity;
		$line->fk_payment_term = (int) $term->id;
		$line->event_type = $eventType;
		$line->percentage = $percentage;
		$line->rang = $rang;
		$line->active = $percentage > 0 ? 1 : 0;
		$result = $line->create($user);
		if ($result <= 0) {
			return -1;
		}
	}

	return 1;
}

$langs->loadLangs(array('admin', 'lmdbsalescommissions@lmdbsalescommissions'));

$action = GETPOST('action', 'aZ09');
$mode = GETPOST('mode', 'aZ09');
$id = GETPOSTINT('id');

if (!isModEnabled('lmdbsalescommissions')) {
	accessforbidden();
}
if (!lmdbsalescommissionsCanConfigure($user)) {
	accessforbidden();
}

$form = new Form($db);
$object = $id > 0 ? lmdbsalescommissions_fetch_payment_term_for_admin($db, $id) : new LmdbSalesCommissionPaymentTerm($db);
if ($id > 0 && !is_object($object)) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}

$events = array(
	'proposal_signed' => $langs->trans('LmdbSalesCommissionsEventProposalSigned'),
	'deposit_paid' => $langs->trans('LmdbSalesCommissionsEventDepositPaid'),
	'final_invoice_paid' => $langs->trans('LmdbSalesCommissionsEventFinalInvoicePaid'),
);

if ($action === 'addpaymentterm' || $action === 'updatepaymentterm') {
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}

	$ref = trim(GETPOST('ref', 'alpha'));
	$label = trim(GETPOST('label', 'restricthtml'));
	$active = GETPOSTINT('active') ? 1 : 0;
	$is_default = GETPOSTINT('is_default') ? 1 : 0;
	$note_private = GETPOST('note_private', 'restricthtml');
	$lines = array();
	$total = 0.0;
	foreach ($events as $eventType => $eventLabel) {
		unset($eventLabel);
		$percentage = price2num(GETPOST('percentage_'.$eventType, 'alphanohtml'), 'MU');
		$lines[$eventType] = $percentage;
		$total += $percentage;
	}

	$errors = array();
	if ($ref === '') {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Ref'));
	}
	if ($label === '') {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Label'));
	}
	foreach ($lines as $percentage) {
		if ($percentage < 0) {
			$errors[] = $langs->trans('LmdbSalesCommissionsPaymentTermNegativePercentage');
			break;
		}
	}
	if (abs($total - 100.0) > 0.0001) {
		$errors[] = $langs->trans('LmdbSalesCommissionsPaymentTermTotalMustBe100');
	}

	if (empty($errors)) {
		$term = $action === 'updatepaymentterm' ? lmdbsalescommissions_fetch_payment_term_for_admin($db, $id) : new LmdbSalesCommissionPaymentTerm($db);
		if (!is_object($term)) {
			accessforbidden($langs->trans('ErrorRecordNotFound'));
		}

		$db->begin();
		$error = 0;

		$term->ref = $ref;
		$term->label = $label;
		$term->active = $active;
		$term->is_default = $is_default;
		$term->note_private = $note_private;

		$result = $action === 'updatepaymentterm' ? $term->update($user) : $term->create($user);
		if ($result <= 0) {
			$error++;
		} else {
			if (empty($term->id)) {
				$term->id = $result;
			}
			if ($is_default) {
				$sql = 'UPDATE '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term';
				$sql .= ' SET is_default = 0';
				$sql .= ' WHERE entity = '.((int) $term->entity);
				$sql .= ' AND rowid <> '.((int) $term->id);
				if (!$db->query($sql)) {
					$error++;
				}
			}

			if (!$error && lmdbsalescommissions_save_payment_term_lines($db, $user, $term, $lines) <= 0) {
				$error++;
			}
		}

		if (!$error) {
			$db->commit();
			setEventMessages($langs->trans($action === 'updatepaymentterm' ? 'RecordModifiedSuccessfully' : 'RecordCreatedSuccessfully'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}

		$db->rollback();
		setEventMessages($term->error ?: $db->lasterror(), $term->errors, 'errors');
	} else {
		setEventMessages('', $errors, 'errors');
		$mode = $action === 'updatepaymentterm' ? 'edit' : 'create';
	}
}

llxHeader('', $langs->trans('LmdbSalesCommissionsPaymentTerms'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());

$head = lmdbsalescommissionsAdminPrepareHead();
print dol_get_fiche_head($head, 'paymentterms', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent');
print load_fiche_titre($langs->trans('LmdbSalesCommissionsPaymentTerms'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');

print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?mode=create">'.$langs->trans('New').'</a>';
print '</div>';

if ($mode === 'create' || $mode === 'edit') {
	$term = is_object($object) ? $object : new LmdbSalesCommissionPaymentTerm($db);
	$formaction = $mode === 'edit' ? 'updatepaymentterm' : 'addpaymentterm';
	$lineValues = $mode === 'edit' ? lmdbsalescommissions_fetch_payment_term_lines($db, (int) $id) : array();

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="paymenttermform">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.$formaction.'">';
	if ($mode === 'edit') {
		print '<input type="hidden" name="id" value="'.((int) $id).'">';
	}

	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Ref').'</td><td><input class="minwidth300" type="text" name="ref" value="'.dol_escape_htmltag((string) $term->ref).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Label').'</td><td><input class="minwidth500" type="text" name="label" value="'.dol_escape_htmltag((string) $term->label).'"></td></tr>';
	print '<tr><td>'.$langs->trans('Active').'</td><td>'.$form->selectyesno('active', (int) ($term->active !== null ? $term->active : 1), 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('Default').'</td><td>'.$form->selectyesno('is_default', (int) ($term->is_default !== null ? $term->is_default : 0), 1).'</td></tr>';
	foreach ($events as $eventType => $eventLabel) {
		$value = $lineValues[$eventType] ?? 0;
		print '<tr><td>'.$eventLabel.'</td><td><input class="width75 right" type="text" name="percentage_'.$eventType.'" value="'.dol_escape_htmltag((string) $value).'"> %</td></tr>';
	}
	print '<tr><td>'.$langs->trans('NotePrivate').'</td><td><textarea class="quatrevingtpercent" name="note_private" rows="4">'.dol_escape_htmltag((string) $term->note_private).'</textarea></td></tr>';
	print '</table>';

	print '<div class="center">';
	print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
	print ' ';
	print '<a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Cancel').'</a>';
	print '</div>';
	print '</form>';
}

$sql = 'SELECT t.rowid, t.ref, t.label, t.active, t.is_default';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term AS t';
$sql .= ' WHERE t.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_payment_term')).')';
$sql .= ' ORDER BY t.is_default DESC, t.active DESC, t.label ASC';

$resql = $db->query($sql);
if (!$resql) {
	setEventMessages($db->lasterror(), null, 'errors');
} else {
	print '<br>';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('Ref').'</td>';
	print '<td>'.$langs->trans('Label').'</td>';
	print '<td>'.$langs->trans('LmdbSalesCommissionsPaymentDistribution').'</td>';
	print '<td class="center">'.$langs->trans('Default').'</td>';
	print '<td class="center">'.$langs->trans('Active').'</td>';
	print '<td class="right">'.$langs->trans('Action').'</td>';
	print '</tr>';

	$num = $db->num_rows($resql);
	if ($num === 0) {
		lmdbsalescommissionsPrintNoRecordRow($langs, 6);
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		$lineValues = lmdbsalescommissions_fetch_payment_term_lines($db, (int) $obj->rowid);
		$parts = array();
		foreach ($events as $eventType => $eventLabel) {
			$parts[] = $eventLabel.' '.price($lineValues[$eventType] ?? 0).' %';
		}

		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag((string) $obj->ref).'</td>';
		print '<td>'.dol_escape_htmltag((string) $obj->label).'</td>';
		print '<td>'.dol_escape_htmltag(implode(' / ', $parts)).'</td>';
		print '<td class="center">'.yn((int) $obj->is_default).'</td>';
		print '<td class="center">'.yn((int) $obj->active).'</td>';
		print '<td class="right"><a class="reposition" href="'.$_SERVER['PHP_SELF'].'?mode=edit&id='.((int) $obj->rowid).'">'.img_edit().'</a></td>';
		print '</tr>';
	}
	print '</table>';
	$db->free($resql);
}

print dol_get_fiche_end();
llxFooter();
$db->close();
