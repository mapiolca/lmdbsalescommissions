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
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionrule.class.php', 0);

/**
 * Build timestamp from Dolibarr date selector POST fields.
 *
 * @param string $prefix Date input prefix
 * @return int
 */
function lmdbsalescommissions_get_post_date($prefix)
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
 * Fetch a rule and verify entity scope.
 *
 * @param DoliDB $db Database handler
 * @param int    $id Rule id
 * @return LmdbSalesCommissionRule|null
 */
function lmdbsalescommissions_fetch_rule_for_admin($db, $id)
{
	if ($id <= 0) {
		return null;
	}

	$rule = new LmdbSalesCommissionRule($db);
	$result = $rule->fetch($id);
	if ($result <= 0) {
		return null;
	}

	if (function_exists('getEntity')) {
		$allowed = array_map('intval', explode(',', getEntity($rule->table_element)));
		if (!in_array((int) $rule->entity, $allowed, true)) {
			return null;
		}
	}

	return $rule;
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
$object = $id > 0 ? lmdbsalescommissions_fetch_rule_for_admin($db, $id) : new LmdbSalesCommissionRule($db);
if ($id > 0 && !is_object($object)) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}

$ruletypes = array(
	'margin' => $langs->trans('LmdbSalesCommissionsRuleTypeMargin'),
	'tier' => $langs->trans('LmdbSalesCommissionsRuleTypeTier'),
);
$sourcetypes = array(
	'proposal' => $langs->trans('Proposal'),
	'order' => $langs->trans('Order'),
	'contract' => $langs->trans('Contract'),
);
$periodtypes = array(
	'monthly' => $langs->trans('Month'),
	'quarterly' => $langs->trans('Quarter'),
	'yearly' => $langs->trans('Year'),
);
$negativeMarginModes = array(
	'zero' => $langs->trans('LmdbSalesCommissionsNegativeMarginZero'),
);
$tierGridOptions = lmdbsalescommissionsGetTierGridOptions($db, true);
$paymentTermOptions = lmdbsalescommissionsGetPaymentTermOptions($db, true);

