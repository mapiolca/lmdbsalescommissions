<?php
/** Actual module acquisition/due services, simulated persistence and transaction nesting.
	* Complements MariaDB DDL/rollback checks; not a deployed Dolibarr signing test.
	*/
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('DOL_DOCUMENT_ROOT', __DIR__.'/fixtures/reward');
define('MAIN_DB_PREFIX', 'life_');
foreach (array('LOG_ERR'=>3,'LOG_INFO'=>6) as $name=>$value) { if (!defined($name)) { define($name,$value); } }
$conf = (object) array('entity'=>1);
function isModEnabled($key) { return true; }
function getDolGlobalInt($key, $default = 0) { return $default; }
function dol_now() { return 2000; }
function dol_syslog($message, $level = 0) {}
function price2num($value, $mode = '') { return round((float) $value, 2); } // Test precision only; native precision tested separately.
require_once __DIR__.'/../class/lmdbsalescommissionlineservice.class.php';
class LifecycleDb
{
	public $objects = array(); public $snapshot; public $failure = ''; private $backup = array(); private $depth = 0;
	public function begin() { if ($this->failure === 'begin') { return 0; } if (!$this->depth++) { $this->backup = $this->objects; } return 1; }
	public function commit() { if ($this->failure === 'commit') { return 0; } $this->depth--; return 1; }
	public function rollback() { if (--$this->depth === 0) { $this->objects = $this->backup; } return 1; }
	public function saveObject($object) {
		if (($this->failure === 'due' && $object->table_element === 'lmdbsalescommissions_due') || ($this->failure === 'line' && $object->table_element === 'lmdbsalescommissions_line')) { $object->error = 'Injected write failure'; return -1; }
		if (!$object->id) { $object->id = count($this->objects[$object->table_element] ?? array()) + 1; }
		$row = array('id'=>$object->id);
		foreach ($object->fields as $field=>$metadata) { $row[$field] = $object->$field; }
		$row['rowid'] = $object->id; $this->objects[$object->table_element][$object->id] = $row;
		return $object->id;
	}
	public function query($sql) {
		if ($this->failure === 'distribution' && strpos($sql, '_payment_term_line') !== false) { return false; }
		if ($this->failure === 'totals' && strpos($sql, 'SELECT SUM') === 0) { return false; }
		if (strpos($sql, 'WHERE entity = 1') === false && strpos($sql, 'AND entity = 1') === false) { throw new LogicException('Unscoped query: '.$sql); }
		$rows = array();
		if (strpos($sql, '_margin_snapshot') !== false) { $rows[] = array('fk_user'=>7,'snapshot_payload'=>json_encode($this->snapshot)); }
		elseif (strpos($sql, '_payment_term_line') !== false) { $rows = array(array('event_type'=>'proposal_signed','percentage'=>40),array('event_type'=>'final_invoice_paid','percentage'=>60)); }
		elseif (strpos($sql, 'SELECT SUM') === 0) {
			$paid=0; $payable=0;
			foreach ($this->objects['lmdbsalescommissions_due'] ?? array() as $due) { if (in_array($due['status'],array(1,2),true)) { $payable += $due['amount']; } if ($due['status']===2) { $paid += $due['amount']; } }
			$rows[] = array('paid'=>$paid,'payable'=>$payable);
		} elseif (strpos($sql, 'SELECT rowid') === 0) {
			$table = strpos($sql, '_due') !== false ? 'lmdbsalescommissions_due' : 'lmdbsalescommissions_line';
			foreach ($this->objects[$table] ?? array() as $row) {
				$match = true;
				foreach (array('entity','fk_user','fk_source','fk_rule','fk_commission_line','revision') as $field) { if (preg_match('/\b'.$field.' = (\d+)/',$sql,$m) && (int) $row[$field] !== (int) $m[1]) { $match=false; } }
				foreach (array('source_type','mode','event_type') as $field) { if (preg_match("/\b".$field." = '([^']+)'/",$sql,$m) && $row[$field] !== $m[1]) { $match=false; } }
				if ($match) { $rows[] = array('rowid'=>$row['id']); }
			}
		} elseif (strpos($sql,'UPDATE ') !== 0) { throw new LogicException('Unexpected query: '.$sql); }
		return (object) array('rows'=>$rows,'offset'=>0);
	}
	public function fetch_object($q) { return isset($q->rows[$q->offset]) ? (object) $q->rows[$q->offset++] : false; }
	public function num_rows($q) { return count($q->rows); }
	public function free($q) {}
	public function escape($s) { return addslashes($s); }
	public function lasterror() { return 'Injected SQL failure'; }
}
$tests=0;
function verifyLifecycle($condition,$label) { global $tests; $tests++; if (!$condition) { throw new RuntimeException($label); } }
$reward=array('amount'=>125.01,'reason'=>'earned','threshold'=>40.0,'cost'=>1000.0,'margin'=>600.0,'surplus'=>200.0,'share'=>0.5,'mode'=>'fixed','value'=>250.02,'rule_id'=>42,'rule_label'=>'Bonus','payment_term_id'=>5,'policy_fingerprint'=>'original');
$snapshot=array('frozen'=>true,'sale'=>'allow','commission'=>'allow','checks'=>array(),'reward'=>$reward);
$proposal=(object) array('id'=>10,'entity'=>1,'status'=>2,'date_signature'=>2000,'total_ht'=>1600.0,'context'=>array());
$actor=(object) array('id'=>99);
$method=new ReflectionMethod(LmdbSalesCommissionLineService::class,'processRewards'); $method->setAccessible(true);
$run=static function($db,$status=1) use ($method,$proposal,$actor) { $service=new LmdbSalesCommissionLineService($db); $result=$method->invoke($service,$proposal,$actor,2000,$status); if ($result < 0 && $db->failure === "") { throw new RuntimeException($service->error); } return $result; };
$db=new LifecycleDb(); $db->snapshot=$snapshot;
verifyLifecycle($run($db,0)===1,'Estimate created');
verifyLifecycle(empty($db->objects['lmdbsalescommissions_due']),'No estimated dues');
verifyLifecycle($run($db)===0 && count($db->objects['lmdbsalescommissions_line'])===1,'Same estimate acquired');
$line=$db->objects['lmdbsalescommissions_line'][1];
verifyLifecycle($line['fk_reward_rule']===42 && $line['fk_rule']===0,'Stable identity separate from actual rule');
verifyLifecycle($line['commission_total']===125.01 && $line['payable_total']===50.0,'Commission and payable total');
verifyLifecycle(json_decode($line['snapshot_reward'],true)['acquired']===true,'Acquisition frozen');
verifyLifecycle(array_sum(array_column($db->objects['lmdbsalescommissions_due'],'amount'))===125.01,'Rounded due residual preserved');
verifyLifecycle(count($db->objects['lmdbsalescommissions_due'])===2,'Usual payment distribution inherited');
$before=$db->objects;
verifyLifecycle($run($db)===0 && $before===$db->objects,'Repeated acquisition is inert');
$db->objects['lmdbsalescommissions_due'][1]['status']=2;
$db->objects['lmdbsalescommissions_line'][1]['status']=6;
$db->snapshot['reward']['rule_id']=99; $db->snapshot['reward']['amount']=999.0;
$before=$db->objects;
verifyLifecycle($run($db)===0 && $before===$db->objects,'Cancelled acquired line and paid history remain untouched');
foreach (array('begin','line','due','distribution','totals','commit') as $failure) {
	$db=new LifecycleDb(); $db->snapshot=$snapshot; $db->failure=$failure;
	verifyLifecycle($run($db)===-1 && $db->objects===array(),'Atomic failure: '.$failure);
}
$db=new LifecycleDb(); $db->snapshot=$snapshot; $db->begin();
verifyLifecycle($run($db)===1,'Nested acquisition can succeed');
$db->rollback(); verifyLifecycle($db->objects===array(),'Caller rollback controls final persistence');
$db=new LifecycleDb(); $db->snapshot=$snapshot; $db->snapshot['reward']['amount']=0.0;
verifyLifecycle($run($db)===1 && empty($db->objects['lmdbsalescommissions_due']),'Zero bonus has audit line but no due');
echo "Reward lifecycle: $tests assertions passed (persistence and transactions simulated).\n";
