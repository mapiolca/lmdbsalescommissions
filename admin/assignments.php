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
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionruleassignment.class.php', 0);

/**
 * Build timestamp from Dolibarr date selector POST fields.
 *
 * @param string $prefix Date input prefix
 * @return int
 */
function lmdbsalescommissions_assignment_get_post_date($prefix)
{
	$day = GETPOSTINT($prefix.'day');
	$month = GETPOSTINT($prefix.'month');
	$year = GETPOSTINT($prefix.'year');
	if ($day <= 0 || $month <= 0 || $year <= 0) {
		return 0;
	}

	return dol_mktime(0, 0, 0, $month, $day, $year);
}

/**
 * Fetch assignment and verify entity scope.
 *
 * @param DoliDB $db Database handler
 * @param int    $id Assignment id
 * @return LmdbSalesCommissionRuleAssignment|null
 */
function lmdbsalescommissions_fetch_assignment_for_admin($db, $id)
{
	if ($id <= 0) {
		return null;
	}

	$assignment = new LmdbSalesCommissionRuleAssignment($db);
	$result = $assignment->fetch($id);
	if ($result <= 0) {
		return null;
	}

	$allowed = array_map('intval', explode(',', getEntity($assignment->table_element)));
	if (!in_array((int) $assignment->entity, $allowed, true)) {
		return null;
	}

	return $assignment;
}

$langs->loadLangs(array('admin', 'users', 'lmdbsalescommissions@lmdbsalescommissions'));

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
$object = $id > 0 ? lmdbsalescommissions_fetch_assignment_for_admin($db, $id) : new LmdbSalesCommissionRuleAssignment($db);
if ($id > 0 && !is_object($object)) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}

$assignmentTypes = array(
	'user' => $langs->trans('User'),
	'group' => $langs->trans('Group'),
	'default' => $langs->trans('Default'),
);
$ruleOptions = lmdbsalescommissionsGetRuleOptions($db, true);
$userOptions = lmdbsalescommissionsGetUserOptions($db, true);
$groupOptions = lmdbsalescommissionsGetUserGroupOptions($db, true);
$paymentTermOptions = lmdbsalescommissionsGetPaymentTermOptions($db, true);

