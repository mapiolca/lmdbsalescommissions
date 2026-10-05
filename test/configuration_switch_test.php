<?php
/** Switch persistence and exclusive defaults; database and CommonObject persistence simulated. */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('DOL_DOCUMENT_ROOT', __DIR__.'/fixtures/reward');
define('MAIN_DB_PREFIX', 'switch_test_');
function getEntity($element) { return '1,2'; }
class SwitchUser {
	public $id = 5;
	public $permitted = true;
	public function hasRight($module, $object, $action) { return $this->permitted; }
}
class SwitchDb {
	public $objects = array();
	public $snapshots = array();
	public $failSave = false;
	public $failLock = false;
	public $failCommit = false;
	public $writes = 0;
	public function begin() { $this->snapshots[] = $this->objects; return 1; }
	public function commit() { if ($this->failCommit) { return 0; } array_pop($this->snapshots); return 1; }
	public function rollback() { $this->objects = array_pop($this->snapshots); return 1; }
	public function lasterror() { return 'Simulated failure'; }
	public function free($result) {}
	public function fetch_object($result) { return array_shift($result->rows); }
	public function query($sql) {
		if (!preg_match('/FROM switch_test_(\w+) WHERE entity = (\d+)/', $sql, $matches)) { throw new RuntimeException('Unscoped query: '.$sql); }
		if ($this->failLock && strpos($sql, 'FOR UPDATE') !== false) { return false; }
		$excluded = preg_match('/rowid <> (\d+)/', $sql, $matchId) ? (int) $matchId[1] : 0;
		$rows = array();
		foreach ($this->objects[$matches[1]] ?? array() as $id => $row) {
			if ($row['entity'] === (int) $matches[2] && $id !== $excluded
				&& (strpos($sql, 'is_default = 1') === false || $row['is_default'] === 1)) { $rows[] = (object) array('rowid' => $id); }
		}
		return (object) array('rows' => $rows);
	}
	public function saveObject($object) {
		if ($this->failSave) { $object->error = 'Simulated failure'; return -1; }
		if (!$object->id) { $object->id = count($this->objects[$object->table_element] ?? array()) + 1; }
		$row = array('id' => $object->id);
		foreach ($object->fields as $field => $definition) { if (property_exists($object, $field)) { $row[$field] = $object->{$field}; } }
		$this->objects[$object->table_element][$object->id] = $row;
		$this->writes++;
		return $object->id;
	}
}
require __DIR__.'/../class/lmdbsalescommissionpaymentterm.class.php';
require __DIR__.'/../class/lmdbsalescommissiontiergrid.class.php';
require __DIR__.'/../class/lmdbsalescommissionruleassignment.class.php';
$db = new SwitchDb(); $user = new SwitchUser(); $conf = (object) array('entity' => 1);
$tests = 0;
function checkSwitch($condition, $message) { global $tests; $tests++; if (!$condition) { throw new RuntimeException($message); } }
$first = new LmdbSalesCommissionPaymentTerm($db); $first->active = 1; $first->is_default = 1;
checkSwitch($first->create($user) > 0, 'Create initial default');
$second = new LmdbSalesCommissionPaymentTerm($db); $second->active = 1; $second->is_default = 0;
checkSwitch($second->create($user) > 0, 'Create alternate term');
$conf->entity = 2; $other = new LmdbSalesCommissionPaymentTerm($db); $other->active = 1; $other->is_default = 1;
checkSwitch($other->create($user) > 0, 'Create separate entity default');
$conf->entity = 1;
checkSwitch($second->setConfigurationFlag('is_default', 1, $user) > 0, 'Select alternate default');
$table = $first->table_element;
checkSwitch($db->objects[$table][1]['is_default'] === 0 && $db->objects[$table][2]['is_default'] === 1, 'Only the chosen term is default');
checkSwitch($db->objects[$table][3]['is_default'] === 1, 'Other entity default preserved');
checkSwitch($second->setConfigurationFlag('active', 0, $user) > 0 && $db->objects[$table][2]['is_default'] === 0, 'Disabling a default clears its default flag');
checkSwitch($second->setConfigurationFlag('is_default', 1, $user) > 0 && $db->objects[$table][2]['active'] === 1, 'Selecting default enables the term');
checkSwitch($second->setConfigurationFlag('is_default', 0, $user) > 0 && $db->objects[$table][2]['active'] === 1, 'Removing default preserves active state');
checkSwitch($first->setConfigurationFlag('is_default', 1, $user) > 0, 'Restore first default');
$before = $db->objects; $db->failSave = true;
checkSwitch($second->setConfigurationFlag('is_default', 1, $user) < 0 && $db->objects === $before, 'Write failure preserves former default');
$db->failSave = false; $db->failCommit = true;
checkSwitch($second->setConfigurationFlag('is_default', 1, $user) < 0 && $db->objects === $before, 'Commit failure rolls back flags');
$db->failCommit = false; $db->failLock = true;
checkSwitch($first->setConfigurationFlag('active', 0, $user) < 0 && $db->objects === $before, 'Lock failure has no writes');
$db->failLock = false; $user->permitted = false;
checkSwitch($first->setConfigurationFlag('active', 0, $user) < 0 && $db->objects === $before, 'Missing permission refuses mutation');
$user->permitted = true; $first->entity = 9;
checkSwitch($first->setConfigurationFlag('active', 0, $user) < 0 && $db->objects === $before, 'Inaccessible entity refuses mutation');
$first->entity = 1;
checkSwitch($first->setConfigurationFlag('active', 2, $user) < 0, 'Invalid state refused');
checkSwitch($first->setConfigurationFlag('label', 1, $user) < 0, 'Non-boolean field refused');
// A form save obeys the same exclusivity as switches.
$second->is_default = 1; $second->active = 1;
checkSwitch($second->update($user) > 0 && $db->objects[$table][1]['is_default'] === 0, 'Form save replaces the previous default');
require_once __DIR__.'/../class/lmdbsalescommissionobjective.class.php';
foreach (array(LmdbSalesCommissionTierGrid::class, LmdbSalesCommissionRuleAssignment::class, LmdbSalesCommissionObjective::class) as $class) {
	$object = new $class($db); $object->active = 1;
	checkSwitch($object->create($user) > 0, 'Create configuration object');
	checkSwitch($object->setConfigurationFlag('active', 0, $user) > 0 && $db->objects[$object->table_element][$object->id]['active'] === 0, 'Deactivate configuration object');
	checkSwitch($object->setConfigurationFlag('active', 1, $user) > 0 && $db->objects[$object->table_element][$object->id]['active'] === 1, 'Reactivate configuration object');
}
checkSwitch(count($db->snapshots) === 0, 'All nested transactions completed');
print 'Configuration switches: '.$tests." assertions passed (persistence simulated).\n";
