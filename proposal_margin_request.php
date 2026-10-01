<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
$res = 0;
if (file_exists('../main.inc.php')) { $res = @include '../main.inc.php'; }
if (!$res && file_exists('../../main.inc.php')) { $res = @include '../../main.inc.php'; }
if (!$res) { die('Include of main fails'); }

require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once __DIR__.'/class/lmdbsalescommissionmarginservice.class.php';
$langs->loadLangs(array('propal', 'lmdbsalescommissions@lmdbsalescommissions'));
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$reason = GETPOST('reason', 'alphanohtml');
if (!isModEnabled('lmdbsalescommissions') || !empty($user->socid) || !$user->hasRight('propal', 'lire')
	|| (!getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && !$user->hasRight('propal', 'creer'))
	|| (getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && !$user->hasRight('propal', 'propal_advance', 'validate'))) { accessforbidden(); }
$object = new Propal($db);
if ($id <= 0 || $object->fetch($id) <= 0) { accessforbidden($langs->trans('ErrorRecordNotFound')); }
if ((int) $object->entity !== (int) $conf->entity) { accessforbidden($langs->trans('LscOwnerContext')); }
restrictedArea($user, 'propal', $object->id, 'propal', '', 'fk_soc', 'rowid', 0, 0, 'write');
$cardUrl = DOL_URL_ROOT.'/comm/propal/card.php?id='.(int) $object->id;
if (GETPOST('confirm', 'alpha') === 'no') { header('Location: '.$cardUrl); exit; }
if ((int) $object->status !== 0 || LmdbSalesCommissionProposalService::getSignatureDate($object) > 0) { accessforbidden($langs->trans('LscApprovalDenied')); }
$service = new LmdbSalesCommissionMarginService($db);
if ($action === 'requestsaleapproval') {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || GETPOST('token', 'alpha') === '') { accessforbidden(); }
	try {
		$service->requestSaleApproval($object, $user, $reason, GETPOST('fingerprint', 'aZ09'));
		setEventMessages($langs->trans('LscRequestSaved'), null, 'mesgs');
		header('Location: '.$cardUrl); exit;
	} catch (Exception $e) { setEventMessages($langs->trans($e->getMessage()), null, 'errors'); }
}
$fingerprint = '';
try {
	foreach ($service->assess($object, false, $user) as $decision) {
		foreach ($decision['checks'] as $check) {
			if ($check['effect'] === 'sale' && $check['state'] === 'deny') { $fingerprint = $decision['fingerprint']; }
		}
	}
	if ($fingerprint !== '' && GETPOST('fingerprint', 'aZ09') !== '' && !hash_equals($fingerprint, GETPOST('fingerprint', 'aZ09')) && $action !== 'requestsaleapproval') {
		setEventMessages($langs->trans('LscApprovalStale'), null, 'warnings');
	}
} catch (Exception $e) { setEventMessages($langs->trans($e->getMessage()), null, 'errors'); }
llxHeader('', $langs->trans('LscRequestApproval'));
print load_fiche_titre($langs->trans('LscRequestApproval'), '<a href="'.dol_escape_htmltag($cardUrl).'">'.$langs->trans('LscModifyProposal').'</a>', 'propal');
print '<p>'.$object->getNomUrl(1).'</p>';
if ($fingerprint === '') {
	print '<div class="warning">'.$langs->trans('LscRequestNoDeniedSale').'</div>';
} else {
	print '<p>'.$langs->trans('LscRequestHelp').'</p>';
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="requestsaleapproval">';
	print '<input type="hidden" name="id" value="'.(int) $object->id.'">';
	print '<input type="hidden" name="fingerprint" value="'.dol_escape_htmltag($fingerprint).'">';
	print '<table class="border centpercent"><tr><td class="fieldrequired"><label for="reason">'.$langs->trans('Reason').'</label></td>';
	print '<td><textarea id="reason" name="reason" class="quatrevingtpercent" rows="4" required>'.dol_escape_htmltag($reason).'</textarea></td></tr></table>';
	print '<div class="center"><button type="submit" class="button">'.$langs->trans('LscRequestApproval').'</button> ';
	print '<a class="button button-cancel" href="'.dol_escape_htmltag($cardUrl).'">'.$langs->trans('Cancel').'</a></div></form>';
}
llxFooter();
$db->close();
