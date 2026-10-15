<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
/** Native methods run against an in-memory SQL/HTTP double, never a real instance. */
$core = $argv[1] ?? (__DIR__.'/.cleanup-core/20.0.0/htdocs');
$dbSource = $argv[2] ?? ($core.'/core/db/DoliDB.class.php');
define('DOL_DOCUMENT_ROOT', realpath($core) ?: $core);
define('MAIN_DB_PREFIX', 'testprefix_');
define('DOL_VERSION', '20.0.0');
$conf = (object) array('entity' => 1);
$now = 1800000000;
$messages = array();
$request = array();
$_SESSION = array();
function dol_now() { global $now; return $now; }
function dol_syslog($message, $level = 0, $indent = 0) {}
function isModEnabled($module) { return $module === 'lmdbsalescommissions'; }
function dol_buildpath($path, $mode = 0) { return dirname(__DIR__).substr($path, strlen('/lmdbsalescommissions')); }
function setEventMessages($message, $errors = null, $type = 'errors') { global $messages; $messages[] = array($message, $errors, $type); }
function GETPOST($name, $type = '') { global $request; return $request[$name] ?? ''; }
function getDolGlobalString($key, $default = '') { return $default; }
function getDolGlobalInt($key, $default = 0) { return $default; }
function dol_escape_htmltag($value, ...$args) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function dol_nboflines_bis($text, ...$args) { return 1; }
function newToken() { return 'simulatedcsrf'; }
function img_picto(...$args) { return ''; }
function price2num($value, $mode = '') { return (float) $value; }
function dol_print_date($date, $format) { return strftime($format, $date); }
function dol_mktime($h, $m, $s, $mo, $d, $y) { return mktime($h, $m, $s, $mo, $d, $y); }
class User {
 public $id = 10;
 public $admin = 1;
 public $socid = 0;
 public $denied = array();
 public function hasRight(...$parts) { return !in_array(implode('.', $parts), $this->denied, true); }
}
class Translate {
 public function load($catalog) {}
 public function trans($key, ...$args) { return $key; }
}
class Conf {}
$langs = new Translate();
$user = new User();
/** Extract unchanged native method tokens; braces in strings/comments are ignored. */
function nativeMethod($file, $name) {
 $tokens = token_get_all(file_get_contents($file));
 for ($i = 0; $i < count($tokens); $i++) {
  if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
  $j = $i + 1;
  while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
  if (!is_array($tokens[$j]) || $tokens[$j][1] !== $name) { continue; }
  $body = ''; $depth = 0; $started = false;
  for ($k = $i; $k < count($tokens); $k++) {
   $token = $tokens[$k]; $body .= is_array($token) ? $token[1] : $token;
   if ($token === '{') { $depth++; $started = true; }
   if ($token === '}') { $depth--; if ($started && !$depth) { return $body; } }
  }
 }
 throw new RuntimeException('Missing native method '.$name);
}
eval('trait CleanupNativeTransactions { '.nativeMethod($dbSource, 'begin').nativeMethod($dbSource, 'commit').nativeMethod($dbSource, 'rollback').' }');
class CleanupResult {
 public $rows;
 public function __construct($rows) { $this->rows = $rows; }
}
class CleanupDB {
 use CleanupNativeTransactions;
 public $tierRuleAvailable = false;
 public $transaction_opened = 0;
 public $lines = array(); public $dues = array(); public $proposals = array();
 public $queries = array(); public $saved = null; public $fail = ''; public $failures = array();
 public function query($sql) {
  $this->queries[] = $sql;
  foreach ($this->failures as $failure) { if (strpos($sql, $failure) !== false) { return false; } }
  if ($this->fail !== '' && strpos($sql, $this->fail) !== false) { return false; }
  if ($sql === 'BEGIN') { $this->saved = serialize(array($this->lines, $this->dues, $this->proposals)); return true; }
  if ($sql === 'COMMIT') { $this->saved = null; return true; }
  if ($sql === 'ROLLBACK') { if ($this->saved !== null) { list($this->lines, $this->dues, $this->proposals) = unserialize($this->saved); } return true; }
  if (preg_match('/UPDATE testprefix_propal SET fk_statut = 0.*rowid = (\d+)/', $sql, $m)) { $this->proposals[(int) $m[1]]['fk_statut'] = 0; return true; }
  if (strpos($sql, 'SELECT rowid, entity, fk_statut FROM testprefix_propal') === 0) {
   preg_match('/rowid = (\d+) AND entity = (\d+)/', $sql, $m); $p = $this->proposals[(int) $m[1]] ?? null;
   if ($p && $p['entity'] !== (int) $m[2]) { $p = null; }
   return new CleanupResult($p ? array($p) : array());
  }
  if (strpos($sql, 'SELECT * FROM testprefix_lmdbsalescommissions_line') === 0) {
   preg_match('/fk_source = (\d+) AND entity = (\d+)/', $sql, $m);
   return new CleanupResult(array_values(array_filter($this->lines, function ($l) use ($m) { return $l['source_type'] === 'proposal' && $l['fk_source'] === (int) $m[1] && $l['entity'] === (int) $m[2]; })));
  }
  if (strpos($sql, 'SELECT * FROM testprefix_lmdbsalescommissions_due') === 0) {
   preg_match('/entity = (\d+).*IN \(([\d,]+)\)/', $sql, $m); $ids = array_map('intval', explode(',', $m[2]));
   return new CleanupResult(array_values(array_filter($this->dues, function ($d) use ($m, $ids) { return $d['entity'] === (int) $m[1] && in_array($d['fk_commission_line'], $ids, true); })));
  }
  if (strpos($sql, 'SELECT l.fk_source, COUNT(*)') === 0) {
   preg_match('/l.entity = (\d+).*l.fk_source > (\d+)/', $sql, $m); $groups = array();
   foreach ($this->lines as $l) {
    $p = $this->proposals[$l['fk_source']] ?? null;
    if ($l['source_type'] !== 'proposal' || $l['entity'] !== (int) $m[1] || $l['fk_source'] <= (int) $m[2] || ($p && ($p['entity'] !== $l['entity'] || $p['fk_statut'] !== 0))) { continue; }
    $id = $l['fk_source']; if (!isset($groups[$id])) { $groups[$id] = array('fk_source' => $id, 'source_ref' => $l['source_ref'], 'line_count' => 0, 'amount' => 0); }
    $groups[$id]['line_count']++; $groups[$id]['amount'] += $l['commission_total'];
   }
   ksort($groups); return new CleanupResult(array_slice(array_values($groups), 0, 50));
  }
  if (preg_match('/DELETE FROM testprefix_lmdbsalescommissions_(due|line) .*rowid = (\d+)$/', $sql, $m)) {
   $property = $m[1] === 'due' ? 'dues' : 'lines'; unset($this->{$property}[(int) $m[2]]); return true;
  }
  if (strpos($sql, 'UPDATE testprefix_lmdbsalescommissions_line SET status = 6') === 0) {
   preg_match('/entity = (\d+).*fk_source = (\d+)/', $sql, $m);
   foreach ($this->lines as &$l) { if ($l['entity'] === (int) $m[1] && $l['fk_source'] === (int) $m[2] && $l['source_type'] === 'proposal') { $l['status'] = 6; } } unset($l); return true;
  }
  if ($this->tierRuleAvailable && strpos($sql, 'SELECT a.rowid AS assignment_id') === 0) {
   return new CleanupResult(array(array('assignment_id' => 1, 'assignment_type' => 'user', 'assignment_priority' => 1, 'assignment_payment_term' => null, 'rule_id' => 5, 'rule_ref' => 'R5', 'rule_label' => 'Tier rule', 'rule_type' => 'tier', 'source_type' => 'proposal', 'period_type' => 'yearly', 'rate' => null, 'fk_tier_grid' => 1, 'rule_payment_term' => null, 'rule_priority' => 1)));
  }
  if ($this->tierRuleAvailable && strpos($sql, 'SELECT SUM(src.amount_base)') === 0) {
   preg_match('/l.entity = (\d+).*l.fk_user = (\d+).*l.fk_rule = (\d+)/', $sql, $m);
   $total = 0;
   foreach ($this->lines as $line) {
    if ($line['entity'] === (int) $m[1] && $line['fk_user'] === (int) $m[2] && ($line['fk_rule'] ?? 0) === (int) $m[3] && $line['source_type'] === 'proposal' && in_array($line['mode'], array('turnover', 'tier')) && $line['status'] === 1) { $total += $line['amount_base']; }
   }
   return new CleanupResult(array(array('total' => $total)));
  }
  if ($this->tierRuleAvailable && strpos($sql, 'SELECT calculation_mode') === 0) { return new CleanupResult(array(array('calculation_mode' => 'fixed_bonus'))); }
  if ($this->tierRuleAvailable && strpos($sql, 'SELECT rowid, threshold_amount') === 0) {
   return new CleanupResult(array(array('rowid' => 1, 'threshold_amount' => 100000, 'bonus_amount' => 1000, 'commission_rate' => null), array('rowid' => 2, 'threshold_amount' => 200000, 'bonus_amount' => 2500, 'commission_rate' => null)));
  }
  if (strpos($sql, 'SELECT ') === 0) { return new CleanupResult(array()); }
  throw new RuntimeException('Unimplemented simulated SQL: '.$sql);
 }
 public function fetch_object($result) { $row = array_shift($result->rows); return $row === null ? false : (object) $row; }
 public function free($result) {}
 public function lasterror() { return 'injected SQL failure'; }
 public function error() { return $this->lasterror(); }
 public function jdate($date) { return strtotime($date); }
 public function idate($date) { return date('Y-m-d H:i:s', $date); }
 public function escape($value) { return addslashes($value); }
}
require_once __DIR__.'/../class/lmdbsalescommissionproposalcleanup.class.php';
require_once __DIR__.'/../class/lmdbsalescommissionlineservice.class.php';
require_once __DIR__.'/../class/actions_lmdbsalescommissions.class.php';
require_once __DIR__.'/../core/triggers/interface_99_modLmdbSalesCommissions_LmdbSalesCommissionsTriggers.class.php';
eval('trait CleanupNativeForm { '.nativeMethod(DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php', 'formconfirm').' }');
class Form { use CleanupNativeForm; public function selectyesno($name, $value, ...$args) { return '<select name="confirm"><option value="no" selected>No</option><option value="yes">Yes</option></select>'; } }
require_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
eval('trait CleanupNativeDraft { '.nativeMethod(DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php', 'setDraft').' }');
class CleanupProposal {
 use CleanupNativeDraft;
 const STATUS_DRAFT = 0;
 public $table_element = 'propal';
 public $id = 100; public $entity = 1; public $statut = 2; public $status = 2;
 public $date_signature = 1700000000; public $db; public $oldcopy; public $context = array();
 public $error = ''; public $errors = array();
 public function __construct($db) { $this->db = $db; }
 public function call_trigger($action, $user) {
  global $langs;
  $trigger = new InterfaceLmdbSalesCommissionsTriggers($this->db);
  $result = $trigger->runTrigger($action, $this, $user, $langs, new Conf());
  $this->error = $trigger->error; $this->errors = $trigger->errors; return $result;
 }
}
$checks = 0;
function check($condition, $label) { global $checks; $checks++; if (!$condition) { throw new RuntimeException('FAILED: '.$label); } }
function seeded($paid = false) {
 $db = new CleanupDB();
 $db->proposals[100] = array('rowid' => 100, 'entity' => 1, 'fk_statut' => 2);
 foreach (array(1 => 1, 2 => 2, 3 => 1) as $id => $entity) {
  $db->lines[$id] = array('rowid' => $id, 'entity' => $entity, 'source_type' => $id === 3 ? 'tier_period' : 'proposal', 'fk_source' => 100, 'source_ref' => 'P100', 'paid_total' => $id === 1 && $paid ? 20 : 0, 'commission_total' => 40, 'mode' => 'margin', 'status' => 1, 'date_acquired' => null, 'fk_user' => 10);
  $db->dues[$id] = array('rowid' => $id, 'entity' => $entity, 'fk_commission_line' => $id, 'status' => $id === 1 && $paid ? 2 : 1, 'amount' => 20, 'date_paid' => $id === 1 && $paid ? '2026-07-01 12:00:00' : null);
 }
 return $db;
}
$proposalId = (object) array('id' => 100, 'entity' => 1);
// Before-change reproduction: native draft emitted MODIFY while the old status was signed.
$db = seeded(); $proposal = new CleanupProposal($db);
$old = shell_exec('git show main:core/triggers/interface_99_modLmdbSalesCommissions_LmdbSalesCommissionsTriggers.class.php');
if (is_string($old) && strpos($old, 'class InterfaceLmdbSalesCommissionsTriggers') !== false) {
 $old = preg_replace('/^<\?php/', '', $old);
 eval(str_replace('class InterfaceLmdbSalesCommissionsTriggers', 'class CleanupBeforeTrigger', $old));
 $before = new CleanupBeforeTrigger($db);
 $db->proposals[100]['fk_statut'] = 0;
 check($before->runTrigger('PROPAL_MODIFY', $proposal, $user, $langs, new Conf()) === 0 && isset($db->lines[1]), 'before: draft leaves acquired commission');
 $db->proposals[100]['fk_statut'] = 2;
 check($before->runTrigger('PROPAL_DELETE', $proposal, $user, $langs, new Conf()) === 0 && isset($db->lines[1], $db->dues[1]) && $db->lines[1]['status'] === 6, 'before: delete cancels but preserves commission and due');
}
$db = seeded(); $proposal = new CleanupProposal($db);
check($proposal->setDraft($user) > 0, 'native setDraft succeeds');
check(!isset($db->lines[1], $db->dues[1]) && $db->proposals[100]['fk_statut'] === 0, 'draft removes lines and dues');
check(isset($db->lines[2], $db->lines[3], $db->dues[2], $db->dues[3]), 'other entity and periodic bonus preserved');
check($proposal->date_signature > 0, 'native historical signature retained');
check((new LmdbSalesCommissionProposalDispatchService($db))->isProposalEditable($proposal), 'commission dispatch unlocked in draft');
check((new LmdbSalesCommissionProposalTurnoverDispatchService($db))->isProposalEditable($proposal), 'turnover dispatch unlocked in draft');
$proposal->statut = 1; $proposal->status = 1;
check((new LmdbSalesCommissionProposalDispatchService($db))->isProposalEditable($proposal), 'revalidated historical signature does not lock dispatch');
$db = seeded(); $db->begin(); $trigger = new InterfaceLmdbSalesCommissionsTriggers($db);
check($trigger->runTrigger('PROPAL_DELETE', new CleanupProposal($db), $user, $langs, new Conf()) === 0, 'delete trigger succeeds');
check(!isset($db->lines[1], $db->dues[1]) && $db->transaction_opened === 1, 'inner success retains native outer transaction');
$db->rollback(); check(isset($db->lines[1], $db->dues[1]), 'later native failure rolls cleanup back');
$db = seeded(); $service = new LmdbSalesCommissionProposalCleanup($db);
check($service->deleteForProposal($proposalId, $user, 'delete') === 1, 'unpaid delete');
check($service->deleteForProposal($proposalId, $user, 'delete') === 0, 'repeated cleanup is idempotent');
foreach (array('DELETE FROM testprefix_lmdbsalescommissions_due', 'DELETE FROM testprefix_lmdbsalescommissions_line', 'COMMIT', 'BEGIN') as $failure) {
 $db = seeded(); $db->fail = $failure; $service = new LmdbSalesCommissionProposalCleanup($db);
 check($service->deleteForProposal($proposalId, $user, 'delete') < 0 && isset($db->lines[1], $db->dues[1]), 'SQL rollback: '.$failure);
}
$db = seeded(); $db->failures = array('DELETE FROM testprefix_lmdbsalescommissions_line', 'ROLLBACK');
$service = new LmdbSalesCommissionProposalCleanup($db);
check($service->deleteForProposal($proposalId, $user, 'delete') < 0 && in_array('LscCleanupTransactionFailed', $service->errors), 'rollback failure is reported explicitly');
$inner = seeded();
$decorator = new class($inner) {
 private $inner;
 public function __construct($inner) { $this->inner = $inner; }
 public function __call($method, $args) { return $this->inner->$method(...$args); }
};
$service = new LmdbSalesCommissionProposalCleanup($decorator);
check($service->deleteForProposal($proposalId, $user, 'delete') === 1 && !isset($inner->lines[1]), 'database decorator works without reading a transaction counter');
$db = seeded(true); $proposal = new CleanupProposal($db);
check($proposal->setDraft($user) < 0 && $db->proposals[100]['fk_statut'] === 2 && isset($db->lines[1], $db->dues[1]), 'API/script draft without paid confirmation rejected and rolled back');
$service = new LmdbSalesCommissionProposalCleanup($db);
check($service->deleteForProposal($proposalId, $user, 'delete') < 0, 'mass/API delete without paid confirmation refused');
$nonce = $service->prepareConfirmation(100, 1, 'draft', $user, $service->inspect(100, 1));
$service->approveConfirmation(100, 1, 'draft', $user, $service->inspect(100, 1), $nonce);
$proposal->context['lmdb_cleanup_confirmation'] = $nonce;
check($proposal->setDraft($user) > 0 && !isset($db->lines[1], $db->dues[1]), 'confirmed paid native draft succeeds');
foreach (array('expired', 'changed', 'user', 'entity', 'operation', 'nonce', 'permission', 'contextentity') as $case) {
 $db = seeded(true); $service = new LmdbSalesCommissionProposalCleanup($db); $actor = clone $user;
 $nonce = $service->prepareConfirmation(100, 1, 'delete', $actor, $service->inspect(100, 1));
 $service->approveConfirmation(100, 1, 'delete', $actor, $service->inspect(100, 1), $nonce);
 if ($case === 'expired') { $_SESSION['lmdbsalescommissions_cleanup']['expires'] = dol_now(); }
 if ($case === 'changed') { $db->dues[1]['amount'] = 21; }
 if ($case === 'user') { $actor->id++; }
 if ($case === 'entity') { $_SESSION['lmdbsalescommissions_cleanup']['entity'] = 2; }
 if ($case === 'operation') { $_SESSION['lmdbsalescommissions_cleanup']['operation'] = 'draft'; }
 if ($case === 'nonce') { $nonce = 'forged'; }
 if ($case === 'permission') { $actor->denied[] = 'lmdbsalescommissions.due.pay'; }
 if ($case === 'contextentity') { $conf->entity = 2; }
 check($service->deleteForProposal($proposalId, $actor, 'delete', $nonce) < 0 && isset($db->lines[1], $db->dues[1]), 'confirmation refuses '.$case);
 $conf->entity = 1;
}
$db = seeded(true); $service = new LmdbSalesCommissionProposalCleanup($db);
$nonce = $service->prepareConfirmation(100, 1, 'delete', $user, $service->inspect(100, 1));
$service->approveConfirmation(100, 1, 'delete', $user, $service->inspect(100, 1), $nonce);
check($service->deleteForProposal($proposalId, $user, 'delete', $nonce) === 1 && !isset($_SESSION['lmdbsalescommissions_cleanup']), 'paid delete consumes grant');
$db = seeded(true); check($service = new LmdbSalesCommissionProposalCleanup($db), 'new service');
check($service->deleteForProposal($proposalId, $user, 'delete', $nonce) < 0, 'consumed grant cannot replay');
$db = seeded(); $service = new LmdbSalesCommissionProposalCleanup($db);
check($service->candidates(1) === array(), 'signed proposals excluded from historical candidates');
$db->proposals[100]['fk_statut'] = 0;
check(count($service->candidates(1)) === 1, 'draft candidate excludes other entity and period sources');
$nonce = $service->prepareConfirmation(100, 1, 'cleanup', $user, $service->inspect(100, 1));
$service->approveConfirmation(100, 1, 'cleanup', $user, $service->inspect(100, 1), $nonce);
check($service->deleteForProposal($proposalId, $user, 'cleanup', $nonce) === 1 && !$service->candidates(1), 'historical draft cleanup and rerun');
$db = seeded(); unset($db->proposals[100]); $service = new LmdbSalesCommissionProposalCleanup($db);
check(count($service->candidates(1)) === 1, 'missing proposal candidate');
$nonce = $service->prepareConfirmation(100, 1, 'cleanup', $user, $service->inspect(100, 1));
$service->approveConfirmation(100, 1, 'cleanup', $user, $service->inspect(100, 1), $nonce);
check($service->deleteForProposal($proposalId, $user, 'cleanup', $nonce) === 1, 'orphan cleanup');
$db = seeded(); $db->proposals[100]['entity'] = 2; $service = new LmdbSalesCommissionProposalCleanup($db);
check(!$service->candidates(1) && $service->deleteForProposal($proposalId, $user, 'cleanup') < 0, 'mismatched entity is not an orphan');
$db = seeded(); $db->proposals[100]['fk_statut'] = 0; $service = new LmdbSalesCommissionProposalCleanup($db);
$nonce = $service->prepareConfirmation(100, 1, 'cleanup', $user, $service->inspect(100, 1));
$service->approveConfirmation(100, 1, 'cleanup', $user, $service->inspect(100, 1), $nonce); $db->proposals[100]['fk_statut'] = 2;
check($service->deleteForProposal($proposalId, $user, 'cleanup', $nonce) < 0, 're-signed candidate cannot be cleaned');
$actor = clone $user; $actor->denied[] = 'lmdbsalescommissions.maintenance.recalculate'; $actor->admin = 1;
check($service->deleteForProposal($proposalId, $actor, 'cleanup', $nonce) < 0, 'admin without explicit maintenance right refused');
$db = seeded(); $db->lines[1]['mode'] = 'turnover'; $service = new LmdbSalesCommissionProposalCleanup($db);
check($service->deleteForProposal($proposalId, $user, 'delete') < 0, 'missing historical acquisition date fails conservatively');
$db->lines[1]['date_acquired'] = '2026-01-01 00:00:00';
check($service->deleteForProposal($proposalId, $user, 'delete') === 1 && in_array('LscCleanupTierRuleMissing', $service->errors), 'unavailable historic tier rule signalled');
$db = seeded(true); $hook = new ActionsLmdbSalesCommissions($db); $proposal = new CleanupProposal($db); $manager = new stdClass();
$request = array('confirm' => 'yes'); $action = 'confirm_delete';
check($hook->doActions(array('context' => 'propalcard'), $proposal, $action, $manager) === 1 && $action === 'lscaskdelete', 'card deletion requests second approval');
$request = array('confirm' => 'no'); $action = 'lscconfirmdelete';
check($hook->doActions(array('context' => 'propalcard'), $proposal, $action, $manager) === 1 && $action === '' && !isset($_SESSION['lmdbsalescommissions_cleanup']) && isset($db->lines[1]), 'cancel leaves data intact and removes grant');
$request = array(); $action = 'modif';
check($hook->doActions(array('context' => 'propalcard'), $proposal, $action, $manager) === 1 && $action === 'lscaskdraft', 'draft requests additional confirmation');
$request = array('confirm' => 'yes', 'lsc_confirmation' => $_SESSION['lmdbsalescommissions_cleanup']['nonce']); $action = 'lscconfirmdraft';
check($hook->doActions(array('context' => 'propalcard'), $proposal, $action, $manager) === 0 && $action === 'modif' && $proposal->setDraft($user) > 0, 'card second approval resumes native draft');
$action = 'modif'; check($hook->doActions(array('context' => 'other'), $proposal, $action, $manager) === 0, 'unrelated context neutral');
foreach (array('usergroup_user', 'lmdbsalescommissions_rule_assignment') as $failure) {
 $db = seeded(); $db->lines[1]['mode'] = 'turnover'; $db->lines[1]['date_acquired'] = '2026-01-01 00:00:00'; $db->fail = $failure;
 $service = new LmdbSalesCommissionProposalCleanup($db);
 check($service->deleteForProposal($proposalId, $user, 'delete') < 0 && isset($db->lines[1], $db->dues[1]), 'tier resolver read error rolls deletion back: '.$failure);
}
// The actual tier resolver/engine observes reduced turnover after source removal.
$db = seeded(); $db->lines[1]['mode'] = 'turnover'; $db->lines[1]['date_acquired'] = '2026-01-01 00:00:00';
$db->lines[1]['amount_base'] = 200000; $db->lines[1]['fk_rule'] = 5;
$db->lines[4] = $db->lines[1]; $db->lines[4]['rowid'] = 4; $db->lines[4]['fk_source'] = 200; $db->lines[4]['amount_base'] = 50000;
$db->tierRuleAvailable = true;
$tier = new LmdbSalesCommissionTierService($db);
$progress = $tier->getProgressForUser(10, strtotime('2026-01-01'), 1);
check($progress['status'] === 'ok' && $progress['turnover'] === 250000.0 && $progress['current_commission'] === 2500.0, 'tier engine sees acquired turnover before deletion');
// Persistence of the periodic bonus is covered by real-instance acceptance, not this SQL double.
$db->tierRuleAvailable = false; $service = new LmdbSalesCommissionProposalCleanup($db);
check($service->deleteForProposal($proposalId, $user, 'delete') === 1, 'turnover source deleted');
$db->tierRuleAvailable = true; $progress = $tier->getProgressForUser(10, strtotime('2026-01-01'), 1);
check($progress['status'] === 'ok' && $progress['turnover'] === 50000.0 && $progress['current_commission'] === 0.0, 'tier engine removes deleted proposal from bonus calculation');
// Native renderer and manager, including consecutive calls and native reset to null.
$conf->browser = (object) array('layout' => 'classic'); $conf->use_javascript_ajax = 0;
$_SERVER['PHP_SELF'] = '/comm/propal/card.php';
$db = seeded(true); $proposal = new CleanupProposal($db); $hook = new ActionsLmdbSalesCommissions($db);
$form = new Form(); $manager = new HookManager($db);
$manager->contextarray = array('propalcard'); $manager->hooksSorted = array('propalcard' => array('50:lmdbsalescommissions' => $hook));
$manager->hooks = $manager->hooksSorted;
$request = array('confirm' => 'yes'); $action = 'confirm_delete';
check($manager->executeHooks('doActions', array(), $proposal, $action) === 1 && $action === 'lscaskdelete', 'native manager intercepts paid delete');
check($manager->executeHooks('formConfirm', array(), $proposal, $action) === 1, 'native manager renders additional confirmation');
$html = $manager->resPrint;
check(strpos($html, 'method="POST"') !== false && strpos($html, 'name="token"') !== false && strpos($html, 'name="lsc_confirmation"') !== false, 'native confirmation submits POST with CSRF and challenge');
check($hook->resprints === null, 'native manager resets resprints to null');
$action = '';
check($manager->executeHooks('formConfirm', array(), $proposal, $action) === 0 && $manager->resPrint === '', 'no stale confirmation after successive call');
// A preview is not itself approval.
$db = seeded(true); $service = new LmdbSalesCommissionProposalCleanup($db);
$nonce = $service->prepareConfirmation(100, 1, 'delete', $user, $service->inspect(100, 1));
check($service->deleteForProposal($proposalId, $user, 'delete', $nonce) < 0, 'unapproved preview cannot authorize deletion');
// Recalculation errors must not be converted into an empty schedule or success.
// Invoice dependencies are outside this test: run these unchanged module methods in isolation.
$dueSource = dirname(__DIR__).'/class/lmdbsalescommissiondueservice.class.php';
eval('class CleanupDueErrors { public $db; public $error = ""; public $errors = array(); const STATUS_DUE = 1; const STATUS_PAID = 2; '.nativeMethod($dueSource, '__construct').nativeMethod($dueSource, 'generateForLine').nativeMethod($dueSource, 'fetchDistribution').nativeMethod($dueSource, 'refreshLineTotals').' }');
$db = seeded(); $db->fail = 'lmdbsalescommissions_payment_term_line';
$dues = new CleanupDueErrors($db);
$line = (object) array('id' => 1, 'entity' => 1, 'commission_total' => 100, 'fk_payment_term' => 4);
check($dues->generateForLine($line, $user) < 0 && $dues->error !== '', 'payment distribution read error propagated');
$db->fail = 'SUM(CASE';
$refresh = new ReflectionMethod($dues, 'refreshLineTotals');
check($refresh->invoke($dues, 1, 1, $user) < 0 && $dues->error !== '', 'due totals read error propagated');
$db = seeded(); $db->lines[1]['mode'] = 'turnover'; $db->lines[1]['date_acquired'] = 'invalid-date';
$service = new LmdbSalesCommissionProposalCleanup($db);
check($service->deleteForProposal($proposalId, $user, 'delete') < 0 && isset($db->lines[1], $db->dues[1]), 'invalid acquisition date refuses cleanup');
fwrite(STDOUT, 'OK '.$checks." assertions (native setDraft/transactions, simulated SQL/HTTP)\n");
