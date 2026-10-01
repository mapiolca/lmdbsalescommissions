<?php
/** Actual view, policy snapshots and native Form; SQL, users and estimates are fixtures. */
$lscNativeRoot = realpath($argv[1] ?? __DIR__.'/.core-cache/20.0.0/htdocs');
if (!$lscNativeRoot) { throw new RuntimeException('Fetch native contracts first'); }
// Execute the native display formatter; keep the surrounding ERP environment simulated.
$nativeFunctions = file_get_contents($lscNativeRoot.'/core/lib/functions.lib.php');
if (!preg_match('/^function price\(.*?^\}/ms', $nativeFunctions, $nativePrice)) { throw new RuntimeException('Native price formatter missing'); }
eval($nativePrice[0]);
if (!preg_match('/^function price2num\(.*?^\}/ms', $nativeFunctions, $nativeNormalizer)) { throw new RuntimeException('Native price normalizer missing'); }
eval($nativeNormalizer[0]);
$nativeBadgeSource = $nativeFunctions.(is_file($lscNativeRoot.'/core/lib/html.lib.php') ? file_get_contents($lscNativeRoot.'/core/lib/html.lib.php') : '');
if (!preg_match('/^function dolGetBadge\(.*?^\}/ms', $nativeBadgeSource, $nativeBadge)) { throw new RuntimeException('Native badge missing'); }
eval($nativeBadge[0]);
function dolPrintHTMLForAttribute($value) { return dol_escape_htmltag($value); }
function dol_strlen($value) { return strlen($value); }
define('DOL_DOCUMENT_ROOT', __DIR__.'/fixtures/margin-summary');
define('MAIN_DB_PREFIX', 'summary_test_');
$conf = (object) array('entity' => 1, 'currency' => 'EUR', 'use_javascript_ajax' => 1);
function getDolGlobalInt($key, $default = 0) { return $default; }
function getDolGlobalString($key, $default = '') { return array('MAIN_MAX_DECIMALS_TOT' => '2', 'MAIN_MAX_DECIMALS_UNIT' => '5', 'MAIN_MAX_DECIMALS_SHOWN' => '2')[$key] ?? $default; }
$summaryModules = array('lmdbsalescommissions');
function isModEnabled($key) { global $summaryModules; return in_array($key, $summaryModules, true); }
function dol_now() { return 2000; }
function dol_syslog($message, $level = 0) {}
function dol_escape_htmltag($value, ...$args) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function img_picto($alt, $key) { return '<span class="fa fa-search" aria-hidden="true"></span>'; }
class SummaryLangs
{
	public function loadLangs($keys) {}
	public function trans($key, ...$values) { return $key === 'LscTravelApplied' ? implode('|', $values) : $key; }
	public function transnoentitiesnoconv($key) { return $key; }
}
class SummaryDb
{
	public $snapshots = array();
	public $groupUsers = array();
	public $fail = false;
	public $queries = array();
	public function query($sql) {
		$this->queries[] = $sql;
		if ($this->fail) { return false; }
		if (strpos($sql, 'usergroup_user') !== false) {
			if (strpos($sql, 'a.entity = 1') === false) { throw new RuntimeException('Unscoped group query'); }
			$rows = array_map(static function ($id) { return (object) array('fk_user' => $id); }, $this->groupUsers);
		} elseif (strpos($sql, "SELECT rowid, label") === 0) {
			if (strpos($sql, "entity = 1 AND rule_type = 'margin_policy'") === false) { throw new RuntimeException('Unscoped labels'); }
			$rows = array((object) array('rowid'=>71,'label'=>'LABEL-SEVEN <unsafe>'), (object) array('rowid'=>81,'label'=>'LABEL-EIGHT'));
		} elseif (strpos($sql, '_margin_snapshot WHERE entity = 1 AND fk_propal = 41') !== false) {
			$rows = array();
			foreach ($this->snapshots as $id => $decision) { $rows[] = (object) array('fk_user' => $id, 'snapshot_payload' => json_encode($decision)); }
		} else { throw new RuntimeException('Unexpected SQL: '.$sql); }
		return (object) array('rows' => $rows, 'offset' => 0);
	}
	public function fetch_object($q) { return $q->rows[$q->offset++] ?? false; }
	public function free($q) {}
	public function lasterror() { return 'Injected read failure'; }
}
require DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require __DIR__.'/../class/lmdbsalescommissionmarginview.class.php';
$langs = new SummaryLangs(); $db = new SummaryDb(); $user = new User($db);
$proposal = (object) array('id' => 41, 'entity' => 1, 'date_signature' => 100, 'status' => 2);
$db->snapshots = array(
	7 => array('sale' => 'allow', 'commission' => 'allow', 'inputs' => array('rate' => 65), 'checks' => array(array('rule_id' => 71, 'origin_type' => 'user', 'origin' => 'RULE-SEVEN <unsafe>', 'context' => 'general', 'effect' => 'commission', 'threshold' => 60, 'base_threshold' => 50, 'travel_uplift' => 10, 'travel_metric' => 'minutes', 'travel_value' => 106, 'reason' => 'met'))),
	8 => array('sale' => 'allow', 'commission' => 'deny', 'inputs' => array('rate' => 40), 'checks' => array(array('rule_id' => 81, 'origin_type' => 'default', 'origin' => 'RULE-EIGHT', 'context' => 'general', 'effect' => 'commission', 'threshold' => 60, 'reason' => 'below'))),
);
// Deliberately reverse the order: position-based matching would disclose the wrong rule.
$estimates = array('rows' => array(
	array('beneficiary_id' => 8, 'beneficiary' => 'Commercial 8', 'formula' => 'FIXED-EIGHT', 'payment_term' => 'TERMS-EIGHT', 'amount' => '0', 'status' => 'Not acquired'),
	array('beneficiary_id' => 7, 'beneficiary' => 'Commercial 7', 'formula' => '15 % <margin>', 'payment_term' => 'TERMS-SEVEN <unsafe>', 'amount' => '195', 'status' => 'Not acquired'),
), 'total' => '195');
$tests = 0;
function check($condition, $message) { global $tests; $tests++; if (!$condition) { throw new RuntimeException($message); } }
function parseView($html) {
	$dom = new DOMDocument(); $previous = libxml_use_internal_errors(true);
	$dom->loadHTML('<!doctype html><html><meta charset="utf-8"><body>'.$html.'</body></html>');
	libxml_clear_errors(); libxml_use_internal_errors($previous);
	return new DOMXPath($dom);
}
$render = static function ($data) use ($db, $proposal, $user) { return LmdbSalesCommissionMarginView::render($db, $proposal, $user, false, $data); };
$html = $render($estimates); $xpath = parseView($html);
check($xpath->query('//table[not(ancestor::table)]')->length === 1, 'One top-level summary table');
check($xpath->query('//table[not(ancestor::table)]/tr[1]/th')->length === 4, 'Exactly four summary columns');
$dialogs = $xpath->query('//div[starts-with(@id,"idfortooltiponclick_")]');
check($dialogs->length === 2, 'One native dialog per beneficiary');
foreach ($dialogs as $dialog) {
	$id = strpos($dialog->getAttribute('id'), 'user7view') !== false ? 7 : 8;
	check($xpath->query('.//table', $dialog)->length === 3, 'Three detail tables in each dialog');
	check($xpath->query('.//a[@aria-haspopup="dialog"]', $dialog)->length === 0, 'No nested Consulter dialog');
	check($xpath->query('.//a[contains(@href,"/user/card.php")]', $dialog)->length === 1, 'Beneficiary link retained inside dialog');
	check($xpath->query('.//a[@title or contains(@class,"classfortooltip")]', $dialog)->length === 0, 'Dialog autofocus cannot trigger a user tooltip');
	check(strpos($dialog->textContent, $id === 7 ? 'LABEL-SEVEN' : 'LABEL-EIGHT') !== false, 'Matching beneficiary policy');
	check(strpos($dialog->textContent, $id === 7 ? 'LABEL-EIGHT' : 'LABEL-SEVEN') === false, 'No other beneficiary policy');
	check(strpos($dialog->textContent, $id === 7 ? 'TERMS-SEVEN' : 'TERMS-EIGHT') !== false, 'Matching payment term');
	$ruleTable = $xpath->query('.//table[tbody/tr[1]/th[1][text()="LscRules"]]', $dialog)->item(0);
	check($xpath->query('./tbody/tr[1]/th', $ruleTable)->length === 2 && $xpath->query('./tbody/tr[1]/th[1]', $ruleTable)->item(0)->textContent === 'LscRules', 'Applied rules have only Rules and Status columns');
	$tooltip = $xpath->query('.//span[contains(@class,"classforajaxtooltip")]', $ruleTable)->item(0);
	$params = json_decode($tooltip->getAttribute('data-params'), true);
	check($params === array('id'=>41,'objecttype'=>'lmdbsalescommissionpolicytooltip@lmdbsalescommissions','option'=>$id.':'.($id === 7 ? 71 : 81).':commission'), 'Ajax parameters bind the correct proposal, beneficiary, rule and effect');

}
check($xpath->query('//span[contains(@class,"badge-success")]')->length === 1 && $xpath->query('//span[contains(@class,"badge-warning")]')->length === 1, 'Native green and orange status badges');
check($xpath->query('//span[contains(@class,"classforajaxtooltip")]')->length === 2, 'Native Ajax tooltip on each label');
check(strpos($html, '<unsafe>') === false && strpos($html, '<margin>') === false, 'Rule and payment text escaped');
check(strpos(LmdbSalesCommissionMarginView::render($db, $proposal, $user), '50|10|106,00|LscTravelMinutes') !== false, 'Effective margin formats round trip with two decimals');
$db->snapshots[7]['checks'][0]['travel_value'] = 1275.9533333333;
check(strpos(LmdbSalesCommissionMarginView::render($db, $proposal, $user), '1 275,95|LscTravelMinutes') !== false, 'Fractional duration uses native French separators and two decimals');
$db->snapshots[7]['checks'][0]['travel_value'] = 106;
check($xpath->query('//tr[@class="liste_total"]/td[2]')->item(0)->textContent === '195', 'Total kept in amount column');
$summaryRows = $xpath->query('//table[not(ancestor::table)]/tr[@class="oddeven"]');
check($summaryRows->item(0)->childNodes->item(2)->textContent === 'LscState_commission_deny', 'Denied commission shown as null');
check($summaryRows->item(1)->childNodes->item(2)->textContent === 'Not acquired', 'Acquisition state preserved when allowed');
$second = parseView($render($estimates));
check($dialogs->item(0)->getAttribute('id') !== $second->query('//div[starts-with(@id,"idfortooltiponclick_")]')->item(0)->getAttribute('id'), 'Repeated renders have distinct dialog IDs');
$user->permissions = array('readown'); $own = $render($estimates);
check(strpos($own, 'LABEL-EIGHT') === false && strpos($own, 'TERMS-EIGHT') === false, 'Own scope also hides modal content');
check(strpos($own, 'liste_total') === false, 'No global total outside global scope');
$user->permissions = array('readgroup'); $db->groupUsers = array(8);
check(strpos($render($estimates), 'LABEL-EIGHT') !== false, 'Group beneficiary visible');
$db->groupUsers = array();
check(strpos($render($estimates), 'LABEL-EIGHT') === false, 'Other group hidden');
$user->permissions = array(); $db->queries = array();
check($render($estimates) === '', 'Admin without explicit rights gets no table');
check($db->queries === array(), 'No protected query without rights');
$user->permissions = array('readall'); $user->socid = 1;
check($render($estimates) === '', 'External users excluded'); $user->socid = 0;
$db->snapshots[8]['commission'] = 'unknown';
check(strpos($render($estimates), 'LscState_commission_unknown') !== false, 'Unknown commission distinguished');
$saved = $db->snapshots; $db->snapshots = array();
$withoutPolicies = $render($estimates);
check(strpos($withoutPolicies, '195') !== false && strpos($withoutPolicies, 'LscNoMarginControl') !== false, 'Estimates survive absent controls');
$auto = array('beneficiary_id' => 7, 'beneficiary' => 'Commercial 7', 'amount' => '195', 'margin' => '1300', 'rate' => '15 %', 'rule' => 'AUTO-RULE', 'source' => 'Default', 'status' => 'Not acquired');
check(strpos($render($auto), 'AUTO-RULE') !== false && strpos($render($auto), '1300') !== false, 'Automatic rule keeps calculation details');
$message = array('beneficiary_id' => 7, 'beneficiary' => 'Commercial 7', 'message' => 'NO-CALCULATION');
check(strpos($render($message), 'NO-CALCULATION') !== false, 'Unavailable calculation reason retained');
$db->fail = true;
check(strpos($render($auto), 'LscPolicyUnavailable') !== false && strpos($render($auto), 'LscState_commission_unknown') !== false, 'Read error never shown as conformity');
$db->fail = false; $db->snapshots = $saved;
$conf->use_javascript_ajax = 0; $nojs = parseView($render($estimates));
check($nojs->query('//details')->length === 2 && $nojs->query('//details//table')->length === 6, 'No-JS details retain calculation, controls and rules tables');
$conf->use_javascript_ajax = 1;
$detailed = parseView(LmdbSalesCommissionMarginView::render($db, $proposal, $user));
check($detailed->query('//table[not(ancestor::table)]/tr[1]/th')->length === 5, 'Dispatch policy view preserved');
// Exercise the real hook too: approval-only viewers see decisions but no commission amounts.
require __DIR__.'/../class/actions_lmdbsalescommissions.class.php';
$user->permissions = array('approvesale');
$controller = new ActionsLmdbSalesCommissions($db); $action = ''; $manager = null;
$controller->displayMarginInfos(array('context' => 'propalcard'), $proposal, $action, $manager);
check(substr_count($controller->resprints, 'class="oddeven lmdbsalescommissions-estimated-commission"') === 1, 'Hook emits one outer margin row');
check(strpos($controller->resprints, '195') === false && strpos($controller->resprints, 'LABEL-SEVEN') !== false, 'Approval permission does not grant commission detail access');
$user->permissions = array();
$controller->displayMarginInfos(array('context' => 'propalcard'), $proposal, $action, $manager);
check($controller->resprints === '', 'Successive unauthorized hook clears prior output');
// The same visible summary now includes the supplementary frozen reward.
$user->permissions = array('readall');
$db->snapshots[7]['reward'] = array('amount'=>50.0,'mode'=>'fixed','value'=>100.0,'rule_label'=>'BONUS-SEVEN <unsafe>','threshold'=>60.0,'surplus'=>1000.0,'share'=>0.5,'reason'=>'earned');
$estimates['rows'][0]['amount_value'] = 0.0;
$estimates['rows'][1]['amount_value'] = 195.0;
$html=$render($estimates); $xpath=parseView($html);
check($xpath->query('//tr[@class="liste_total"]/td[2]')->item(0)->textContent === '245,00', 'Summary total includes bonus exactly once');
check(strpos($html,'LscBaseCommission')!==false && strpos($html,'LscRewardSurplus')!==false, 'Base, reward and surplus explained');
check(strpos($html,'BONUS-SEVEN &lt;unsafe&gt;')!==false, 'Reward label escaped');
$rewardTable = $xpath->query('//table[tbody/tr/td[text()="BONUS-SEVEN <unsafe>"]]')->item(0);
check($rewardTable !== null && $xpath->query('./tbody/tr[@class="oddeven"]', $rewardTable)->length === 8, 'Reward uses native table body and alternating rows');
check($xpath->query('./tbody/tr/td[text()="EUR"]', $rewardTable)->length === 3, 'Fixed value, surplus and reward use configured currency');
check($xpath->query('.//span[contains(@class,"badge-success")]', $rewardTable)->length === 1, 'Exceeded target has a green native badge');

