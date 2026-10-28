<?php
/** Real DDL and uniqueness checks on a disposable CI database only.
 * This does not claim a full Dolibarr activation or a native transaction test.
 */
$pdo = new PDO(getenv('LSC_TEST_DSN'), getenv('LSC_TEST_USER'), getenv('LSC_TEST_PASSWORD'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$prefix = 'long_test_prefix_';
$tables = array('rule', 'rule_assignment', 'margin_band', 'margin_travel_band', 'margin_complex_site', 'margin_category', 'margin_product', 'margin_approval', 'margin_request', 'margin_case', 'margin_revision', 'margin_snapshot', 'line', 'due');
foreach ($tables as $table) {
	$sql = file_get_contents(__DIR__.'/../sql/llx_lmdbsalescommissions_'.$table.'.sql');
	$pdo->exec(str_replace('llx_', $prefix, $sql));
	$sql = file_get_contents(__DIR__.'/../sql/llx_lmdbsalescommissions_'.$table.'.key.sql');
	foreach (explode(';', $sql) as $statement) {
		if (trim($statement) !== '') { $pdo->exec(str_replace('llx_', $prefix, $statement)); }
	}
}
$revision = $prefix.'lmdbsalescommissions_margin_revision';
$pdo->exec("INSERT INTO $revision (entity,object_id,revision) VALUES (1,0,1) ON DUPLICATE KEY UPDATE revision = revision + 1");
$pdo->exec("INSERT INTO $revision (entity,object_id,revision) VALUES (1,0,1) ON DUPLICATE KEY UPDATE revision = revision + 1");
if ((int) $pdo->query("SELECT revision FROM $revision WHERE entity = 1 AND object_id = 0")->fetchColumn() !== 2) { throw new RuntimeException('Revision upsert'); }
$snapshot = $prefix.'lmdbsalescommissions_margin_snapshot';
$insert = "INSERT INTO $snapshot (entity,fk_propal,fk_user,fingerprint,sale_state,commission_state,snapshot_payload,date_creation) VALUES (1,10,7,'test','allow','deny','{}',NOW())";
$pdo->beginTransaction(); $pdo->exec($insert); $pdo->rollBack();
if ((int) $pdo->query("SELECT COUNT(*) FROM $snapshot")->fetchColumn() !== 0) { throw new RuntimeException('Snapshot rollback'); }
$pdo->exec($insert);
try { $pdo->exec($insert); throw new RuntimeException('Duplicate snapshot accepted'); }
catch (PDOException $error) { if ($error->errorInfo[1] !== 1062) { throw $error; } }
$pdo->exec(str_replace('(1,10,7,', '(2,10,7,', $insert));
if ((int) $pdo->query("SELECT COUNT(*) FROM $snapshot")->fetchColumn() !== 2) { throw new RuntimeException('Entity uniqueness'); }
echo "MariaDB DDL, long prefix, revision, rollback and entity uniqueness passed.\n";

// A single nullable active key permits multiple historical requests, but only one pending request.
$cases = $prefix.'lmdbsalescommissions_margin_case';
$caseInsert = "INSERT INTO $cases (entity,fk_propal,fk_author,active_proposal,status,fingerprint,reason,snapshot_payload,fk_user_creat,date_creation) VALUES (1,10,7,10,0,'fingerprint','Reason','{}',7,NOW())";
$pdo->exec($caseInsert);
try { $pdo->exec($caseInsert); throw new RuntimeException('Two active dossiers accepted'); }
catch (PDOException $error) { if ($error->errorInfo[1] !== 1062) { throw $error; } }
$pdo->exec("UPDATE $cases SET active_proposal=NULL,status=2 WHERE entity=1 AND fk_propal=10");
$pdo->exec($caseInsert);
$pdo->exec("CREATE TABLE {$prefix}propal (rowid integer PRIMARY KEY, entity integer NOT NULL, fk_statut integer NOT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO {$prefix}propal VALUES (10,1,0)");
$pdo->beginTransaction();
$pdo->query("SELECT rowid FROM {$prefix}propal WHERE entity=1 AND rowid=10 FOR UPDATE")->fetchColumn();
$other = new PDO(getenv('LSC_TEST_DSN'), getenv('LSC_TEST_USER'), getenv('LSC_TEST_PASSWORD'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$other->exec('SET SESSION innodb_lock_wait_timeout=1');
$other->beginTransaction();
try { $other->query("SELECT rowid FROM {$prefix}propal WHERE entity=1 AND rowid=10 FOR UPDATE"); throw new RuntimeException('Concurrent decision acquired locked parent'); }
catch (PDOException $error) { if ($error->errorInfo[1] !== 1205) { throw $error; } }
$other->rollBack();
$pdo->exec("UPDATE $cases SET active_proposal=NULL,status=1,retained_percent=50 WHERE entity=1 AND active_proposal=10");
$pdo->exec("UPDATE {$prefix}propal SET fk_statut=1 WHERE entity=1 AND rowid=10");
$pdo->rollBack();
if ((int) $pdo->query("SELECT COUNT(*) FROM $cases WHERE entity=1 AND active_proposal=10 AND status=0")->fetchColumn() !== 1 || (int) $pdo->query("SELECT fk_statut FROM {$prefix}propal WHERE rowid=10")->fetchColumn() !== 0) { throw new RuntimeException('Dossier and native parent did not roll back together'); }
echo "MariaDB workflow uniqueness, historical requests, parent lock contention and atomic rollback passed.\n";

// Execute the actual policy SELECT against MariaDB, including entity-scoped group membership.
define('MAIN_DB_PREFIX', $prefix);
function dol_now() { return 2000; }
function dol_syslog($message, $level = 0) {}
$catalogEntities = '1';
function getEntity($type) { global $catalogEntities; return $catalogEntities; }
// Only category identity is simulated; catalogue visibility SELECTs run on MariaDB.
class Categorie { public const TYPE_PRODUCT = 'product'; public $MAP_ID = array('product' => 0); public function __construct($db) {} }

class MarginPolicyPdo
{
	private PDO $pdo;
	public function __construct(PDO $pdo) { $this->pdo = $pdo; }
	public function query($sql) { return $this->pdo->query($sql); }
	public function fetch_object($result) { return $result->fetchObject(); }
	public function num_rows($result) { return $result->rowCount(); }
	public function free($result) { $result->closeCursor(); }
	public function idate($date) { return gmdate('Y-m-d H:i:s', $date); }
	public function escape($value) { return substr($this->pdo->quote($value), 1, -1); }
	public function sanitize($value) {
		if (!preg_match('/^[0-9]+(,[0-9]+)*$/D', $value)) { throw new RuntimeException('Invalid fixture ID list'); }
		return $value;
	}
}
require_once __DIR__.'/../class/lmdbsalescommissionmarginservice.class.php';
$rules = $prefix.'lmdbsalescommissions_rule';
$assignments = $prefix.'lmdbsalescommissions_rule_assignment';
$pdo->exec("CREATE TABLE {$prefix}usergroup_user (fk_user integer, fk_usergroup integer, entity integer)");
$pdo->exec("INSERT INTO {$prefix}usergroup_user VALUES (7,3,1),(7,4,2)");
foreach (array(1 => 30, 2 => 40, 3 => 50, 4 => 99) as $id => $threshold) {
	$entity = $id === 4 ? 2 : 1;
	$pdo->exec("INSERT INTO $rules (rowid,entity,ref,label,rule_type,policy_context,policy_effect,rate,date_end,date_creation) VALUES ($id,$entity,'R$id','Rule $id','margin_policy','general','sale',$threshold,'1970-01-01',NOW())");
}
$pdo->exec("INSERT INTO $assignments (entity,assignment_type,fk_rule,fk_user,fk_usergroup,date_creation) VALUES (1,'default',1,NULL,NULL,NOW()),(1,'group',2,NULL,3,NOW()),(1,'user',3,7,NULL,NOW()),(2,'group',4,NULL,4,NOW())");
$travelBands = $prefix.'lmdbsalescommissions_margin_travel_band';
$pdo->exec("INSERT INTO $travelBands (entity,fk_rule,metric,min_value,uplift) VALUES (1,3,'minutes',105,10)");
try { $pdo->exec("INSERT INTO $travelBands (entity,fk_rule,metric,min_value,uplift) VALUES (1,3,'minutes',105,20)"); throw new RuntimeException('Duplicate travel tier accepted'); }
catch (PDOException $error) { if ($error->errorInfo[1] !== 1062) { throw $error; } }
$complexTable = $prefix.'lmdbsalescommissions_margin_complex_site';
$pdo->exec("INSERT INTO $complexTable (entity,fk_rule,uplift_without_travel,uplift_with_travel) VALUES (1,3,15,20)");
try { $pdo->exec("INSERT INTO $complexTable (entity,fk_rule,uplift_without_travel,uplift_with_travel) VALUES (1,3,16,NULL)"); throw new RuntimeException('Duplicate complex configuration accepted'); }
catch (PDOException $error) { if ($error->errorInfo[1] !== 1062) { throw $error; } }
$pdo->exec("INSERT INTO $complexTable (entity,fk_rule,uplift_without_travel,uplift_with_travel) VALUES (2,3,17,NULL)");
$service = new LmdbSalesCommissionMarginService(new MarginPolicyPdo($pdo));
$policies = $service->policies(7,1);
if (count($policies) !== 3) { throw new RuntimeException('Inclusive validity end date or owner filter'); }
$travelPolicy = array_values(array_filter($policies, static function ($policy) { return $policy['rule_id'] === 3; }));
if (count($travelPolicy) !== 1 || $travelPolicy[0]['travel_bands'][0]['uplift'] !== 10.0) { throw new RuntimeException('Travel tier not resolved'); }
if ($travelPolicy[0]['complex_site'] !== array('uplift_without_travel' => 15.0, 'uplift_with_travel' => 20.0)) { throw new RuntimeException('Owner complex configuration not resolved'); }
$decision = LmdbSalesCommissionMarginEngine::evaluate($policies,45.0,null,null,array('minutes' => 106.0, 'kilometres' => null), false);
if ($decision['sale'] !== 'deny' || $decision['checks'][0]['rule_id'] !== 3) { throw new RuntimeException('User priority'); }
$pdo->exec("UPDATE $assignments SET active = 0 WHERE entity = 1 AND assignment_type = 'user'");
$decision = LmdbSalesCommissionMarginEngine::evaluate($service->policies(7,1),45.0,null,null);
if ($decision['sale'] !== 'allow' || $decision['checks'][0]['rule_id'] !== 2) { throw new RuntimeException('Group priority'); }
$decision = LmdbSalesCommissionMarginEngine::evaluate($service->policies(8,1),45.0,null,null);
if ($decision['checks'][0]['rule_id'] !== 1) { throw new RuntimeException('Default priority'); }
$decision = LmdbSalesCommissionMarginEngine::evaluate($service->policies(7,2),45.0,null,null);
if ($decision['sale'] !== 'deny' || $decision['checks'][0]['rule_id'] !== 4) { throw new RuntimeException('Other entity membership'); }
echo "MariaDB policy resolution, DATE bounds and entity-scoped assignments passed.\n";
$pdo->exec("CREATE TABLE {$prefix}product (rowid integer PRIMARY KEY, entity integer NOT NULL, fk_product_type integer NOT NULL)");
$pdo->exec("INSERT INTO {$prefix}product VALUES (101,1,0),(202,1,1)");
$pdo->exec("CREATE TABLE {$prefix}categorie (rowid integer PRIMARY KEY, entity integer NOT NULL, type integer NOT NULL)");
$pdo->exec("INSERT INTO {$prefix}categorie VALUES (77,1,0),(88,1,0)");
$categoryLinks = $prefix.'lmdbsalescommissions_margin_category';
$productLinks = $prefix.'lmdbsalescommissions_margin_product';
$pdo->exec("UPDATE $rules SET policy_scope = 'category' WHERE rowid = 3");
$pdo->exec("UPDATE $rules SET policy_scope = 'product' WHERE rowid = 2");
$pdo->exec("INSERT INTO $categoryLinks (entity,fk_rule,fk_category) VALUES (1,3,77),(1,3,88),(2,3,77)");
$pdo->exec("INSERT INTO $productLinks (entity,fk_rule,fk_product) VALUES (1,2,101),(1,2,202)");
try { $pdo->exec("INSERT INTO $categoryLinks (entity,fk_rule,fk_category) VALUES (1,3,77)"); throw new RuntimeException('Duplicate category link accepted'); }
catch (PDOException $error) { if ($error->errorInfo[1] !== 1062) { throw $error; } }
try { $pdo->exec("INSERT INTO $productLinks (entity,fk_rule,fk_product) VALUES (1,2,101)"); throw new RuntimeException('Duplicate product link accepted'); }
catch (PDOException $error) { if ($error->errorInfo[1] !== 1062) { throw $error; } }
$scopedPolicies = $service->policies(7, 1);
$productPolicies = array_values(array_filter($scopedPolicies, static function ($policy) { return $policy['rule_id'] === 2; }));
if (count($productPolicies) !== 1 || $productPolicies[0]['products'] !== array(101, 202)) { throw new RuntimeException('Product links not resolved'); }
$pdo->exec("UPDATE $assignments SET active = 1 WHERE entity = 1 AND assignment_type = 'user'");
$scopedPolicies = $service->policies(7, 1);
$categoryPolicies = array_values(array_filter($scopedPolicies, static function ($policy) { return $policy['rule_id'] === 3; }));
if (count($categoryPolicies) !== 1 || $categoryPolicies[0]['categories'] !== array(77, 88)) { throw new RuntimeException('Category links crossed entity boundary'); }
echo "MariaDB normalized category/product links and owner-scoped policy loading passed.\n";
$pdo->exec("UPDATE {$prefix}product SET entity = 2 WHERE rowid = 202");
try { $service->policies(7, 1); throw new RuntimeException('Hidden product accepted'); }
catch (RuntimeException $error) { if ($error->getMessage() !== 'LscPolicyUnavailable') { throw $error; } }
$catalogEntities = '1,2'; $service->policies(7, 1);
$catalogEntities = '1'; $pdo->exec("UPDATE {$prefix}product SET entity = 1 WHERE rowid = 202");
$pdo->exec("UPDATE {$prefix}categorie SET entity = 2 WHERE rowid = 88");
try { $service->policies(7, 1); throw new RuntimeException('Hidden category accepted'); }
catch (RuntimeException $error) { if ($error->getMessage() !== 'LscPolicyUnavailable') { throw $error; } }
$catalogEntities = '1,2'; $service->policies(7, 1);
$catalogEntities = '1'; $pdo->exec("UPDATE {$prefix}categorie SET entity = 1 WHERE rowid = 88");
echo "MariaDB missing/shared catalogue targets fail closed and respect entity visibility.\n";


// Real request persistence, simulated access/assessment: duplicate POSTs preserve the first request.
function isModEnabled($key) { return true; }
function getDolGlobalInt($key) { return 0; }
function restrictedArea(...$args) { return 1; }
class RequestSchemaService extends LmdbSalesCommissionMarginService {
	public function assess($proposal, $frozen = true, $actor = null) {
		return array(7 => array('fingerprint'=>str_repeat('a',64),'checks'=>array(
			array('rule_id'=>1,'effect'=>'sale','state'=>'deny'),
			array('rule_id'=>2,'effect'=>'sale','state'=>'deny'),
			array('rule_id'=>3,'effect'=>'commission','state'=>'deny')
		)));
	}
}
class RequestSchemaUser {
	public $id=99; public $socid=0;
	public function hasRight(...$args) { return true; }
}
$conf = (object) array('entity'=>1);
$proposal = (object) array('id'=>10,'entity'=>1,'status'=>0,'date_signature'=>0);
$actor = new RequestSchemaUser();
$requests = $prefix.'lmdbsalescommissions_margin_request';
$requestService = new RequestSchemaService(new MarginPolicyPdo($pdo));
$pdo->beginTransaction();
$requestService->requestSaleApproval($proposal,$actor,"Client's reason",str_repeat('a',64));
$pdo->rollBack();
if ((int) $pdo->query("SELECT COUNT(*) FROM $requests")->fetchColumn() !== 0) { throw new RuntimeException('Request rollback'); }
$requestService->requestSaleApproval($proposal,$actor,"Client's reason",str_repeat('a',64));
$requestService->requestSaleApproval($proposal,$actor,'Duplicate reason',str_repeat('a',64));
if ((int) $pdo->query("SELECT COUNT(*) FROM $requests")->fetchColumn() !== 2) { throw new RuntimeException('Request duplicate or commission requested'); }
if ($pdo->query("SELECT reason FROM $requests LIMIT 1")->fetchColumn() !== "Client's reason") { throw new RuntimeException('Request overwritten'); }
$conf->entity = $proposal->entity = 2;
$requestService->requestSaleApproval($proposal,$actor,'Other entity',str_repeat('a',64));
if ((int) $pdo->query("SELECT COUNT(*) FROM $requests WHERE entity = 2")->fetchColumn() !== 2) { throw new RuntimeException('Request entity isolation'); }
echo "MariaDB sale requests, atomic persistence, rollback and duplicate protection passed.\n";

// Actual additive migration methods, with a real MariaDB connection and descriptor base double.
// Reconstruct the old column shape in this disposable database, preserve an existing line.
define('DOL_DOCUMENT_ROOT', __DIR__.'/fixtures/reward');
require_once __DIR__.'/../core/modules/modLmdbSalesCommissions.class.php';
$lines = $prefix.'lmdbsalescommissions_line';
$dues = $prefix.'lmdbsalescommissions_due';
$pdo->exec("INSERT INTO $lines (entity,fk_user,fk_source,mode,fk_rule,commission_total,date_creation) VALUES (1,7,10,'margin',1,123.45,NOW())");
$pdo->exec("ALTER TABLE $lines DROP INDEX idx_lsc_reward_rule, DROP COLUMN fk_reward_rule, DROP COLUMN snapshot_reward");
$pdo->exec("UPDATE $rules SET policy_scope = 'offer'");
$pdo->exec("ALTER TABLE $rules DROP COLUMN policy_scope, DROP COLUMN reward_mode, DROP COLUMN reward_value");
$descriptor = (new ReflectionClass(modLmdbSalesCommissions::class))->newInstanceWithoutConstructor();
$descriptor->db = new MarginPolicyPdo($pdo);
foreach (array('upgradeRuleSchema', 'upgradeCommissionLineDispatchSchema') as $name) {
	$method = new ReflectionMethod($descriptor, $name); $method->setAccessible(true);
	for ($pass=0; $pass<2; $pass++) { if ($method->invoke($descriptor,true)!==1) { throw new RuntimeException('Migration failed: '.$name); } }
}
if ((float) $pdo->query("SELECT commission_total FROM $lines WHERE rowid=1")->fetchColumn() !== 123.45) { throw new RuntimeException('Migration changed history'); }
if ((int) $pdo->query("SELECT COUNT(*) FROM $rules WHERE rule_type = 'margin_policy' AND policy_scope = 'offer'")->fetchColumn() !== 4) { throw new RuntimeException('Existing policies lost their offer scope'); }
$rewardInsert="INSERT INTO $lines (entity,fk_user,fk_source,mode,fk_rule,fk_reward_rule,commission_total,snapshot_reward,date_creation) VALUES (1,7,10,'margin_excess',0,42,50,'{\"acquired\":true}',NOW())";
$pdo->beginTransaction(); $pdo->exec($rewardInsert);
$lineId=(int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO $dues (entity,fk_commission_line,event_type,amount,date_creation) VALUES (1,$lineId,'proposal_signed',50,NOW())");
$pdo->rollBack();
if ((int) $pdo->query("SELECT COUNT(*) FROM $lines WHERE mode='margin_excess'")->fetchColumn() !== 0 || (int) $pdo->query("SELECT COUNT(*) FROM $dues")->fetchColumn() !== 0) { throw new RuntimeException('Reward/dues rollback'); }
$pdo->exec($rewardInsert);
try { $pdo->exec(str_replace(',42,50,', ',99,50,', $rewardInsert)); throw new RuntimeException('Changed rule duplicated reward'); }
catch (PDOException $error) { if ($error->errorInfo[1] !== 1062) { throw $error; } }
$pdo->exec(str_replace('(1,7,10,', '(2,7,10,', $rewardInsert));
if ((int) $pdo->query("SELECT COUNT(*) FROM $lines WHERE mode='margin_excess'")->fetchColumn() !== 2) { throw new RuntimeException('Reward entity isolation'); }
// Real resolver query: end DATE includes the entire business day, separate bonus resolution.
require_once __DIR__.'/../class/lmdbsalescommissionruleresolver.class.php';
$pdo->exec("INSERT INTO $rules (rowid,entity,ref,label,rule_type,reward_mode,reward_value,date_end,date_creation) VALUES (42,1,'BONUS','Reward','margin_excess','fixed',50,'1970-01-01',NOW())");
$pdo->exec("INSERT INTO $assignments (entity,assignment_type,fk_rule,date_creation) VALUES (1,'default',42,NOW())");
$result=(new LmdbSalesCommissionRuleResolver(new MarginPolicyPdo($pdo)))->resolveForUser(7,2000,1,'proposal','margin_excess');
if (($result['selected']['margin_excess']['rule_id'] ?? 0)!==42) { throw new RuntimeException('Reward validity end date'); }
echo "MariaDB reward migrations replayed, history preserved, atomic rollback, stable uniqueness and resolution passed.\n";
