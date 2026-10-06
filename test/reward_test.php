<?php
/** Reward formulas, actual rule resolver and allocation services; SQL/configuration simulated.
 * Native CommonObject is loaded from each pinned core revision. Not a deployed-instance test.
 */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('DOL_DOCUMENT_ROOT', $argv[1]);
define('DOL_VERSION', $argv[2] ?? '20.0.0');
define('MAIN_DB_PREFIX', 'reward_test_');
if (!defined('LOG_ERR')) { define('LOG_ERR', 3); }
$conf = (object) array('entity' => 1);
$precision = 2;
// Execute the original native price normalizer, with controlled configuration.
$functions = file_get_contents(DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php');
if (!preg_match('/^function price2num\(.*?^}/ms', $functions, $match)) { throw new RuntimeException('Native price2num missing'); }
eval($match[0]);
function dol_eval($expression, ...$args) { return $expression === '1' ? 1 : 0; }
function dol_strlen($value) { return strlen((string) $value); }
function dol_now() { return 2000; }
function dol_syslog($message, $level = 0) {}
function getDolGlobalInt($key, $default = 0) { global $precision; return $key === 'MAIN_MAX_DECIMALS_TOT' ? $precision : $default; }
function getDolGlobalString($key, $default = '') { global $precision; return $key === 'MAIN_MAX_DECIMALS_TOT' ? (string) $precision : $default; }
function isModEnabled($key) { return $key === 'lmdbsalescommissions'; }
require_once __DIR__.'/../class/lmdbsalescommissionrewardservice.class.php';
$tests = 0;
function checkReward($actual, $expected, $label) {
	global $tests; $tests++;
	if ($actual !== $expected) { throw new RuntimeException($label.': '.var_export($actual, true).' != '.var_export($expected, true)); }
}
$decision = array('commission' => 'allow', 'checks' => array(
	array('effect' => 'sale', 'threshold' => 80.0, 'state' => 'allow'),
	array('effect' => 'commission', 'threshold' => 30.0, 'state' => 'allow'),
	array('effect' => 'commission', 'threshold' => 40.0, 'state' => 'allow'),
), 'inputs' => array('cost' => 10000.0, 'sale' => 15000.0), 'fingerprint' => 'original', 'frozen' => false);
$r = LmdbSalesCommissionRewardService::calculate($decision, 'percentage', 25.0, 0.6);
checkReward($r['amount'], 150.0, 'Plan example');
checkReward($r['threshold'], 40.0, 'Highest commission target, not sale floor');
checkReward($r['surplus'], 1000.0, 'Surplus in currency, not margin percentage points');
checkReward(LmdbSalesCommissionRewardService::calculate($decision, 'fixed', 200.0, 0.6)['amount'], 120.0, 'Prorated fixed reward');
checkReward(LmdbSalesCommissionRewardService::calculate($decision, 'fixed', 200.0, 0.0)['reason'], 'no_share', 'No share is not full margin');
foreach (array(14000.0, 13999.0, 12000.0) as $sale) {
	$d = $decision; $d['inputs']['sale'] = $sale;
	foreach (array('fixed', 'percentage') as $mode) { checkReward(LmdbSalesCommissionRewardService::calculate($d, $mode, 25.0, 1.0)['amount'], 0.0, 'Below or equal, including an approved exception'); }
}
foreach (array(null, 0.0, -10.0, INF) as $cost) {
	$d = $decision; $d['inputs']['cost'] = $cost;
	checkReward(LmdbSalesCommissionRewardService::calculate($d, 'fixed', 200.0, 1.0)['amount'], 0.0, 'Invalid cost never unlocks fixed reward');
}
foreach (array('deny', 'unknown') as $state) { $d = $decision; $d['commission'] = $state; checkReward(LmdbSalesCommissionRewardService::calculate($d, 'fixed', 200.0, 1.0)['amount'], 0.0, 'Commission decision enforced'); }
$d = $decision; $d['checks'] = array();
checkReward(LmdbSalesCommissionRewardService::calculate($d, 'fixed', 200.0, 1.0)['reason'], 'no_target', 'No threshold does not mean zero threshold');
$d['checks'][] = array('effect' => 'commission', 'threshold' => 0.0, 'state' => 'allow');
checkReward(LmdbSalesCommissionRewardService::calculate($d, 'percentage', 25.0, 1.0)['amount'], 1250.0, 'Explicit zero target');
$d['checks'][0]['threshold'] = 150.0; $d['inputs']['sale'] = 30000.0;
checkReward(LmdbSalesCommissionRewardService::calculate($d, 'percentage', 25.0, 1.0)['amount'], 1250.0, 'Margin target above 100 percent');
foreach (array(array('fixed', 0.0), array('fixed', -1.0), array('fixed', INF), array('percentage', 101.0), array('other', 25.0)) as $invalid) { checkReward(LmdbSalesCommissionRewardService::validValue(...$invalid), false, 'Invalid reward setting'); }
$precision = 3;
checkReward(LmdbSalesCommissionRewardService::calculate($decision, 'fixed', 10.555, 0.5)['amount'], 5.278, 'MT precision delegated to native helper contract');
$precision = 2;
checkReward(LmdbSalesCommissionRewardService::calculate($decision, 'fixed', 10.555, 0.5)['amount'], 5.28, 'Changed MT setting');

// Combined policy/reward regression: use the effective target produced by travel controls.
$travelPolicy = array('rule_id' => 20, 'context' => 'general', 'effect' => 'commission', 'rank' => 3, 'origin' => 'TRAVEL', 'threshold' => 30.0, 'bands' => array(), 'travel_bands' => array(
	array('metric' => 'minutes', 'min_value' => 105.0, 'uplift' => 15.0),
	array('metric' => 'minutes', 'min_value' => 150.0, 'uplift' => 20.0),
));
$pvPolicy = array('rule_id' => 21, 'context' => 'pv', 'effect' => 'commission', 'rank' => 3, 'origin' => 'PV', 'threshold' => null, 'bands' => array(
	array('kwc_min' => 0.0, 'kwc_max' => null, 'kwc_inclusive' => 0, 'kwh_min' => null, 'kwh_max' => null, 'kwh_inclusive' => 0, 'threshold' => 40.0),
));
$travelDecision = static function ($minutes, $sale = 15000.0) use ($travelPolicy, $pvPolicy) {
	$result = LmdbSalesCommissionMarginEngine::evaluate(array($travelPolicy, $pvPolicy), ($sale - 10000.0) / 10000.0 * 100, 6.0, 0.0, array('minutes' => $minutes, 'kilometres' => null));
	$result['inputs'] = array('cost' => 10000.0, 'sale' => $sale);
	return $result;
};
$r = LmdbSalesCommissionRewardService::calculate($travelDecision(106.0), 'percentage', 25.0, 0.6);
checkReward($r['threshold'], 45.0, 'Travel uplift can raise the general target above the PV target');
checkReward($r['surplus'], 500.0, 'Surplus excludes the full travel uplift');
checkReward($r['amount'], 75.0, 'Reward percentage uses effective travel target and CA share');
checkReward(LmdbSalesCommissionRewardService::calculate($travelDecision(106.0), 'fixed', 200.0, 0.6)['amount'], 120.0, 'Fixed reward retained above uplifted target');
checkReward(LmdbSalesCommissionRewardService::calculate($travelDecision(105.0), 'percentage', 25.0, 0.6)['amount'], 150.0, 'Exact travel boundary keeps highest applicable unraised target');
checkReward(LmdbSalesCommissionRewardService::calculate($travelDecision(105.00001), 'percentage', 25.0, 0.6)['amount'], 75.0, 'Travel comparison is not rounded for the reward');
foreach (array('fixed', 'percentage') as $mode) {
	checkReward(LmdbSalesCommissionRewardService::calculate($travelDecision(151.0), $mode, 25.0, 1.0)['amount'], 0.0, 'Equality with uplifted target earns no reward');
	checkReward(LmdbSalesCommissionRewardService::calculate($travelDecision(null), $mode, 25.0, 1.0)['amount'], 0.0, 'Unavailable or stale travel decision cannot earn a reward');
}
$approved = $travelDecision(106.0, 14200.0);
$approved['checks'][0]['state'] = 'allow'; $approved['checks'][0]['reason'] = 'approved';
$approved = LmdbSalesCommissionMarginEngine::aggregate($approved);
checkReward(LmdbSalesCommissionRewardService::calculate($approved, 'fixed', 200.0, 1.0)['amount'], 0.0, 'Commission approval never removes the travel uplift from reward target');
$complexRewardPolicy = $travelPolicy;
$complexRewardPolicy['complex_site'] = array('uplift_without_travel' => 10.0, 'uplift_with_travel' => 20.0);
$complexDecision = LmdbSalesCommissionMarginEngine::evaluate(array($complexRewardPolicy), 70.0, null, null, array('minutes' => 106.0, 'kilometres' => null), true);
$complexDecision['inputs'] = array('cost' => 10000.0, 'sale' => 17000.0);
checkReward($complexDecision['checks'][0]['threshold'], 65.0, 'Reward receives the combined travel and complex target');
checkReward(LmdbSalesCommissionRewardService::calculate($complexDecision, 'percentage', 25.0, 1.0)['amount'], 125.0, 'Reward surplus uses the combined target');

class RewardDb {
	public $rules = array(); public $allocations = array(); public $fail = false; public $queries = array();
	public function query($sql) {
		$this->queries[] = $sql;
		if ($this->fail) { return false; }
		$rows = array();
		if (strpos($sql, 'FROM reward_test_extrafields') !== false) {}
		elseif (strpos($sql, '_rule_assignment AS a') !== false) {
			if (strpos($sql, "r.rule_type = 'margin_excess'") !== false) { $rows = $this->rules; }
		} elseif (strpos($sql, 'SELECT d.*') !== false && strpos($sql, '_proposal_turnover_dispatch') !== false) { $rows = $this->allocations; }
		elseif (strpos($sql, '_proposal_turnover_dispatch') !== false && strpos($sql, 'rowid <>') === false) { $rows = $this->allocations; }
		elseif (strpos($sql, 'FROM reward_test_user WHERE') !== false) { $rows = array(array('rowid' => 7)); }
		elseif (strpos($sql, 'usergroup_user') !== false || strpos($sql, '_proposal_dispatch') !== false || strpos($sql, '_proposal_turnover_dispatch') !== false) {}
		else { throw new RuntimeException('Unexpected SQL: '.$sql); }
		if (strpos($sql, ' as t') !== false && strpos($sql, '_proposal_turnover_dispatch') !== false) {
			$defaults = array_fill_keys(array_keys((new LmdbSalesCommissionProposalTurnoverDispatch($this))->fields), null);
			$rows = array_map(static function ($row) use ($defaults) { return $row + $defaults; }, $rows);
		}
		return (object) array('rows' => $rows, 'offset' => 0);
	}
	public function fetch_object($q) { return isset($q->rows[$q->offset]) ? (object) $q->rows[$q->offset++] : false; }
	public function free($q) {}
	public function num_rows($q) { return count($q->rows); }
	public function escape($value) { return addslashes($value); }
	public function sanitize($value, ...$args) { return preg_replace('/[^a-zA-Z0-9_.,]/', '', $value); }
	public function prefix() { return MAIN_DB_PREFIX; }
	public function jdate($date) { return $date === null ? null : strtotime($date); }
	public function idate($date) { return gmdate('Y-m-d H:i:s', $date); }
	public function lasterror() { return 'Injected failure'; }
}
$db = new RewardDb(); $service = new LmdbSalesCommissionRewardService($db);
$proposal = (object) array('id' => 10, 'entity' => 1, 'total_ht' => 15000.0, 'date_signature' => 0, 'status' => 1, 'user_author_id' => 7, 'context' => array());
$rule = array('assignment_id' => 1, 'assignment_type' => 'default', 'assignment_priority' => 0, 'rule_id' => 42, 'rule_ref' => 'R', 'rule_label' => 'Reward', 'rule_type' => 'margin_excess', 'source_type' => 'proposal', 'period_type' => 'monthly', 'reward_mode' => 'percentage', 'reward_value' => 25.0, 'rate' => null, 'fk_tier_grid' => null, 'assignment_payment_term' => null, 'rule_payment_term' => null, 'rule_priority' => 0);
checkReward($service->forProposal($proposal, 2000, array(7 => $decision)), array(), 'No configured reward');
$db->rules = array($rule);
$r = $service->forProposal($proposal, 2000, array(7 => $decision));
checkReward($r[7]['amount'], 250.0, 'Default author owns all turnover');
checkReward($r[7]['payment_term_id'], 0, 'Native immediate payment fallback');
$db->allocations = array(array('rowid' => 1, 'entity' => 1, 'fk_propal' => 10, 'fk_user' => 7, 'value_type' => 'percentage', 'value' => 60.0));
$r = $service->forProposal($proposal, 2000, array(7 => $decision, 8 => $decision));
checkReward($r[7]['amount'], 150.0, 'Explicit CA allocation');
checkReward($r[8]['amount'], 0.0, 'Beneficiary absent from CA allocation');
$db->allocations[0]['value_type'] = 'amount'; $db->allocations[0]['value'] = 9000.0;
checkReward($service->forProposal($proposal, 2000, array(7 => $decision))[7]['amount'], 150.0, 'Amount CA allocation');
$individual = $rule; $individual['rule_id'] = 43; $individual['assignment_id'] = 2; $individual['assignment_type'] = 'user'; $individual['reward_mode'] = 'fixed'; $individual['reward_value'] = 200.0;
$db->rules[] = $individual;
$r = $service->forProposal($proposal, 2000, array(7 => $decision));
checkReward($r[7]['amount'], 120.0, 'User overrides default');
checkReward($r[7]['rule_id'], 43, 'Resolved rule retained');
$conflict = $individual; $conflict['rule_id'] = 44; $db->rules[] = $conflict;
try { $service->forProposal($proposal, 2000, array(7 => $decision)); throw new LogicException('Conflict accepted'); }
catch (RuntimeException $e) { checkReward($e->getMessage(), 'LscRewardConflict', 'Conflicting reward rejected'); }
$duplicate = $individual; $duplicate['assignment_id'] = 3;
$db->rules = array($individual, $duplicate, $conflict);
try { $service->forProposal($proposal, 2000, array(7 => $decision)); throw new LogicException('Duplicate masked conflict'); }
catch (RuntimeException $e) { checkReward($e->getMessage(), 'LscRewardConflict', 'Duplicate assignments cannot hide a conflicting rule'); }
$db->rules = array($individual, $duplicate);
checkReward($service->forProposal($proposal, 2000, array(7 => $decision))[7]['amount'], 120.0, 'Same rule assigned twice pays once');
$groupRule = $individual; $groupRule['assignment_type'] = 'group';
$db->rules = array($rule, $groupRule);
checkReward($service->forProposal($proposal, 2000, array(7 => $decision))[7]['rule_id'], 43, 'Group overrides default');
$db->fail = true;
try { $service->forProposal($proposal, 2000, array(7 => $decision)); throw new LogicException('Read error ignored'); }
catch (RuntimeException $e) { checkReward($e->getMessage(), 'LscPolicyUnavailable', 'Read error is not absence'); }
// Replaying a signature must not consult today's rules, even when they cannot be read.
$proposal->status = 2; $proposal->date_signature = 2000; $proposal->context['lmdb_margin_signing'] = true;
$frozen = $decision; $frozen['frozen'] = true; $frozen['reward'] = $r[7];
checkReward($service->forProposal($proposal, 9999, array(7 => $frozen))[7]['amount'], 120.0, 'Frozen reward survives new configuration and repeated signature');
$frozen['reward'] = null;
checkReward($service->forProposal($proposal, 9999, array(7 => $frozen)), array(), 'Frozen absence cannot earn retroactively');
unset($frozen['reward']);
checkReward($service->forProposal($proposal, 9999, array(7 => $frozen)), array(), 'Legacy snapshot remains historical');
echo "Reward: $tests assertions passed on ".DOL_VERSION." (SQL/configuration simulated).\n";