if ($action === 'addassignment' || $action === 'updateassignment') {
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}

	$assignment_type = GETPOST('assignment_type', 'aZ09');
	$fk_user = GETPOSTINT('fk_user');
	$fk_usergroup = GETPOSTINT('fk_usergroup');
	$fk_rule = GETPOSTINT('fk_rule');
	$date_start = lmdbsalescommissions_assignment_get_post_date('date_start');
	$date_end = lmdbsalescommissions_assignment_get_post_date('date_end');
	$active = GETPOSTINT('active') ? 1 : 0;
	$cumulative = GETPOSTINT('cumulative') ? 1 : 0;
	$priority = GETPOSTINT('priority');
	$fk_payment_term = GETPOSTINT('fk_payment_term');
	$note_private = GETPOST('note_private', 'restricthtml');

	$errors = array();
	if (!array_key_exists($assignment_type, $assignmentTypes)) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Type'));
	}
	if ($assignment_type === 'user' && $fk_user <= 0) {
		$errors[] = $langs->trans('LmdbSalesCommissionsAssignmentUserRequired');
	}
	if ($assignment_type === 'group' && $fk_usergroup <= 0) {
		$errors[] = $langs->trans('LmdbSalesCommissionsAssignmentGroupRequired');
	}
	if ($assignment_type === 'default') {
		$fk_user = 0;
		$fk_usergroup = 0;
	}
	if ($fk_rule <= 0) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('LmdbSalesCommissionsRule'));
	}
	if ($date_start > 0 && $date_end > 0 && $date_end < $date_start) {
		$errors[] = $langs->trans('ErrorDateEndLowerThanDateStart');
	}

	if (empty($errors)) {
		$assignment = $action === 'updateassignment' ? lmdbsalescommissions_fetch_assignment_for_admin($db, $id) : new LmdbSalesCommissionRuleAssignment($db);
		if (!is_object($assignment)) {
			accessforbidden($langs->trans('ErrorRecordNotFound'));
		}

		$assignment->assignment_type = $assignment_type;
		$assignment->fk_user = $assignment_type === 'user' ? $fk_user : null;
		$assignment->fk_usergroup = $assignment_type === 'group' ? $fk_usergroup : null;
		$assignment->fk_rule = $fk_rule;
		$assignment->date_start = $date_start > 0 ? $date_start : null;
		$assignment->date_end = $date_end > 0 ? $date_end : null;
		$assignment->active = $active;
		$assignment->cumulative = $cumulative;
		$assignment->priority = $priority;
		$assignment->fk_payment_term = $fk_payment_term > 0 ? $fk_payment_term : null;
		$assignment->note_private = $note_private;

		$result = $action === 'updateassignment' ? $assignment->update($user) : $assignment->create($user);
		if ($result > 0) {
			setEventMessages($langs->trans($action === 'updateassignment' ? 'RecordModifiedSuccessfully' : 'RecordCreatedSuccessfully'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}

		setEventMessages($assignment->error, $assignment->errors, 'errors');
	} else {
		setEventMessages('', $errors, 'errors');
		$mode = $action === 'updateassignment' ? 'edit' : 'create';
	}
}

llxHeader('', $langs->trans('LmdbSalesCommissionsAssignments'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());

$head = lmdbsalescommissionsAdminPrepareHead();
print dol_get_fiche_head($head, 'assignments', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent');
print load_fiche_titre($langs->trans('LmdbSalesCommissionsAssignments'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');

print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?mode=create">'.$langs->trans('New').'</a>';
print '</div>';

if ($mode === 'create' || $mode === 'edit') {
	$assignment = is_object($object) ? $object : new LmdbSalesCommissionRuleAssignment($db);
	$formaction = $mode === 'edit' ? 'updateassignment' : 'addassignment';

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="assignmentform">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.$formaction.'">';
	if ($mode === 'edit') {
		print '<input type="hidden" name="id" value="'.((int) $id).'">';
	}

	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Type').'</td><td>'.$form->selectarray('assignment_type', $assignmentTypes, (string) ($assignment->assignment_type ?: 'default'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('User').'</td><td>'.$form->selectarray('fk_user', $userOptions, (int) $assignment->fk_user, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('Group').'</td><td>'.$form->selectarray('fk_usergroup', $groupOptions, (int) $assignment->fk_usergroup, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('LmdbSalesCommissionsRule').'</td><td>'.$form->selectarray('fk_rule', $ruleOptions, (int) $assignment->fk_rule, 0, 0, 0, '', 0, 0, 0, '', 'minwidth500').'</td></tr>';
	print '<tr><td>'.$langs->trans('DateStart').'</td><td>'.$form->selectDate($assignment->date_start, 'date_start', 0, 0, 1, 'assignmentform', 1, 0).'</td></tr>';
	print '<tr><td>'.$langs->trans('DateEnd').'</td><td>'.$form->selectDate($assignment->date_end, 'date_end', 0, 0, 1, 'assignmentform', 1, 0).'</td></tr>';
	print '<tr><td>'.$langs->trans('Active').'</td><td>'.$form->selectyesno('active', (int) ($assignment->active !== null ? $assignment->active : 1), 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('Cumulative').'</td><td>'.$form->selectyesno('cumulative', (int) ($assignment->cumulative !== null ? $assignment->cumulative : 1), 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('Priority').'</td><td><input class="width75 right" type="text" name="priority" value="'.dol_escape_htmltag((string) ($assignment->priority ?? 0)).'"></td></tr>';
	print '<tr><td>'.$langs->trans('LmdbSalesCommissionsPaymentTerms').'</td><td>'.$form->selectarray('fk_payment_term', $paymentTermOptions, (int) $assignment->fk_payment_term, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('NotePrivate').'</td><td><textarea class="quatrevingtpercent" name="note_private" rows="4">'.dol_escape_htmltag((string) $assignment->note_private).'</textarea></td></tr>';
	print '</table>';

	print '<div class="center">';
	print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
	print ' ';
	print '<a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Cancel').'</a>';
	print '</div>';
	print '</form>';

	if (function_exists('ajax_combobox')) {
		print ajax_combobox('assignment_type');
		print ajax_combobox('fk_user');
		print ajax_combobox('fk_usergroup');
		print ajax_combobox('fk_rule');
		print ajax_combobox('fk_payment_term');
	}
}

$sql = 'SELECT a.rowid, a.assignment_type, a.fk_user, a.fk_usergroup, a.fk_rule, a.date_start, a.date_end, a.active, a.cumulative, a.priority,';
$sql .= ' u.login, u.lastname, u.firstname, g.nom AS group_name, r.ref AS rule_ref, r.label AS rule_label, p.ref AS payment_ref, p.label AS payment_label';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule_assignment AS a';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = a.fk_user';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'usergroup AS g ON g.rowid = a.fk_usergroup';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule AS r ON r.rowid = a.fk_rule AND r.entity = a.entity';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term AS p ON p.rowid = a.fk_payment_term AND p.entity = a.entity';
$sql .= ' WHERE a.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_rule_assignment')).')';
$sql .= ' ORDER BY a.active DESC, a.priority DESC, a.assignment_type ASC';

$resql = $db->query($sql);
if (!$resql) {
	setEventMessages($db->lasterror(), null, 'errors');
} else {
	print '<br>';
	print '<table class="noborder liste centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('Type').'</td>';
	print '<td>'.$langs->trans('Target').'</td>';
	print '<td>'.$langs->trans('LmdbSalesCommissionsRule').'</td>';
	print '<td>'.$langs->trans('DateStart').'</td>';
	print '<td>'.$langs->trans('DateEnd').'</td>';
	print '<td class="center">'.$langs->trans('Cumulative').'</td>';
	print '<td class="right">'.$langs->trans('Priority').'</td>';
	print '<td>'.$langs->trans('LmdbSalesCommissionsPaymentTerms').'</td>';
	print '<td class="center">'.$langs->trans('Active').'</td>';
	print '<td class="right">'.$langs->trans('Action').'</td>';
	print '</tr>';

	$num = $db->num_rows($resql);
	if ($num === 0) {
		lmdbsalescommissionsPrintNoRecordRow($langs, 10);
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		$target = $langs->trans('Default');
		if ((string) $obj->assignment_type === 'user') {
			$name = trim((string) $obj->firstname.' '.(string) $obj->lastname);
			$target = $name !== '' ? $name : (string) $obj->login;
		} elseif ((string) $obj->assignment_type === 'group') {
			$target = (string) $obj->group_name;
		}
		$ruleLabel = trim((string) $obj->rule_ref.' - '.(string) $obj->rule_label);
		$paymentLabel = trim((string) $obj->payment_ref.' - '.(string) $obj->payment_label);

		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($assignmentTypes[(string) $obj->assignment_type] ?? (string) $obj->assignment_type).'</td>';
		print '<td>'.dol_escape_htmltag($target).'</td>';
		print '<td>'.dol_escape_htmltag($ruleLabel).'</td>';
		print '<td>'.dol_print_date($db->jdate($obj->date_start), 'day').'</td>';
		print '<td>'.dol_print_date($db->jdate($obj->date_end), 'day').'</td>';
		print '<td class="center">'.yn((int) $obj->cumulative).'</td>';
		print '<td class="right">'.((int) $obj->priority).'</td>';
		print '<td>'.dol_escape_htmltag($paymentLabel).'</td>';
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
