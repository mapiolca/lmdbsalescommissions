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
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissiontiergrid.class.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissiontier.class.php', 0);

/**
 * Fetch grid and verify entity scope.
 *
 * @param DoliDB $db Database handler
 * @param int    $id Grid id
 * @return LmdbSalesCommissionTierGrid|null
 */
function lmdbsalescommissions_fetch_tier_grid_for_admin($db, $id)
{
	if ($id <= 0) {
		return null;
	}

	$grid = new LmdbSalesCommissionTierGrid($db);
	$result = $grid->fetch($id);
	if ($result <= 0) {
		return null;
	}

	$allowed = array_map('intval', explode(',', getEntity($grid->table_element)));
	if (!in_array((int) $grid->entity, $allowed, true)) {
		return null;
	}

	return $grid;
}

/**
 * Fetch tier lines.
 *
 * @param DoliDB $db     Database handler
 * @param int    $gridId Grid id
 * @return array<int, array{threshold_amount:float, bonus_amount:float, active:int}>
 */
function lmdbsalescommissions_fetch_tiers($db, $gridId)
{
	$tiers = array();
	if ($gridId <= 0) {
		return $tiers;
	}

	$sql = 'SELECT threshold_amount, bonus_amount, active';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_tier';
	$sql .= ' WHERE entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_tier')).')';
	$sql .= ' AND fk_tier_grid = '.((int) $gridId);
	$sql .= ' ORDER BY threshold_amount ASC, rang ASC';

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return $tiers;
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		$tiers[] = array(
			'threshold_amount' => (float) $obj->threshold_amount,
			'bonus_amount' => (float) $obj->bonus_amount,
			'active' => (int) $obj->active,
		);
	}
	$db->free($resql);

	return $tiers;
}

/**
 * Save tier lines for a grid.
 *
 * @param DoliDB                       $db    Database handler
 * @param User                         $user  Current user
 * @param LmdbSalesCommissionTierGrid  $grid  Grid
 * @param array<int, array{threshold_amount:float, bonus_amount:float, active:int}> $tiers Tier lines
 * @return int
 */
