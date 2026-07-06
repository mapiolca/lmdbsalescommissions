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

/**
 * Count rows for a diagnostic query.
 *
 * @param DoliDB $db  Database handler
 * @param string $sql SQL query returning COUNT(*) AS nb
 * @return int
 */
function lmdbsalescommissions_check_count($db, $sql)
{
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return -1;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);

	return is_object($obj) ? (int) $obj->nb : 0;
}

$langs->loadLangs(array('admin', 'lmdbsalescommissions@lmdbsalescommissions'));
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('lmdbsalescommissions')) {
	accessforbidden();
}
if (!lmdbsalescommissionsCanConfigure($user) && !lmdbsalescommissionsCanDo($user, 'maintenance', 'recalculate')) {
	accessforbidden();
}
if ($action !== '') {
	accessforbidden($langs->trans('LmdbSalesCommissionsActionNotAvailableYet'));
}

$entityRule = $db->sanitize(getEntity('lmdbsalescommissions_rule'));
$entityPaymentTerm = $db->sanitize(getEntity('lmdbsalescommissions_payment_term'));
$entityTierGrid = $db->sanitize(getEntity('lmdbsalescommissions_tier_grid'));
$entityLine = $db->sanitize(getEntity('lmdbsalescommissions_line'));
$entityObjective = $db->sanitize(getEntity('lmdbsalescommissions_objective'));
$entityArchive = $db->sanitize(getEntity('lmdbsalescommissions_objective_archive'));
$currentYear = (int) date('Y', dol_now());

$checks = array();
$checks[] = array(
	'label' => 'LmdbSalesCommissionsCheckIncompleteRules',
	'level' => 'error',
	'count' => lmdbsalescommissions_check_count($db, "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."lmdbsalescommissions_rule WHERE entity IN (".$entityRule.") AND active = 1 AND ((rule_type = 'margin' AND (rate IS NULL OR rate <= 0)) OR (rule_type = 'tier' AND (fk_tier_grid IS NULL OR fk_tier_grid <= 0)))"),
);
$checks[] = array(
	'label' => 'LmdbSalesCommissionsCheckInvalidPaymentTerms',
	'level' => 'error',
	'count' => lmdbsalescommissions_check_count($db, 'SELECT COUNT(*) AS nb FROM (SELECT pt.rowid, SUM(CASE WHEN ptl.active = 1 THEN ptl.percentage ELSE 0 END) AS total_percentage FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term AS pt LEFT JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term_line AS ptl ON ptl.fk_payment_term = pt.rowid AND ptl.entity = pt.entity WHERE pt.entity IN ('.$entityPaymentTerm.') AND pt.active = 1 GROUP BY pt.rowid HAVING total_percentage <> 100 OR total_percentage IS NULL) AS invalid_terms'),
);
$checks[] = array(
	'label' => 'LmdbSalesCommissionsCheckInvalidTierGrids',
	'level' => 'error',
	'count' => lmdbsalescommissions_check_count($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_tier_grid AS tg WHERE tg.entity IN ('.$entityTierGrid.') AND tg.active = 1 AND NOT EXISTS (SELECT t.rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_tier AS t WHERE t.fk_tier_grid = tg.rowid AND t.entity = tg.entity AND t.active = 1 AND t.threshold_amount > 0 AND t.bonus_amount >= 0)'),
);
$checks[] = array(
	'label' => 'LmdbSalesCommissionsCheckOrphanLines',
	'level' => 'warning',
	'count' => lmdbsalescommissions_check_count($db, "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."lmdbsalescommissions_line AS l LEFT JOIN ".MAIN_DB_PREFIX."propal AS p ON p.rowid = l.fk_source AND l.source_type = 'proposal' WHERE l.entity IN (".$entityLine.") AND l.source_type = 'proposal' AND p.rowid IS NULL"),
);
$checks[] = array(
	'label' => 'LmdbSalesCommissionsCheckDueMismatch',
	'level' => 'error',
	'count' => lmdbsalescommissions_check_count($db, 'SELECT COUNT(*) AS nb FROM (SELECT l.rowid, l.commission_total, COALESCE(SUM(d.amount), 0) AS due_total FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l LEFT JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d ON d.fk_commission_line = l.rowid AND d.entity = l.entity AND d.status <> 3 WHERE l.entity IN ('.$entityLine.') AND l.status = 1 GROUP BY l.rowid, l.commission_total HAVING ABS(l.commission_total - due_total) > 0.01) AS invalid_due_lines'),
);
$checks[] = array(
	'label' => 'LmdbSalesCommissionsCheckObjectivesWithoutUser',
	'level' => 'warning',
	'count' => lmdbsalescommissions_check_count($db, "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."lmdbsalescommissions_objective WHERE entity IN (".$entityObjective.") AND active = 1 AND assignment_type = 'user' AND (fk_user IS NULL OR fk_user <= 0)"),
);
$checks[] = array(
	'label' => 'LmdbSalesCommissionsCheckInvalidObjectives',
	'level' => 'error',
	'count' => lmdbsalescommissions_check_count($db, "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."lmdbsalescommissions_objective WHERE entity IN (".$entityObjective.") AND active = 1 AND (target_value < 0 OR year <= 0 OR (objective_type = 'monthly' AND (month IS NULL OR month < 1 OR month > 12)))"),
);
$checks[] = array(
	'label' => 'LmdbSalesCommissionsCheckMissingArchives',
	'level' => 'warning',
	'count' => lmdbsalescommissions_check_count($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective AS o WHERE o.entity IN ('.$entityObjective.') AND o.active = 1 AND o.year < '.$currentYear.' AND NOT EXISTS (SELECT a.rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective_archive AS a WHERE a.entity IN ('.$entityArchive.') AND a.fk_objective = o.rowid)'),
);

llxHeader('', $langs->trans('LmdbSalesCommissionsChecks'), '', '', 0, 0, array(), lmdbsalescommissionsGetCssFiles(), '', lmdbsalescommissionsGetBodyClass());
$head = lmdbsalescommissionsAdminPrepareHead();
print dol_get_fiche_head($head, 'checks', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent');
print load_fiche_titre($langs->trans('LmdbSalesCommissionsChecks'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');

print '<table class="noborder liste centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Control').'</td><td class="center">'.$langs->trans('Severity').'</td><td class="right">'.$langs->trans('Number').'</td><td>'.$langs->trans('Comment').'</td></tr>';
foreach ($checks as $check) {
	$count = (int) $check['count'];
	$type = $count > 0 ? ($check['level'] === 'error' ? -1 : 0) : 1;
	$status = $count > 0 ? $langs->trans($check['level'] === 'error' ? 'Error' : 'Warning') : $langs->trans('OK');
	print '<tr class="oddeven">';
	print '<td>'.$langs->trans((string) $check['label']).'</td>';
	print '<td class="center">'.lmdbsalescommissionsStatusBadge($status, $type).'</td>';
	print '<td class="right">'.($count >= 0 ? (int) $count : $langs->trans('Error')).'</td>';
	print '<td><span class="opacitymedium">'.$langs->trans((string) $check['label'].'Desc').'</span></td>';
	print '</tr>';
}
print '</table>';
print dol_get_fiche_end();
llxFooter();
$db->close();
