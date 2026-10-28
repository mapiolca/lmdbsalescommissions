<?php
/** Native Propal::valid and FormMargin; SQL, users, CommonObject persistence and email transport simulated. */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$workflowNativeRoot = realpath($argv[1] ?? __DIR__.'/.core-cache/20.0.0/htdocs');
if (!$workflowNativeRoot) { throw new RuntimeException('Missing pinned core'); }
define('DOL_VERSION', $argv[2] ?? '20.0.0');
$bootstrap = __DIR__.'/.tools/workflow-core-'.DOL_VERSION;
foreach (array('core/ajax/onlineSign.php', 'comm/propal/card.php', 'comm/propal/list.php', 'api/index.php', 'includes/restler/framework/Luracast/Restler/Restler.php', 'core/class/html.formmargin.class.php', 'core/class/html.form.class.php') as $file) {
 if (!is_dir(dirname($bootstrap.'/'.$file))) { mkdir(dirname($bootstrap.'/'.$file), 0777, true); }
 copy($workflowNativeRoot.'/'.$file, $bootstrap.'/'.$file);
}
copy(__DIR__.'/fixtures/margin-workflow/core/class/commonobject.class.php', $bootstrap.'/core/class/commonobject.class.php');
if (!is_dir($bootstrap.'/core/lib')) { mkdir($bootstrap.'/core/lib', 0777, true); }
copy(__DIR__.'/fixtures/margin-workflow/core/lib/files.lib.php', $bootstrap.'/core/lib/files.lib.php');
define('DOL_DOCUMENT_ROOT', $bootstrap);
define('MAIN_DB_PREFIX', 'workflow_');
define('DOL_URL_ROOT', '/erp');
$settings = array('MAIN_MAX_DECIMALS_UNIT' => '5', 'MAIN_MAX_DECIMALS_TOT' => '2', 'LMDBSALESCOMMISSIONS_MARGIN_WORKFLOW_READY' => 1, 'LMDBSALESCOMMISSIONS_MARGIN_ENABLED' => 1, 'LMDBSALESCOMMISSIONS_MARGIN_ACTIVATED_AT' => 1000);
$conf = (object) array('entity' => 1, 'global' => (object) array(), 'currency' => 'EUR', 'modules_parts' => array('hooks' => array('lmdbsalescommissions' => array('propalcard', 'propallist', 'api', 'ajaxonlinesign'))));
$allowedScope = true;
$post = array();
function getDolGlobalInt($key, $default = 0) { global $settings; return (int) ($settings[$key] ?? $default); }
function getDolGlobalBool($key, $default = false) { global $settings; return (bool) ($settings[$key] ?? $default); }
function getDolGlobalString($key, $default = '') { global $settings; return (string) ($settings[$key] ?? $default); }
function isModEnabled($key) { return $key === 'lmdbsalescommissions'; }
function getEntity($key, ...$args) { return '1'; }
function restrictedArea(...$args) { global $allowedScope; return $allowedScope ? 1 : 0; }
function dol_now() { return 2000; }
function dol_syslog($message, $level = 0) {}
function dol_include_once($path) { return 1; }
function newToken() { return 'test-only-token'; }
function getNonce() { return 'test-only-nonce'; }
function dol_escape_js($value) { return addslashes($value); }
function img_help(...$args) { return ''; }
function img_picto(...$args) { return ''; }
function dol_nboflines_bis($text, ...$args) { return substr_count($text, "\n") + 1; }
function dolGetStatus($long, $short = '', ...$args) { return $long; }
function dol_sanitizeFileName($ref) { return $ref; }
function dol_escape_htmltag($text, ...$args) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function dol_buildpath($path, $mode = 0) { return '/erp/custom'.$path; }
function GETPOST($name, $type = '') { global $post; return $post[$name] ?? ''; }
function GETPOSTINT($name) { return (int) GETPOST($name); }
function setEventMessages(...$args) {}
function price($amount, ...$args) { return (string) $amount; }
function dol_eval($expression, ...$args) { return $expression === '1' ? 1 : 0; }
function dol_strlen($text) { return strlen((string) $text); }
// Execute the native monetary normalizer with controlled decimal configuration.
$functions = file_get_contents($workflowNativeRoot.'/core/lib/functions.lib.php');
preg_match('/^function price2num\(.*?^}/ms', $functions, $priceMethod);
eval($priceMethod[0]);
function nativeWorkflowMethod($file, $name) {
	$tokens = token_get_all(file_get_contents($file));
	for ($i = 0; $i < count($tokens); $i++) {
		if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
		$j = $i + 1;
		while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
		if (!is_array($tokens[$j]) || $tokens[$j][1] !== $name) { continue; }
		$body = ''; $depth = 0; $started = false;
		for ($k = $i; $k < count($tokens); $k++) {
			$t = $tokens[$k]; $body .= is_array($t) ? $t[1] : $t;
			if ($t === '{') { $depth++; $started = true; }
			if ($t === '}') { $depth--; if ($started && !$depth) { return $body; } }
		}
	}
	throw new RuntimeException('Native method missing: '.$name);
}
eval('trait NativeWorkflowValidation { '.nativeWorkflowMethod($workflowNativeRoot.'/comm/propal/class/propal.class.php', 'valid').' }');
class WorkflowLangs {
	public function load($key) {}
	public function loadLangs($keys) {}
	public function trans($key, ...$args) { return $key; }
	public function transnoentities($key, ...$args) { return $key; }
	public function transnoentitiesnoconv($key) { return $key === 'SeparatorDecimal' ? '.' : ($key === 'SeparatorThousand' ? ',' : $key); }
}
$langs = new WorkflowLangs();
class User {
	public $id = 9;
	public $socid = 0;
	public $error = '';
	public $rightsList = array('lire', 'creer', 'approvesale', 'approvecommission');
	public $children = array(7, 8);
	public function hasRight($module, $object, $action = null) { return in_array($action ?? $object, $this->rightsList, true); }
	public function getAllChildIds($include = 0) { return $this->children; }
}
eval('trait NativeWorkflowHierarchy { '.nativeWorkflowMethod($workflowNativeRoot.'/user/class/user.class.php', 'getAllChildIds').' '.nativeWorkflowMethod($workflowNativeRoot.'/user/class/user.class.php', 'build_path_from_id_user').' }');
class NativeHierarchyUser extends User {
	use NativeWorkflowHierarchy;
	public $users = array(); public $cache_childids = array(); public $parentof = array(8 => 9, 7 => 8);
	public function get_full_tree() {
		foreach (array(9, 8, 7, 6) as $id) { $this->users[$id] = array('id' => $id, 'lastname' => 'U'.$id, 'fullpath' => ''); }
		foreach ($this->users as $id => $row) { if ($this->build_path_from_id_user($id) < 0) { $this->error = 'ErrorLoopInHierarchy'; return -1; } }
		return $this->users;
	}
}
class Societe { public function __construct($db) {} public function fetch($id) { return 1; } }
class Propal {
	use NativeWorkflowValidation;
	public $table_element = 'propal'; public $element = 'propal';
	public const STATUS_DRAFT = 0;
	public const STATUS_VALIDATED = 1;
	public $db; public $id = 10; public $entity = 1; public $status = 0; public $socid = 15; public $ref = 'P10'; public $oldref; public $newref;
	public $user_author_id = 7; public $date_signature = 0; public $total_ht = 125.0; public $total_ttc = 150.0;
	public $lines = array(); public $array_options = array(); public $context = array(); public $error = ''; public $errors = array();
	public $user_validation_id; public $datev; public $date_validation;
	public function __construct($db) { $this->db = $db; $this->fetch_lines(); }
	public function fetch($id) {
		$this->id = $id;
		$row = $this->db->proposals[$id] ?? null;
		if (!$row) { return 0; }
		foreach ($row as $key => $value) { $this->{$key} = $value; }
		$this->fetch_lines(); return 1;
	}
	public function fetch_lines() { $this->lines = array((object) array('id' => 1, 'total_ht' => $this->total_ht, 'subprice' => $this->total_ht, 'pa_ht' => $this->db->cost, 'qty' => 1.0, 'remise_percent' => 0, 'product_type' => 0, 'fk_product_type' => 0, 'fk_product' => null)); return 1; }
	public function fetch_optionals() { return 1; }
	public function checkActiveProductInLines() { return 1; }
	public function __get($name) { return $name === 'statut' ? $this->status : null; }
	public function __isset($name) { return $name === 'statut'; }
	public function __set($name, $value) { if ($name === 'statut') { $this->status = $value; return; } throw new RuntimeException('Unknown quote property '.$name); }
	public function call_trigger($action, $actor) {
		global $user;
		$this->db->nativeValidations[] = $actor->id;
		if ($this->db->failure === 'validation') { $this->error = 'TestValidationFailure'; return -1; }
		$current = new self($this->db); $current->fetch($this->id);
		return (new LmdbSalesCommissionMarginService($this->db))->saleAllowed($current, $actor) ? 0 : -1;
	}
}
class WorkflowRows { public $rows; public function __construct($rows) { $this->rows = $rows; } }
class WorkflowDb {
	public $objects = array(); public $proposals = array(); public $approvals = array(); public $snapshots = array();
	public $revision = 1; public $failure = ''; public $nativeValidations = array(); public $events = array(); public $emails = array(); public $notifications = true;
	public $commissionThreshold = 60.0; public $saleThreshold = 30.0; public $beneficiaries = array(7);
	public $queries = array();
	public $notificationContexts = array();
	public $cost = 100.0;
	private $depth = 0; private $backup;
	public function __construct() { $this->proposals[10] = array('entity' => 1, 'status' => 0, 'statut' => 0, 'user_author_id' => 7, 'total_ht' => 125.0, 'ref' => 'P10'); }
	public function begin() {
		if ($this->failure === 'begin') { return 0; }
		if (!$this->depth++) { $this->backup = serialize(array($this->objects, $this->proposals, $this->approvals, $this->snapshots)); }
		return 1;
	}
	public function commit() { if ($this->failure === 'commit' && $this->depth === 1) { return 0; } $this->depth--; return 1; }
	public function rollback() { if ($this->depth > 0 && --$this->depth === 0) { list($this->objects, $this->proposals, $this->approvals, $this->snapshots) = unserialize($this->backup); } return 1; }
	public function saveObject($object) {
		if ($this->failure === 'save') { $object->error = 'TestSaveFailure'; return -1; }
		if (!$object->id) { $object->id = count($this->objects[$object->table_element] ?? array()) + 1; }
		$row = array('rowid' => $object->id, 'id' => $object->id);
		foreach ($object->fields as $key => $metadata) { $row[$key] = $key === 'rowid' ? $object->id : $object->{$key}; }
		$this->objects[$object->table_element][$object->id] = $row; return $object->id;
	}
	public function event($action, $object, $actor) {
		$this->events[] = $action;
		$this->notificationContexts[] = array('ref' => $object->ref, 'total_ht' => $object->total_ht, 'socid' => $object->socid);
		if (!$this->notifications) { return 0; }
		if ($this->failure === 'notification') { return -1; }
		$this->emails[] = array($action, $object->id, $object->status); return 0;
	}
	public function query($sql) {
		$this->queries[] = $sql;
		if ($this->failure === 'read' || ($this->failure === 'approval' && strpos($sql, 'INSERT INTO workflow_lmdbsalescommissions_margin_approval') === 0)) { return false; }
		if (strpos($sql, 'UPDATE workflow_propal SET') === 0) { $this->proposals[10]['status'] = $this->proposals[10]['statut'] = 1; return true; }
		if (strpos($sql, 'FROM workflow_propal WHERE') !== false) {
			preg_match('/entity = (\d+) AND rowid = (\d+)/', $sql, $m); $p = $this->proposals[(int) $m[2]] ?? null;
			return new WorkflowRows($p && $p['entity'] === (int) $m[1] ? array(array('rowid' => (int) $m[2], 'fk_statut' => $p['status'])) : array());
		}
		if (strpos($sql, '_margin_case') !== false) {
			preg_match('/entity = (\d+)/', $sql, $m); $rows = array_values(array_filter($this->objects['lmdbsalescommissions_margin_case'] ?? array(), static function ($r) use ($m) { return (int) $r['entity'] === (int) $m[1]; }));
			if (preg_match('/active_proposal = (\d+)/', $sql, $m)) { $rows = array_values(array_filter($rows, static function ($r) use ($m) { return $r['active_proposal'] !== null && (int) $r['active_proposal'] === (int) $m[1] && (int) $r['status'] === 0; })); }
			if (preg_match('/fk_propal = (\d+)/', $sql, $m)) { $rows = array_values(array_filter($rows, static function ($r) use ($m) { return (int) $r['fk_propal'] === (int) $m[1]; })); }
			if (strpos($sql, 'status = 1') !== false) { $rows = array_values(array_filter($rows, static function ($r) { return (int) $r['status'] === 1; })); }
			if (preg_match('/fk_author = (\d+)/', $sql, $m)) { $rows = array_values(array_filter($rows, static function ($r) use ($m) { return (int) $r['fk_author'] === (int) $m[1]; })); }
			if (preg_match("/fingerprint = '([^']+)'/", $sql, $m)) { $rows = array_values(array_filter($rows, static function ($r) use ($m) { return $r['fingerprint'] === $m[1]; })); }
			return new WorkflowRows(array_reverse($rows));
		}
		if (strpos($sql, 'INSERT INTO workflow_lmdbsalescommissions_margin_approval') === 0 || strpos($sql, 'INSERT INTO workflow_lmdbsalescommissions_margin_snapshot') === 0) {
			preg_match('/\(([^)]+)\) VALUES \((.*)\)$/s', $sql, $m);
			$keys = explode(',', $m[1]); $values = str_getcsv($m[2], ',', "'", '\\'); $row = array();
			foreach ($keys as $i => $key) { $row[$key] = stripslashes(trim($values[$i])); }
			$row['rowid'] = count($this->approvals) + 1;
			if (strpos($sql, '_margin_snapshot') !== false) { $this->snapshots[] = $row; } else { $this->approvals[] = $row; }
			return true;
		}
		if (strpos($sql, '_margin_approval') !== false) {
			preg_match("/fingerprint = '([^']+)'/", $sql, $m);
			return new WorkflowRows(array_values(array_filter($this->approvals, static function ($r) use ($m) { return $r['fingerprint'] === $m[1]; })));
		}
		if (strpos($sql, '_margin_snapshot') !== false) { return new WorkflowRows($this->snapshots); }
		if (strpos($sql, '_margin_revision') !== false) { return new WorkflowRows(array(array('object_id' => 10, 'revision' => $this->revision))); }
		if (strpos($sql, '_rule r') !== false) { return new WorkflowRows(array(
			array('rowid' => 1, 'assignment_type' => 'default', 'assignment_id' => 1, 'policy_context' => 'general', 'policy_effect' => 'sale', 'rate' => $this->saleThreshold, 'ref' => 'MINIMUM'),
			array('rowid' => 2, 'assignment_type' => 'default', 'assignment_id' => 2, 'policy_context' => 'general', 'policy_effect' => 'commission', 'rate' => $this->commissionThreshold, 'ref' => 'TARGET'),
		)); }
		if (strpos($sql, '_rule_assignment AS a') !== false) {
			return new WorkflowRows(strpos($sql, "r.rule_type = 'margin_excess'") !== false ? array() : array(array('assignment_id' => 3, 'assignment_type' => 'default', 'assignment_priority' => 0, 'assignment_payment_term' => null, 'rule_id' => 3, 'rule_ref' => 'COM15', 'rule_label' => 'Commission 15%', 'rule_type' => 'margin', 'source_type' => 'proposal', 'period_type' => 'monthly', 'rate' => 15, 'fk_tier_grid' => null, 'rule_payment_term' => null, 'rule_priority' => 0)));
		}
		if (strpos($sql, '_proposal_dispatch') !== false) {
			if (strpos($sql, 'SELECT fk_user,') === 0) { return new WorkflowRows(count($this->beneficiaries) > 1 ? array_map(static function ($id) { return array('fk_user' => $id, 'base_type' => 'margin', 'value_type' => 'percentage', 'value' => 15); }, $this->beneficiaries) : array()); }
			return new WorkflowRows(array());
		}
		if (strpos($sql, '_proposal_turnover_dispatch') !== false || strpos($sql, 'usergroup_user') !== false || strpos($sql, '_margin_band') !== false || strpos($sql, '_margin_travel_band') !== false || strpos($sql, '_margin_complex_site') !== false || strpos($sql, '_margin_product') !== false || strpos($sql, '_margin_category') !== false) { return new WorkflowRows(array()); }
		if (strpos($sql, 'FROM workflow_user') !== false) { return new WorkflowRows(array(array('rowid' => 7))); }
		throw new RuntimeException('Unexpected workflow SQL: '.$sql);
	}
	public function fetch_object($q) { $row = array_shift($q->rows); return $row === null ? false : (object) $row; }
	public function free($q) {}
	public function num_rows($q) { return count($q->rows); }
	public function escape($value) { return addslashes($value); }
	public function sanitize($value, ...$args) { return preg_replace('/[^a-zA-Z0-9_.,]/', '', $value); }
	public function prefix() { return MAIN_DB_PREFIX; }
	public function idate($date) { return gmdate('Y-m-d H:i:s', $date); }
	public function jdate($date) { return is_numeric($date) ? (int) $date : strtotime($date); }
	public function lasterror() { return 'Injected failure'; }
}
require_once __DIR__.'/../class/lmdbsalescommissionmarginworkflow.class.php';
$checks = 0;
function checkWorkflow($ok, $label) { global $checks; $checks++; if (!$ok) { throw new RuntimeException($label); } }
function refusedWorkflow($expected, $callable, $label) { try { $callable(); throw new LogicException('Unexpected success: '.$label); } catch (RuntimeException $e) { checkWorkflow($e->getMessage() === $expected, $label.': '.$e->getMessage()); } }
function workflowFixture() {
	global $user;
	$db = new WorkflowDb(); $quote = new Propal($db); $user = new User();
	return array($db, $quote, $user, new LmdbSalesCommissionMarginWorkflow($db));
}
function workflowFingerprint($db, $quote, $actor) { $d = (new LmdbSalesCommissionMarginService($db))->assess($quote, false, $actor); return $d[7]['fingerprint']; }
list($db, $quote, $user, $flow) = workflowFixture();
$requester = new User(); $requester->id = 7; $requester->rightsList = array('lire', 'creer');
$settings['MAIN_USE_ADVANCED_PERMS'] = 1;
$fp = workflowFingerprint($db, $quote, $requester);
$id = $flow->request($quote, $requester, 'Below target', $fp);
checkWorkflow($id === 1 && $flow->pending($quote) !== null, 'Requester without advanced validation right creates a pending dossier');
checkWorkflow($db->proposals[10]['status'] === 0, 'Native parent remains draft');
checkWorkflow($db->notificationContexts[0] === array('ref' => 'P10', 'total_ht' => 125.0, 'socid' => 15), 'Native notification context includes parent identity, subscriptions and amount thresholds without duplicated columns');
checkWorkflow($flow->request($quote, $requester, 'Repeated click', $fp) === $id && count($db->events) === 1, 'Double submission does not duplicate request or notification');
refusedWorkflow('LscWorkflowLocked', static function () use ($flow, $quote) { $flow->assertUnlocked($quote); }, 'Pending economic changes refused');
refusedWorkflow('LscApprovalDenied', static function () use ($flow, $quote, $requester, $id) { $flow->decide($quote, $requester, $id, false, 'Own request', null); }, 'Ordinary requester cannot decide');
$user->rightsList = array('lire', 'validate', 'approvesubordinates', 'approvecommission');
$flow->assertDecisionAccess($quote, $user, true);
checkWorkflow(true, 'Indirect subordinate is eligible');
$user->children = array(8);
refusedWorkflow('LscApprovalDenied', static function () use ($flow, $quote, $user) { $flow->assertDecisionAccess($quote, $user, true); }, 'Unrelated author refused');
$user->children = array(7); $user->error = 'Invalid hierarchy';
refusedWorkflow('LscApprovalDenied', static function () use ($flow, $quote, $user) { $flow->assertDecisionAccess($quote, $user, true); }, 'Hierarchy error is not a permission');
$user->error = ''; $user->rightsList = array('lire', 'validate', 'approvesale', 'approvecommission');
$hierarchy = new NativeHierarchyUser(); $hierarchy->rightsList = array('lire', 'validate', 'approvesubordinates');
$children = $hierarchy->getAllChildIds(0);
checkWorkflow(in_array(7, $children, true) && in_array(8, $children, true) && !in_array(9, $children, true) && !in_array(6, $children, true), 'Native hierarchy paths cover direct and indirect reports only');
$flow->assertDecisionAccess($quote, $hierarchy, true);
$hierarchy->id = 7;
refusedWorkflow('LscApprovalDenied', static function () use ($flow, $quote, $hierarchy) { $flow->assertDecisionAccess($quote, $hierarchy, false); }, 'Scoped approver cannot approve own dossier');
$flow->decide($quote, $user, $id, true, 'Approved with half commission', 50.0);
checkWorkflow($quote->status === 1 && $db->proposals[10]['status'] === 1 && $quote->user_validation_id === 9, 'Native validation attributes the operation to approver and validates parent');
checkWorkflow($flow->pending($quote) === null && count($db->approvals) === 2, 'Sale and author commission approvals are distinct records');
$policy = new LmdbSalesCommissionMarginService($db);
checkWorkflow($policy->commissionAmount($quote, 7, 3.75) === (float) price2num(1.875, 'MT'), 'Retained percentage applies to direct amount using native rounding');
checkWorkflow($flow->decide($quote, $user, $id, true, 'Approved with half commission', 50.0) === $id && count($db->emails) === 2 && count($db->nativeValidations) === 1, 'Decision replay does not repeat validation or notification');
refusedWorkflow('LscWorkflowChanged', static function () use ($flow, $quote, $user, $id) { $flow->decide($quote, $user, $id, true, 'Conflicting replay', 100.0); }, 'A conflicting replay cannot change an existing decision');
$quote->status = $quote->statut = 2; $quote->date_signature = 2000; $quote->context['lmdb_margin_signing'] = true;
$policy->freeze($quote, $user);
$db->revision++;
checkWorkflow($policy->commissionAmount($quote, 7, 3.75) === (float) price2num(1.875, 'MT'), 'Signed snapshot freezes retained percent despite current rule revisions');
foreach (array(0.0, 25.0, 100.0) as $percent) {
	list($db, $quote, $user, $flow) = workflowFixture(); $user->rightsList[] = 'validate';
	$id = $flow->request($quote, $requester, 'Request', workflowFingerprint($db, $quote, $requester));
	$flow->decide($quote, $user, $id, true, 'Decision', $percent);
	checkWorkflow((new LmdbSalesCommissionMarginService($db))->commissionAmount($quote, 7, 100.0) === $percent, 'Commission choice '.$percent);
}
list($db, $quote, $user, $flow) = workflowFixture(); $user->rightsList[] = 'validate';
$id = $flow->request($quote, $requester, 'First request', workflowFingerprint($db, $quote, $requester));
$flow->decide($quote, $user, $id, false, 'Please revise costs', null);
checkWorkflow($quote->status === 0 && $flow->pending($quote) === null && $db->nativeValidations === array(), 'Return to editing does not validate proposal');
$flow->assertUnlocked($quote);
checkWorkflow($flow->request($quote, $requester, 'Revised request', workflowFingerprint($db, $quote, $requester)) === 2, 'A returned dossier permits a new request');
foreach (array('read', 'approval', 'save', 'validation', 'notification', 'commit') as $failure) {
	list($db, $quote, $user, $flow) = workflowFixture(); $user->rightsList[] = 'validate';
	$id = $flow->request($quote, $requester, 'Request', workflowFingerprint($db, $quote, $requester));
	$db->failure = $failure;
	try { $flow->decide($quote, $user, $id, true, 'Decision', 50.0); throw new LogicException('Failure accepted: '.$failure); }
	catch (RuntimeException $e) { checkWorkflow(true, 'Failure propagated '.$failure); }
	$db->failure = '';
	checkWorkflow($flow->pending($quote) !== null && $db->proposals[10]['status'] === 0 && !$db->approvals && $quote->status === 0, 'Atomic rollback of case, approvals and validation: '.$failure);
	checkWorkflow(!LmdbSalesCommissionMarginWorkflow::inDecision($quote), 'Transition scope cleared after failure: '.$failure);
}
list($db, $quote, $user, $flow) = workflowFixture(); $user->rightsList[] = 'validate';
$id = $flow->request($quote, $requester, 'Request', workflowFingerprint($db, $quote, $requester));
$db->revision++;
refusedWorkflow('LscApprovalStale', static function () use ($flow, $quote, $user, $id) { $flow->decide($quote, $user, $id, true, 'Stale', 100.0); }, 'Changed economics refuse approval');
$flow->decide($quote, $user, $id, false, 'Refresh economics', null);
checkWorkflow($flow->pending($quote) === null, 'Stale request can still be returned for correction');
list($db, $quote, $user, $flow) = workflowFixture(); $user->rightsList[] = 'validate'; $user->id = 7;
$id = $flow->request($quote, $user, 'Own request', workflowFingerprint($db, $quote, $user));
$flow->decide($quote, $user, $id, true, 'Global self approval', 0.0);
checkWorkflow($quote->status === 1, 'Global approver can approve own request');
list($db, $quote, $user, $flow) = workflowFixture(); $user->rightsList[] = 'validate'; $user->rightsList = array_diff($user->rightsList, array('approvecommission'));
$id = $flow->request($quote, $requester, 'Request', workflowFingerprint($db, $quote, $requester));
refusedWorkflow('LscApprovalDenied', static function () use ($flow, $quote, $user, $id) { $flow->decide($quote, $user, $id, true, 'No commission right', 1.0); }, 'Sale permission does not restore commission');
$flow->decide($quote, $user, $id, true, 'No commission', 0.0);
checkWorkflow((new LmdbSalesCommissionMarginService($db))->commissionAmount($quote, 7, 100.0) === 0.0, 'Sale can be validated with zero commission');
list($db, $quote, $user, $flow) = workflowFixture(); $db->notifications = false;
$id = $flow->request($quote, $requester, 'No notification module', workflowFingerprint($db, $quote, $requester));
checkWorkflow($flow->pending($quote) !== null && !$db->emails, 'Notifications disabled do not prevent business transition');
$quote->entity = 2;
refusedWorkflow('LscApprovalDenied', static function () use ($flow, $quote, $user) { $flow->assertDecisionAccess($quote, $user, true); }, 'Owner entity boundary');
$quote->entity = 1; $allowedScope = false;
refusedWorkflow('LscApprovalDenied', static function () use ($flow, $quote, $user) { $flow->assertDecisionAccess($quote, $user, true); }, 'Native object access boundary');
$allowedScope = true;
list($db, $quote, $user, $flow) = workflowFixture(); $user->rightsList[] = 'validate';
$db->beneficiaries = array(7, 8); $db->commissionThreshold = 20.0;
$id = $flow->request($quote, $requester, 'Multiple recipients', workflowFingerprint($db, $quote, $requester));
$flow->decide($quote, $user, $id, true, 'Retain forty percent for author', 40.0);
checkWorkflow((new LmdbSalesCommissionMarginService($db))->commissionAmount($quote, 7, 100.0) === 40.0 && (new LmdbSalesCommissionMarginService($db))->commissionAmount($quote, 8, 100.0) === 100.0, 'Only author commission is reduced; other beneficiary keeps ordinary result');
class WorkflowDispatchCalculation extends LmdbSalesCommissionProposalDispatchService {
	public function validate($dispatch, $proposal, $date) { return true; }
	public function resolvePaymentTermId($dispatch, $date) { return 0; }
}
$allocation = (object) array('fk_user' => 7, 'base_type' => 'turnover', 'value_type' => 'amount', 'value' => 50.0);
$calc = new WorkflowDispatchCalculation($db);
$manual = $calc->calculate($allocation, $quote, 2000);
checkWorkflow($manual['commission'] === 20.0 && $manual['turnover'] === 125.0, 'Fixed manual commission scaled without reducing turnover');
$allocation->value_type = 'percentage'; $allocation->value = 10.0;
checkWorkflow($calc->calculate($allocation, $quote, 2000)['commission'] === 5.0, 'Proportional manual commission scaled once');
list($db, $quote, $user, $flow) = workflowFixture(); $db->saleThreshold = 20.0;
refusedWorkflow('LscRequestNoDeniedSale', static function () use ($db, $flow, $quote, $requester) { $flow->request($quote, $requester, 'Commission only', workflowFingerprint($db, $quote, $requester)); }, 'Commission target alone cannot create request');
// Read immutable native contracts without treating this as a full installed ERP or SMTP test.
list($db, $quote, $user, $flow) = workflowFixture();
refusedWorkflow('LscRequestReasonRequired', static function () use ($db, $flow, $quote, $requester) { $flow->request($quote, $requester, ' ', workflowFingerprint($db, $quote, $requester)); }, 'Reason required before any mutation');
// Unknown margin is tested through the real calculator, with missing unit cost.
$db->cost = 0.0;
refusedWorkflow('LscWorkflowUnknown', static function () use ($flow, $quote, $requester) { $flow->requestable($quote, $requester); }, 'Indeterminate sale is not requestable');
list($db, $quote, $user, $flow) = workflowFixture(); $user->rightsList[] = 'validate';
$id = $flow->request($quote, $requester, 'Request', workflowFingerprint($db, $quote, $requester));
foreach (array(-1.0, 101.0, INF, NAN, null) as $invalid) {
	refusedWorkflow('LscWorkflowInvalidPercent', static function () use ($flow, $quote, $user, $id, $invalid) { $flow->decide($quote, $user, $id, true, 'Decision', $invalid); }, 'Invalid percentage refused');
}
$settings['LMDBSALESCOMMISSIONS_MARGIN_ENABLED'] = 0;
refusedWorkflow('LscWorkflowLocked', static function () use ($flow, $quote) { $flow->assertUnlocked($quote, true); }, 'Existing lock survives switching off margin rules');
checkWorkflow(strpos(implode("\n", $db->queries), 'propal WHERE entity = 1 AND rowid = 10 FOR UPDATE') !== false, 'Mutation and request lock the same native parent');
$settings['LMDBSALESCOMMISSIONS_MARGIN_ENABLED'] = 1;
refusedWorkflow('LscWorkflowLocked', static function () use ($db, $quote, $user) { (new LmdbSalesCommissionMarginService($db))->approve($quote, $user, 7, 1, 'sale', 'Bypass', workflowFingerprint($db, $quote, $user)); }, 'Old individual approval cannot bypass a pending dossier');
checkWorkflow(!(new LmdbSalesCommissionProposalDispatchService($db))->isProposalEditable($quote) && !(new LmdbSalesCommissionProposalTurnoverDispatchService($db))->isProposalEditable($quote), 'Both allocation editors locked');

