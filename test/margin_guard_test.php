<?php
/** Native Restler call/event queue and HookManager lifecycle, simulated authenticated user/SQL.
 * No HTTP server, token authentication, PDF writer or ERP database is simulated as a real instance.
 */
require __DIR__.'/margin_service_test.php';
foreach (array('EventDispatcher', 'Restler', 'Scope', 'Defaults', 'RestException') as $class) {
	require_once DOL_DOCUMENT_ROOT.'/includes/restler/framework/Luracast/Restler/'.$class.'.php';
}
require_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
require_once __DIR__.'/../class/actions_lmdbsalescommissions.class.php';

class GuardDb extends PolicyDb {
	public function query($sql) {
		if (strpos($sql, '_rule r') !== false) {
			if (strpos($sql, 'r.entity = 1') === false) { throw new RuntimeException('Wrong policy entity'); }
			return new PolicyRows(array(array('rowid'=>1,'assignment_type'=>'default','assignment_id'=>1,'policy_context'=>'general','policy_effect'=>'both','rate'=>30,'ref'=>'MINIMUM')));
		}
		if (strpos($sql, '_margin_band') !== false) { return new PolicyRows(array()); }
		if (strpos($sql, '_margin_travel_band') !== false) { return new PolicyRows(array()); }
		if (strpos($sql, '_margin_complex_site') !== false) { return new PolicyRows(array()); }
		return parent::query($sql);
	}
}
class Propal extends PolicyProposal {
	public function __construct($db) { parent::__construct(); }
	public function fetch($id, $ref = '') { $this->id = $id ?: 10; return 1; }
}
class User extends PolicyUser {
	public function __construct($db) {}
	public function fetch($id) { $this->id = $id; return $id > 0 ? 1 : 0; }
}
class DolibarrApiAccess { public static $user; }
class Proposals {
	public $effects = 0;
	public function post($request_data) { $this->effects++; }
	public function validate($id, $notrigger = 0) { $this->effects++; }
	public function close($id, $status, $notrigger = 0) { $this->effects++; }
}
class GuardLangs {
	public function load($key) {}
	public function trans($key) { return $key; }
}
$allowedScope = true; $post = array(); $messages = array();
function restrictedArea(...$args) { global $allowedScope; return $allowedScope ? 1 : 0; }
function GETPOST($key, $type) { global $post; return $post[$key] ?? ''; }
function GETPOSTINT($key) { return (int) GETPOST($key, 'int'); }
function setEventMessages($message, $errors, $level) { global $messages; $messages[] = $message; }
function dol_include_once($path) { require_once __DIR__.'/..'.substr($path, strlen('/lmdbsalescommissions')); return 1; }
function httponly_accessforbidden($message, $status) { throw new RuntimeException($message, $status); }

$db = new GuardDb(); $conf->entity = 1; $langs = new GuardLangs();
$user = new PolicyUser(); $user->rightsList = array('creer'); DolibarrApiAccess::$user = $user;
$conf->modules_parts = array('hooks' => array('lmdbsalescommissions' => array('api','propalcard','propallist','ajaxonlinesign')));
$hookmanager = new HookManager($db);
// Exactly the native order: context instantiates our controller before Restler exists.
$hookmanager->initHooks(array('api'));
$restler = (new ReflectionClass(\Luracast\Restler\Restler::class))->newInstanceWithoutConstructor();
(new ReflectionMethod(\Luracast\Restler\EventDispatcher::class, '__construct'))->invoke($restler);
\Luracast\Restler\Scope::set('Restler', $restler);
$endpoint = new Proposals(); \Luracast\Restler\Scope::set('Proposals', $endpoint);
$call = new ReflectionMethod($restler, 'call'); $call->setAccessible(true);
$restler->requestMethod = 'POST';
$request = static function ($method, $args, $expectedCode) use ($restler, $call) {
	$restler->apiMethodInfo = (object) array('className'=>'Proposals','methodName'=>$method,'arguments'=>array_flip(array_keys($args)),'parameters'=>array_values($args),'accessLevel'=>0);
	try { $call->invoke($restler); expect(0, $expectedCode, 'native API allowed '.$method); }
	catch (\Luracast\Restler\RestException $e) { expect($e->getCode(), $expectedCode, 'native API refused '.$method); }
};
$request('post', array('request_data'=>array('socid'=>1,'status'=>2)), 409);
$request('post', array('request_data'=>array('socid'=>1,'notrigger'=>1)), 409);
$request('post', array('request_data'=>array('entity'=>2)), 409);
$request('validate', array('id'=>10,'notrigger'=>1), 409);
$request('validate', array('id'=>10,'notrigger'=>0), 409);
$request('close', array('id'=>10,'status'=>2,'notrigger'=>0), 409);
expect($endpoint->effects, 0, 'no native endpoint mutation after any denial');
$request('post', array('request_data'=>array('socid'=>1)), 0);
expect($endpoint->effects, 1, 'ordinary draft creation preserved');
$allowedScope = false; $request('validate', array('id'=>10,'notrigger'=>0), 403); $allowedScope = true;
$user->rightsList = array(); $request('post', array('request_data'=>array('socid'=>1)), 403); $user->rightsList = array('creer');
$settings['LMDBSALESCOMMISSIONS_MARGIN_ENABLED'] = 0;
$request('validate', array('id'=>10,'notrigger'=>1), 0);
$settings['LMDBSALESCOMMISSIONS_MARGIN_ENABLED'] = 1;

