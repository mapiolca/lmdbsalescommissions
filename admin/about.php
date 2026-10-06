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
require_once dol_buildpath('/lmdbsalescommissions/core/modules/modLmdbSalesCommissions.class.php', 0);

$langs->loadLangs(array('admin', 'lmdbsalescommissions@lmdbsalescommissions'));
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('lmdbsalescommissions')) {
	accessforbidden();
}
if (empty($user->admin) && !$user->hasRight('lmdbsalescommissions', 'admin', 'configure')) {
	accessforbidden();
}
if ($action !== '') {
	accessforbidden($langs->trans('LmdbSalesCommissionsActionNotAvailableYet'));
}

$moduleDescriptor = new modLmdbSalesCommissions($db);
$descriptionKey = !empty($moduleDescriptor->descriptionlong) ? (string) $moduleDescriptor->descriptionlong : (string) $moduleDescriptor->description;
$dolibarrMinimum = implode('.', $moduleDescriptor->need_dolibarr_version);
$phpMinimum = implode('.', $moduleDescriptor->phpmin);
$compatibilityLabel = 'Dolibarr v'.$dolibarrMinimum.'+ / PHP '.$phpMinimum.'+';

$dependencyNames = array();
foreach ($moduleDescriptor->depends as $dependencyGroup) {
	$dependencies = is_array($dependencyGroup) ? $dependencyGroup : array($dependencyGroup);
	foreach ($dependencies as $dependency) {
		if (!is_string($dependency) || $dependency === '') {
			continue;
		}
		$dependencyKey = preg_replace('/^mod/', '', $dependency);
		if (is_string($dependencyKey) && $dependencyKey !== '') {
			$dependencyNames[] = $langs->trans($dependencyKey);
		}
	}
}
$dependencyLabel = empty($dependencyNames) ? $langs->trans('None') : implode(', ', array_unique($dependencyNames));

$editorLabel = dol_escape_htmltag((string) $moduleDescriptor->editor_name);
if (!empty($moduleDescriptor->editor_url)) {
	$editorUrl = dol_escape_htmltag((string) $moduleDescriptor->editor_url);
	$editorLabel = '<a href="'.$editorUrl.'" target="_blank" rel="noopener noreferrer">'.$editorLabel.'</a>';
}

llxHeader('', $langs->trans('About'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());
$head = lmdbsalescommissionsAdminPrepareHead();
print load_fiche_titre($langs->trans('About'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');
print dol_get_fiche_head($head, 'about', $langs->trans('LmdbSalesCommissionsSetup'), -1, (string) $moduleDescriptor->picto);

print '<div class="underbanner opacitymedium">'.dol_escape_htmltag($langs->trans($descriptionKey)).'</div><br>';
print '<div class="fichecenter"><div class="fichehalfleft"><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('LscAboutGeneral').'</th></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Module').'</td><td>'.dol_escape_htmltag($langs->trans((string) $moduleDescriptor->name)).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Version').'</td><td>'.dol_escape_htmltag((string) $moduleDescriptor->version).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LscAboutFamily').'</td><td>'.dol_escape_htmltag((string) $moduleDescriptor->family).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Author').'</td><td>'.$editorLabel.'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Description').'</td><td>'.dol_escape_htmltag($langs->trans($descriptionKey)).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsCompatibility').'</td><td>'.dol_escape_htmltag($compatibilityLabel).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Dependencies').'</td><td>'.dol_escape_htmltag($dependencyLabel).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LscAboutOptionalDependencies').'</td><td>'.dol_escape_htmltag($langs->trans('LscAboutOptionalDependenciesValue')).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('License').'</td><td>'.dol_escape_htmltag($moduleDescriptor->module_license).'</td></tr>';
print '</table></div></div>';
print '<div class="fichehalfright"><div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('LscAboutResources').'</th></tr>';
foreach ($moduleDescriptor->about_resources as $resourceKey => $resourceUrl) {
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($resourceKey).'</td><td><a href="'.dol_escape_htmltag($resourceUrl).'" target="_blank" rel="noopener noreferrer">'.dol_escape_htmltag($langs->trans($resourceKey)).'</a></td></tr>';
}
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('License').'</td><td><a href="'.dol_buildpath('/lmdbsalescommissions/LICENSE', 1).'" target="_blank" rel="noopener noreferrer">'.dol_escape_htmltag($moduleDescriptor->module_license).'</a></td></tr>';
print '</table></div></div></div><div class="clearboth"></div><br>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('LscAboutFeatures').'</th></tr>';
print '<tr class="oddeven"><td>'.dol_escape_htmltag($langs->trans($moduleDescriptor->about_main_features)).'</td></tr>';
print '<tr class="oddeven"><td>'.dol_escape_htmltag($langs->trans('LscAboutMarginFeatures')).'</td></tr>';
print '</table></div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
