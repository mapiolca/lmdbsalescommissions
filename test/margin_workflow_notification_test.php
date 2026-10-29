<?php
/** Native template lookup, module transport bridge; database and email delivery are simulated. */
require __DIR__.'/margin_workflow_test.php';
require_once __DIR__.'/../class/lmdbsalescommissionworkflownotification.class.php';
eval('class NativeWorkflowMail { '.nativeWorkflowMethod($workflowNativeRoot.'/core/class/html.formmail.class.php', 'getEMailTemplate').' }');
class ModelMail extends stdClass { public function __construct($db) {} }
class CEmailTemplate extends ModelMail {}
class WorkflowTemplateDb extends WorkflowDb {
	public $templates = array();
	public function order($fields, $directions) { return ' ORDER BY '.$fields; }
	public function query($sql) {
		if (strpos($sql, 'c_email_templates') === false) { return parent::query($sql); }
		$this->queries[] = $sql;
		if (strpos($sql, 'DELETE') === 0) {
			$this->templates = array_filter($this->templates, static function ($row) { return substr($row['type_template'], -5) !== '_send'; });
			return true;
		}
		if (strpos($sql, 'SELECT') === 0) {
			preg_match("/type_template = '([^']+)'/", $sql, $type);
			preg_match("/label = '([^']+)'/", $sql, $label);
			$rows = array_values(array_filter($this->templates, static function ($row) use ($type, $label, $sql) {
				if ($row['type_template'] !== $type[1] || $row['label'] !== $label[1] || $row['entity'] !== 1 || !$row['active']) { return false; }
				if (preg_match("/lang = '([^']+)'/", $sql, $lang) && $row['lang'] !== $lang[1]) { return false; }
				return true;
			}));
			return new WorkflowRows($rows);
		}
		if (strpos($sql, 'INSERT') === 0) {
			preg_match('/\(([^)]+)\) VALUES \((.*)\)$/s', $sql, $m);
			$row = array_combine(explode(',', $m[1]), array_map('stripslashes', str_getcsv($m[2], ',', "'", '\\')));
			$row['rowid'] = count($this->templates) + 1; $row['entity'] = (int) $row['entity']; $row['active'] = (int) $row['active'];
			$this->templates[$row['rowid']] = $row; return true;
		}
		if (strpos($sql, 'UPDATE') === 0) {
			preg_match('/ SET (.*) WHERE entity = \d+ AND rowid = (\d+)$/s', $sql, $m);
			foreach (str_getcsv($m[1], ',', "'", '\\') as $pair) {
				list($key, $value) = explode(' = ', $pair, 2);
				$this->templates[(int) $m[2]][$key] = stripslashes(trim($value, "'"));
			}
			$this->templates[(int) $m[2]]['entity'] = 1; $this->templates[(int) $m[2]]['active'] = 1;
			return true;
		}
		throw new RuntimeException('Unexpected template SQL');
	}
}
$db = new WorkflowTemplateDb(); $user = new User();
$type = (new LmdbSalesCommissionMarginCase($db))->element;
checkWorkflow(strlen($type.'_send') <= 32, 'Template type including legacy suffix fits the native c_email_templates column');
$schema = file_get_contents($workflowNativeRoot.'/install/mysql/tables/llx_c_email_templates.sql');
preg_match('/type_template\s+varchar\((\d+)\)/i', $schema, $column);
checkWorkflow(isset($column[1]) && strlen($type.'_send') <= (int) $column[1], 'Template identity fits the pinned native database schema');
$conf->modules_parts['hooks']['lmdbsalescommissions'][] = 'elementproperties';
eval(nativeWorkflowMethod($workflowNativeRoot.'/core/lib/functions.lib.php', 'getElementProperties'));
$properties = getElementProperties($type);
checkWorkflow($properties['module'] === 'lmdbsalescommissions' && $properties['classfile'] === 'lmdbsalescommissionmargincase' && $properties['classname'] === LmdbSalesCommissionMarginCase::class && $properties['table_element'] === 'lmdbsalescommissions_margin_case', 'Native object resolver finds the short dossier identity');
$code = 'LMDBSALESCOMMISSIONS_MARGIN_CASE_CREATE';
$settings[$code.'_TEMPLATE'] = 'CUSTOM';
$conf->lmdbsalescommissions = (object) array('enabled' => 1, 'multidir_output' => array(1 => '/documents/commissions'));
foreach (array('fr_FR', 'en_US') as $index => $language) {
	$row = array_fill_keys(array('email_from', 'email_to', 'email_tocc', 'email_tobcc', 'content_lines', 'fk_user'), null);
	$row += array('rowid' => $index + 1, 'entity' => 1, 'module' => 'lmdbsalescommissions', 'lang' => $language, 'private' => 0, 'type_template' => $type, 'label' => 'CUSTOM', 'position' => 100, 'active' => 1, 'enabled' => '1', 'joinfiles' => 0, 'topic' => 'Custom subject '.$language, 'content' => 'Custom body '.$language);
	$db->templates[$index + 1] = $row;
}
$originals = $db->templates;
$legacy = version_compare(DOL_VERSION, '24.0.0', '<');
$transportType = $type.($legacy ? '_send' : '');
$expectedCount = $legacy ? 4 : 2;
LmdbSalesCommissionWorkflowNotification::prepare($db, $user, $code, 1);
checkWorkflow(count($db->templates) === $expectedCount && array_slice($db->templates, 0, 2, true) === $originals, 'Transport preserves both customized source models');
$mail = new NativeWorkflowMail();
$outputlangs = new stdClass(); $outputlangs->defaultlang = 'fr_FR';
$model = $mail->getEMailTemplate($db, $transportType, $user, $outputlangs, 0, 1, 'CUSTOM');
checkWorkflow($model->content === 'Custom body fr_FR' && $model->topic === 'Custom subject fr_FR', 'Native Notify template type resolves selected French custom model');
$db->templates[1]['content'] = 'Changed by administrator';
LmdbSalesCommissionWorkflowNotification::prepare($db, $user, $code, 1);
checkWorkflow(count($db->templates) === $expectedCount && $db->templates[1]['content'] === 'Changed by administrator', 'Replaying the bridge neither duplicates nor overwrites source templates');
$model = $mail->getEMailTemplate($db, $transportType, $user, $outputlangs, 0, 1, 'CUSTOM');
checkWorkflow($model->content === 'Changed by administrator', 'Native transport follows current customized content');
$outputlangs->defaultlang = 'en_US';
$model = $mail->getEMailTemplate($db, $transportType, $user, $outputlangs, 0, 1, 'CUSTOM');
checkWorkflow($model->content === 'Custom body en_US', 'Native language selection preserved');
$settings[$code.'_TEMPLATE'] = 'Deleted';
refusedWorkflow('LscWorkflowTemplateMissing', static function () use ($db, $user, $code) { LmdbSalesCommissionWorkflowNotification::prepare($db, $user, $code, 1); }, 'Deleted selected model is an error, never an arbitrary fallback');
refusedWorkflow('LscOwnerContext', static function () use ($db, $user, $code) { LmdbSalesCommissionWorkflowNotification::prepare($db, $user, $code, 2); }, 'Template bridge respects owner entity');
$notifySource = file_get_contents($workflowNativeRoot.'/core/class/notify.class.php');
checkWorkflow($legacy ? strpos($notifySource, '$object_type.\'_send\'') !== false : strpos($notifySource, 'getEMailTemplate($this->db, $object_type,') !== false, 'Native notification sender uses documented version-specific transport type');
$adminSource = file_get_contents($workflowNativeRoot.'/admin/notification.php');
checkWorkflow(strpos($adminSource, '$model = $notifiedevent[\'elementtype\']') !== false, 'Native administration uses documented visible type');
// Exercise shipped substitutions, including plain-text email subjects.
// Persistence and the HTML/plain translation distinction are simulated.
foreach (array('comm/propal/class/propal.class.php', 'user/class/user.class.php') as $file) {
	if (!is_dir(dirname(DOL_DOCUMENT_ROOT.'/'.$file))) { mkdir(dirname(DOL_DOCUMENT_ROOT.'/'.$file), 0777, true); }
	file_put_contents(DOL_DOCUMENT_ROOT.'/'.$file, '<?php // Classes already supplied by the workflow fixture.');
}
function dol_nl2br($text) { return nl2br($text); }
eval(nativeWorkflowMethod(__DIR__.'/../core/substitutions/functions_lmdbsalescommissions.lib.php', 'lmdbsalescommissions_completesubstitutionarray'));
class WorkflowMailLangs extends WorkflowLangs {
	public $translations = array();
	public function trans($key, ...$args) { return htmlentities($this->translations[$key] ?? $key, ENT_QUOTES, 'UTF-8'); }
	public function transnoentities($key, ...$args) { return $this->translations[$key] ?? $key; }
}
$mailLangs = new WorkflowMailLangs();
foreach (file(__DIR__.'/../langs/fr_FR/lmdbsalescommissions.lang', FILE_IGNORE_NEW_LINES) as $line) {
	if (strpos($line, '=') !== false) { list($key, $value) = explode('=', $line, 2); $mailLangs->translations[$key] = $value; }
}
$case = new LmdbSalesCommissionMarginCase($db);
$case->fk_propal = 10; $case->entity = 1; $case->fk_author = 7; $case->fk_user_creat = 7; $case->fk_user_decider = 9;
$case->reason = '<script>unsafe</script>'; $case->decision_reason = 'Retour motivé';
$case->snapshot_commission_base = 37.5; $case->retained_percent = 50;
$case->snapshot_payload = json_encode(array(array('inputs' => array('rate' => 25), 'checks' => array(array('effect' => 'sale', 'state' => 'deny', 'origin' => 'Minimum 30', 'threshold' => 30)))));
foreach (array(0 => 'En attente de dérogation', 1 => 'Dérogation approuvée', 2 => 'Modification réautorisée') as $status => $label) {
	$case->status = $status; $substitutions = array();
	lmdbsalescommissions_completesubstitutionarray($substitutions, $mailLangs, $case);
	$subject = strtr('__LSC_WORKFLOW_DECISION__ : __LSC_WORKFLOW_PROPOSAL_REF__', $substitutions);
	checkWorkflow($subject === $label.' : P10', 'French email subject keeps readable accents for decision '.$status);
	checkWorkflow(strpos($substitutions['__LSC_WORKFLOW_REASON__'], '<script>') === false, 'Free-text reason remains HTML-escaped');
	checkWorkflow($substitutions['__LSC_WORKFLOW_COMMISSION__'] === '50 % (18.75)' && $substitutions['__LSC_WORKFLOW_MARGIN__'] === '25 %', 'Native monetary normalization and margin substitutions preserved');
}

echo 'Workflow notification models: '.$checks." cumulative assertions passed; SMTP not executed.\n";
