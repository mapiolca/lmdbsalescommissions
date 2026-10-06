<?php
/** Real native FormMargin, in-memory repository and users. No instance/database claim. */
define('DOL_DOCUMENT_ROOT', $argv[1] ?? __DIR__.'/.core-cache/20.0.0/htdocs');
define('DOL_VERSION', $argv[2] ?? '20.0.0');
define('MAIN_DB_PREFIX', 'test_prefix_');
$settings = array('LMDBSALESCOMMISSIONS_MARGIN_ENABLED' => 1, 'LMDBSALESCOMMISSIONS_MARGIN_ACTIVATED_AT' => 1000);
$conf = (object) array('entity' => 1, 'global' => (object) array(), 'modules_parts' => array('hooks' => array('lmdbsalescommissions' => array('propalcard', 'propallist', 'api', 'ajaxonlinesign'))));
function getDolGlobalInt($key, $default = 0) { global $settings; return (int) ($settings[$key] ?? $default); }
function getDolGlobalString($key, $default = '') { global $settings; return (string) ($settings[$key] ?? $default); }
function isModEnabled($key) { global $zoningEnabled, $propalPvEnabled; return $key === 'lmdbsalescommissions' || ($key === 'lmdbzoning' && !empty($zoningEnabled)) || ($key === 'lmdbpropalpv' && !empty($propalPvEnabled)); }
if (!function_exists('dol_include_once')) { function dol_include_once($path) { return 1; } }
function dol_now() { return 2000; }
function dol_syslog($message, $level = 0) {}
require_once __DIR__.'/../class/lmdbsalescommissionmarginservice.class.php';
require_once __DIR__.'/margin_policy_test.php';

