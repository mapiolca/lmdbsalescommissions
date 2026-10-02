<?php
/** Native resolver and formatter, actual tooltip/service; SQL, session and proposal are fixtures. */
$root = realpath($argv[1] ?? __DIR__.'/.core-cache/20.0.0/htdocs');
if (!$root) { throw new RuntimeException('Fetch native contracts first'); }
define('DOL_DOCUMENT_ROOT', __DIR__.'/fixtures/policy-tooltip');
define('MAIN_DB_PREFIX', 'tooltip_test_');
$source = file_get_contents($root.'/core/lib/functions.lib.php');
foreach (array('getElementProperties', 'fetchObjectByElement', 'price', 'price2num') as $name) {
	if (!preg_match('/^function '.preg_quote($name, '/').'\(.*?^\}/ms', $source, $match)) { throw new RuntimeException($name); }
	eval($match[0]);
}
function dol_include_once($path) {
	if ($path !== '/lmdbsalescommissions/class/lmdbsalescommissionpolicytooltip.class.php') { throw new RuntimeException($path); }
	require_once __DIR__.'/../class/lmdbsalescommissionpolicytooltip.class.php'; return 1;
}
function dol_syslog($message, $level = 0) {}
function dol_strlen($v) { return strlen($v); }
function dol_escape_htmltag($v, ...$args) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function getDolGlobalInt($key, $default = 0) { return $default; }
function getDolGlobalString($key, $default = '') { return array('MAIN_MAX_DECIMALS_TOT'=>'2','MAIN_MAX_DECIMALS_UNIT'=>'5','MAIN_MAX_DECIMALS_SHOWN'=>'2')[$key] ?? $default; }
$enabled = true; $entities = '1'; $parentAllowed = true; $parentChecks = 0;
function isModEnabled($module) { global $enabled; return $enabled; }
function getEntity($element) { global $entities; return $entities; }
function restrictedArea($user, $module, $id, ...$args) {
	global $parentAllowed, $parentChecks; $parentChecks++;
	if ($module !== 'propal' || $id !== 41 || !$parentAllowed) { throw new RuntimeException('Parent denied'); }
	return 1;
}
class TooltipLangs {
	public function loadLangs($keys) {}
	public function trans($key, ...$values) { return $key; }
	public function transnoentitiesnoconv($key) { return $key; }
}
class TooltipUser {
	public $id = 7; public $socid = 0; public $admin = 1; public $readProposal = true;
	public $rightsFixture = array('readown');
	public function hasRight($module, $level, $action = '') { return $module === 'propal' ? $this->readProposal : in_array($action, $this->rightsFixture, true); }
}
class TooltipDb {
	public $queries = array(); public $group = false; public $fail = false; public $record = true; public $decision;
	public function query($sql) {
		$this->queries[] = $sql;
		if ($this->fail) { return false; }
		if (strpos($sql, 'entity = 1') === false) { throw new RuntimeException('Unscoped query'); }
		if (strpos($sql, 'usergroup_user') !== false) { $rows = $this->group ? array((object) array('fk_user'=>8)) : array(); }
		elseif (strpos($sql, '_margin_snapshot') !== false) {
			if (strpos($sql, 'fk_propal = 41') === false) { throw new RuntimeException('Wrong proposal'); }
			$rows = array((object) array('fk_user'=>7,'snapshot_payload'=>json_encode($this->decision)), (object) array('fk_user'=>8,'snapshot_payload'=>json_encode($this->decision)));
		} elseif (strpos($sql, 'SELECT ref, label, active, description') === 0) {
			if (strpos($sql, "rule_type = 'margin_policy' AND rowid = 71") === false) { throw new RuntimeException('Arbitrary rule exposed'); }
			$rows = $this->record ? array((object) array('ref'=>'REF-71','label'=>'LABEL <script>','active'=>0,'description'=>'Description <img src=x onerror=alert(1)>')) : array();
		} else { throw new RuntimeException($sql); }
		return (object) array('rows'=>$rows,'offset'=>0);
	}
	public function fetch_object($q) { return $q->rows[$q->offset++] ?? false; }
	public function free($q) {}
	public function lasterror() { return 'Injected error'; }
}
$conf = (object) array('entity'=>1); $db = new TooltipDb(); $user = new TooltipUser(); $langs = new TooltipLangs();
$hookmanager = new class {
	public $resArray = array(); public $contextarray = array();
	public function initHooks($contexts) { $this->contextarray = $contexts; }
	public function executeHooks($name, $params) { return 0; }
};
$db->decision = array('sale'=>'allow','commission'=>'allow','inputs'=>array('rate'=>180,'kwc'=>9,'kwh'=>12),
	'checks'=>array(array('rule_id'=>71,'effect'=>'commission','origin'=>'OLD-REF','origin_type'=>'default','context'=>'pv','base_threshold'=>70,'threshold'=>170,'travel_metric'=>'minutes','travel_value'=>1275.9533333,'travel_uplift'=>100,'reason'=>'met','approval_id'=>4)),
	'rules'=>array(array('rule_id'=>71,'effect'=>'commission','bands'=>array(array('kwc_min'=>0,'kwc_max'=>10,'kwc_inclusive'=>0,'kwh_min'=>null,'kwh_max'=>null,'kwh_inclusive'=>1,'threshold'=>70)), 'travel_bands'=>array(array('metric'=>'minutes','min_value'=>105,'uplift'=>100)))));
