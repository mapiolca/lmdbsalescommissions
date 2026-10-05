<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
$res = 0;
if (!$res && file_exists('../../main.inc.php')) { $res = include '../../main.inc.php'; }
if (!$res && file_exists('../../../main.inc.php')) { $res = include '../../../main.inc.php'; }
if (!$res) { die('Include of main fails'); }
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/../lib/lmdbsalescommissions.lib.php';
require_once __DIR__.'/../class/lmdbsalescommissionrule.class.php';
require_once __DIR__.'/../class/lmdbsalescommissionmarginengine.class.php';
require_once __DIR__.'/../class/lmdbsalescommissionscompatibility.class.php';
$langs->loadLangs(array('admin', 'lmdbsalescommissions@lmdbsalescommissions'));
if (!isModEnabled('lmdbsalescommissions') || !$user->admin || !empty($user->socid) || !$user->hasRight('lmdbsalescommissions', 'admin', 'configure')) { accessforbidden(); }
$action = GETPOST('action', 'aZ09');
$travelAvailable = LmdbSalesCommissionsCompatibility::isFeatureAvailable('travel_margin_uplift');
$complexAvailable = LmdbSalesCommissionsCompatibility::isFeatureAvailable('complex_site_margin_uplift');
if (in_array($action, array('addtravelband', 'deletetravelband'), true) && !$travelAvailable) { accessforbidden(); }
if (in_array($action, array('savecomplexsite', 'deletecomplexsite'), true) && !$complexAvailable) { accessforbidden(); }
$id = GETPOSTINT('id');
$mode = GETPOST('mode', 'aZ09');
if ($mode === '' && $id > 0 && $action === '') { $mode = 'edit'; }
$pageUrl = dol_buildpath('/lmdbsalescommissions/admin/marginpolicies.php', 1);
if (!in_array($mode, array('', 'create', 'edit', 'delete'), true) || (in_array($mode, array('edit', 'delete'), true) && $id <= 0) || ($mode === 'create' && $id)) { accessforbidden(); }
$rule = new LmdbSalesCommissionRule($db);
if ($id && ($rule->fetch($id) <= 0 || (int) $rule->entity !== (int) $conf->entity || $rule->rule_type !== 'margin_policy')) { accessforbidden(); }
$contexts = array('general' => $langs->trans('LscGeneral'), 'pv' => $langs->trans('LscPv'), 'storage' => $langs->trans('LscStorage'), 'mixed' => $langs->trans('LscMixed'));
$effects = array('sale' => $langs->trans('LscSale'), 'commission' => $langs->trans('LscCommission'), 'both' => $langs->trans('LscBoth'));
$form = new Form($db);
$bands = array();
$travelBands = array();
$complexSite = null;
if ($id) {
	$q = $db->query('SELECT * FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_band WHERE entity = '.((int) $conf->entity).' AND fk_rule = '.$id.' ORDER BY rowid');
	if (!$q) { dol_print_error($db); exit; }
	while (is_object($row = $db->fetch_object($q))) {
		$band = array('rowid' => (int) $row->rowid, 'threshold' => (float) $row->threshold);
		foreach (array('kwc', 'kwh') as $axis) {
			$band[$axis.'_min'] = $row->{$axis.'_min'} === null ? null : (float) $row->{$axis.'_min'};
			$band[$axis.'_max'] = $row->{$axis.'_max'} === null ? null : (float) $row->{$axis.'_max'};
			$band[$axis.'_inclusive'] = (int) $row->{$axis.'_inclusive'};
		}
		$bands[] = $band;
	}
	$db->free($q);
	$q = $db->query('SELECT rowid, metric, min_value, uplift FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_travel_band WHERE entity = '.((int) $conf->entity).' AND fk_rule = '.$id.' ORDER BY min_value');
	if (!$q) { dol_print_error($db); exit; }
	while (is_object($row = $db->fetch_object($q))) { $travelBands[] = array('rowid' => (int) $row->rowid, 'metric' => (string) $row->metric, 'min_value' => (float) $row->min_value, 'uplift' => (float) $row->uplift); }
	$db->free($q);
	$q = $db->query('SELECT rowid, uplift_without_travel, uplift_with_travel FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_complex_site WHERE entity = '.((int) $conf->entity).' AND fk_rule = '.$id);
	if (!$q) { dol_print_error($db); exit; }
	if (is_object($row = $db->fetch_object($q))) { $complexSite = array('rowid' => (int) $row->rowid, 'uplift_without_travel' => (float) $row->uplift_without_travel, 'uplift_with_travel' => $row->uplift_with_travel === null ? null : (float) $row->uplift_with_travel); }
	$db->free($q);
}
if ($action !== '') {
	// Native switches and formconfirm use token-protected GET; editor and band forms use POST.
	if (GETPOST('token', 'alpha') === '' || ($_SERVER['REQUEST_METHOD'] !== 'POST' && !in_array($action, array('activate', 'togglepolicy', 'confirm_delete'), true))) { accessforbidden(); }
	if ($action === 'confirm_delete' && GETPOST('confirm', 'alpha') !== 'yes') { header('Location: '.$pageUrl); exit; }
	$originalId = $id;
	$transactionStarted = $db->begin();
	try {
		if (!$transactionStarted) { throw new RuntimeException('LscPolicyUnavailable'); }
		if ($id) {
			$lock = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule WHERE entity = '.((int) $conf->entity).' AND rowid = '.$id.' FOR UPDATE');
			if (!$lock || !$db->num_rows($lock)) { throw new RuntimeException('LscPolicyUnavailable'); }
			$db->free($lock);
			if ($rule->fetch($id) <= 0 || $rule->rule_type !== 'margin_policy') { throw new RuntimeException('LscInvalidPolicy'); }
			$q = $db->query('SELECT * FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_band WHERE entity = '.((int) $conf->entity).' AND fk_rule = '.$id.' ORDER BY rowid');
			if (!$q) { throw new RuntimeException('LscPolicyUnavailable'); }
			$bands = array();
			while (is_object($row = $db->fetch_object($q))) {
				$band = array('rowid' => (int) $row->rowid, 'threshold' => (float) $row->threshold);
				foreach (array('kwc', 'kwh') as $axis) {
					$band[$axis.'_min'] = $row->{$axis.'_min'} === null ? null : (float) $row->{$axis.'_min'};
					$band[$axis.'_max'] = $row->{$axis.'_max'} === null ? null : (float) $row->{$axis.'_max'};
					$band[$axis.'_inclusive'] = (int) $row->{$axis.'_inclusive'};
				}
				$bands[] = $band;
			}
			$db->free($q);
			$q = $db->query('SELECT rowid, metric, min_value, uplift FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_travel_band WHERE entity = '.((int) $conf->entity).' AND fk_rule = '.$id.' ORDER BY min_value');
			if (!$q) { throw new RuntimeException('LscPolicyUnavailable'); }
			$travelBands = array();
			while (is_object($row = $db->fetch_object($q))) { $travelBands[] = array('rowid' => (int) $row->rowid, 'metric' => (string) $row->metric, 'min_value' => (float) $row->min_value, 'uplift' => (float) $row->uplift); }
			$db->free($q);
			$q = $db->query('SELECT rowid, uplift_without_travel, uplift_with_travel FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_complex_site WHERE entity = '.((int) $conf->entity).' AND fk_rule = '.$id);
			if (!$q) { throw new RuntimeException('LscPolicyUnavailable'); }
			$complexSite = null;
			if (is_object($row = $db->fetch_object($q))) { $complexSite = array('rowid' => (int) $row->rowid, 'uplift_without_travel' => (float) $row->uplift_without_travel, 'uplift_with_travel' => $row->uplift_with_travel === null ? null : (float) $row->uplift_with_travel); }
			$db->free($q);
		}
		if ($action === 'activate') {
			if (!LmdbSalesCommissionsCompatibility::isFeatureAvailable('margin_policy_guards')) { throw new RuntimeException('LscPolicyUnavailable'); }
			$enabled = GETPOSTINT('enabled') === 1 ? 1 : 0;
			if ($enabled && !getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_ENABLED') && dolibarr_set_const($db, 'LMDBSALESCOMMISSIONS_MARGIN_ACTIVATED_AT', (string) dol_now(), 'chaine', 0, '', (int) $conf->entity) <= 0) { throw new RuntimeException('LscPolicyUnavailable'); }
			if (dolibarr_set_const($db, 'LMDBSALESCOMMISSIONS_MARGIN_ENABLED', (string) $enabled, 'chaine', 0, '', (int) $conf->entity) <= 0) { throw new RuntimeException('LscPolicyUnavailable'); }
		} elseif ($action === 'savepolicy') {
			$complexWithout = str_replace(',', '.', trim(GETPOST('complex_without_travel', 'alphanohtml')));
			$complexWith = str_replace(',', '.', trim(GETPOST('complex_with_travel', 'alphanohtml')));
			$pendingComplex = !$id && ($complexWithout !== '' || $complexWith !== '');
			if ($pendingComplex && !$complexAvailable) { throw new RuntimeException('LscComplexSiteCompatibilityUnavailable'); }
			if ($pendingComplex && ($complexWithout === '' || !is_numeric($complexWithout) || ($complexWith !== '' && !is_numeric($complexWith))
				|| !LmdbSalesCommissionMarginEngine::validComplexSiteUplift(array('uplift_without_travel' => (float) $complexWithout, 'uplift_with_travel' => $complexWith === '' ? null : (float) $complexWith)))) { throw new RuntimeException('LscInvalidComplexSiteUplift'); }
			$context = GETPOST('policy_context', 'aZ09');
			$effect = GETPOST('policy_effect', 'aZ09');
			$rate = str_replace(',', '.', trim(GETPOST('rate', 'alphanohtml')));
			if (!isset($contexts[$context], $effects[$effect]) || ($context !== 'general' && $effect !== 'commission') || ($context === 'general' && (!is_numeric($rate) || !is_finite((float) $rate) || (float) $rate < 0))) { throw new RuntimeException('LscInvalidPolicy'); }
			if ($id && $rule->policy_context !== $context && $bands) { throw new RuntimeException('LscPolicyContextHasBands'); }
			$rule->entity = (int) $conf->entity;
			$rule->ref = trim(GETPOST('ref', 'alphanohtml'));
			$rule->label = trim(GETPOST('label', 'alphanohtml'));
			$rule->rule_type = 'margin_policy';
			$rule->policy_context = $context; $rule->policy_effect = $effect;
			$rule->rate = $context === 'general' ? (float) $rate : null;
			$rule->source_type = 'proposal'; $rule->period_type = 'monthly'; $rule->cumulative = 1; $rule->priority = 0;
			$rule->active = $id ? (int) $rule->active : 0;
			$rule->negative_margin_mode = 'zero';
			if (!$rule->validateField($rule->fields, 'ref', $rule->ref) || !$rule->validateField($rule->fields, 'label', $rule->label)) { throw new RuntimeException('LscInvalidPolicy'); }
			$result = $id ? $rule->update($user) : $rule->create($user);
			if ($result <= 0) { throw new RuntimeException('LscInvalidPolicy'); }
			if (!$id) { $id = $result; }
			if ($pendingComplex) {
				$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_complex_site (entity,fk_rule,uplift_without_travel,uplift_with_travel) VALUES ('.((int) $conf->entity).','.$id.','.(float) $complexWithout.','.($complexWith === '' ? 'NULL' : (string) (float) $complexWith).')';
				if (!$db->query($sql)) { throw new RuntimeException('LscInvalidComplexSiteUplift'); }
			}
		} elseif ($action === 'confirm_delete' && $id) {
			if ($rule->delete($user) <= 0) { throw new RuntimeException($rule->error); }
			$id = 0;
		} elseif ($action === 'togglepolicy' && $id) {
			if (!$rule->active && $travelBands && !$travelAvailable) { throw new RuntimeException('LscTravelCompatibilityUnavailable'); }
			if (!$rule->active && $complexSite !== null && !$complexAvailable) { throw new RuntimeException('LscComplexSiteCompatibilityUnavailable'); }
			$rule->active = (int) $rule->active ? 0 : 1;
			if ($rule->update($user) <= 0) { throw new RuntimeException('LscInvalidPolicy'); }
		} elseif ($action === 'deleteband' && $id) {
			if (!$db->query('DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_band WHERE entity = '.((int) $conf->entity).' AND fk_rule = '.$id.' AND rowid = '.GETPOSTINT('band'))) { throw new RuntimeException('LscInvalidBand'); }
			if ($rule->update($user) <= 0) { throw new RuntimeException('LscInvalidPolicy'); }
		} elseif ($action === 'addtravelband' && $id) {
			$metric = GETPOST('metric', 'aZ09');
			$minimum = str_replace(',', '.', trim(GETPOST('min_value', 'alphanohtml')));
			$uplift = str_replace(',', '.', trim(GETPOST('uplift', 'alphanohtml')));
			if (!in_array($metric, array('minutes', 'kilometres'), true) || !is_numeric($minimum) || !is_numeric($uplift)) { throw new RuntimeException('LscInvalidTravelBand'); }
			$travelBand = array('metric' => $metric, 'min_value' => (float) $minimum, 'uplift' => (float) $uplift);
			if (!LmdbSalesCommissionMarginEngine::validTravelBands(array_merge($travelBands, array($travelBand)))) { throw new RuntimeException('LscInvalidTravelBand'); }
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_travel_band (entity,fk_rule,metric,min_value,uplift) VALUES ('.((int) $conf->entity).','.$id.",'".$db->escape($metric)."',".$travelBand['min_value'].','.$travelBand['uplift'].')';
			if (!$db->query($sql)) { throw new RuntimeException('LscInvalidTravelBand'); }
			if ($rule->update($user) <= 0) { throw new RuntimeException('LscInvalidPolicy'); }
		} elseif ($action === 'deletetravelband' && $id) {
			if (!$db->query('DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_travel_band WHERE entity = '.((int) $conf->entity).' AND fk_rule = '.$id.' AND rowid = '.GETPOSTINT('band'))) { throw new RuntimeException('LscInvalidTravelBand'); }
			if ($rule->update($user) <= 0) { throw new RuntimeException('LscInvalidPolicy'); }
		} elseif ($action === 'savecomplexsite' && $id) {
			$complexWithout = str_replace(',', '.', trim(GETPOST('complex_without_travel', 'alphanohtml')));
			$complexWith = str_replace(',', '.', trim(GETPOST('complex_with_travel', 'alphanohtml')));
			if (!is_numeric($complexWithout) || ($complexWith !== '' && !is_numeric($complexWith))
				|| !LmdbSalesCommissionMarginEngine::validComplexSiteUplift(array('uplift_without_travel' => (float) $complexWithout, 'uplift_with_travel' => $complexWith === '' ? null : (float) $complexWith))) { throw new RuntimeException('LscInvalidComplexSiteUplift'); }
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_complex_site (entity,fk_rule,uplift_without_travel,uplift_with_travel) VALUES ('.((int) $conf->entity).','.$id.','.(float) $complexWithout.','.($complexWith === '' ? 'NULL' : (string) (float) $complexWith).') ON DUPLICATE KEY UPDATE uplift_without_travel = VALUES(uplift_without_travel), uplift_with_travel = VALUES(uplift_with_travel)';
			if (!$db->query($sql) || $rule->update($user) <= 0) { throw new RuntimeException('LscInvalidComplexSiteUplift'); }
		} elseif ($action === 'deletecomplexsite' && $id) {
			if (!$db->query('DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_complex_site WHERE entity = '.((int) $conf->entity).' AND fk_rule = '.$id)
				|| $rule->update($user) <= 0) { throw new RuntimeException('LscInvalidComplexSiteUplift'); }
		} elseif ($action !== 'addband' || !$id || $rule->policy_context === 'general') { throw new RuntimeException('LscInvalidPolicy'); }
		// Save a pending band with the policy, including its first creation, in the same transaction.
		if (in_array($action, array('savepolicy', 'addband'), true) && $rule->policy_context !== 'general') {
			$band = array();
			$hasBandInput = $action === 'addband' || GETPOST('add_band_continue', 'alpha') !== '';
			foreach (array('kwc_min', 'kwc_max', 'kwh_min', 'kwh_max', 'threshold') as $key) {
				$value = str_replace(',', '.', trim(GETPOST($key, 'alphanohtml')));
				if ($value !== '') { $hasBandInput = true; }
				if ($value === '') { $band[$key] = null; }
				elseif (!is_numeric($value) || !is_finite((float) $value)) { throw new RuntimeException('LscInvalidBand'); }
				else { $band[$key] = (float) $value; }
			}
			$band['kwc_inclusive'] = GETPOSTINT('kwc_inclusive') === 1 ? 1 : 0;
			$band['kwh_inclusive'] = GETPOSTINT('kwh_inclusive') === 1 ? 1 : 0;
			if ($hasBandInput) {
				if ($band['threshold'] === null || !LmdbSalesCommissionMarginEngine::validBands(array_merge($bands, array($band)), $rule->policy_context)) { throw new RuntimeException('LscInvalidBand'); }
				$columns = array_keys($band); $values = array();
				foreach ($band as $value) { $values[] = $value === null ? 'NULL' : "'".$db->escape((string) $value)."'"; }
				if (!$db->query('INSERT INTO '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_band (entity,fk_rule,'.implode(',', $columns).') VALUES ('.((int) $conf->entity).','.$id.','.implode(',', $values).')')) { throw new RuntimeException('LscInvalidBand'); }
				if ($action === 'addband' && $rule->update($user) <= 0) { throw new RuntimeException('LscInvalidPolicy'); }
			}
		}
		if (!$db->commit()) { throw new RuntimeException('LscPolicyUnavailable'); }
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		$keepEditor = $id && (in_array($action, array('addband', 'deleteband', 'addtravelband', 'deletetravelband', 'savecomplexsite', 'deletecomplexsite'), true) || ($action === 'togglepolicy' && $mode === 'edit') || ($action === 'savepolicy' && GETPOST('add_band_continue', 'alpha') !== '' && $rule->policy_context !== 'general'));
		header('Location: '.$pageUrl.($keepEditor ? '?mode=edit&id='.$id : '')); exit;
	} catch (Exception $e) {
		if ($transactionStarted) { $db->rollback(); }
		$id = $originalId;
		setEventMessages($langs->trans($e->getMessage()), null, 'errors');
		$mode = $action === 'savepolicy' ? ($id ? 'edit' : 'create') : ($id && $action !== 'confirm_delete' ? 'edit' : '');
	}
}
$formValues = array();
foreach (array('ref', 'label', 'rate', 'policy_context', 'policy_effect') as $key) {
	$formValues[$key] = $action === 'savepolicy' ? GETPOST($key, 'alphanohtml') : (string) ($rule->$key ?? '');
}
$bandValues = array();
foreach (array('kwc_min', 'kwc_max', 'kwc_inclusive', 'kwh_min', 'kwh_max', 'kwh_inclusive', 'threshold') as $key) {
	$bandValues[$key] = in_array($action, array('savepolicy', 'addband'), true) ? GETPOST($key, 'alphanohtml') : '';
}
$travelValues = array(
	'metric' => $action === 'addtravelband' ? GETPOST('metric', 'aZ09') : ($travelBands ? $travelBands[0]['metric'] : 'minutes'),
	'min_value' => $action === 'addtravelband' ? GETPOST('min_value', 'alphanohtml') : '',
	'uplift' => $action === 'addtravelband' ? GETPOST('uplift', 'alphanohtml') : '',
);
$complexValues = array(
	'without' => ($action === 'savecomplexsite' || ($action === 'savepolicy' && !$id)) ? GETPOST('complex_without_travel', 'alphanohtml') : ($complexSite === null ? '' : (string) $complexSite['uplift_without_travel']),
	'with' => ($action === 'savecomplexsite' || ($action === 'savepolicy' && !$id)) ? GETPOST('complex_with_travel', 'alphanohtml') : ($complexSite === null || $complexSite['uplift_with_travel'] === null ? '' : (string) $complexSite['uplift_with_travel']),
);
$policies = array();
$q = $db->query("SELECT rowid, ref, label, policy_context, policy_effect, active FROM ".MAIN_DB_PREFIX."lmdbsalescommissions_rule WHERE rule_type = 'margin_policy' AND entity = ".((int) $conf->entity).' ORDER BY ref');
if (!$q) { dol_print_error($db); exit; }
while (is_object($row = $db->fetch_object($q))) { $policies[] = $row; }
$db->free($q);
llxHeader('', $langs->trans('LscPolicies'), '', '', 0, 0, array(dol_buildpath('/lmdbsalescommissions/js/marginpolicies.js', 1)));
print dol_get_fiche_head(lmdbsalescommissionsAdminPrepareHead(), 'marginpolicies', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent_fas_#f0b400');
print load_fiche_titre($langs->trans('LscPolicies'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');
require __DIR__.'/../tpl/marginpolicies.tpl.php';
print dol_get_fiche_end(); llxFooter(); $db->close();