if ($action === 'addrule' || $action === 'updaterule') {
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}

	$ref = trim(GETPOST('ref', 'alpha'));
	$label = trim(GETPOST('label', 'restricthtml'));
	$rule_type = GETPOST('rule_type', 'aZ09');
	$source_type = GETPOST('source_type', 'aZ09');
	$period_type = GETPOST('period_type', 'aZ09');
	$negative_margin_mode = GETPOST('negative_margin_mode', 'aZ09');
	$rate = price2num(GETPOST('rate', 'alphanohtml'), 'MU');
	$fk_tier_grid = GETPOSTINT('fk_tier_grid');
	$fk_payment_term = GETPOSTINT('fk_payment_term');
	$cumulative = GETPOSTINT('cumulative') ? 1 : 0;
	$priority = GETPOSTINT('priority');
	$active = GETPOSTINT('active') ? 1 : 0;
	$date_start = lmdbsalescommissions_get_post_date('date_start');
	$date_end = lmdbsalescommissions_get_post_date('date_end');
	$description = GETPOST('description', 'restricthtml');

	$errors = array();
	if ($ref === '') {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Ref'));
	}
	if ($label === '') {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Label'));
	}
	if (!array_key_exists($rule_type, $ruletypes)) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Type'));
	}
	if (!array_key_exists($source_type, $sourcetypes)) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Source'));
	}
	if (!array_key_exists($period_type, $periodtypes)) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('Period'));
	}
	if (!array_key_exists($negative_margin_mode, $negativeMarginModes)) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('LmdbSalesCommissionsNegativeMarginMode'));
	}
	if ($rule_type === 'margin' && $rate <= 0) {
		$errors[] = $langs->trans('LmdbSalesCommissionsRateRequiredForMarginRule');
	}
	if ($rule_type === 'tier' && $fk_tier_grid <= 0) {
		$errors[] = $langs->trans('LmdbSalesCommissionsTierGridRequiredForTierRule');
	}
	if ($date_start > 0 && $date_end > 0 && $date_end < $date_start) {
		$errors[] = $langs->trans('ErrorDateEndLowerThanDateStart');
	}

	if (empty($errors)) {
		$rule = $action === 'updaterule' ? lmdbsalescommissions_fetch_rule_for_admin($db, $id) : new LmdbSalesCommissionRule($db);
		if (!is_object($rule)) {
			accessforbidden($langs->trans('ErrorRecordNotFound'));
		}

		$rule->ref = $ref;
		$rule->label = $label;
		$rule->rule_type = $rule_type;
		$rule->rate = $rule_type === 'margin' ? $rate : null;
		$rule->fk_tier_grid = $rule_type === 'tier' ? $fk_tier_grid : null;
		$rule->fk_payment_term = $fk_payment_term > 0 ? $fk_payment_term : null;
		$rule->source_type = $source_type;
		$rule->period_type = $period_type;
		$rule->cumulative = $cumulative;
		$rule->priority = $priority;
		$rule->active = $active;
		$rule->date_start = $date_start > 0 ? $date_start : null;
		$rule->date_end = $date_end > 0 ? $date_end : null;
		$rule->negative_margin_mode = $negative_margin_mode;
		$rule->description = $description;

		$result = $action === 'updaterule' ? $rule->update($user) : $rule->create($user);
		if ($result > 0) {
			setEventMessages($langs->trans($action === 'updaterule' ? 'RecordModifiedSuccessfully' : 'RecordCreatedSuccessfully'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}

		setEventMessages($rule->error, $rule->errors, 'errors');
	} else {
		setEventMessages('', $errors, 'errors');
		$mode = $action === 'updaterule' ? 'edit' : 'create';
	}
}

llxHeader('', $langs->trans('LmdbSalesCommissionsRules'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());

$head = lmdbsalescommissionsAdminPrepareHead();
print dol_get_fiche_head($head, 'rules', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent');
print load_fiche_titre($langs->trans('LmdbSalesCommissionsRules'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');

print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?mode=create">'.$langs->trans('New').'</a>';
print '</div>';

if ($mode === 'create' || $mode === 'edit') {
	$rule = is_object($object) ? $object : new LmdbSalesCommissionRule($db);
	$formaction = $mode === 'edit' ? 'updaterule' : 'addrule';

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="ruleform">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.$formaction.'">';
	if ($mode === 'edit') {
		print '<input type="hidden" name="id" value="'.((int) $id).'">';
	}

	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Ref').'</td><td><input class="minwidth300" type="text" name="ref" value="'.dol_escape_htmltag((string) $rule->ref).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Label').'</td><td><input class="minwidth500" type="text" name="label" value="'.dol_escape_htmltag((string) $rule->label).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Type').'</td><td>'.$form->selectarray('rule_type', $ruletypes, (string) $rule->rule_type, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('Rate').'</td><td><input class="width75 right" type="text" name="rate" value="'.dol_escape_htmltag((string) $rule->rate).'"> %</td></tr>';
	print '<tr><td>'.$langs->trans('LmdbSalesCommissionsTierGrid').'</td><td>'.$form->selectarray('fk_tier_grid', $tierGridOptions, (int) $rule->fk_tier_grid, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('LmdbSalesCommissionsPaymentTerms').'</td><td>'.$form->selectarray('fk_payment_term', $paymentTermOptions, (int) $rule->fk_payment_term, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Source').'</td><td>'.$form->selectarray('source_type', $sourcetypes, (string) ($rule->source_type ?: 'proposal'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Period').'</td><td>'.$form->selectarray('period_type', $periodtypes, (string) ($rule->period_type ?: 'monthly'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('Cumulative').'</td><td>'.$form->selectyesno('cumulative', (int) ($rule->cumulative !== null ? $rule->cumulative : 1), 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('Priority').'</td><td><input class="width75 right" type="text" name="priority" value="'.dol_escape_htmltag((string) ($rule->priority ?? 0)).'"></td></tr>';
	print '<tr><td>'.$langs->trans('Active').'</td><td>'.$form->selectyesno('active', (int) ($rule->active !== null ? $rule->active : 1), 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('DateStart').'</td><td>';
	print $form->selectDate($rule->date_start, 'date_start', 0, 0, 1, 'ruleform', 1, 0);
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('DateEnd').'</td><td>';
	print $form->selectDate($rule->date_end, 'date_end', 0, 0, 1, 'ruleform', 1, 0);
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('LmdbSalesCommissionsNegativeMarginMode').'</td><td>'.$form->selectarray('negative_margin_mode', $negativeMarginModes, (string) ($rule->negative_margin_mode ?: 'zero'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('Description').'</td><td><textarea class="quatrevingtpercent" name="description" rows="4">'.dol_escape_htmltag((string) $rule->description).'</textarea></td></tr>';
	print '</table>';

	print '<div class="center">';
	print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
	print ' ';
	print '<a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Cancel').'</a>';
	print '</div>';
	print '</form>';

	if (function_exists('ajax_combobox')) {
		print ajax_combobox('rule_type');
		print ajax_combobox('fk_tier_grid');
		print ajax_combobox('fk_payment_term');
		print ajax_combobox('source_type');
		print ajax_combobox('period_type');
		print ajax_combobox('negative_margin_mode');
	}
}

$sql = 'SELECT t.rowid, t.ref, t.label, t.rule_type, t.rate, t.fk_tier_grid, t.source_type, t.period_type, t.cumulative, t.priority, t.active, t.date_start, t.date_end';
$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule AS t';
$sql .= ' WHERE t.entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_rule')).')';
$sql .= ' ORDER BY t.active DESC, t.priority DESC, t.label ASC';

$resql = $db->query($sql);
if (!$resql) {
	setEventMessages($db->lasterror(), null, 'errors');
} else {
	print '<br>';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('Ref').'</td>';
	print '<td>'.$langs->trans('Label').'</td>';
	print '<td>'.$langs->trans('Type').'</td>';
	print '<td class="right">'.$langs->trans('Rate').'</td>';
	print '<td>'.$langs->trans('Source').'</td>';
	print '<td>'.$langs->trans('Period').'</td>';
	print '<td class="center">'.$langs->trans('Cumulative').'</td>';
	print '<td class="right">'.$langs->trans('Priority').'</td>';
	print '<td class="center">'.$langs->trans('Active').'</td>';
	print '<td class="right">'.$langs->trans('Action').'</td>';
	print '</tr>';

	$num = $db->num_rows($resql);
	if ($num === 0) {
		lmdbsalescommissionsPrintNoRecordRow($langs, 10);
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag((string) $obj->ref).'</td>';
		print '<td>'.dol_escape_htmltag((string) $obj->label).'</td>';
		print '<td>'.dol_escape_htmltag($ruletypes[(string) $obj->rule_type] ?? (string) $obj->rule_type).'</td>';
		print '<td class="right">'.($obj->rate !== null ? price((float) $obj->rate).' %' : '').'</td>';
		print '<td>'.dol_escape_htmltag($sourcetypes[(string) $obj->source_type] ?? (string) $obj->source_type).'</td>';
		print '<td>'.dol_escape_htmltag($periodtypes[(string) $obj->period_type] ?? (string) $obj->period_type).'</td>';
		print '<td class="center">'.yn((int) $obj->cumulative).'</td>';
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