$hookmanager = new HookManager($db); $hookmanager->initHooks(array('propallist'));
$object = new Propal($db); $action = 'sign'; $toselect = array(10, 11); $massaction = '';
expect($hookmanager->executeHooks('doActions', array(), $object, $action), 1, 'native list hook denies batch');
expect($action, '', 'native unguarded list action cleared'); expect($toselect, array(), 'batch cleared before first operation');
$action = 'view';
expect($hookmanager->executeHooks('doActions', array(), $object, $action), 0, 'second native hook call neutral');
expect($hookmanager->resPrint, '', 'native reset leaves no stale output');
$hookmanager = new HookManager($db); $hookmanager->initHooks(array('propalcard'));
$action = 'confirm_closeas'; $post['statut'] = 2;
expect($hookmanager->executeHooks('doActions', array(), $object, $action), 1, 'card signature refused before close');

$_SERVER['SCRIPT_FILENAME'] = DOL_DOCUMENT_ROOT.'/core/ajax/onlineSign.php';
$post = array('action'=>'importSignature','mode'=>'proposal','ref'=>'PR-TEST');
$hookmanager = new HookManager($db);
try { $hookmanager->initHooks(array('ajaxonlinesign')); throw new Exception('Public signature guard missing'); }
catch (RuntimeException $e) { expect($e->getCode(), 403, 'public guard refuses during initialization before file writes'); }
expect(count($db->snapshots), 0, 'prechecks never create signature snapshots');
// Exercise the actual manual commission calculation; relation validation/payment schedule are isolated.
if (is_file(DOL_DOCUMENT_ROOT.'/core/class/doldeprecationhandler.class.php')) { require_once DOL_DOCUMENT_ROOT.'/core/class/doldeprecationhandler.class.php'; }
require_once __DIR__.'/../class/lmdbsalescommissionproposaldispatchservice.class.php';
function price2num($value, $mode = '') { return (float) $value; }
class GuardDispatchService extends LmdbSalesCommissionProposalDispatchService {
	public function validate($dispatch, $proposal, $date) { return true; }
	public function resolvePaymentTermId($dispatch, $date) { return 0; }
}
$dispatch = new LmdbSalesCommissionProposalDispatch($db);
$dispatch->entity=1; $dispatch->fk_propal=10; $dispatch->fk_user=7;
$dispatch->base_type='turnover'; $dispatch->value_type='amount'; $dispatch->value=10;
$calculation = (new GuardDispatchService($db))->calculate($dispatch, new Propal($db), 2000);
expect($calculation['commission'], 0.0, 'manual fixed commission denied');
expect($calculation['turnover'], 125.0, 'CA retained when commission denied');
$dispatch->value_type='percentage'; $dispatch->value=10;
$calculation = (new GuardDispatchService($db))->calculate($dispatch, new Propal($db), 2000);
expect($calculation['commission'], 0.0, 'manual proportional commission denied');
$settings['LMDBSALESCOMMISSIONS_MARGIN_ENABLED']=0;
$calculation = (new GuardDispatchService($db))->calculate($dispatch, new Propal($db), 2000);
expect($calculation['commission'], 12.5, 'disabled control preserves manual calculation');
print "Native guards: $tests total assertions passed.\n";
