<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

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

require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/propal.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposaldispatch.class.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposaldispatchservice.class.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposalturnoverdispatch.class.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposalturnoverdispatchservice.class.php', 0);

$langs->loadLangs(array('propal', 'users', 'lmdbsalescommissions@lmdbsalescommissions'));

$id = GETPOSTINT('id');
$mode = GETPOST('mode', 'aZ09');
$action = GETPOST('action', 'aZ09');
$dispatchId = GETPOSTINT('dispatchid');
$turnoverDispatchId = GETPOSTINT('turnoverdispatchid');

if (!isModEnabled('lmdbsalescommissions')) {
	accessforbidden();
}
if ($id <= 0 || (!$user->admin && !$user->hasRight('propal', 'lire'))) {
	accessforbidden();
}

$object = new Propal($db);
if ($object->fetch($id) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}
if (!empty($user->socid) && (int) $object->socid !== (int) $user->socid) {
	accessforbidden();
}
$allowedProposalEntities = array_map('intval', explode(',', getEntity('propal')));
if (!in_array((int) $object->entity, $allowedProposalEntities, true)) {
	accessforbidden();
}
if (method_exists($object, 'fetch_lines')) {
	$object->fetch_lines();
}
if (method_exists($object, 'fetch_thirdparty')) {
	$object->fetch_thirdparty();
}

$service = new LmdbSalesCommissionProposalDispatchService($db);
$turnoverService = new LmdbSalesCommissionProposalTurnoverDispatchService($db);
$canManage = lmdbsalescommissionsCanManageDispatch($user);
$canReadAny = lmdbsalescommissionsCanReadCommissions($user) || $canManage;
if (!$canReadAny) {
	accessforbidden();
}

/** @var LmdbSalesCommissionProposalDispatch|null $editedDispatch */
$editedDispatch = null;
if ($dispatchId > 0) {
	$candidate = new LmdbSalesCommissionProposalDispatch($db);
	if ($candidate->fetch($dispatchId) > 0 && (int) $candidate->entity === (int) $object->entity && (int) $candidate->fk_propal === (int) $object->id) {
		$editedDispatch = $candidate;
	}
	if (!is_object($editedDispatch)) {
		accessforbidden($langs->trans('ErrorRecordNotFound'));
	}
}

/** @var LmdbSalesCommissionProposalTurnoverDispatch|null $editedTurnoverDispatch */
$editedTurnoverDispatch = null;
if ($turnoverDispatchId > 0) {
	$turnoverCandidate = new LmdbSalesCommissionProposalTurnoverDispatch($db);
	if ($turnoverCandidate->fetch($turnoverDispatchId) > 0 && (int) $turnoverCandidate->entity === (int) $object->entity && (int) $turnoverCandidate->fk_propal === (int) $object->id) {
		$editedTurnoverDispatch = $turnoverCandidate;
	}
	if (!is_object($editedTurnoverDispatch)) {
		accessforbidden($langs->trans('ErrorRecordNotFound'));
	}
}

if (in_array($action, array('savedispatch', 'deletedispatch', 'saveturnoverdispatch', 'deleteturnoverdispatch'), true)) {
	if (!$canManage) {
		accessforbidden();
	}
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}
}

