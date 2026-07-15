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
if (!lmdbsalescommissionsCanConfigure($user)) {
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
print dol_get_fiche_head($head, 'about', $langs->trans('LmdbSalesCommissionsSetup'), -1, (string) $moduleDescriptor->picto);
print load_fiche_titre($langs->trans('About'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');

print '<table class="noborder liste centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Module').'</td><td>'.dol_escape_htmltag($langs->trans((string) $moduleDescriptor->name)).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Version').'</td><td>'.dol_escape_htmltag((string) $moduleDescriptor->version).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Author').'</td><td>'.$editorLabel.'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Description').'</td><td>'.dol_escape_htmltag($langs->trans($descriptionKey)).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('LmdbSalesCommissionsCompatibility').'</td><td>'.dol_escape_htmltag($compatibilityLabel).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Dependencies').'</td><td>'.dol_escape_htmltag($dependencyLabel).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('MainFeatures').'</td><td>'.dol_escape_htmltag($langs->trans($moduleDescriptor->about_main_features)).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('License').'</td><td>'.dol_escape_htmltag($moduleDescriptor->module_license).'</td></tr>';
print '</table>';

print dol_get_fiche_end();
llxFooter();
$db->close();