// Execute the native hook manager and modal renderer, including their successive-call resets.
require_once $workflowNativeRoot.'/core/class/hookmanager.class.php';
require_once __DIR__.'/../class/actions_lmdbsalescommissions.class.php';
$conf->use_javascript_ajax = 1; $conf->browser = (object) array('layout' => 'classic');
$hookmanager = new HookManager($db); $hookmanager->initHooks(array('propalcard'));
$action = 'confirm_validate'; $massaction = ''; $toselect = array();
checkWorkflow($hookmanager->executeHooks('doActions', array(), $quote, $action) > 0 && $action === '', 'Native hook rejects direct validation while pending');
$flow->decide($quote, $user, $id, false, 'Correct costs', null);
$user = $requester; $action = 'validate';
checkWorkflow($hookmanager->executeHooks('formConfirm', array(), $quote, $action) > 0 && strpos($hookmanager->resPrint, 'submitrequest') !== false && strpos($hookmanager->resPrint, 'test-only-token') !== false, 'Native confirmation requests validation with CSRF even without validation right');
checkWorkflow(strpos($hookmanager->resPrint, 'confirm_validate') === false, 'Request confirmation does not perform native validation');
$db->saleThreshold = 20.0;
checkWorkflow($hookmanager->executeHooks('formConfirm', array(), $quote, $action) === 0 && $hookmanager->resPrint === '', 'Commission-only rule preserves native modal and clears prior hook output');
$db->saleThreshold = 30.0; $conf->use_javascript_ajax = 0;
checkWorkflow($hookmanager->executeHooks('formConfirm', array(), $quote, $action) > 0 && strpos($hookmanager->resPrint, 'submitrequest') !== false, 'Native non-JavaScript confirmation remains available');
$conf->use_javascript_ajax = 1;
$id = $flow->request($quote, $requester, 'Again', workflowFingerprint($db, $quote, $requester));
$hookmanager->initHooks(array('propallist'));
$objectstatic = $quote; $action = ''; $parameters = array('obj' => (object) array('lsc_pending_case' => $id));
$hookmanager->executeHooks('printFieldListValue', $parameters, $quote, $action);
checkWorkflow($hookmanager->executeHooks('LibStatut', array('status' => 0, 'mode' => 5), $quote, $action) > 0 && strpos($hookmanager->resPrint, 'LscWorkflowPending') !== false, 'Native list displays pending business status');
$parameters['obj']->lsc_pending_case = null;
$hookmanager->executeHooks('printFieldListValue', $parameters, $quote, $action);
// Use a manager with only the list context, as on the native list page.
$hookmanager = new HookManager($db); $hookmanager->initHooks(array('propallist'));
checkWorkflow($hookmanager->executeHooks('LibStatut', array('status' => 0, 'mode' => 5), $quote, $action) === 0 && $hookmanager->resPrint === '', 'Next list row retains native draft without stale pending flag');
$nativeProposal = file_get_contents($workflowNativeRoot.'/comm/propal/class/propal.class.php');
checkWorkflow(strpos($nativeProposal, "executeHooks('LibStatut'") !== false, 'Native quote status hook available');
checkWorkflow(strpos(file_get_contents($workflowNativeRoot.'/comm/propal/card.php'), "executeHooks('addMoreActionsButtons'") !== false, 'Native action hook available');
echo 'Margin workflow: '.$checks.' assertions passed on '.DOL_VERSION." (native validation/calculator; persistence, hierarchy and emails simulated).\n";
