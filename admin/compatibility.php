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
require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionscompatibility.class.php', 0);

$langs->loadLangs(array('admin', 'lmdbsalescommissions@lmdbsalescommissions'));
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('lmdbsalescommissions')) {
	accessforbidden();
}
if (!lmdbsalescommissionsCanConfigure($user)) {
	accessforbidden();
}
if ($action !== '') {
	accessforbidden($langs->trans('LmdbSalesCommissionsActionNotAvailableYet'));
}

llxHeader('', $langs->trans('Compatibility'));
$head = lmdbsalescommissionsAdminPrepareHead();
print dol_get_fiche_head($head, 'compatibility', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent');
print load_fiche_titre($langs->trans('Compatibility'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsDetectedDolibarrVersion').'</td><td>'.dol_escape_htmltag(defined('DOL_VERSION') ? (string) DOL_VERSION : $langs->trans('Unknown')).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsDetectedPhpVersion').'</td><td>'.dol_escape_htmltag(PHP_VERSION).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsMinimumDolibarrVersion').'</td><td>'.dol_escape_htmltag(LmdbSalesCommissionsCompatibility::MIN_DOLIBARR_VERSION).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsMinimumPhpVersion').'</td><td>'.dol_escape_htmltag(LmdbSalesCommissionsCompatibility::MIN_PHP_VERSION).'</td></tr>';
print '</table>';

print '<br>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Code').'</td>';
print '<td>'.$langs->trans('Feature').'</td>';
print '<td>'.$langs->trans('Description').'</td>';
print '<td>'.$langs->trans('Status').'</td>';
print '<td>'.$langs->trans('Reason').'</td>';
print '</tr>';

$features = LmdbSalesCommissionsCompatibility::getCompatibilityFeatures();
foreach ($features as $code => $feature) {
	$isavailable = !empty($feature['available']);
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($code).'</td>';
	print '<td>'.$langs->trans($feature['label']).'</td>';
	print '<td>'.$langs->trans($feature['description']).'</td>';
	print '<td>'.yn($isavailable).'</td>';
	print '<td>'.($isavailable ? $langs->trans('Available') : $langs->trans($feature['reason'] ?? 'Unavailable')).'</td>';
	print '</tr>';
}
print '</table>';

print dol_get_fiche_end();
llxFooter();
$db->close();
