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
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissiondueservice.class.php', 0);

$langs->loadLangs(array('admin', 'lmdbsalescommissions@lmdbsalescommissions'));

$action = GETPOST('action', 'aZ09');

if (!isModEnabled('lmdbsalescommissions')) {
	accessforbidden();
}

if (!lmdbsalescommissionsCanConfigure($user)) {
	accessforbidden();
}

$form = new Form($db);
$finalInvoiceDueModeOptions = array(
	LmdbSalesCommissionDueService::FINAL_INVOICE_MODE_FIRST_PAID => $langs->trans('LmdbSalesCommissionsFinalInvoiceDueModeFirstPaid'),
	LmdbSalesCommissionDueService::FINAL_INVOICE_MODE_ALL_LINKED_PAID => $langs->trans('LmdbSalesCommissionsFinalInvoiceDueModeAllLinkedPaid'),
	LmdbSalesCommissionDueService::FINAL_INVOICE_MODE_ORDER_BILLED_AND_ALL_PAID => $langs->trans('LmdbSalesCommissionsFinalInvoiceDueModeOrderBilledAndAllPaid'),
);
$finalInvoiceDueMode = getDolGlobalString('LMDBSALESCOMMISSIONS_FINAL_INVOICE_DUE_MODE', LmdbSalesCommissionDueService::FINAL_INVOICE_MODE_FIRST_PAID);
if (!array_key_exists($finalInvoiceDueMode, $finalInvoiceDueModeOptions)) {
	$finalInvoiceDueMode = LmdbSalesCommissionDueService::FINAL_INVOICE_MODE_FIRST_PAID;
}
$pageUrl = $_SERVER['PHP_SELF'];

if ($action === 'savegeneralsettings') {
	if (GETPOST('token', 'alpha') === '') {
		accessforbidden($langs->trans('ErrorBadToken'));
	}

	$newFinalInvoiceDueMode = GETPOST('final_invoice_due_mode', 'alphanohtml');
	if (!array_key_exists($newFinalInvoiceDueMode, $finalInvoiceDueModeOptions)) {
		setEventMessages($langs->trans('LmdbSalesCommissionsInvalidFinalInvoiceDueMode'), null, 'errors');
	} else {
		$result = dolibarr_set_const($db, 'LMDBSALESCOMMISSIONS_FINAL_INVOICE_DUE_MODE', $newFinalInvoiceDueMode, 'chaine', 0, '', (int) $conf->entity);
		if ($result <= 0) {
			setEventMessages($db->lasterror(), null, 'errors');
		} else {
			setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
			header('Location: '.$pageUrl);
			exit;
		}
	}
} elseif ($action !== '') {
	accessforbidden($langs->trans('LmdbSalesCommissionsActionNotAvailableYet'));
}

llxHeader('', $langs->trans('LmdbSalesCommissionsSetup'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());

$head = lmdbsalescommissionsAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent');
print load_fiche_titre($langs->trans('LmdbSalesCommissionsGeneralSettings'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');
print '<form method="POST" action="'.dol_escape_htmltag($pageUrl).'" name="lmdbsalescommissionssetupform">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savegeneralsettings">';
print '<table class="border centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('LmdbSalesCommissionsGeneralSettings').'</td></tr>';
print '<tr><td class="titlefield">'.$langs->trans('LmdbSalesCommissionsFinalInvoiceDueMode').'</td><td>';
print $form->selectarray('final_invoice_due_mode', $finalInvoiceDueModeOptions, $finalInvoiceDueMode, 0, 0, 0, '', 0, 0, 0, '', 'minwidth500', 1);
print '</td></tr>';
print '<tr><td></td><td><span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsFinalInvoiceDueModeDesc').'</span></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';
if (function_exists('ajax_combobox')) {
	print ajax_combobox('final_invoice_due_mode');
}
print dol_get_fiche_end();

llxFooter();
$db->close();
