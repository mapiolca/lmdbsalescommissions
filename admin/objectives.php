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
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionobjective.class.php', 0);

/**
 * Build timestamp from Dolibarr date selector POST fields.
 *
 * @param string $prefix Date input prefix
 * @return int
 */
function lmdbsalescommissions_objective_get_post_date($prefix)
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
 * Fetch objective and verify entity scope.
 *
 * @param DoliDB $db Database handler
 * @param int    $id Objective id
 * @return LmdbSalesCommissionObjective|null
 */
function lmdbsalescommissions_fetch_objective_for_admin($db, $id)
{
	if ($id <= 0) {
		return null;
	}

	$objective = new LmdbSalesCommissionObjective($db);
	$result = $objective->fetch($id);
	if ($result <= 0) {
		return null;
	}

	$allowed = array_map('intval', explode(',', getEntity($objective->table_element)));
	if (!in_array((int) $objective->entity, $allowed, true)) {
		return null;
	}

	return $objective;
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
$object = $id > 0 ? lmdbsalescommissions_fetch_objective_for_admin($db, $id) : new LmdbSalesCommissionObjective($db);
if ($id > 0 && !is_object($object)) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}

$assignmentTypes = array(
	'user' => $langs->trans('User'),
	'group' => $langs->trans('Group'),
	'default' => $langs->trans('Default'),
);
$objectiveTypes = array(
	'monthly' => $langs->trans('Month'),
	'yearly' => $langs->trans('Year'),
);
$baseTypes = array(
	'signed_turnover' => $langs->trans('LmdbSalesCommissionsObjectiveBaseSignedTurnover'),
	'signed_margin' => $langs->trans('LmdbSalesCommissionsObjectiveBaseSignedMargin'),
	'signed_deals_count' => $langs->trans('LmdbSalesCommissionsObjectiveBaseSignedDealsCount'),
);
$userOptions = lmdbsalescommissionsGetUserOptions($db, true);
$groupOptions = lmdbsalescommissionsGetUserGroupOptions($db, true);

