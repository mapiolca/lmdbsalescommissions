<?php
/** Proposal line trigger regression with native event names; SQL and actor are simulated. */
define('DOL_DOCUMENT_ROOT', $argv[1] ?? __DIR__.'/.core-cache/20.0.0/htdocs');
define('MAIN_DB_PREFIX', 'line_test_');

function isModEnabled($key) { return $key === 'lmdbsalescommissions'; }
class User { public $id = 1; public function hasRight(...$args) { return true; } }
function dol_syslog($message, $level = 0) {}
function getDolGlobalInt($name, $default = 0) { return $default; }
class Translate {
	public function load($key) {}
	public function trans($key) { return $key; }
}
class Conf {}
class TriggerDb {
	public $invalidations = array();
	public $fail = false;
	public function query($sql) {
		if ($this->fail) { return false; }
		if (strpos($sql, 'SELECT rowid, entity, fk_statut FROM line_test_propal ') === 0) { return (object) array('rows' => array((object) array('rowid' => 42, 'entity' => 2, 'fk_statut' => 0))); }
		if (strpos($sql, 'SELECT * FROM line_test_lmdbsalescommissions_line ') === 0) { return (object) array('rows' => array()); }
		if (strpos($sql, 'DELETE FROM line_test_lmdbsalescommissions_margin_snapshot ') === 0) { return true; }
		if (!preg_match('/^INSERT INTO line_test_lmdbsalescommissions_margin_revision \(entity,object_id,revision\) VALUES \((\d+),(\d+),1\)/', $sql, $matches)) {
			throw new RuntimeException('Unexpected SQL: '.$sql);
		}
		$this->invalidations[] = array((int) $matches[1], (int) $matches[2]);
		return true;
	}
	public function fetch_object($result) { return array_shift($result->rows); }
	public function free($result) {}
	public function begin() { return true; }
	public function commit() { return true; }
	public function rollback() { return true; }
	public function lasterror() { return 'Injected failure'; }
}
class Propal {
	public $array_options = array();
	public $id = 0;
	public $entity = 2;
	public $status = 0;
	public $statut = 0;
	public function __construct($db) {}
	public function fetch($id) { $this->id = $id; return $id > 0 ? 1 : -1; }
}
require __DIR__.'/../core/triggers/interface_99_modLmdbSalesCommissions_LmdbSalesCommissionsTriggers.class.php';
$db = new TriggerDb(); $trigger = new InterfaceLmdbSalesCommissionsTriggers($db);
$user = new User(); $langs = new Translate(); $conf = new Conf();
$line = (object) array('fk_propal' => 42);
foreach (array('LINEPROPAL_INSERT', 'LINEPROPAL_MODIFY', 'LINEPROPAL_DELETE') as $action) {
	if ($trigger->runTrigger($action, $line, $user, $langs, $conf) !== 0) { throw new RuntimeException($action.' failed'); }
}
if ($db->invalidations !== array(array(2, 42), array(2, 42), array(2, 42))) {
	throw new RuntimeException('Every native line mutation must invalidate the proposal decision');
}
$proposal = new Propal($db); $proposal->id = 42;
$proposal->array_options = array('options_lmdbpropalpv_complex_site' => 1);
if ($trigger->runTrigger('PROPAL_MODIFY', $proposal, $user, $langs, $conf) !== 0 || $db->invalidations[3] !== array(2, 42)) {
	throw new RuntimeException('The lmdbpropalpv switch mutation must invalidate the proposal decision');
}
$trigger->runTrigger('LINEPROPAL_UPDATE', $line, $user, $langs, $conf);
if (count($db->invalidations) !== 4) { throw new RuntimeException('Non-native alias must not trigger recalculation'); }
$db->fail = true;
if ($trigger->runTrigger('LINEPROPAL_MODIFY', $line, $user, $langs, $conf) !== -1 || $trigger->error !== 'LscPolicyUnavailable') {
	throw new RuntimeException('Revision failure must fail the native edit transaction');
}
print "Native proposal and line events: 7 assertions passed.\n";