if ($action === 'savedispatch') {
	$dispatch = is_object($editedDispatch) ? $editedDispatch : new LmdbSalesCommissionProposalDispatch($db);
	$dispatch->entity = (int) $object->entity;
	$dispatch->fk_propal = (int) $object->id;
	$dispatch->fk_user = GETPOSTINT('fk_user');
	$dispatch->base_type = GETPOST('base_type', 'aZ09');
	$dispatch->value_type = GETPOST('value_type', 'aZ09');
	$dispatch->value = price2num(GETPOST('value', 'alphanohtml'), $dispatch->value_type === LmdbSalesCommissionProposalDispatchService::VALUE_AMOUNT ? 'MT' : '');
	$dispatch->payment_term_mode = GETPOST('payment_term_mode', 'aZ09');
	$dispatch->fk_payment_term = $dispatch->payment_term_mode === LmdbSalesCommissionProposalDispatchService::PAYMENT_EXPLICIT ? GETPOSTINT('fk_payment_term') : null;
	$dispatch->note_private = GETPOST('note_private', 'restricthtml');

	$result = $service->save($dispatch, $object, $user);
	if ($result > 0) {
		setEventMessages($langs->trans(is_object($editedDispatch) ? 'RecordModifiedSuccessfully' : 'RecordCreatedSuccessfully'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
		exit;
	}
	setEventMessages($langs->trans($service->error), array_map(array($langs, 'trans'), $service->errors), 'errors');
	$editedDispatch = $dispatch;
	$mode = is_object($editedDispatch) && !empty($editedDispatch->id) ? 'edit' : 'create';
} elseif ($action === 'deletedispatch') {
	if (!is_object($editedDispatch)) {
		accessforbidden($langs->trans('ErrorRecordNotFound'));
	}
	$result = $service->delete($editedDispatch, $object, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
		exit;
	}
	setEventMessages($langs->trans($service->error), array_map(array($langs, 'trans'), $service->errors), 'errors');
} elseif ($action === 'saveturnoverdispatch') {
	$turnoverDispatch = is_object($editedTurnoverDispatch) ? $editedTurnoverDispatch : new LmdbSalesCommissionProposalTurnoverDispatch($db);
	$turnoverDispatch->entity = (int) $object->entity;
	$turnoverDispatch->fk_propal = (int) $object->id;
	$turnoverDispatch->fk_user = GETPOSTINT('turnover_fk_user');
	$turnoverDispatch->value_type = GETPOST('turnover_value_type', 'aZ09');
	$turnoverDispatch->value = price2num(GETPOST('turnover_value', 'alphanohtml'), $turnoverDispatch->value_type === LmdbSalesCommissionProposalTurnoverDispatchService::VALUE_AMOUNT ? 'MT' : '');
	$turnoverDispatch->note_private = GETPOST('turnover_note_private', 'restricthtml');

	$result = $turnoverService->save($turnoverDispatch, $object, $user);
	if ($result > 0) {
		setEventMessages($langs->trans(is_object($editedTurnoverDispatch) ? 'RecordModifiedSuccessfully' : 'RecordCreatedSuccessfully'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
		exit;
	}
	setEventMessages($langs->trans($turnoverService->error), array_map(array($langs, 'trans'), $turnoverService->errors), 'errors');
	$editedTurnoverDispatch = $turnoverDispatch;
	$mode = is_object($editedTurnoverDispatch) && !empty($editedTurnoverDispatch->id) ? 'editturnover' : 'createturnover';
} elseif ($action === 'deleteturnoverdispatch') {
	if (!is_object($editedTurnoverDispatch)) {
		accessforbidden($langs->trans('ErrorRecordNotFound'));
	}
	$result = $turnoverService->delete($editedTurnoverDispatch, $object, $user);
	if ($result > 0) {
		setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
		exit;
	}
	setEventMessages($langs->trans($turnoverService->error), array_map(array($langs, 'trans'), $turnoverService->errors), 'errors');
}

$dispatches = $service->fetchForProposal((int) $object->id, (int) $object->entity);
$turnoverDispatches = $turnoverService->fetchForProposal((int) $object->id, (int) $object->entity);
$editable = $canManage && $service->isProposalEditable($object);
$canSeeAll = $canManage || !empty($user->admin) || $user->hasRight('lmdbsalescommissions', 'commission', 'readall');

$form = new Form($db);
$userOptions = lmdbsalescommissionsGetUserOptionsForEntity($db, (int) $object->entity, false);
$paymentOptions = lmdbsalescommissionsGetPaymentTermOptionsForEntity($db, (int) $object->entity, false);
$baseOptions = array(
	LmdbSalesCommissionProposalDispatchService::BASE_MARGIN => $langs->trans('Margin'),
	LmdbSalesCommissionProposalDispatchService::BASE_TURNOVER => $langs->trans('AmountHT'),
);
$valueOptions = array(
	LmdbSalesCommissionProposalDispatchService::VALUE_AMOUNT => $langs->trans('LmdbSalesCommissionsDispatchFixedAmount'),
	LmdbSalesCommissionProposalDispatchService::VALUE_PERCENTAGE => $langs->trans('Percentage'),
);
$paymentModeOptions = array(
	LmdbSalesCommissionProposalDispatchService::PAYMENT_AUTOMATIC => $langs->trans('LmdbSalesCommissionsDispatchPaymentAutomatic'),
	LmdbSalesCommissionProposalDispatchService::PAYMENT_EXPLICIT => $langs->trans('LmdbSalesCommissionsDispatchPaymentExplicit'),
);

llxHeader('', $langs->trans('LmdbSalesCommissionsProposalDispatch'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());
$head = propal_prepare_head($object);
print dol_get_fiche_head($head, 'lmdbsalescommissions_dispatch', $langs->trans('Proposal'), -1, 'propal');

$linkback = '<a href="'.DOL_URL_ROOT.'/comm/propal/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
$thirdpartyName = is_object($object->thirdparty) ? (string) $object->thirdparty->name : '';
$morehtmlref = '<div class="refidno"><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $object->socid).'">'.dol_escape_htmltag($thirdpartyName).'</a></div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);
print '<div class="underbanner clearboth"></div>';

if ($editable) {
	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&mode=create">'.$langs->trans('LmdbSalesCommissionsNewCommissionDispatch').'</a>';
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&mode=createturnover">'.$langs->trans('LmdbSalesCommissionsNewTurnoverDispatch').'</a>';
	print '</div>';
} elseif ($canManage) {
	print info_admin($langs->trans('LmdbSalesCommissionsDispatchLocked'), 0, 0, 'info');
}

print load_fiche_titre($langs->trans('LmdbSalesCommissionsCommissionDispatchSection'), '', 'fa-percent');

if ($editable && ($mode === 'create' || $mode === 'edit')) {
	$dispatch = is_object($editedDispatch) ? $editedDispatch : new LmdbSalesCommissionProposalDispatch($db);
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="dispatchform">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="savedispatch">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
	if ($mode === 'edit') {
		print '<input type="hidden" name="dispatchid" value="'.((int) $dispatch->id).'">';
	}
	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('SalesRepresentative').'</td><td>'.$form->selectarray('fk_user', $userOptions, (int) $dispatch->fk_user, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('LmdbSalesCommissionsDispatchBase').'</td><td>'.$form->selectarray('base_type', $baseOptions, (string) ($dispatch->base_type ?: LmdbSalesCommissionProposalDispatchService::BASE_MARGIN), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('LmdbSalesCommissionsDispatchValueType').'</td><td>'.$form->selectarray('value_type', $valueOptions, (string) ($dispatch->value_type ?: LmdbSalesCommissionProposalDispatchService::VALUE_PERCENTAGE), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Value').'</td><td><input class="width100 right" type="text" name="value" value="'.dol_escape_htmltag((string) $dispatch->value).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('LmdbSalesCommissionsDispatchPaymentMode').'</td><td>'.$form->selectarray('payment_term_mode', $paymentModeOptions, (string) ($dispatch->payment_term_mode ?: LmdbSalesCommissionProposalDispatchService::PAYMENT_AUTOMATIC), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('LmdbSalesCommissionsPaymentTerms').'</td><td>'.$form->selectarray('fk_payment_term', $paymentOptions, (int) $dispatch->fk_payment_term, 1, 0, 0, '', 0, 0, 0, '', 'minwidth500').'</td></tr>';
	print '<tr><td>'.$langs->trans('NotePrivate').'</td><td><textarea class="quatrevingtpercent" name="note_private" rows="3">'.dol_escape_htmltag((string) $dispatch->note_private).'</textarea></td></tr>';
	print '</table>';
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"> <a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'">'.$langs->trans('Cancel').'</a></div>';
	print '</form>';
	if (function_exists('ajax_combobox')) {
		foreach (array('fk_user', 'base_type', 'value_type', 'payment_term_mode', 'fk_payment_term') as $selectId) {
			print ajax_combobox($selectId);
		}
	}
}

print '<br><table class="noborder liste centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('SalesRepresentative').'</td><td>'.$langs->trans('LmdbSalesCommissionsDispatchFormula').'</td><td>'.$langs->trans('LmdbSalesCommissionsPaymentTerms').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsCommissionTotal').'</td>'.($editable ? '<td class="center">'.$langs->trans('Action').'</td>' : '').'</tr>';
$visibleCount = 0;
$visibleTotal = 0.0;
foreach ($dispatches as $dispatch) {
	if (!$canSeeAll && !lmdbsalescommissionsCanReadUserScope($user, (int) $dispatch->fk_user)) {
		continue;
	}
	$visibleCount++;
	$calculation = $service->getCalculationForDisplay($dispatch, $object, dol_now());
	$beneficiary = new User($db);
	$beneficiaryLabel = (string) $dispatch->fk_user;
	if ($beneficiary->fetch((int) $dispatch->fk_user) > 0) {
		$beneficiaryLabel = $beneficiary->getNomUrl(1);
	}
	$formula = lmdbsalescommissionsFormatDispatchFormula($langs, (string) $dispatch->base_type, (string) $dispatch->value_type, $dispatch->value);
	$paymentLabel = '';
	$commission = null;
	if (is_array($calculation)) {
		$paymentLabel = $calculation['payment_term_label'] === 'LmdbSalesCommissionsPaymentImmediateAtSignature' ? $langs->trans($calculation['payment_term_label']) : $calculation['payment_term_label'];
		$commission = (float) $calculation['commission'];
		$visibleTotal += $commission;
	}
	print '<tr class="oddeven"><td>'.$beneficiaryLabel.'</td><td>'.dol_escape_htmltag($formula).'</td><td>'.dol_escape_htmltag($paymentLabel).'</td><td class="right">'.($commission !== null ? lmdbsalescommissionsFormatTotalAmount($commission) : img_warning($langs->trans($service->error))).'</td>';
	if ($editable) {
		$editUrl = $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&mode=edit&dispatchid='.((int) $dispatch->id);
		$deleteUrl = $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&action=deletedispatch&dispatchid='.((int) $dispatch->id).'&token='.newToken();
		print '<td class="center nowraponall"><a class="reposition" href="'.dol_escape_htmltag($editUrl).'">'.img_edit($langs->trans('Edit')).'</a> ';
		print '<a class="reposition" href="'.dol_escape_htmltag($deleteUrl).'">'.img_delete($langs->trans('Delete')).'</a></td>';
	}
	print '</tr>';
}
if ($visibleCount === 0) {
	lmdbsalescommissionsPrintNoRecordRow($langs, $editable ? 5 : 4);
} elseif ($canSeeAll) {
	print '<tr class="liste_total">';
	print '<td class="liste_total">'.$langs->trans('Total').'</td>';
	print '<td class="liste_total"></td>';
	print '<td class="liste_total"></td>';
	print '<td class="liste_total right">'.lmdbsalescommissionsFormatTotalAmount($visibleTotal).'</td>';
	if ($editable) {
		print '<td class="liste_total"></td>';
	}
	print '</tr>';
}
print '</table>';

print '<br>';
print load_fiche_titre($langs->trans('LmdbSalesCommissionsTurnoverDispatchSection'), '', 'fa-chart-line');
print '<div class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsTurnoverDispatchHelp').'</div>';

if ($editable && ($mode === 'createturnover' || $mode === 'editturnover')) {
	$turnoverDispatch = is_object($editedTurnoverDispatch) ? $editedTurnoverDispatch : new LmdbSalesCommissionProposalTurnoverDispatch($db);
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="turnoverdispatchform">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="saveturnoverdispatch">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
	if ($mode === 'editturnover') {
		print '<input type="hidden" name="turnoverdispatchid" value="'.((int) $turnoverDispatch->id).'">';
	}
	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('SalesRepresentative').'</td><td>'.$form->selectarray('turnover_fk_user', $userOptions, (int) $turnoverDispatch->fk_user, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('LmdbSalesCommissionsTurnoverDispatchValueType').'</td><td>'.$form->selectarray('turnover_value_type', $valueOptions, (string) ($turnoverDispatch->value_type ?: LmdbSalesCommissionProposalTurnoverDispatchService::VALUE_PERCENTAGE), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Value').'</td><td><input class="width100 right" type="text" name="turnover_value" value="'.dol_escape_htmltag((string) $turnoverDispatch->value).'"></td></tr>';
	print '<tr><td>'.$langs->trans('NotePrivate').'</td><td><textarea class="quatrevingtpercent" name="turnover_note_private" rows="3">'.dol_escape_htmltag((string) $turnoverDispatch->note_private).'</textarea></td></tr>';
	print '</table>';
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"> <a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'">'.$langs->trans('Cancel').'</a></div>';
	print '</form>';
	if (function_exists('ajax_combobox')) {
		print ajax_combobox('turnover_fk_user');
		print ajax_combobox('turnover_value_type');
	}
}

$proposalTurnover = property_exists($object, 'total_ht') && is_numeric($object->total_ht) ? (float) price2num(max(0, (float) $object->total_ht), 'MT') : 0.0;
$turnoverVisibleCount = 0;
$turnoverFullTotal = 0.0;
print '<br><table class="noborder liste centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('SalesRepresentative').'</td><td>'.$langs->trans('LmdbSalesCommissionsTurnoverDispatchFormula').'</td><td class="right">'.$langs->trans('LmdbSalesCommissionsAttributedTurnover').'</td>'.($editable ? '<td class="center">'.$langs->trans('Action').'</td>' : '').'</tr>';
foreach ($turnoverDispatches as $turnoverDispatch) {
	$allocatedAmount = $turnoverService->calculateAmount($turnoverDispatch, $proposalTurnover);
	$turnoverFullTotal = (float) price2num($turnoverFullTotal + $allocatedAmount, 'MT');
	if (!$canSeeAll && !lmdbsalescommissionsCanReadUserScope($user, (int) $turnoverDispatch->fk_user)) {
		continue;
	}
	$turnoverVisibleCount++;
	$beneficiary = new User($db);
	$beneficiaryLabel = dol_escape_htmltag((string) $turnoverDispatch->fk_user);
	if ($beneficiary->fetch((int) $turnoverDispatch->fk_user) > 0) {
		$beneficiaryLabel = $beneficiary->getNomUrl(1);
	}
	$formula = (string) $turnoverDispatch->value_type === LmdbSalesCommissionProposalTurnoverDispatchService::VALUE_PERCENTAGE
		? price((float) $turnoverDispatch->value, 0, $langs, 0, 0, -1).'%'
		: lmdbsalescommissionsFormatTotalAmount($turnoverDispatch->value);
	print '<tr class="oddeven"><td>'.$beneficiaryLabel.'</td><td>'.$formula.'</td><td class="right">'.lmdbsalescommissionsFormatTotalAmount($allocatedAmount).'</td>';
	if ($editable) {
		$editUrl = $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&mode=editturnover&turnoverdispatchid='.((int) $turnoverDispatch->id);
		$deleteUrl = $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&action=deleteturnoverdispatch&turnoverdispatchid='.((int) $turnoverDispatch->id).'&token='.newToken();
		print '<td class="center nowraponall"><a class="reposition" href="'.dol_escape_htmltag($editUrl).'">'.img_edit($langs->trans('Edit')).'</a> ';
		print '<a class="reposition" href="'.dol_escape_htmltag($deleteUrl).'">'.img_delete($langs->trans('Delete')).'</a></td>';
	}
	print '</tr>';
}
if ($turnoverVisibleCount === 0) {
	lmdbsalescommissionsPrintNoRecordRow($langs, $editable ? 4 : 3);
} elseif ($canSeeAll) {
	$distributedRate = $proposalTurnover > 0 ? (float) price2num(($turnoverFullTotal / $proposalTurnover) * 100) : 0.0;
	print '<tr class="liste_total"><td class="liste_total">'.$langs->trans('Total').'</td><td class="liste_total">'.price($distributedRate, 0, $langs, 0, 0, -1).'%</td><td class="liste_total right">'.lmdbsalescommissionsFormatTotalAmount($turnoverFullTotal).'</td>'.($editable ? '<td class="liste_total"></td>' : '').'</tr>';
}
print '</table>';

if (empty($turnoverDispatches)) {
	print info_admin($langs->trans('LmdbSalesCommissionsTurnoverDispatchAutomatic'), 0, 0, 'info');
} elseif ((float) price2num($turnoverFullTotal, 'MT') !== (float) price2num($proposalTurnover, 'MT')) {
	print info_admin($langs->trans('LmdbSalesCommissionsTurnoverDispatchIncomplete'), 0, 0, 'warning');
}

print dol_get_fiche_end();
llxFooter();
$db->close();
