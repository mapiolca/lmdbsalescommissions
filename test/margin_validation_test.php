<?php
/** Native Form confirmation and HookManager; users/SQL/proposals remain simulated. */
require __DIR__.'/margin_guard_test.php';
define('DOL_URL_ROOT', '/erp');
function dol_buildpath($path, $type = 0) { return '/erp/custom'.$path; }
function dol_escape_htmltag($value, ...$args) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function dol_escape_js($value) { return addslashes($value); }
function newToken() { return 'test-only-csrf'; }
function getNonce() { return 'test-only-nonce'; }
function img_help(...$args) { return ''; }
function img_picto(...$args) { return ''; }
class ValidationLangs extends GuardLangs {
	public function transnoentities($key, ...$args) { return $key; }
}
class ValidationDb extends GuardDb {
	public $effect = 'both'; public $requests = array(); public $requestFailure = false;
	public function query($sql) {
		if (strpos($sql, 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_request') === 0) {
			if ($this->requestFailure) { return false; }
			$this->requests[] = $sql; return true;
		}
		if (strpos($sql, '_rule r') !== false) {
			return new PolicyRows(array(array('rowid'=>1,'assignment_type'=>'default','assignment_id'=>1,'policy_context'=>'general','policy_effect'=>$this->effect,'rate'=>30,'ref'=>'MINIMUM')));
		}
		return parent::query($sql);
	}
}
$db = new ValidationDb(); $langs = new ValidationLangs(); $user = new PolicyUser();
$user->rightsList = array('lire', 'creer'); $allowedScope = true; $post = array();
$settings['LMDBSALESCOMMISSIONS_MARGIN_ENABLED'] = 1;
$conf->entity = 1; $conf->use_javascript_ajax = 1; $conf->browser = (object) array('layout'=>'classic');
$hookmanager = new HookManager($db); $hookmanager->initHooks(array('propalcard'));
$object = new Propal($db); $object->status = 0; $action = 'validate';
$params = array('formConfirm'=>'native validation confirmation');
expect($hookmanager->executeHooks('formConfirm', $params, $object, $action), 1, 'blocked sale replaces native confirmation');
expect(strpos($hookmanager->resPrint, 'LscRequestApproval') !== false, true, 'request choice');
expect(strpos($hookmanager->resPrint, 'LscModifyProposal') !== false, true, 'edit choice');
expect(strpos($hookmanager->resPrint, 'confirm_validate'), false, 'blocked modal has no validation action');
expect(strpos($hookmanager->resPrint, '&confirm=no') !== false, true, 'native edit choice navigates away');
expect(count($db->requests), 0, 'rendering never records a request');
// Same manager, second render: commission-only denial must preserve native validation.
$db->effect = 'commission';
expect($hookmanager->executeHooks('formConfirm', $params, $object, $action), 0, 'commission target never interrupts sale');
expect($hookmanager->resPrint, '', 'native hook resets prior modal');
$db->effect = 'both'; $object->lines[0]->total_ht = 130.0;
expect($hookmanager->executeHooks('formConfirm', $params, $object, $action), 0, 'exact threshold preserves validation');
$object->lines[0]->pa_ht = 0;
expect($hookmanager->executeHooks('formConfirm', $params, $object, $action), 1, 'unknown sale offers correction');
expect(strpos($hookmanager->resPrint, 'LscRequestApproval'), false, 'unknown cost is not requestable');
$object->lines[0]->pa_ht = 100.0; $object->lines[0]->total_ht = 125.0;
$user->rightsList = array('lire');
expect($hookmanager->executeHooks('formConfirm', $params, $object, $action), 0, 'no validation right does not expose modal');
$user->rightsList = array('lire', 'creer');
$service = new LmdbSalesCommissionMarginService($db);
$before = $service->assess($object); $fp = $before[7]['fingerprint'];
$service->requestSaleApproval($object, $user, 'Commercial reason', $fp);
expect(count($db->requests), 1, 'request stored with one atomic statement');
expect(substr_count($db->requests[0], "'Commercial reason'"), 1, 'commission check excluded from sale request');
expect($service->saleAllowed($object), false, 'request never grants sale approval');
expect($service->commissionState($object, 7), 'deny', 'request never restores commission');
expect(count($db->approvals), 0, 'no approval recorded');
$refused = static function ($expected, $reason, $fingerprint) use ($service, $object, $user) {
	try { $service->requestSaleApproval($object, $user, $reason, $fingerprint); throw new Exception('Request should fail'); }
	catch (RuntimeException $e) { expect($e->getMessage(), $expected, 'request refusal'); }
};
$refused('LscRequestReasonRequired', ' ', $fp);
$refused('LscApprovalStale', 'Reason', str_repeat('0',64));
$user->rightsList = array('lire'); $refused('LscApprovalDenied', 'Reason', $fp);
$user->rightsList = array('lire','creer'); $user->socid = 1; $refused('LscApprovalDenied', 'Reason', $fp); $user->socid = 0;
$allowedScope = false; $refused('LscApprovalDenied', 'Reason', $fp); $allowedScope = true;
$conf->entity = 2; $refused('LscApprovalDenied', 'Reason', $fp); $conf->entity = 1;
$object->status = 1; $refused('LscApprovalDenied', 'Reason', $fp); $object->status = 0;
$settings['MAIN_USE_ADVANCED_PERMS'] = 1;
$refused('LscApprovalDenied', 'Reason', $fp);
$user->rightsList = array('lire','validate');
$service->requestSaleApproval($object, $user, 'Advanced validation right', $fp);
$settings['MAIN_USE_ADVANCED_PERMS'] = 0; $user->rightsList = array('lire','creer');
$db->requestFailure = true; $refused('LscPolicyUnavailable', 'Reason', $fp); $db->requestFailure = false;
$db->effect = 'commission';
$commissionOnly = $service->assess($object);
$refused('LscRequestNoDeniedSale', 'Reason', $commissionOnly[7]['fingerprint']);
$db->effect = 'both';
$db->revision++;
$refused('LscApprovalStale', 'Reason', $fp);
$current = $service->assess($object); $fp = $current[7]['fingerprint'];
$db->dispatch = array(array('fk_user'=>7,'base_type'=>'turnover','value_type'=>'amount','value'=>10), array('fk_user'=>8,'base_type'=>'turnover','value_type'=>'amount','value'=>10));
$multiple = $service->assess($object);
$service->requestSaleApproval($object,$user,'Both beneficiaries',$multiple[7]['fingerprint']);
expect(substr_count(end($db->requests), "'Both beneficiaries'"), 2, 'each blocked beneficiary is included');
$db->dispatch = array();
$conf->use_javascript_ajax = 0;
expect($hookmanager->executeHooks('formConfirm', $params, $object, $action), 1, 'native no-JS confirmation');
expect(strpos($hookmanager->resPrint, '<form method="POST"') !== false, true, 'no-JS choices keep native form');
expect(strpos($hookmanager->resPrint, 'name="token"') !== false, true, 'native no-JS CSRF token present');
$conf->use_javascript_ajax = 1;
// An accepted sale exception removes the modal; commission remains independently denied.
$user->rightsList[] = 'approvesale'; $service->approve($object,$user,7,1,'sale','Approved',$fp);
expect($hookmanager->executeHooks('formConfirm', $params, $object, $action), 0, 'approved sale uses native validation');
expect($service->commissionState($object, 7), 'deny', 'sale approval preserves commission denial');
print "Validation modal and requests: $tests total assertions passed.\n";