class PolicyRows {
	public $rows; public $offset = 0;
	public function __construct($rows) { $this->rows = $rows; }
}
class PolicyDb {
	public $dispatch = array(); public $approvals = array(); public $snapshots = array(); public $revision = 1; public $fail = false; public $failSnapshot = 0; public $transaction;
	public function query($sql) {
		if ($this->fail) { return false; }
		if (strpos($sql, 'INSERT INTO') === 0) {
			preg_match('/\(([^)]+)\) VALUES \((.*)\)$/s', $sql, $parts);
			$values = str_getcsv($parts[2], ',', "'", '\\');
			$row = array_combine(explode(',', $parts[1]), array_map('stripslashes', $values));
			if (strpos($sql, '_margin_approval') !== false) { $row['rowid'] = count($this->approvals) + 1; $this->approvals[] = $row; }
			elseif (strpos($sql, '_margin_snapshot') !== false) {
				if ($this->failSnapshot && count($this->snapshots) + 1 === $this->failSnapshot) { return false; }
				$this->snapshots[] = $row;
			} else { throw new RuntimeException('Unexpected insert'); }
			return true;
		}
		if (strpos($sql, '_rule_assignment AS a') !== false || strpos($sql, 'usergroup_user') !== false) { return new PolicyRows(array()); }
		if (strpos($sql, '_proposal_dispatch') !== false) { return new PolicyRows($this->dispatch); }
		if (strpos($sql, '_margin_revision') !== false) { return new PolicyRows(array(array('object_id' => '10', 'revision' => (string) $this->revision))); }
		if (strpos($sql, '_margin_approval') !== false) {
			preg_match("/fingerprint = '([^']+)'/", $sql, $m);
			return new PolicyRows(array_values(array_filter($this->approvals, static function ($a) use ($m) { return $a['fingerprint'] === $m[1]; })));
		}
		if (strpos($sql, '_margin_snapshot') !== false) { return new PolicyRows($this->snapshots); }
		if (strpos($sql, 'FROM test_prefix_user') !== false) { return new PolicyRows(array(array('rowid' => 7))); }
		throw new RuntimeException('Unexpected query: '.$sql);
	}
	public function fetch_object($r) { return isset($r->rows[$r->offset]) ? (object) $r->rows[$r->offset++] : false; }
	public function free($r) {}
	public function num_rows($r) { return count($r->rows); }
	public function escape($s) { return addslashes($s); }
	public function idate($date) { return gmdate('Y-m-d H:i:s', $date); }
	public function lasterror() { return 'Injected failure'; }
	public function begin() { $this->transaction = array($this->snapshots, $this->approvals); }
	public function rollback() { list($this->snapshots, $this->approvals) = $this->transaction; }
}
class PolicyProposal {
	public $id=10; public $entity=1; public $socid=15; public $user_author_id=7; public $date_signature=0; public $status=1; public $statut=1; public $element='propal'; public $context=array();
	public $array_options=array(); public $lines; public $total_ht=125.0; public $optionReads=0;
	public function __construct() { $this->lines = array((object) array('id'=>1,'total_ht'=>125.0,'subprice'=>125.0,'pa_ht'=>100.0,'qty'=>1.0,'remise_percent'=>0,'product_type'=>0,'fk_product_type'=>0)); }
	public function fetch_lines() { return 1; }
	public function fetch_optionals() { $this->optionReads++; return 1; }
}
class PolicyUser {
	public $id=99; public $socid=0; public $admin=1; public $rightsList=array();
	public function hasRight($module,$object,$action=null) { return in_array($action ?? $object, $this->rightsList, true); }
}
class PolicyService extends LmdbSalesCommissionMarginService {
	public $rules; public $owners=array();
	public function policies($beneficiary, $entity) { $this->owners[] = $entity; return $this->rules[$beneficiary] ?? array(); }
}
class LmdbZoningCompatibility { public static function isTravelAvailable($type = '') { return $type === 'propal'; } }
class LmdbZoningTravelService {
	public static $response = array(); public static $source = array();
	public function __construct($db) {}
	public function read($type, $id, $profile, $actor) {
		self::$source = array($type, $id, $profile);
		if (!$actor->hasRight('lmdbzoning', 'lmdbzoning', 'read')) { throw new RuntimeException('TravelForbidden'); }
		// The proposal has its own stored journey; no journey was calculated on its client card.
		if ($type !== 'propal' || $id !== 10) { return array('state' => 'not_calculated'); }
		return self::$response;
	}
}
class LmdbPropalPVComplexSiteService {
	public static $calls = 0;
	public static function isAvailable($db): bool { global $complexFieldEnabled; self::$calls++; return isModEnabled('lmdbpropalpv') && getDolGlobalInt('LMDBPROPALPV_COMPLEX_SITE_ENABLED') === 1 && !empty($complexFieldEnabled); }
}
$db = new PolicyDb(); $service = new PolicyService($db); $proposal = new PolicyProposal(); $actor = new PolicyUser();
$service->rules = array(7 => $general);
$r = $service->assess($proposal);
expect($r[7]['sale'], 'deny', 'native margin on cost 25%');
expect($r[7]['inputs']['cost'], 100.0, 'native HT cost');
expect($proposal->optionReads, 0, 'general mode never reads extrafields');
try { $service->approve($proposal,$actor,7,1,'sale','Required exception',$r[7]['fingerprint']); throw new Exception('Missing denial'); }
catch (RuntimeException $e) { expect($e->getMessage(),'LscApprovalDenied','admin without explicit right denied'); }
$actor->rightsList=array('approvesale');
$service->approve($proposal,$actor,7,1,'sale','Approved sale',$r[7]['fingerprint']);
$r=$service->assess($proposal); expect($r[7]['sale'],'allow','sale approved'); expect($r[7]['commission'],'deny','commission remains denied');
expect($db->approvals[0]['date_creation'],'1970-01-01 00:33:20','server timestamp');
$proposal->lines[0]->total_ht=130.0;
expect($service->assess($proposal)[7]['checks'][0]['reason'],'met','changed data recomputed');
$proposal->lines[0]->total_ht=125.0; $db->revision++;
expect($service->assess($proposal)[7]['sale'],'deny','restoring values never restores approval');
try { $service->approve($proposal,$actor,7,1,'sale','Stale',$r[7]['fingerprint']); throw new Exception('Missing stale denial'); }
catch (RuntimeException $e) { expect($e->getMessage(),'LscApprovalStale','stale view rejected'); }
$r=$service->assess($proposal); $service->approve($proposal,$actor,7,1,'sale','Updated sale',$r[7]['fingerprint']);
$actor->rightsList=array('approvecommission'); $service->approve($proposal,$actor,7,2,'commission','Keep commission',$r[7]['fingerprint']);
expect($service->commissionState($proposal,7),'allow','independent commission approval');
$proposal->date_signature=2000; $proposal->status=2; $proposal->context['lmdb_margin_signing']=true;
$service->freeze($proposal,$actor); expect(count($db->snapshots),1,'signature snapshot');
$service->freeze($proposal,$actor); expect(count($db->snapshots),1,'idempotent repeat signature');
$service->rules[7][0]['threshold']=999.0; $proposal->lines[0]->total_ht=1.0;
expect($service->assess($proposal)[7]['sale'],'allow','historical decision immutable');
$settings['LMDBSALESCOMMISSIONS_MARGIN_ENABLED']=0;
expect($service->assess($proposal)[7]['frozen'],true,'disabled controls preserve snapshots');
$settings['LMDBSALESCOMMISSIONS_MARGIN_ENABLED']=1;
$db->snapshots=array(); $proposal->context=array(); $proposal->date_signature=900;
expect($service->assess($proposal),array(),'pre-activation signature preserved');
$proposal->date_signature=2000;
try { $service->assess($proposal); throw new Exception('Missing historical denial'); }
catch (RuntimeException $e) { expect($e->getMessage(),'LscSnapshotMissing','no retrospective reinterpretation'); }
$proposal=new PolicyProposal(); $proposal->lines[0]->pa_ht=0;
expect($service->assess($proposal)[7]['sale'],'unknown','zero total cost not compliant');
$proposal=new PolicyProposal(); $service->rules=array(7=>$general,8=>array(policy(9,'general','sale',10.0)));
$db->dispatch=array(array('fk_user'=>7,'base_type'=>'turnover','value_type'=>'amount','value'=>'500'),array('fk_user'=>8,'base_type'=>'margin','value_type'=>'percentage','value'=>'5'));
expect($service->saleAllowed($proposal),false,'all beneficiaries checked, actor ignored');
expect($service->assess($proposal)[8]['sale'],'allow','per-beneficiary decision');
$service->rules[7]=array(policy(10,'general','commission',60.0));
expect($service->saleAllowed($proposal),true,'commission-only rule never blocks sale');
$proposal->date_signature=2000; $proposal->context['lmdb_margin_signing']=true; $db->failSnapshot=2;
$db->begin();
try { $service->freeze($proposal,$actor); throw new Exception('Expected insert failure'); }
catch (RuntimeException $e) { $db->rollback(); expect(count($db->snapshots),0,'caller rollback removes all partial snapshots (simulated SQL)'); }
$proposal=new PolicyProposal(); $db->fail=true;
try { $service->saleAllowed($proposal); throw new Exception('Expected SQL failure'); }
catch (RuntimeException $e) { expect($e->getMessage(),'LscPolicyUnavailable','SQL error never becomes compliance'); }
$db->fail=false; $conf->entity=2; $proposal->entity=2; $db->dispatch=array(); $service->rules=array(7=>$general);
expect($service->assess($proposal)[7]['sale'],'deny','second entity works in owner context');
expect(end($service->owners),2,'resolver receives proposal owner');
$conf->entity=1; $db = new PolicyDb(); $service = new PolicyService($db); $proposal = new PolicyProposal();
$service->rules = array(7 => array(policy(19, 'general', 'sale', 40.0)));
$before = $service->assess($proposal)[7];
expect($before['sale'], 'deny', 'initial proposal lines fail the margin rule');
$proposal->lines[] = (object) array('id'=>2,'total_ht'=>100.0,'subprice'=>100.0,'pa_ht'=>50.0,'qty'=>1.0,'remise_percent'=>0,'product_type'=>0,'fk_product_type'=>0);
$afterAdd = $service->assess($proposal)[7];
expect($afterAdd['sale'], 'allow', 'adding a proposal line recalculates the margin and rule');
expect($afterAdd['fingerprint'] === $before['fingerprint'], false, 'added line changes the approval fingerprint');
$proposal->lines[1]->total_ht = 60.0; $proposal->lines[1]->subprice = 60.0;
$afterModify = $service->assess($proposal)[7];
expect($afterModify['sale'], 'deny', 'editing a proposal line recalculates the margin and rule');
expect($afterModify['fingerprint'] === $afterAdd['fingerprint'], false, 'edited line changes the approval fingerprint');
$proposal->lines[1]->total_ht = 100.0; $proposal->lines[1]->subprice = 100.0;
$afterRestore = $service->assess($proposal)[7];
expect($afterRestore['sale'], 'allow', 'restoring the line recalculates the rule');
array_pop($proposal->lines);
$afterDelete = $service->assess($proposal)[7];
expect($afterDelete['sale'], 'deny', 'deleting a proposal line recalculates the margin and rule');
expect($afterDelete['fingerprint'] === $afterRestore['fingerprint'], false, 'deleted line changes the approval fingerprint');
$conf->entity=1; $db = new PolicyDb(); $service = new PolicyService($db); $proposal = new PolicyProposal();
$zoningEnabled = false; $settings['LMDBZONING_DEFAULT_PROFILE'] = 'HQ'; $user = $actor; $actor->rightsList = array('read', 'approvesale');
expect(LmdbSalesCommissionsCompatibility::isFeatureAvailable('travel_margin_uplift'), false, 'travel controls unavailable while lmdbzoning is disabled');
$travelPolicy = policy(20, 'general', 'sale', 20.0);
$travelPolicy['travel_bands'] = array(array('metric' => 'minutes', 'min_value' => 105.0, 'uplift' => 10.0));
$service->rules = array(7 => array($travelPolicy));
expect($service->assess($proposal)[7]['sale'], 'unknown', 'disabled zoning cannot waive route uplift');
$zoningEnabled = true;
expect(LmdbSalesCommissionsCompatibility::isFeatureAvailable('travel_margin_uplift'), true, 'travel controls available when the zoning route contract is active');
LmdbZoningTravelService::$response = array('state' => 'ready', 'profile_ref' => 'HQ', 'date_calculation' => '2026-09-30 12:00:00', 'provider' => 'ign', 'optimization' => 'fastest', 'total' => array('round_trip' => array('duration_s' => 6360.0, 'distance_m' => 50000.0)));
$travelDecision = $service->assess($proposal)[7];
expect($travelDecision['inputs']['travel']['state'], 'ready', 'stored proposal journey used even when client journey is absent');
expect(LmdbZoningTravelService::$source, array('propal', 10, ''), 'native source resolves the proposal reference point or default profile');
expect($travelDecision['sale'], 'deny', '106-minute journey adds ten points to 20% base');
expect($travelDecision['checks'][0]['threshold'], 30.0, 'effective margin threshold');
expect($travelDecision['inputs']['travel']['kilometres'], 50.0, 'stored round-trip metres converted to kilometres');
$settings['LMDBZONING_DEFAULT_PROFILE'] = '';
$settings['LMDBZONING_DEFAULT_REFERENCEPOINT'] = 3;
expect($service->assess($proposal)[7]['checks'][0]['threshold'], 30.0, 'standalone reference point works without a zoning profile');
$settings['LMDBZONING_DEFAULT_PROFILE'] = 'OLD-PROFILE';
expect($service->assess($proposal)[7]['checks'][0]['threshold'], 30.0, 'native source retains reference-point priority over an old profile');
expect(LmdbZoningTravelService::$source, array('propal', 10, ''), 'no forced profile bypasses standalone reference-point selection');
$service->approve($proposal, $actor, 7, 20, 'sale', 'Travel exception', $travelDecision['fingerprint']);
expect($service->assess($proposal)[7]['sale'], 'allow', 'exact-route exception is accepted');
LmdbZoningTravelService::$response['total']['round_trip']['duration_s'] = 6420.0;
expect($service->assess($proposal)[7]['sale'], 'deny', 'new stored journey expires previous exception');
LmdbZoningTravelService::$response['total']['round_trip']['duration_s'] = 6300.0;
$boundaryDecision = $service->assess($proposal)[7];
expect($boundaryDecision['sale'], 'allow', 'exactly 105 minutes does not cross strict breakpoint');
expect($boundaryDecision['fingerprint'] === $travelDecision['fingerprint'], false, 'new route invalidates previous fingerprint');
LmdbZoningTravelService::$response['state'] = 'stale';
expect($service->assess($proposal)[7]['sale'], 'unknown', 'stale route cannot grant sale');
$actor->rightsList = array();
expect($service->assess($proposal)[7]['sale'], 'unknown', 'unreadable route cannot grant sale');
// Signature uses its explicit actor for route access, independently of the global user.
$db = new PolicyDb(); $service = new PolicyService($db); $proposal = new PolicyProposal();
$proposal->lines[0]->total_ht = $proposal->lines[0]->subprice = $proposal->total_ht = 150.0;
$travelPolicy['effect'] = 'commission';
$service->rules = array(7 => array($travelPolicy));
LmdbZoningTravelService::$response['state'] = 'ready';
LmdbZoningTravelService::$response['total']['round_trip']['duration_s'] = 6360.0;
$signer = new PolicyUser(); $signer->rightsList = array('read');
expect($service->assess($proposal)[7]['commission'], 'unknown', 'global actor cannot read the required route');
$proposal->date_signature = 2000; $proposal->status = 2; $proposal->context['lmdb_margin_signing'] = true;
$service->freeze($proposal, $signer);
$snapshot = $service->assess($proposal)[7];
expect($snapshot['commission'], 'allow', 'freeze preserves signing actor route access');
expect($snapshot['checks'][0]['threshold'], 30.0, 'freeze keeps the effective travel target');
expect($snapshot['inputs']['travel']['minutes'], 106.0, 'freeze keeps the actual journey used for its decision');
expect(array_key_exists('reward', $snapshot) && $snapshot['reward'] === null, true, 'freeze records explicit reward absence alongside travel');
LmdbZoningTravelService::$response['total']['round_trip']['duration_s'] = 600.0;
$service->freeze($proposal, $signer);
expect(count($db->snapshots), 1, 'replayed signature does not replace the travel and reward snapshot');
expect($service->assess($proposal)[7]['checks'][0]['threshold'], 30.0, 'changed journey cannot alter a frozen reward target');
$db = new PolicyDb(); $service = new PolicyService($db); $proposal = new PolicyProposal();
$propalPvEnabled = true; $complexFieldEnabled = true; $settings['LMDBPROPALPV_COMPLEX_SITE_ENABLED'] = 1;
$actor->rightsList = array('approvesale');
$complexRule = policy(30, 'general', 'sale', 20.0);
$complexRule['complex_site'] = array('uplift_without_travel' => 15.0, 'uplift_with_travel' => null);
$unrelated = $complexRule;
$unrelated['context'] = 'pv'; $unrelated['effect'] = 'commission';
$service->rules = array(7 => array($unrelated));
$proposal->array_options['options_powerplantpv_peak_power'] = 0;
$proposal->array_options['options_powerplantpv_storage_capacity'] = 10;
$callsBefore = LmdbPropalPVComplexSiteService::$calls;
expect($service->assess($proposal)[7]['commission'], 'allow', 'unrelated PV rule does not apply to storage proposal');
expect(LmdbPropalPVComplexSiteService::$calls, $callsBefore, 'unrelated rule does not query complex-site availability');
$proposal->array_options = array();
$service->rules = array(7 => array($complexRule));
$proposal->array_options['options_lmdbpropalpv_complex_site'] = 1;
$on = $service->assess($proposal)[7];
expect($on['checks'][0]['threshold'], 35.0, 'stored qualification raises the threshold');
expect($on['sale'], 'deny', 'ON qualification blocks insufficient sale margin');
$service->rules[7][0]['complex_site']['uplift_without_travel'] = 16.0;
$changedConfiguration = $service->assess($proposal)[7];
expect($changedConfiguration['checks'][0]['threshold'], 36.0, 'edited complex configuration changes the current threshold');
expect($changedConfiguration['fingerprint'] === $on['fingerprint'], false, 'edited complex configuration changes the approval fingerprint');
$service->rules[7][0]['complex_site']['uplift_without_travel'] = 15.0; $db->revision++;
$on = $service->assess($proposal)[7];
$service->approve($proposal, $actor, 7, 30, 'sale', 'Complex exception', $on['fingerprint']);
expect($service->assess($proposal)[7]['sale'], 'allow', 'matching complex approval accepted');
$proposal->array_options['options_lmdbpropalpv_complex_site'] = null; $db->revision++;
$off = $service->assess($proposal)[7];
expect($off['checks'][0]['threshold'], 20.0, 'NULL switch is OFF');
expect($off['fingerprint'] === $on['fingerprint'], false, 'switch mutation invalidates approval fingerprint');
$proposal->array_options['options_lmdbpropalpv_complex_site'] = 1; $db->revision++;
expect($service->assess($proposal)[7]['sale'], 'deny', 'old approval does not revive when switch returns ON');
$propalPvEnabled = false;
expect($service->assess($proposal)[7]['sale'], 'unknown', 'disabled dependency makes active rule indeterminate');
$propalPvEnabled = true; $settings['LMDBPROPALPV_COMPLEX_SITE_ENABLED'] = 0;
expect($service->assess($proposal)[7]['sale'], 'unknown', 'disabled entity setting makes active rule indeterminate');
$settings['LMDBPROPALPV_COMPLEX_SITE_ENABLED'] = 1; $complexFieldEnabled = false;
expect($service->assess($proposal)[7]['sale'], 'unknown', 'missing extrafield definition makes active rule indeterminate');
$complexFieldEnabled = true;
$proposal->date_signature = 2000; $proposal->status = 2; $proposal->context['lmdb_margin_signing'] = true;
$service->freeze($proposal, $actor);
$settings['LMDBPROPALPV_COMPLEX_SITE_ENABLED'] = 0;
expect($service->assess($proposal)[7]['checks'][0]['threshold'], 35.0, 'signed decision retains complex uplift after dependency removal');
print "Service + native FormMargin: $tests assertions passed using ".DOL_DOCUMENT_ROOT.".\n";
