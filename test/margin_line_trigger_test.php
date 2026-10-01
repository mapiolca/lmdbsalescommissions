<?php
/** Proposal line trigger regression with native event names; SQL and actor are simulated. */
define('DOL_DOCUMENT_ROOT', $argv[1] ?? __DIR__.'/.core-cache/20.0.0/htdocs');
define('MAIN_DB_PREFIX', 'line_test_');

function isModEnabled($key) { return $key === 'lmdbsalescommissions'; }
class User {}
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
		if (!preg_match('/^INSERT INTO line_test_lmdbsalescommissions_margin_revision \(entity,object_id,revision\) VALUES \((\d+),(\d+),1\)/', $sql, $matches)) {
			throw new RuntimeException('Unexpected SQL: '.$sql);
		}
		$this->invalidations[] = array((int) $matches[1], (int) $matches[2]);
		return true;
	}
}
class Propal {
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
$trigger->runTrigger('LINEPROPAL_UPDATE', $line, $user, $langs, $conf);
if (count($db->invalidations) !== 3) { throw new RuntimeException('Non-native alias must not trigger recalculation'); }
$db->fail = true;
if ($trigger->runTrigger('LINEPROPAL_MODIFY', $line, $user, $langs, $conf) !== -1 || $trigger->error !== 'LscPolicyUnavailable') {
	throw new RuntimeException('Revision failure must fail the native edit transaction');
}
print "Native proposal line events: 6 assertions passed.\n";
