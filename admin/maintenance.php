<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

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
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionobjectivearchiveservice.class.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissiondueservice.class.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionretroactiveservice.class.php', 0);

$langs->loadLangs(array('admin', 'lmdbsalescommissions@lmdbsalescommissions'));
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('lmdbsalescommissions')) {
	accessforbidden();
}
if (!lmdbsalescommissionsCanConfigure($user) && !lmdbsalescommissionsCanDo($user, 'maintenance', 'recalculate') && !lmdbsalescommissionsCanDo($user, 'objective', 'archive')) {
	accessforbidden();
}

$form = new Form($db);
$objectiveTypes = array(
	'monthly' => $langs->trans('Month'),
	'yearly' => $langs->trans('Year'),
);
$userOptions = lmdbsalescommissionsGetUserOptions($db, true);

if ($action === 'archiveobjective') {
	if (!lmdbsalescommissionsCanConfigure($user) && !lmdbsalescommissionsCanDo($user, 'objective', 'archive')) {
		accessforbidden();
	}
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}

	$fk_user = GETPOSTINT('fk_user');
	$objective_type = GETPOST('objective_type', 'aZ09');
	$year = GETPOSTINT('year');
	$month = GETPOSTINT('month');
	$realized_value = price2num(GETPOST('realized_value', 'alphanohtml'), 'MT');

	$errors = array();
	if ($fk_user <= 0) {
		$errors[] = $langs->trans('ErrorFieldRequired', $langs->trans('User'));
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
	if ($realized_value < 0) {
		$errors[] = $langs->trans('LmdbSalesCommissionsRealizedMustNotBeNegative');
	}

	if (empty($errors)) {
		$archiveService = new LmdbSalesCommissionObjectiveArchiveService($db);
		$result = $archiveService->archiveUserPeriod($fk_user, $objective_type, $year, $month, $user, $realized_value);
		if ($result > 0) {
			setEventMessages($langs->trans('LmdbSalesCommissionsObjectiveArchived'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}
		setEventMessages($langs->trans($archiveService->error ?: 'Error'), $archiveService->errors, 'errors');
	} else {
		setEventMessages('', $errors, 'errors');
	}
} elseif ($action === 'rebuilddues') {
	if (!lmdbsalescommissionsCanConfigure($user) && !lmdbsalescommissionsCanDo($user, 'maintenance', 'recalculate')) {
		accessforbidden();
	}
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}

	$line_id = GETPOSTINT('line_id');
	$fk_user_filter = GETPOSTINT('fk_user_rebuild');
	$service = new LmdbSalesCommissionDueService($db);
	$processed = 0;
	$created = 0;
	$error = 0;

	$sql = 'SELECT rowid';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
	$sql .= ' WHERE entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_line')).')';
	$sql .= ' AND status = 1';
	if ($line_id > 0) {
		$sql .= ' AND rowid = '.((int) $line_id);
	}
	if ($fk_user_filter > 0) {
		$sql .= ' AND fk_user = '.((int) $fk_user_filter);
	}
	$sql .= ' ORDER BY rowid ASC';

	$resql = $db->query($sql);
	if (!$resql) {
		setEventMessages($db->lasterror(), null, 'errors');
	} else {
		while (is_object($obj = $db->fetch_object($resql))) {
			$processed++;
			$result = $service->rebuildForLine((int) $obj->rowid, $user);
			if ($result < 0) {
				$error++;
				dol_syslog(__METHOD__.': '.$service->error.' for commission line '.$obj->rowid, LOG_ERR);
				continue;
			}
			$created += $result;
		}
		$db->free($resql);
		if ($error > 0) {
			setEventMessages($langs->trans('LmdbSalesCommissionsRebuildDuesPartial', $processed, $created, $error), null, 'warnings');
		} else {
			setEventMessages($langs->trans('LmdbSalesCommissionsRebuildDuesDone', $processed, $created), null, 'mesgs');
		}
	}
} elseif ($action === 'backfillsignedproposals') {
	if (!lmdbsalescommissionsCanConfigure($user) && !lmdbsalescommissionsCanDo($user, 'maintenance', 'recalculate')) {
		accessforbidden();
	}
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}

	$date_start = dol_mktime(0, 0, 0, GETPOSTINT('retroactive_date_startmonth'), GETPOSTINT('retroactive_date_startday'), GETPOSTINT('retroactive_date_startyear'));
	$date_end = dol_mktime(23, 59, 59, GETPOSTINT('retroactive_date_endmonth'), GETPOSTINT('retroactive_date_endday'), GETPOSTINT('retroactive_date_endyear'));
	$fk_user_backfill = GETPOSTINT('fk_user_backfill');

	if ($date_start <= 0 || $date_end <= 0 || $date_end < $date_start) {
		setEventMessages($langs->trans('LmdbSalesCommissionsBackfillInvalidPeriod'), null, 'errors');
	} else {
		$retroactiveService = new LmdbSalesCommissionRetroactiveService($db);
		$stats = $retroactiveService->backfillSignedProposals($date_start, $date_end, $user, $fk_user_backfill);
		$messageTemplate = !empty($langs->tab_translate['LmdbSalesCommissionsRetroactiveBackfillDone'])
			? (string) $langs->tab_translate['LmdbSalesCommissionsRetroactiveBackfillDone']
			: 'LmdbSalesCommissionsRetroactiveBackfillDone';
		$message = sprintf(
			$messageTemplate,
			$stats['analysed'],
			$stats['processed'],
			$stats['created'],
			$stats['existing'],
			$stats['tracking'],
			$stats['skipped_no_user'],
			$stats['payable_detected'],
			$stats['errors']
		);
		if ($stats['errors'] > 0) {
			$errors = $retroactiveService->error !== '' ? array($langs->trans($retroactiveService->error)) : array();
			$errors = array_merge($errors, $retroactiveService->errors);
			setEventMessages($message, $errors, 'warnings');
		} else {
			setEventMessages($message, null, 'mesgs');
		}
	}
} elseif ($action !== '') {
	accessforbidden($langs->trans('LmdbSalesCommissionsActionNotAvailableYet'));
}