$user->permissions=array('readown'); $user->id=8;
check(strpos($render($estimates),'BONUS-SEVEN')===false, 'Reward outside own scope hidden');
$user->permissions=array('approvesale');
check(strpos(LmdbSalesCommissionMarginView::render($db,$proposal,$user),'BONUS-SEVEN')===false, 'Approver alone cannot read reward');

// Native initialization, natural priority sort, concatenation and reset; sibling renderers are fixtures.
require $lscNativeRoot.'/core/class/hookmanager.class.php';
function dol_include_once($path) {
	if ($path === '/lmdbsalescommissions/class/actions_lmdbsalescommissions.class.php') {
		require_once __DIR__.'/../class/actions_lmdbsalescommissions.class.php';
		return 1;
	}
	return in_array($path, array('/powerplantpv/class/actions_powerplantpv.class.php', '/zzzmarginfixture/class/actions_zzzmarginfixture.class.php'), true) ? 1 : 0;
}
class ActionsPowerplantpv
{
	public $error = '';
	public $errors = array();
	public $results = array();
	public $resprints;
	public function __construct($db) {}
	public function displayMarginInfos($parameters, &$object, &$action, $hookmanager) {
		$this->resprints = '<tr class="powerplantpv-price-per-wattpeak"><td>Price per watt-peak</td></tr>';
		return 0;
	}
}
class ActionsZzzmarginfixture extends ActionsPowerplantpv
{
	public $priority = 1000;
	public function displayMarginInfos($parameters, &$object, &$action, $hookmanager) {
		$this->resprints = '<tr class="another-margin-contribution"><td>Another margin contribution</td></tr>';
		return 0;
	}
}
$user->permissions = array('approvesale');
foreach (array(array('lmdbsalescommissions', 'powerplantpv', 'zzzmarginfixture'), array('zzzmarginfixture', 'powerplantpv', 'lmdbsalescommissions'), array('lmdbsalescommissions')) as $summaryModules) {
	$conf->modules_parts = array('hooks' => array_fill_keys($summaryModules, array('propalcard')));
	$hookmanager = new HookManager($db);
	$hookmanager->initHooks(array('propalcard'));
	for ($pass = 0; $pass < 2; $pass++) {
		check($hookmanager->executeHooks('displayMarginInfos', array(), $proposal, $action) === 0, 'Margin contributions preserve native rendering');
		$table = parseView('<table id="native-margin-table">'.$hookmanager->resPrint.'</table>');
		$rows = $table->query('//table[@id="native-margin-table"]/tr');
		check($rows->length === count($summaryModules), 'Each active contribution is preserved exactly once');
		check(strpos($rows->item($rows->length - 1)->getAttribute('class'), 'lmdbsalescommissions-estimated-commission') !== false, 'Commission summary is the final margin row');
	}
}
print "Commission summary: $tests assertions passed using native Form and HookManager from $lscNativeRoot.\n";
