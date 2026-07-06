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

llxHeader('', $langs->trans('About'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());
$head = lmdbsalescommissionsAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent');
print load_fiche_titre($langs->trans('About'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');

print '<table class="noborder liste centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Module').'</td><td>'.$langs->trans('LmdbSalesCommissions').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Version').'</td><td>0.1.0</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Author').'</td><td>Pierre Ardoin &lt;developpeur@lesmetiersdubatiment.fr&gt;</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Description').'</td><td>'.$langs->trans('LmdbSalesCommissionsDescLong').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsCompatibility').'</td><td>'.$langs->trans('LmdbSalesCommissionsCompatibilitySummary').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Dependencies').'</td><td>'.$langs->trans('LmdbSalesCommissionsNoMandatoryDependency').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('MainFeatures').'</td><td>'.$langs->trans('LmdbSalesCommissionsMainFeaturesSummary').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('License').'</td><td>AGPL-3.0-or-later</td></tr>';
print '</table>';

print dol_get_fiche_end();
llxFooter();
$db->close();