function lmdbsalescommissions_save_tiers($db, $user, $grid, array $tiers)
{
	$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_tier';
	$sql .= ' WHERE entity = '.((int) $grid->entity);
	$sql .= ' AND fk_tier_grid = '.((int) $grid->id);
	if (!$db->query($sql)) {
		return -1;
	}

	$rang = 0;
	foreach ($tiers as $tierData) {
		$rang++;
		$tier = new LmdbSalesCommissionTier($db);
		$tier->entity = (int) $grid->entity;
		$tier->fk_tier_grid = (int) $grid->id;
		$tier->threshold_amount = $tierData['threshold_amount'];
		$tier->bonus_amount = $tierData['bonus_amount'];
		$tier->rang = $rang;
		$tier->active = $tierData['active'];
		$result = $tier->create($user);
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
$object = $id > 0 ? lmdbsalescommissions_fetch_tier_grid_for_admin($db, $id) : new LmdbSalesCommissionTierGrid($db);
if ($id > 0 && !is_object($object)) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}

$periodtypes = array(
	'monthly' => $langs->trans('Month'),
	'quarterly' => $langs->trans('Quarter'),
	'yearly' => $langs->trans('Year'),
);

if ($action === 'addtiergrid' || $action === 'updatetiergrid') {
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}

	$ref = trim(GETPOST('ref', 'alpha'));
	$label = trim(GETPOST('label', 'restricthtml'));
	$period_type = GETPOST('period_type', 'aZ09');
	$active = GETPOSTINT('active') ? 1 : 0;
	$note_private = GETPOST('note_private', 'restricthtml');
	$tiers = array();
	$thresholds = array();
	$lastThreshold = null;
	$errors = array();

	for ($i = 0; $i < 10; $i++) {
		$threshold = price2num(GETPOST('threshold_'.$i, 'alphanohtml'), 'MU');
		$bonus = price2num(GETPOST('bonus_'.$i, 'alphanohtml'), 'MU');
		$tierActive = GETPOSTINT('tier_active_'.$i) ? 1 : 0;

		if ($threshold == 0 && $bonus == 0) {
			continue;
		}
		if ($threshold <= 0) {
			$errors[] = $langs->trans('LmdbSalesCommissionsTierThresholdMustBePositive');
			continue;
		}
		if ($bonus < 0) {
			$errors[] = $langs->trans('LmdbSalesCommissionsTierBonusMustNotBeNegative');
			continue;
		}
		if (isset($thresholds[(string) $threshold])) {
			$errors[] = $langs->trans('LmdbSalesCommissionsTierDuplicateThreshold');
			continue;
		}
		if ($lastThreshold !== null && $threshold <= $lastThreshold) {
			$errors[] = $langs->trans('LmdbSalesCommissionsTierThresholdsMustBeOrdered');
			continue;
		}
		$thresholds[(string) $threshold] = true;
		$lastThreshold = $threshold;
		$tiers[] = array(
			'threshold_amount' => $threshold,
			'bonus_amount' => $bonus,
			'active' => $tierActive,
		);
	}

	if ($ref === '') {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Ref'));
	}
	if ($label === '') {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Label'));
	}
	if (!array_key_exists($period_type, $periodtypes)) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Period'));
	}
	if (empty($tiers)) {
		$errors[] = $langs->trans('LmdbSalesCommissionsTierGridMustHaveTier');
	}

	if (empty($errors)) {
		$grid = $action === 'updatetiergrid' ? lmdbsalescommissions_fetch_tier_grid_for_admin($db, $id) : new LmdbSalesCommissionTierGrid($db);
		if (!is_object($grid)) {
			accessforbidden($langs->trans('ErrorRecordNotFound'));
		}

		$db->begin();
		$error = 0;

		$grid->ref = $ref;
		$grid->label = $label;
		$grid->period_type = $period_type;
		$grid->active = $active;
		$grid->note_private = $note_private;

		$result = $action === 'updatetiergrid' ? $grid->update($user) : $grid->create($user);
		if ($result <= 0) {
			$error++;
		} else {
			if (empty($grid->id)) {
				$grid->id = $result;
			}
			if (lmdbsalescommissions_save_tiers($db, $user, $grid, $tiers) <= 0) {
				$error++;
			}
		}

		if (!$error) {
			$db->commit();
			setEventMessages($langs->trans($action === 'updatetiergrid' ? 'RecordModifiedSuccessfully' : 'RecordCreatedSuccessfully'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}

		$db->rollback();
		setEventMessages($grid->error ?: $db->lasterror(), $grid->errors, 'errors');
	} else {
		setEventMessages('', $errors, 'errors');
		$mode = $action === 'updatetiergrid' ? 'edit' : 'create';
	}
}

llxHeader('', $langs->trans('LmdbSalesCommissionsTierGrids'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());

$head = lmdbsalescommissionsAdminPrepareHead();
print dol_get_fiche_head($head, 'tiergrids', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent');
print load_fiche_titre($langs->trans('LmdbSalesCommissionsTierGrids'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');

print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?mode=create">'.$langs->trans('New').'</a>';
print '</div>';

if ($mode === 'create' || $mode === 'edit') {
	$grid = is_object($object) ? $object : new LmdbSalesCommissionTierGrid($db);
	$formaction = $mode === 'edit' ? 'updatetiergrid' : 'addtiergrid';
	$tierValues = $mode === 'edit' ? lmdbsalescommissions_fetch_tiers($db, (int) $id) : array();
	for ($i = count($tierValues); $i < 5; $i++) {
		$tierValues[] = array('threshold_amount' => 0.0, 'bonus_amount' => 0.0, 'active' => 1);
	}

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="tiergridform">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.$formaction.'">';
	if ($mode === 'edit') {
		print '<input type="hidden" name="id" value="'.((int) $id).'">';
	}

	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Ref').'</td><td><input class="minwidth300" type="text" name="ref" value="'.dol_escape_htmltag((string) $grid->ref).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Label').'</td><td><input class="minwidth500" type="text" name="label" value="'.dol_escape_htmltag((string) $grid->label).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Period').'</td><td>'.$form->selectarray('period_type', $periodtypes, (string) ($grid->period_type ?: 'monthly'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('Active').'</td><td>'.$form->selectyesno('active', (int) ($grid->active !== null ? $grid->active : 1), 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('NotePrivate').'</td><td><textarea class="quatrevingtpercent" name="note_private" rows="3">'.dol_escape_htmltag((string) $grid->note_private).'</textarea></td></tr>';
	print '</table>';

	print '<br>';
	print '<table class="noborder liste centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('LmdbSalesCommissionsThresholdAmount').'</td><td>'.$langs->trans('LmdbSalesCommissionsBonusAmount').'</td><td class="center">'.$langs->trans('Active').'</td></tr>';
	foreach ($tierValues as $i => $tierData) {
		print '<tr class="oddeven">';
		print '<td><input class="width100 right" type="text" name="threshold_'.$i.'" value="'.dol_escape_htmltag((string) $tierData['threshold_amount']).'"></td>';
		print '<td><input class="width100 right" type="text" name="bonus_'.$i.'" value="'.dol_escape_htmltag((string) $tierData['bonus_amount']).'"></td>';
		print '<td class="center">'.$form->selectyesno('tier_active_'.$i, (int) $tierData['active'], 1).'</td>';
		print '</tr>';
	}
	print '</table>';

	print '<div class="center">';
	print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
	print ' ';
	print '<a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Cancel').'</a>';
	print '</div>';
	print '</form>';

	if (function_exists('ajax_combobox')) {
		print ajax_combobox('period_type');
	}
}

$sql = 'SELECT g.rowid, g.ref, g.label, g.period_type, g.active, COUNT(t.rowid) AS nb_tiers';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_tier_grid AS g';
$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_tier AS t ON t.fk_tier_grid = g.rowid AND t.entity = g.entity';
$sql .= ' WHERE g.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_tier_grid')).')';
$sql .= ' GROUP BY g.rowid, g.ref, g.label, g.period_type, g.active';
$sql .= ' ORDER BY g.active DESC, g.label ASC';

$resql = $db->query($sql);
if (!$resql) {
	setEventMessages($db->lasterror(), null, 'errors');
} else {
	print '<br>';
	print '<table class="noborder liste centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('Ref').'</td>';
	print '<td>'.$langs->trans('Label').'</td>';
	print '<td>'.$langs->trans('Period').'</td>';
	print '<td class="right">'.$langs->trans('LmdbSalesCommissionsTierCount').'</td>';
	print '<td class="center">'.$langs->trans('Active').'</td>';
	print '<td class="right">'.$langs->trans('Action').'</td>';
	print '</tr>';

	$num = $db->num_rows($resql);
	if ($num === 0) {
		lmdbsalescommissionsPrintNoRecordRow($langs, 6);
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag((string) $obj->ref).'</td>';
		print '<td>'.dol_escape_htmltag((string) $obj->label).'</td>';
		print '<td>'.dol_escape_htmltag($periodtypes[(string) $obj->period_type] ?? (string) $obj->period_type).'</td>';
		print '<td class="right">'.((int) $obj->nb_tiers).'</td>';
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
