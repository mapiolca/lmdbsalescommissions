<?php
/** Real DDL and uniqueness checks on a disposable CI database only.
 * This does not claim a full Dolibarr activation or a native transaction test.
 */
$pdo = new PDO(getenv('LSC_TEST_DSN'), getenv('LSC_TEST_USER'), getenv('LSC_TEST_PASSWORD'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$prefix = 'long_test_prefix_';
$tables = array('rule', 'rule_assignment', 'margin_band', 'margin_approval', 'margin_revision', 'margin_snapshot');
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

// Execute the actual policy SELECT against MariaDB, including entity-scoped group membership.
define('MAIN_DB_PREFIX', $prefix);
function dol_now() { return 2000; }
function dol_syslog($message, $level = 0) {}
class MarginPolicyPdo
{
	private PDO $pdo;
	public function __construct(PDO $pdo) { $this->pdo = $pdo; }
	public function query($sql) { return $this->pdo->query($sql); }
	public function fetch_object($result) { return $result->fetchObject(); }
	public function free($result) { $result->closeCursor(); }
	public function idate($date) { return gmdate('Y-m-d H:i:s', $date); }
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
$service = new LmdbSalesCommissionMarginService(new MarginPolicyPdo($pdo));
$policies = $service->policies(7,1);
if (count($policies) !== 3) { throw new RuntimeException('Inclusive validity end date or owner filter'); }
$decision = LmdbSalesCommissionMarginEngine::evaluate($policies,45.0,null,null);
if ($decision['sale'] !== 'deny' || $decision['checks'][0]['rule_id'] !== 3) { throw new RuntimeException('User priority'); }
$pdo->exec("UPDATE $assignments SET active = 0 WHERE entity = 1 AND assignment_type = 'user'");
$decision = LmdbSalesCommissionMarginEngine::evaluate($service->policies(7,1),45.0,null,null);
if ($decision['sale'] !== 'allow' || $decision['checks'][0]['rule_id'] !== 2) { throw new RuntimeException('Group priority'); }
$decision = LmdbSalesCommissionMarginEngine::evaluate($service->policies(8,1),45.0,null,null);
if ($decision['checks'][0]['rule_id'] !== 1) { throw new RuntimeException('Default priority'); }
$decision = LmdbSalesCommissionMarginEngine::evaluate($service->policies(7,2),45.0,null,null);
if ($decision['sale'] !== 'deny' || $decision['checks'][0]['rule_id'] !== 4) { throw new RuntimeException('Other entity membership'); }
echo "MariaDB policy resolution, DATE bounds and entity-scoped assignments passed.\n";
