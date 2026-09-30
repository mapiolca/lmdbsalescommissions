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
if (!isModEnabled('lmdbsalescommissions') || !$user->admin || !$user->hasRight('lmdbsalescommissions', 'admin', 'configure')) { accessforbidden(); }
$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$rule = new LmdbSalesCommissionRule($db);
if ($id && ($rule->fetch($id) <= 0 || (int) $rule->entity !== (int) $conf->entity || $rule->rule_type !== 'margin_policy')) { accessforbidden(); }
$contexts = array('general' => $langs->trans('LscGeneral'), 'pv' => $langs->trans('LscPv'), 'storage' => $langs->trans('LscStorage'), 'mixed' => $langs->trans('LscMixed'));
$effects = array('sale' => $langs->trans('LscSale'), 'commission' => $langs->trans('LscCommission'), 'both' => $langs->trans('LscBoth'));
$form = new Form($db);
$bands = array();
$travelBands = array();
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
}
if ($action !== '') {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST' || GETPOST('token', 'alpha') === '') { accessforbidden(); }
	$db->begin();
	try {
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
		}
		if ($action === 'activate') {
			if (!LmdbSalesCommissionsCompatibility::isFeatureAvailable('margin_policy_guards')) { throw new RuntimeException('LscPolicyUnavailable'); }
			$enabled = GETPOSTINT('enabled') === 1 ? 1 : 0;
			if ($enabled && !getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_ENABLED') && dolibarr_set_const($db, 'LMDBSALESCOMMISSIONS_MARGIN_ACTIVATED_AT', (string) dol_now(), 'chaine', 0, '', (int) $conf->entity) <= 0) { throw new RuntimeException('LscPolicyUnavailable'); }
			if (dolibarr_set_const($db, 'LMDBSALESCOMMISSIONS_MARGIN_ENABLED', (string) $enabled, 'chaine', 0, '', (int) $conf->entity) <= 0) { throw new RuntimeException('LscPolicyUnavailable'); }
		} elseif ($action === 'savepolicy') {
			$context = GETPOST('policy_context', 'aZ09');
			$effect = GETPOST('policy_effect', 'aZ09');
			$rate = str_replace(',', '.', trim(GETPOST('rate', 'alphanohtml')));
			if (!isset($contexts[$context], $effects[$effect]) || ($context !== 'general' && $effect !== 'commission') || ($context === 'general' && (!is_numeric($rate) || !is_finite((float) $rate) || (float) $rate < 0))) { throw new RuntimeException('LscInvalidPolicy'); }
			if ($id && $rule->policy_context !== $context && $bands) { throw new RuntimeException('LscInvalidPolicy'); }
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
		} elseif ($action === 'togglepolicy' && $id) {
			$rule->active = (int) $rule->active ? 0 : 1;
			if ($rule->update($user) <= 0) { throw new RuntimeException('LscInvalidPolicy'); }
		} elseif ($action === 'addband' && $id && $rule->policy_context !== 'general') {
			$band = array();
			foreach (array('kwc_min', 'kwc_max', 'kwh_min', 'kwh_max', 'threshold') as $key) {
				$value = str_replace(',', '.', trim(GETPOST($key, 'alphanohtml')));
				if ($value === '' && $key !== 'threshold') { $band[$key] = null; }
				elseif (!is_numeric($value) || !is_finite((float) $value)) { throw new RuntimeException('LscInvalidBand'); }
				else { $band[$key] = (float) $value; }
			}
			$band['kwc_inclusive'] = GETPOSTINT('kwc_inclusive') === 1 ? 1 : 0;
			$band['kwh_inclusive'] = GETPOSTINT('kwh_inclusive') === 1 ? 1 : 0;
			if (!LmdbSalesCommissionMarginEngine::validBands(array_merge($bands, array($band)), $rule->policy_context)) { throw new RuntimeException('LscInvalidBand'); }
			$columns = array_keys($band); $values = array();
			foreach ($band as $value) { $values[] = $value === null ? 'NULL' : "'".$db->escape((string) $value)."'"; }
			if (!$db->query('INSERT INTO '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_band (entity,fk_rule,'.implode(',', $columns).') VALUES ('.((int) $conf->entity).','.$id.','.implode(',', $values).')')) { throw new RuntimeException('LscInvalidBand'); }
			if ($rule->update($user) <= 0) { throw new RuntimeException('LscInvalidPolicy'); }
		} elseif ($action === 'deleteband' && $id) {
			if (!$db->query('DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_band WHERE entity = '.((int) $conf->entity).' AND fk_rule = '.$id.' AND rowid = '.GETPOSTINT('band'))) { throw new RuntimeException('LscInvalidBand'); }
			if ($rule->update($user) <= 0) { throw new RuntimeException('LscInvalidPolicy'); }
		} elseif ($action === 'addtravelband' && $id) {
			$metric = GETPOST('metric', 'aZ09');
			$minimum = str_replace(',', '.', trim(GETPOST('min_value', 'alphanohtml')));
			$uplift = str_replace(',', '.', trim(GETPOST('uplift', 'alphanohtml')));
			if (!in_array($metric, array('minutes', 'kilometres'), true) || !is_numeric($minimum) || !is_numeric($uplift)) { throw new RuntimeException('LscInvalidTravelBand'); }
			$band = array('metric' => $metric, 'min_value' => (float) $minimum, 'uplift' => (float) $uplift);
			if (!LmdbSalesCommissionMarginEngine::validTravelBands(array_merge($travelBands, array($band)))) { throw new RuntimeException('LscInvalidTravelBand'); }
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_travel_band (entity,fk_rule,metric,min_value,uplift) VALUES ('.((int) $conf->entity).','.$id.",'".$db->escape($metric)."',".$band['min_value'].','.$band['uplift'].')';
			if (!$db->query($sql)) { throw new RuntimeException('LscInvalidTravelBand'); }
			if ($rule->update($user) <= 0) { throw new RuntimeException('LscInvalidPolicy'); }
		} elseif ($action === 'deletetravelband' && $id) {
			if (!$db->query('DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_travel_band WHERE entity = '.((int) $conf->entity).' AND fk_rule = '.$id.' AND rowid = '.GETPOSTINT('band'))) { throw new RuntimeException('LscInvalidTravelBand'); }
			if ($rule->update($user) <= 0) { throw new RuntimeException('LscInvalidPolicy'); }
		} else { throw new RuntimeException('LscInvalidPolicy'); }
		if (!$db->commit()) { throw new RuntimeException('LscPolicyUnavailable'); }
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].($id ? '?id='.$id : '')); exit;
	} catch (Exception $e) { $db->rollback(); setEventMessages($langs->trans($e->getMessage()), null, 'errors'); }
}
llxHeader('', $langs->trans('LscPolicies'));
print dol_get_fiche_head(lmdbsalescommissionsAdminPrepareHead(), 'marginpolicies', $langs->trans('LmdbSalesCommissionsSetup'), -1, 'fa-percent');
print load_fiche_titre($langs->trans('LscPolicies'), lmdbsalescommissionsBuildModuleListLink(), 'title_setup');
print '<p>'.$langs->trans('LscPolicyHelp').'</p>';
print '<form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="activate">';
print '<button class="noborder" name="enabled" value="'.(getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_ENABLED') ? 0 : 1).'">'.img_picto($langs->trans('Active'), getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_ENABLED') ? 'switch_on' : 'switch_off').'</button></form>';
print '<p>'.$langs->trans('LscActivationHelp').'</p>';
print '<a href="assignments.php">'.$langs->trans('LmdbSalesCommissionsAssignments').'</a> · <a href="marginpolicies.php">'.$langs->trans('New').'</a>';
$q = $db->query("SELECT rowid, ref, label, policy_context, policy_effect, active FROM ".MAIN_DB_PREFIX."lmdbsalescommissions_rule WHERE rule_type = 'margin_policy' AND entity = ".((int) $conf->entity).' ORDER BY ref');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Label').'</td><td>'.$langs->trans('LscPolicyContext').'</td><td>'.$langs->trans('LscPolicyEffect').'</td><td>'.$langs->trans('Active').'</td></tr>';
$count = 0;
if ($q) { while (is_object($row = $db->fetch_object($q))) { $count++; print '<tr class="oddeven"><td><a href="?id='.((int) $row->rowid).'">'.dol_escape_htmltag($row->ref).'</a></td><td>'.dol_escape_htmltag($row->label).'</td><td>'.($contexts[$row->policy_context] ?? '').'</td><td>'.($effects[$row->policy_effect] ?? '').'</td><td>'.yn($row->active).'</td></tr>'; } $db->free($q); }
if (!$count) { print '<tr><td colspan="5">'.$langs->trans('NoRecordFound').'</td></tr>'; }
print '</table></div><br>';
print '<form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savepolicy"><input type="hidden" name="id" value="'.$id.'"><table class="border centpercent">';
foreach (array('ref' => 'Ref', 'label' => 'Label', 'rate' => 'LscThreshold') as $key => $label) {
	print '<tr><td class="titlefield fieldrequired">'.$langs->trans($label).'</td><td><input name="'.$key.'" value="'.dol_escape_htmltag((string) ($rule->$key ?? '')).'"></td></tr>';
}
print '<tr><td>'.$langs->trans('LscPolicyContext').'</td><td>'.$form->selectarray('policy_context', $contexts, $rule->policy_context ?? 'general').'</td></tr>';
print '<tr><td>'.$langs->trans('LscPolicyEffect').'</td><td>'.$form->selectarray('policy_effect', $effects, $rule->policy_effect ?? 'commission').'</td></tr>';
print '</table><button class="button">'.$langs->trans('Save').'</button></form>';
if ($id) { print '<form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="togglepolicy"><input type="hidden" name="id" value="'.$id.'"><button class="noborder">'.img_picto($langs->trans('Active'), $rule->active ? 'switch_on' : 'switch_off').'</button></form>'; }
print ajax_combobox('policy_context'); print ajax_combobox('policy_effect');
if ($id && $rule->policy_context !== 'general') {
	print '<p>'.$langs->trans('LscBandHelp').'</p><div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>kWc</td><td>kWh</td><td>'.$langs->trans('LscThreshold').'</td><td></td></tr>';
	foreach ($bands as $band) {
		print '<tr class="oddeven">';
		foreach (array('kwc', 'kwh') as $axis) { print '<td>'.($band[$axis.'_inclusive'] ? '[' : ']').dol_escape_htmltag((string) ($band[$axis.'_min'] ?? '−∞')).' ; '.dol_escape_htmltag((string) ($band[$axis.'_max'] ?? '+∞')).']</td>'; }
		print '<td>'.dol_escape_htmltag((string) $band['threshold']).' %</td><td><form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="deleteband"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="band" value="'.$band['rowid'].'"><button class="button">'.$langs->trans('Delete').'</button></form></td></tr>';
	}
	if (!$bands) { print '<tr><td colspan="4">'.$langs->trans('NoRecordFound').'</td></tr>'; }
	print '</table></div><form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="addband"><input type="hidden" name="id" value="'.$id.'">';
	foreach (array('kwc', 'kwh') as $axis) {
		print '<p>'.($axis === 'kwc' ? 'kWc' : 'kWh').' : <input class="width75" name="'.$axis.'_min" aria-label="'.$langs->trans('LscLower').'"> '.$form->selectarray($axis.'_inclusive', array(0 => ']', 1 => '['), 0).' — <input class="width75" name="'.$axis.'_max" aria-label="'.$langs->trans('LscUpper').'"> ]</p>';
		print ajax_combobox($axis.'_inclusive');
	}
	print '<p>'.$langs->trans('LscThreshold').' <input class="width75" name="threshold"> %</p><button class="button">'.$langs->trans('Add').'</button></form>';
}
if ($id) {
	print '<h3>'.$langs->trans('LscTravelMargin').'</h3><p>'.$langs->trans('LscTravelHelp').'</p>';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('LscTravelMetric').'</td><td>'.$langs->trans('LscTravelMinimum').'</td><td>'.$langs->trans('LscTravelUplift').'</td><td></td></tr>';
	foreach ($travelBands as $band) {
		$metricLabel = $band['metric'] === 'minutes' ? 'LscTravelMinutes' : 'LscTravelKilometres';
		print '<tr class="oddeven"><td>'.$langs->trans($metricLabel).'</td><td>&gt; '.dol_escape_htmltag((string) $band['min_value']).'</td><td>+'.dol_escape_htmltag((string) $band['uplift']).' '.$langs->trans('LscPercentagePoints').'</td><td><form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="deletetravelband"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="band" value="'.$band['rowid'].'"><button class="button">'.$langs->trans('Delete').'</button></form></td></tr>';
	}
	if (!$travelBands) { print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>'; }
	print '</table></div><form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="addtravelband"><input type="hidden" name="id" value="'.$id.'">';
	print '<p>'.$langs->trans('LscTravelMetric').' '.$form->selectarray('metric', array('minutes' => $langs->trans('LscTravelMinutes'), 'kilometres' => $langs->trans('LscTravelKilometres')), $travelBands ? $travelBands[0]['metric'] : 'minutes').'</p>';
	print '<p>'.$langs->trans('LscTravelMinimum').' <input class="width75" name="min_value" required> '.$langs->trans('LscTravelUplift').' <input class="width75" name="uplift" required> '.$langs->trans('LscPercentagePoints').'</p><button class="button">'.$langs->trans('Add').'</button></form>';
	print ajax_combobox('metric');
}
print dol_get_fiche_end(); llxFooter(); $db->close();
