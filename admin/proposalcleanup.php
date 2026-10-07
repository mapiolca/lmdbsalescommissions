<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

$res = 0;
if (file_exists('../../main.inc.php')) { $res = @include '../../main.inc.php'; }
if (!$res && file_exists('../../../main.inc.php')) { $res = @include '../../../main.inc.php'; }
if (!$res) { die('Include of main fails'); }
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/../lib/lmdbsalescommissions.lib.php';
require_once __DIR__.'/../class/lmdbsalescommissionproposalcleanup.class.php';
$langs->loadLangs(array('admin', 'propal', 'lmdbsalescommissions@lmdbsalescommissions'));
if (!isModEnabled('lmdbsalescommissions') || !$user->admin || !$user->hasRight('lmdbsalescommissions', 'maintenance', 'recalculate') || !empty($user->socid)) { accessforbidden(); }
$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('proposal_id');
$after = max(0, GETPOSTINT('after'));
$form = new Form($db);
$cleanup = new LmdbSalesCommissionProposalCleanup($db);
$entity = (int) $conf->entity;
$snapshot = null;
$nonce = '';
$confirmationAction = '';
$url = dol_buildpath('/lmdbsalescommissions/admin/proposalcleanup.php', 1);
if ($action !== '') {
	if (!in_array($action, array('previewcleanup', 'confirmcleanup', 'confirmpaidcleanup'), true)
		|| $_SERVER['REQUEST_METHOD'] !== 'POST' || GETPOST('token', 'alpha') === '') { accessforbidden(); }
	if ($action !== 'previewcleanup' && GETPOST('confirm', 'alpha') !== 'yes') {
		unset($_SESSION['lmdbsalescommissions_cleanup']);
		header('Location: '.$url); exit;
	}
	$state = $cleanup->proposalState($id, $entity);
	// A row in a different entity is not an orphan. Candidates must still qualify.
	$candidates = $cleanup->candidates($entity, max(0, $id - 1));
	$eligible = false;
	if (is_array($candidates)) {
		foreach ($candidates as $candidate) { if ((int) $candidate['fk_source'] === $id) { $eligible = true; break; } }
	}
	if (!$eligible || $cleanup->error !== '' || ($state !== null && (int) $state['fk_statut'] !== 0)) {
		setEventMessages($langs->trans($cleanup->error ?: 'LscCleanupChanged'), null, 'errors');
	} else {
		$snapshot = $cleanup->inspect($id, $entity);
		if ($snapshot === null) {
			setEventMessages($langs->trans($cleanup->error), null, 'errors');
		} elseif ($snapshot['paid'] && !$user->hasRight('lmdbsalescommissions', 'due', 'pay')) {
			setEventMessages($langs->trans('LscCleanupPaidPermission'), null, 'errors'); $snapshot = null;
		} elseif ($action === 'previewcleanup') {
			$nonce = $cleanup->prepareConfirmation($id, $entity, 'cleanup', $user, $snapshot);
			$confirmationAction = 'confirmcleanup';
		} elseif ($action === 'confirmcleanup' && $snapshot['paid']) {
			$nonce = GETPOST('lsc_confirmation', 'aZ09');
			if ($cleanup->approveConfirmation($id, $entity, 'cleanup', $user, $snapshot, $nonce)) {
				$_SESSION['lmdbsalescommissions_cleanup']['approved'] = false;
				$_SESSION['lmdbsalescommissions_cleanup']['first_confirmed'] = true;
				$confirmationAction = 'confirmpaidcleanup';
			} else { setEventMessages($langs->trans($cleanup->error), null, 'errors'); }
		} else {
			$nonce = GETPOST('lsc_confirmation', 'aZ09');
			if (($action === 'confirmpaidcleanup' && empty($_SESSION['lmdbsalescommissions_cleanup']['first_confirmed']))
				|| !$cleanup->approveConfirmation($id, $entity, 'cleanup', $user, $snapshot, $nonce)) {
				setEventMessages($langs->trans('LscCleanupConfirmationRequired'), null, 'errors');
				header('Location: '.$url); exit;
			}
			$proposal = (object) array('id' => $id, 'entity' => $entity);
			$result = $cleanup->deleteForProposal($proposal, $user, 'cleanup', $nonce);
			setEventMessages($result < 0 ? $langs->trans($cleanup->error) : $langs->trans('LscCleanupDone', $result),
				array_map(array($langs, 'trans'), $cleanup->errors), $result < 0 ? 'errors' : ($cleanup->errors ? 'warnings' : 'mesgs'));
			header('Location: '.$url); exit;
		}
	}
}
llxHeader('', $langs->trans('LscCleanupTitle'));
print dol_get_fiche_head(lmdbsalescommissionsAdminPrepareHead(), 'maintenance', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent');
print load_fiche_titre($langs->trans('LscCleanupTitle'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');
print '<p>'.$langs->trans('LscCleanupDescription').'</p>';
$userLabels = array();
if ($snapshot !== null && $confirmationAction !== '') {
	$userIds = array_unique(array_map(static function (array $line): int { return (int) $line['fk_user']; }, $snapshot['lines']));
	if ($userIds) {
		$resql = $db->query('SELECT rowid, firstname, lastname, login FROM '.MAIN_DB_PREFIX.'user WHERE rowid IN ('.implode(',', $userIds).')');
		if (!$resql) { setEventMessages($db->lasterror(), null, 'errors'); $snapshot = null; }
		else {
			while (is_object($row = $db->fetch_object($resql))) { $userLabels[(int) $row->rowid] = trim($row->firstname.' '.$row->lastname) ?: $row->login; }
			$db->free($resql);
		}
	}
}
if ($snapshot !== null && $confirmationAction !== '') {
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('SalesRepresentative').'</td><td>'.$langs->trans('Mode').'</td><td>'.$langs->trans('Amount').'</td><td>'.$langs->trans('LmdbSalesCommissionsPaidTotal').'</td></tr>';
	foreach ($snapshot['lines'] as $line) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag((string) $line['source_ref']).'</td><td>'.dol_escape_htmltag($userLabels[(int) $line['fk_user']] ?? $langs->trans('User')).'</td><td>'.dol_escape_htmltag(lmdbsalescommissionsGetModeLabel($langs, (string) $line['mode'])).'</td><td>'.price($line['commission_total']).'</td><td>'.price($line['paid_total']).'</td></tr>';
	}
	print '</table></div><br><div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Event').'</td><td>'.$langs->trans('Amount').'</td><td>'.$langs->trans('Status').'</td><td>'.$langs->trans('DatePayment').'</td></tr>';
	foreach ($snapshot['dues'] as $due) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag(lmdbsalescommissionsGetDueEventLabel($langs, (string) $due['event_type'])).'</td><td>'.price($due['amount']).'</td><td>'.dolGetStatus(lmdbsalescommissionsGetDueStatusLabel($langs, (int) $due['status']), lmdbsalescommissionsGetDueStatusLabel($langs, (int) $due['status']), '', 'status'.((int) $due['status']), 2).'</td><td>'.($due['date_paid'] ? dol_print_date($db->jdate($due['date_paid']), 'dayhour') : '').'</td></tr>';
	}
	if (!$snapshot['dues']) { print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>'; }
	print '</table></div>';
	$fields = array(array('type' => 'hidden', 'name' => 'proposal_id', 'value' => $id), array('type' => 'hidden', 'name' => 'lsc_confirmation', 'value' => $nonce));
	// Use the non-Ajax native confirmation: inspect the tables before submitting.
	print $form->formconfirm($url, $langs->trans('LscCleanupTitle'), $langs->trans($confirmationAction === 'confirmpaidcleanup' ? 'LscCleanupPaidWarning' : 'LscCleanupConfirm'), $confirmationAction, $fields, 'no', 0);
} else {
	$candidates = $cleanup->candidates($entity, $after);
	if ($candidates === null) { setEventMessages($cleanup->error, null, 'errors'); $candidates = array(); }
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('LscCleanupLines').'</td><td>'.$langs->trans('Amount').'</td><td>'.$langs->trans('Action').'</td></tr>';
	foreach ($candidates as $candidate) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag((string) $candidate['source_ref']).'</td><td>'.((int) $candidate['line_count']).'</td><td>'.price($candidate['amount']).'</td><td>';
		print '<form method="POST" action="'.$url.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="previewcleanup"><input type="hidden" name="proposal_id" value="'.((int) $candidate['fk_source']).'"><input class="button" type="submit" value="'.$langs->trans('Preview').'"></form></td></tr>';
	}
	if (!$candidates) { print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>'; }
	print '</table></div>';
	if (count($candidates) === 50) {
		$last = $candidates[count($candidates) - 1];
		print '<a class="butAction" href="'.$url.'?after='.((int) $last['fk_source']).'">'.$langs->trans('Next').'</a>';
	}
}
print dol_get_fiche_end();
llxFooter();
$db->close();
