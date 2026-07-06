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

llxHeader('', $langs->trans('LmdbSalesCommissionsSetup'));

$head = lmdbsalescommissionsAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent');
print load_fiche_titre($langs->trans('LmdbSalesCommissionsGeneralSettings'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');
lmdbsalescommissionsPrintScaffoldTable($langs, 'LmdbSalesCommissionsGeneralSettingsScaffold');
print dol_get_fiche_end();

llxFooter();
$db->close();