llxHeader('', $langs->trans('LmdbSalesCommissionsMaintenance'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());
$head = lmdbsalescommissionsAdminPrepareHead();
print dol_get_fiche_head($head, 'maintenance', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent');
print load_fiche_titre($langs->trans('LmdbSalesCommissionsMaintenance'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');

$realizedValueInput = GETPOST('realized_value', 'alphanohtml');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="archiveobjectiveform">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="archiveobjective">';
print '<table class="border centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('LmdbSalesCommissionsManualObjectiveArchive').'</td></tr>';
print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('User').'</td><td>'.$form->selectarray('fk_user', $userOptions, GETPOSTINT('fk_user'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('LmdbSalesCommissionsObjectiveType').'</td><td>'.$form->selectarray('objective_type', $objectiveTypes, GETPOST('objective_type', 'aZ09') ?: 'monthly', 0, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('Year').'</td><td><input class="width75 right" type="text" name="year" value="'.dol_escape_htmltag((string) (GETPOSTINT('year') ?: dol_print_date(dol_now(), '%Y'))).'"></td></tr>';
print '<tr><td>'.$langs->trans('Month').'</td><td><input class="width75 right" type="text" name="month" value="'.dol_escape_htmltag((string) GETPOSTINT('month')).'"></td></tr>';
print '<tr><td>'.$langs->trans('LmdbSalesCommissionsRealizedValue').'</td><td><input class="width100 right" type="text" name="realized_value" value="'.dol_escape_htmltag($realizedValueInput !== '' ? price2num($realizedValueInput, 'MT') : '').'"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('LmdbSalesCommissionsArchiveObjective').'"></div>';
print '</form>';

print '<br>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="rebuildduesform">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="rebuilddues">';
print '<table class="border centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('LmdbSalesCommissionsRebuildDues').'</td></tr>';
print '<tr><td class="titlefield">'.$langs->trans('LmdbSalesCommissionsCommissionLine').'</td><td><input class="width75 right" type="text" name="line_id" value="'.dol_escape_htmltag((string) GETPOSTINT('line_id')).'"> <span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsOptionalLineId').'</span></td></tr>';
print '<tr><td>'.$langs->trans('SalesRepresentative').'</td><td>'.$form->selectarray('fk_user_rebuild', $userOptions, GETPOSTINT('fk_user_rebuild'), 1, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('LmdbSalesCommissionsRebuildDues').'"></div>';
print '</form>';

print '<br>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="retroactivebackfillform">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="backfillsignedproposals">';
print '<table class="border centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('LmdbSalesCommissionsRetroactiveBackfill').'</td></tr>';
print '<tr><td colspan="2"><span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsRetroactiveBackfillDesc').'</span></td></tr>';
print '<tr><td class="titlefield fieldrequired">'.$langs->trans('LmdbSalesCommissionsRetroactiveDateStart').'</td><td>';
print $form->selectDate(GETPOSTINT('retroactive_date_startyear') > 0 ? dol_mktime(0, 0, 0, GETPOSTINT('retroactive_date_startmonth'), GETPOSTINT('retroactive_date_startday'), GETPOSTINT('retroactive_date_startyear')) : -1, 'retroactive_date_start', 0, 0, 1, 'retroactivebackfillform', 1, 0);
print '</td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('LmdbSalesCommissionsRetroactiveDateEnd').'</td><td>';
print $form->selectDate(GETPOSTINT('retroactive_date_endyear') > 0 ? dol_mktime(23, 59, 59, GETPOSTINT('retroactive_date_endmonth'), GETPOSTINT('retroactive_date_endday'), GETPOSTINT('retroactive_date_endyear')) : -1, 'retroactive_date_end', 0, 0, 1, 'retroactivebackfillform', 1, 0);
print '</td></tr>';
print '<tr><td>'.$langs->trans('SalesRepresentative').'</td><td>'.$form->selectarray('fk_user_backfill', $userOptions, GETPOSTINT('fk_user_backfill'), 1, 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('LmdbSalesCommissionsRetroactiveBackfill').'"></div>';
print '</form>';

if (function_exists('ajax_combobox')) {
	print ajax_combobox('fk_user');
	print ajax_combobox('fk_user_rebuild');
	print ajax_combobox('fk_user_backfill');
	print ajax_combobox('objective_type');
}

print '<br>';
lmdbsalescommissionsPrintScaffoldTable($langs, 'LmdbSalesCommissionsMaintenanceScaffold');
print dol_get_fiche_end();
llxFooter();
$db->close();