if ($action === 'addobjective' || $action === 'updateobjective') {
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}

	$assignment_type = GETPOST('assignment_type', 'aZ09');
	$fk_user = GETPOSTINT('fk_user');
	$fk_usergroup = GETPOSTINT('fk_usergroup');
	$objective_type = GETPOST('objective_type', 'aZ09');
	$year = GETPOSTINT('year');
	$month = GETPOSTINT('month');
	$base_type = GETPOST('base_type', 'aZ09');
	$target_value = price2num(GETPOST('target_value', 'alphanohtml'), 'MU');
	$active = GETPOSTINT('active') ? 1 : 0;
	$date_start = lmdbsalescommissions_objective_get_post_date('date_start');
	$date_end = lmdbsalescommissions_objective_get_post_date('date_end');
	$priority = GETPOSTINT('priority');
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
	if (!array_key_exists($objective_type, $objectiveTypes)) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('LmdbSalesCommissionsObjectiveType'));
	}
	if ($year <= 0) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Year'));
	}
	if ($objective_type === 'monthly' && ($month < 1 || $month > 12)) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Month'));
	}
	if (!array_key_exists($base_type, $baseTypes)) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('LmdbSalesCommissionsObjectiveBase'));
	}
	if ($target_value < 0) {
		$errors[] = $langs->trans('LmdbSalesCommissionsObjectiveTargetMustNotBeNegative');
	}
	if ($date_start > 0 && $date_end > 0 && $date_end < $date_start) {
		$errors[] = $langs->trans('ErrorDateEndLowerThanDateStart');
	}

	if (empty($errors)) {
		$objective = $action === 'updateobjective' ? lmdbsalescommissions_fetch_objective_for_admin($db, $id) : new LmdbSalesCommissionObjective($db);
		if (!is_object($objective)) {
			accessforbidden($langs->trans('ErrorRecordNotFound'));
		}

		$objective->assignment_type = $assignment_type;
		$objective->fk_user = $assignment_type === 'user' ? $fk_user : null;
		$objective->fk_usergroup = $assignment_type === 'group' ? $fk_usergroup : null;
		$objective->objective_type = $objective_type;
		$objective->year = $year;
		$objective->month = $objective_type === 'monthly' ? $month : null;
		$objective->base_type = $base_type;
		$objective->target_value = $target_value;
		$objective->active = $active;
		$objective->date_start = $date_start > 0 ? $date_start : null;
		$objective->date_end = $date_end > 0 ? $date_end : null;
		$objective->priority = $priority;
		$objective->note_private = $note_private;

		$result = $action === 'updateobjective' ? $objective->update($user) : $objective->create($user);
		if ($result > 0) {
			setEventMessages($langs->trans($action === 'updateobjective' ? 'RecordModifiedSuccessfully' : 'RecordCreatedSuccessfully'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}

		setEventMessages($objective->error, $objective->errors, 'errors');
	} else {
		setEventMessages('', $errors, 'errors');
		$mode = $action === 'updateobjective' ? 'edit' : 'create';
	}
}

llxHeader('', $langs->trans('LmdbSalesCommissionsObjectives'));

$head = lmdbsalescommissionsAdminPrepareHead();
print dol_get_fiche_head($head, 'objectives', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent');
print load_fiche_titre($langs->trans('LmdbSalesCommissionsObjectives'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');

print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?mode=create">'.$langs->trans('New').'</a>';
print '</div>';

if ($mode === 'create' || $mode === 'edit') {
	$objective = is_object($object) ? $object : new LmdbSalesCommissionObjective($db);
	$formaction = $mode === 'edit' ? 'updateobjective' : 'addobjective';

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="objectiveform">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.$formaction.'">';
	if ($mode === 'edit') {
		print '<input type="hidden" name="id" value="'.((int) $id).'">';
	}

	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Type').'</td><td>'.$form->selectarray('assignment_type', $assignmentTypes, (string) ($objective->assignment_type ?: 'default'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('User').'</td><td>'.$form->selectarray('fk_user', $userOptions, (int) $objective->fk_user, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('Group').'</td><td>'.$form->selectarray('fk_usergroup', $groupOptions, (int) $objective->fk_usergroup, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('LmdbSalesCommissionsObjectiveType').'</td><td>'.$form->selectarray('objective_type', $objectiveTypes, (string) ($objective->objective_type ?: 'monthly'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Year').'</td><td><input class="width75 right" type="text" name="year" value="'.dol_escape_htmltag((string) ($objective->year ?: (int) dol_print_date(dol_now(), '%Y'))).'"></td></tr>';
	print '<tr><td>'.$langs->trans('Month').'</td><td><input class="width75 right" type="text" name="month" value="'.dol_escape_htmltag((string) $objective->month).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('LmdbSalesCommissionsObjectiveBase').'</td><td>'.$form->selectarray('base_type', $baseTypes, (string) ($objective->base_type ?: 'signed_turnover'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('LmdbSalesCommissionsTargetValue').'</td><td><input class="width100 right" type="text" name="target_value" value="'.dol_escape_htmltag((string) ($objective->target_value ?? 0)).'"></td></tr>';
	print '<tr><td>'.$langs->trans('Active').'</td><td>'.$form->selectyesno('active', (int) ($objective->active !== null ? $objective->active : 1), 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('DateStart').'</td><td>'.$form->selectDate($objective->date_start, 'date_start', 0, 0, 1, 'objectiveform', 1, 0).'</td></tr>';
	print '<tr><td>'.$langs->trans('DateEnd').'</td><td>'.$form->selectDate($objective->date_end, 'date_end', 0, 0, 1, 'objectiveform', 1, 0).'</td></tr>';
	print '<tr><td>'.$langs->trans('Priority').'</td><td><input class="width75 right" type="text" name="priority" value="'.dol_escape_htmltag((string) ($objective->priority ?? 0)).'"></td></tr>';
	print '<tr><td>'.$langs->trans('NotePrivate').'</td><td><textarea class="quatrevingtpercent" name="note_private" rows="4">'.dol_escape_htmltag((string) $objective->note_private).'</textarea></td></tr>';
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
		print ajax_combobox('objective_type');
		print ajax_combobox('base_type');
	}
}

$sql = 'SELECT o.rowid, o.assignment_type, o.fk_user, o.fk_usergroup, o.objective_type, o.year, o.month, o.base_type, o.target_value, o.active, o.priority,';
$sql .= ' u.login, u.lastname, u.firstname, g.nom AS group_name';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective AS o';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = o.fk_user';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'usergroup AS g ON g.rowid = o.fk_usergroup';
$sql .= ' WHERE o.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_objective')).')';
$sql .= ' ORDER BY o.active DESC, o.year DESC, o.month DESC, o.priority DESC';

$resql = $db->query($sql);
if (!$resql) {
	setEventMessages($db->lasterror(), null, 'errors');
} else {
	print '<br>';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('Type').'</td>';
	print '<td>'.$langs->trans('Target').'</td>';
	print '<td>'.$langs->trans('LmdbSalesCommissionsObjectiveType').'</td>';
	print '<td>'.$langs->trans('Period').'</td>';
	print '<td>'.$langs->trans('LmdbSalesCommissionsObjectiveBase').'</td>';
	print '<td class="right">'.$langs->trans('LmdbSalesCommissionsTargetValue').'</td>';
	print '<td class="right">'.$langs->trans('Priority').'</td>';
	print '<td class="center">'.$langs->trans('Active').'</td>';
	print '<td class="right">'.$langs->trans('Action').'</td>';
	print '</tr>';

	$num = $db->num_rows($resql);
	if ($num === 0) {
		lmdbsalescommissionsPrintNoRecordRow($langs, 9);
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		$target = $langs->trans('Default');
		if ((string) $obj->assignment_type === 'user') {
			$name = trim((string) $obj->firstname.' '.(string) $obj->lastname);
			$target = $name !== '' ? $name : (string) $obj->login;
		} elseif ((string) $obj->assignment_type === 'group') {
			$target = (string) $obj->group_name;
		}
		$period = (string) $obj->year;
		if ((string) $obj->objective_type === 'monthly') {
			$period .= '-'.sprintf('%02d', (int) $obj->month);
		}

		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($assignmentTypes[(string) $obj->assignment_type] ?? (string) $obj->assignment_type).'</td>';
		print '<td>'.dol_escape_htmltag($target).'</td>';
		print '<td>'.dol_escape_htmltag($objectiveTypes[(string) $obj->objective_type] ?? (string) $obj->objective_type).'</td>';
		print '<td>'.dol_escape_htmltag($period).'</td>';
		print '<td>'.dol_escape_htmltag($baseTypes[(string) $obj->base_type] ?? (string) $obj->base_type).'</td>';
		print '<td class="right">'.price((float) $obj->target_value).'</td>';
		print '<td class="right">'.((int) $obj->priority).'</td>';
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