$tests = 0;
function verify($ok, $message) { global $tests; $tests++; if (!$ok) { throw new RuntimeException($message); } }
$object = fetchObjectByElement(41, 'lmdbsalescommissionpolicytooltip@lmdbsalescommissions');
verify($object instanceof LmdbSalesCommissionPolicyTooltip && $object->module === 'propal' && $object->element === 'propal', 'Native resolver retains proposal security identity');
$get = static function ($option = '7:71:commission') use ($object) { return $object->getTooltipContent(array('option'=>$option)); };
$html = $get();
verify($parentChecks === 1, 'Native parent access requested');
foreach (array('LABEL &lt;script&gt;', 'REF-71', 'LscFrozen', 'LscRuleCurrentMetadata', 'LscContext_pv', 'LscEffect_commission', '170,00', '180,00', '1 275,95', '105,00', '100,00', '70,00', ']0,00 ; 10,00]', '#4') as $expected) { verify(strpos($html,$expected)!==false, 'Missing detail '.$expected); }
verify(strpos($html,'<script>')===false && strpos($html,'<img')===false, 'Stored labels and description escaped');
verify($get('8:71:commission') === 'LscPolicyUnavailable', 'Other beneficiary denied with readown');
verify($get('7:72:commission') === 'LscPolicyUnavailable', 'Unapplied rule denied');
verify($get('7:71:sale') === 'LscPolicyUnavailable', 'Unapplied effect denied');
foreach (array('', '0:71:commission', '7:71:commission:extra', '7:71:commission<script>', array()) as $invalid) { verify($get($invalid)==='LscPolicyUnavailable', 'Invalid option denied'); }
$user->rightsFixture = array(); $db->queries = array();
verify($get()==='LscPolicyUnavailable' && !$db->queries, 'Admin without rights cannot query policy');
$user->rightsFixture = array('readgroup');
verify($get('8:71:commission')==='LscPolicyUnavailable', 'No shared group denied');
$db->group = true; verify(strpos($get('8:71:commission'),'LABEL')!==false, 'Shared group allowed');
foreach (array('readall','dispatch','approvesale','approvecommission') as $right) { $user->rightsFixture=array($right); verify(strpos($get('8:71:commission'),'LABEL')!==false, 'Authorized scope '.$right); }
$user->socid = 9; $db->queries = array(); verify($get()==='LscPolicyUnavailable' && !$db->queries, 'External user denied before policy SQL'); $user->socid = 0;
$user->readProposal = false; verify($get()==='LscPolicyUnavailable', 'Proposal permission required'); $user->readProposal = true;
$entities = '2'; verify($get()==='LscPolicyUnavailable', 'Other entity denied'); $entities = '1';
$enabled = false; verify($get()==='LscPolicyUnavailable', 'Inactive module denied'); $enabled = true;
$parentAllowed = false; $db->queries = array();
try { $get(); verify(false,'Parent access must reject'); } catch (RuntimeException $e) { verify($e->getMessage()==='Parent denied' && !$db->queries,'Parent restriction precedes policy data'); }
$parentAllowed = true; $db->fail = true; verify($get()==='LscPolicyUnavailable', 'SQL failure is unavailable'); $db->fail = false;
$db->record = false; verify(strpos($get(),'OLD-REF')!==false && strpos($get(),'170,00')!==false,'Deleted rule retains historical explanation');
$db->decision['rules']=array(); verify(strpos($get(),'LscRuleHistoricalDetailsUnavailable')!==false,'Missing historical bands never replaced with live bands');
$endpoint = file_get_contents($root.'/core/ajax/ajaxtooltip.php');
verify(strpos($endpoint, "include '../../main.inc.php'")!==false && strpos($endpoint,'restrictedArea(')!==false && strpos($endpoint,'getTooltipContent($params)')!==false, 'Native endpoint keeps session, CSRF and permissions');
$js = file_get_contents($root.'/core/js/lib_foot.js.php');
if (is_file($root.'/core/js/lib_initTooltips.js')) { $js .= file_get_contents($root.'/core/js/lib_initTooltips.js'); }
verify(strpos($js,'classforajaxtooltip')!==false && strpos($js,'anti-csrf-currenttoken')!==false && strpos($js,'/core/ajax/ajaxtooltip.php')!==false, 'Native JS uses token and standard endpoint');
echo "Policy tooltip: $tests assertions passed (native resolver; simulated ERP/session/SQL).\n";
