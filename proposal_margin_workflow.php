<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
$res = 0;
if (file_exists(dirname(__DIR__).'/main.inc.php')) { $res = @include dirname(__DIR__).'/main.inc.php'; }
if (!$res && file_exists(dirname(__DIR__, 2).'/main.inc.php')) { $res = @include dirname(__DIR__, 2).'/main.inc.php'; }
if (!$res) { die('Include of main fails'); }
/**
 * @var DoliDB $db
 * @var Conf $conf
 * @var User $user
 * @var Translate $langs
 * @var HookManager $hookmanager
 */
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/propal.lib.php';
require_once __DIR__.'/class/lmdbsalescommissionmarginworkflow.class.php';
require_once __DIR__.'/class/lmdbsalescommissionmarginworkflowview.class.php';
$langs->loadLangs(array('propal', 'companies', 'users', 'lmdbsalescommissions@lmdbsalescommissions'));
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$mode = GETPOST('mode', 'aZ09');
$object = new Propal($db);
if (!LmdbSalesCommissionMarginWorkflow::available() || !empty($user->socid) || !$user->hasRight('propal', 'lire') || $id <= 0 || $object->fetch($id) <= 0) { accessforbidden(); }
if ((int) $object->entity !== (int) $conf->entity) { accessforbidden($langs->trans('LscOwnerContext')); }
restrictedArea($user, 'propal', $id, 'propal', '', 'fk_soc', 'rowid', 0, 0, 'read');
if ($object->fetch_thirdparty() <= 0) { accessforbidden($langs->trans('ErrorRecordNotFound')); }
$workflow = new LmdbSalesCommissionMarginWorkflow($db);
$url = dol_buildpath('/lmdbsalescommissions/proposal_margin_workflow.php', 1).'?id='.$id;
$reason = GETPOST('reason', 'alphanohtml');
$percent = null;
if (GETPOSTISSET('retained_percent')) {
	$number = price2num(GETPOST('retained_percent', 'alphanohtml'), '', 2);
	if (is_numeric($number) && is_finite((float) $number)) { $percent = (float) $number; }
}
if ($action !== '') {
	// Native formconfirm uses token-protected GET in its Ajax mode on older cores,
	// and POST without JavaScript. main.inc.php remains the CSRF authority for both.
	if (!in_array($_SERVER['REQUEST_METHOD'], array('GET', 'POST'), true) || ($action === 'previewcommission' && $_SERVER['REQUEST_METHOD'] !== 'POST') || GETPOST('token', 'alpha') === '' || !in_array($action, array('submitrequest', 'approvevalidation', 'returnvalidation', 'previewcommission'), true)) { accessforbidden(); }
	if ($action !== 'previewcommission' && GETPOST('confirm', 'alpha') !== 'yes') { header('Location: '.$url); exit; }
	try {
		if ($action === 'previewcommission') {
			$workflow->assertDecisionAccess($object, $user, true);
			$pending = $workflow->pending($object);
			if (!$pending || $percent === null || $percent < 0 || $percent > 100) { throw new RuntimeException('LscWorkflowInvalidPercent'); }
			$base = $workflow->theoreticalCommission($object, (int) $pending['fk_author']);
			header('Content-Type: application/json');
			print json_encode(array('amount' => price(price2num($base * $percent / 100, 'MT')).' '.$conf->currency), JSON_THROW_ON_ERROR);
			exit;
		}
		if ($action === 'submitrequest') { $workflow->request($object, $user, $reason, GETPOST('fingerprint', 'aZ09')); }
		else { $workflow->decide($object, $user, GETPOSTINT('case_id'), $action === 'approvevalidation', $reason, $percent); }
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		header('Location: '.$url); exit;
	} catch (Throwable $e) {
		if ($action === 'previewcommission') { http_response_code(409); header('Content-Type: application/json'); print json_encode(array('error' => $langs->trans($e->getMessage()))); exit; }
		setEventMessages($langs->trans($e->getMessage()), null, 'errors');
		$mode = $action === 'submitrequest' ? 'request' : ($action === 'approvevalidation' ? 'approve' : 'return');
	}
}
$hookmanager->initHooks(array('lmdbsalescommissionmarginworkflow', 'globalcard'));
$form = new Form($db);
llxHeader('', $object->ref.' - '.$langs->trans('LscWorkflowHistory'), 'EN:Commercial_Proposals|FR:Proposition_commerciale|ES:Presupuestos');
$head = propal_prepare_head($object);
print dol_get_fiche_head($head, 'lmdbsalescommissions_validations', $langs->trans('Proposal'), -1, $object->picto);
$linkback = '<a href="'.DOL_URL_ROOT.'/comm/propal/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
$morehtmlref = '<div class="refidno">';
$morehtmlref .= $form->editfieldkey('RefCustomer', 'ref_client', $object->ref_customer, $object, 0, 'string', '', 0, 1);
$morehtmlref .= $form->editfieldval('RefCustomer', 'ref_client', $object->ref_customer, $object, 0, 'string', '', null, null, '', 1);
$morehtmlref .= '<br>'.$object->thirdparty->getNomUrl(1);
if (isModEnabled('project') && $object->fk_project > 0 && $user->hasRight('projet', 'lire')) {
	require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
	$project = new Project($db);
	if ($project->fetch($object->fk_project) > 0
		&& in_array((int) $project->entity, array_map('intval', explode(',', getEntity('project'))), true)
		&& $project->restrictedProjectArea($user, 'read') > 0) {
		$langs->load('projects');
		$morehtmlref .= '<br>'.$project->getNomUrl(1);
		if ($project->title !== '') { $morehtmlref .= '<span class="opacitymedium"> - '.dol_escape_htmltag($project->title).'</span>'; }
	}
}
$morehtmlref .= '</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);
print '<div class="underbanner clearboth"></div>';
print dol_get_fiche_end();
try {
	$pending = $workflow->pending($object);
	$actions = LmdbSalesCommissionMarginWorkflowView::actions($db, $object, $user, false);
	if ($actions !== '') { print '<div class="tabsAction">'.$actions.'</div>'; }
	if ($mode === 'request' && !$pending) {
		if (!$user->hasRight('propal', 'creer')) { throw new RuntimeException('NotEnoughPermissions'); }
		$decisions = $workflow->requestable($object, $user);
		$fingerprint = reset($decisions)['fingerprint'];
		$fields = array(array('type' => 'hidden', 'name' => 'fingerprint', 'value' => $fingerprint), array('type' => 'textarea', 'name' => 'reason', 'label' => $langs->trans('Reason'), 'value' => dol_escape_htmltag($reason), 'moreattr' => 'required'));
		print $form->formconfirm($url, $langs->trans('LscWorkflowRequest'), LmdbSalesCommissionMarginWorkflowView::checks($decisions), 'submitrequest', $fields, 'no', 1, 0, 800);
	} elseif ($pending && in_array($mode, array('approve', 'return'), true)) {
		$workflow->assertDecisionAccess($object, $user, $mode === 'approve');
		$fields = array(array('type' => 'hidden', 'name' => 'case_id', 'value' => (int) $pending['rowid']), array('type' => 'textarea', 'name' => 'reason', 'label' => $langs->trans('Reason'), 'value' => dol_escape_htmltag($reason), 'moreattr' => 'required'));
		$question = dol_escape_htmltag($pending['reason']);
		if ($mode === 'approve') {
			$decisions = $workflow->requestable($object, $user);
			foreach ($decisions as $decision) { if (!hash_equals($pending['fingerprint'], $decision['fingerprint'])) { throw new RuntimeException('LscApprovalStale'); } }
			$base = $workflow->theoreticalCommission($object, (int) $pending['fk_author']);
			$usual = isset($decisions[(int) $pending['fk_author']]) && $decisions[(int) $pending['fk_author']]['commission'] === 'allow' ? 100.0 : 0.0;
			$value = $percent ?? $usual;
			if ($usual === 0.0 && !$user->hasRight('lmdbsalescommissions', 'marginpolicy', 'approvecommission')) { $value = 0.0; }
			$fields[] = array('type' => 'text', 'name' => 'retained_percent', 'label' => $langs->trans('LscWorkflowPercent'), 'value' => price($value), 'moreattr' => ($usual === 0.0 && !$user->hasRight('lmdbsalescommissions', 'marginpolicy', 'approvecommission') ? 'readonly ' : '').'required');
			$question .= LmdbSalesCommissionMarginWorkflowView::checks($decisions).'<p>'.$langs->trans('LscWorkflowBase').' : '.price($base).' '.$conf->currency.'</p><p>'.$langs->trans('LscWorkflowRetained').' : <span class="lsc-retained-amount" data-url="'.dol_escape_htmltag($url).'" data-token="'.dol_escape_htmltag(newToken()).'">'.price(price2num($base * $value / 100, 'MT')).' '.$conf->currency.'</span></p>';
		}
		print $form->formconfirm($url, $langs->trans($mode === 'approve' ? 'LscWorkflowApprove' : 'LscWorkflowReturn'), $question, $mode === 'approve' ? 'approvevalidation' : 'returnvalidation', $fields, 'no', 1, 0, 800);
	}
	print LmdbSalesCommissionMarginWorkflowView::history($db, $object);
} catch (Throwable $e) { print '<div class="warning">'.dol_escape_htmltag($langs->trans($e->getMessage())).'</div>'; }
print '<script src="'.dol_buildpath('/lmdbsalescommissions/js/marginworkflow.js', 1).'"></script>';
llxFooter();
$db->close();
