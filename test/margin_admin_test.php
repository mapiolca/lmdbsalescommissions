<?php
/** Native Form and CommonObject contracts, simulated database/actor; no ERP authentication. */
define('DOL_DOCUMENT_ROOT', realpath($argv[1] ?? __DIR__.'/.core-cache/20.0.0/htdocs'));
define('DOL_URL_ROOT', '/erp');
define('MAIN_DB_PREFIX', 'admin_test_');
$conf = (object) array('entity' => 1, 'use_javascript_ajax' => 1, 'browser' => (object) array('layout' => 'classic'));
function getDolGlobalInt($key, $default = 0) { return $key === 'LMDBSALESCOMMISSIONS_MARGIN_ENABLED' ? 1 : $default; }
function getDolGlobalString($key, $default = '') { return $key === 'MAIN_USE_JQUERY_MULTISELECT' ? '1' : $default; }
function dol_escape_htmltag($value, ...$args) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function dol_escape_js($value) { return addslashes($value); }
function dol_syslog($message, $level = 0) {}
function dol_nboflines_bis($text, ...$args) { return substr_count($text, "\n") + 1; }
function newToken() { return 'test-csrf'; }
function getNonce() { return 'test-nonce'; }
function img_picto($alt, $key, ...$args) { return '<span data-picto="'.$key.'"></span>'; }
function img_edit(...$args) { return img_picto('', 'edit'); }
function img_delete(...$args) { return img_picto('', 'delete'); }
function img_help(...$args) { return ''; }
function yn($value) { return $value ? 'Yes' : 'No'; }
function dolGetButtonTitle($label, $help, $icon, $url, $id) { return '<a id="'.$id.'" href="'.$url.'">'.$label.'</a>'; }
function load_fiche_titre($title, $link, $icon) { return $link; }
class AdminLangs {
	public function trans($key, ...$args) { return $key.implode('', $args); }
	public function transnoentities($key, ...$args) { return $this->trans($key, ...$args); }
	public function transnoentitiesnoconv($key) { return $key; }
}
class User {
	public $id = 5; public $admin = 1; public $socid = 0; public $permitted = true;
	public function hasRight($module, $object, $action) { return $this->permitted; }
}
class AdminDb {
	public $referenced = ''; public $fail = ''; public $deleted = false; public $bands = array(7, 8); public $queries = array(); public $saved;
	public function begin() { if ($this->fail === 'begin') { return 0; } $this->saved = array($this->deleted, $this->bands); return 1; }
	public function commit() { return $this->fail !== 'commit'; }
	public function rollback() { list($this->deleted, $this->bands) = $this->saved; return 1; }
	public function query($sql) {
		$this->queries[] = $sql;
		if ($this->fail === 'query') { return false; }
		if (strpos($sql, 'entity = 1') === false) { throw new RuntimeException('Query without owner entity'); }
		if (strpos($sql, 'FOR UPDATE') !== false) { return (object) array('count' => 1); }
		if (strpos($sql, 'SELECT') === 0) { return (object) array('count' => $this->referenced && strpos($sql, 'lmdbsalescommissions_'.$this->referenced.' ') !== false ? 1 : 0); }
		if (strpos($sql, 'DELETE FROM admin_test_lmdbsalescommissions_margin_band') === 0) {
			if ($this->fail === 'bands') { return false; }
			$this->bands = array(); return true;
		}
		throw new RuntimeException('Unexpected query');
	}
	public function num_rows($result) { return $result->count; }
	public function free($result) {}
	public function plimit($limit) { return ' LIMIT '.(int) $limit; }
}
require DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
require __DIR__.'/../class/lmdbsalescommissionrule.class.php';
class AdminRule extends LmdbSalesCommissionRule {
	public $nativeDeletes = 0;
	public $triggers = array();
	public function deleteCommon(User $user, $notrigger = 0, $forcechilddeletion = 0) {
		if (!$notrigger) { throw new RuntimeException('Unexpected native class-name trigger'); }
		$this->nativeDeletes++;
		if ($this->db->fail === 'native') { return -1; }
		$this->db->deleted = true; return 1;
	}
	public function call_trigger($trigger, $user) { $this->triggers[] = $trigger; return $this->db->fail === 'trigger' ? -1 : 1; }
}
$tests = 0;
function check($condition, $message) { global $tests; $tests++; if (!$condition) { throw new RuntimeException($message); } }
function parseView($html) {
	$dom = new DOMDocument(); $previous = libxml_use_internal_errors(true);
	$dom->loadHTML('<!doctype html><html><meta charset="utf-8"><body>'.$html.'</body></html>');
	libxml_clear_errors(); libxml_use_internal_errors($previous); return new DOMXPath($dom);
}
$db = new AdminDb(); $user = new User(); $langs = new AdminLangs();
$rule = new AdminRule($db); $rule->id = 1; $rule->entity = 1; $rule->rule_type = 'margin_policy';
$rule->ref = 'RULE "<&'; $rule->label = 'Label'; $rule->policy_context = 'general'; $rule->policy_effect = 'sale'; $rule->rate = 30; $rule->active = 1;
$form = new Form($db); $pageUrl = '/erp/custom/lmdbsalescommissions/admin/marginpolicies.php';
$contexts = array('general' => 'General', 'pv' => 'PV'); $effects = array('sale' => 'Sale', 'commission' => 'Commission', 'both' => 'Both');
$formValues = array('ref' => $rule->ref, 'label' => 'Label', 'rate' => '30', 'policy_context' => 'general', 'policy_effect' => 'sale');
$policies = array((object) array('rowid' => 1, 'ref' => $rule->ref, 'label' => 'Label', 'policy_context' => 'general', 'policy_effect' => 'sale', 'active' => 1));
$bands = array(); $mode = ''; $id = 0;
$bandValues = array_fill_keys(array('kwc_min', 'kwc_max', 'kwc_inclusive', 'kwh_min', 'kwh_max', 'kwh_inclusive', 'threshold'), '');
$render = static function () {
	extract($GLOBALS, EXTR_SKIP);
	ob_start(); require __DIR__.'/../tpl/marginpolicies.tpl.php'; return ob_get_clean();
};
$html = $render(); $view = parseView($html);
check($view->query('//a[@id="lsc-new-policy"]')->length === 1, 'One native create entry');
check($view->query('//div[@id="lsc-policy-editor"]')->length === 0, 'No permanent creation form');
check($view->query('//table[@id="lsc-policies"]/tr[2]/td[1]/a')->length === 0, 'Reference is plain text');
check($view->query('//table[@id="lsc-policies"]/tr[2]/td[6]/a')->length === 2, 'Edit and delete in final column');
check($view->query('//a[contains(@href,"assignments.php")]')->length === 0, 'Redundant assignments link removed');
check($view->query('//a[@role="switch"]/ancestor::tr/td')->length === 2, 'Activation row has two cells');
check($view->query('//a[@role="switch"]')->item(0)->getAttribute('aria-checked') === 'true', 'Activation state exposed');
$mode = 'create'; $html = $render(); $view = parseView($html);
check($view->query('//form[@id="lsc-policy-form" and @method="POST"]')->length === 1, 'Editor saves by POST');
check($view->query('//form[@id="lsc-policy-form"]/input[@name="token"]')->length === 1, 'Editor has CSRF token');
check(strpos($html, '<&') === false && $view->query('//input[@name="ref"]')->item(0)->getAttribute('value') === $rule->ref, 'Field values escaped');
check(strpos($html, 'select2') !== false, 'Native Select2 initialization present');
check($view->query('//div[@id="lsc-policy-bands"]')->length === 1, 'Bands are available at first creation, even before selecting a technical context');
check($view->query('//form[@id="lsc-policy-form"]//input[@name="kwc_min" or @name="kwh_min" or @name="threshold"]')->length === 3, 'Pending band submits with the policy');
check($view->query('//table[@id="lsc-band-table"]/tr[2]/td/input')->length === 5 && $view->query('//table[@id="lsc-band-table"]/tr[2]/td/button[@name="add_band_continue"]')->length === 1, 'Creation inputs and Add are immediately below the bounds header');
$bandValues['kwc_min'] = '1,5'; $bandValues['threshold'] = '30';
$view = parseView($render());
check($view->query('//input[@name="kwc_min"]')->item(0)->getAttribute('value') === '1,5', 'Failed input keeps its original decimal format');
check($view->query('//input[@name="threshold"]')->item(0)->getAttribute('value') === '30', 'Pending threshold is preserved');
$mode = 'edit'; $id = 1; $rule->policy_context = 'pv'; $view = parseView($render());
check($view->query('//form[@id="lsc-policy-form"]//button[@name="add_band_continue"]')->length === 1, 'Add a band and continue editing');
check($view->query('//table[@id="lsc-band-table"]/tr[2]/td/input')->length === 5 && !$view->query('//div[@id="lsc-policy-bands"]/p//input')->length, 'Edition uses the same input row, without a separate form below the table');
check($view->query('//form//form')->length === 0, 'No nested forms in editor');
$bands = array(array('rowid' => 9, 'threshold' => 30, 'kwc_min' => 0, 'kwc_max' => 3, 'kwc_inclusive' => 0, 'kwh_min' => null, 'kwh_max' => null, 'kwh_inclusive' => 0));
$view = parseView($render());
check($view->query('//div[@id="lsc-policy-bands"]//button[@form="lsc-delete-band-9"]')->length === 1, 'Band deletion targets its own POST form');
check($view->query('//button[@form="lsc-delete-band-9"]/span[@data-picto="delete"]')->length === 1 && trim($view->query('//button[@form="lsc-delete-band-9"]')->item(0)->textContent) === '', 'Band deletion uses the native pictogram without a text button');
check($view->query('//form[@id="lsc-delete-band-9"]/input[@name="token"]')->length === 1 && !$view->query('//form//form')->length, 'Independent deletion form keeps token and has no nested form');
$bands = array();
$mode = 'delete'; $html = $render();
check(strpos($html, 'dialog-confirm') !== false && strpos($html, 'action=confirm_delete') !== false, 'Native delete confirmation');
check(strpos($html, 'token=test-csrf') !== false, 'Native confirmation includes token');
$conf->use_javascript_ajax = 0; $view = parseView($render());
check($view->query('//form[@method="POST"]/input[@name="action" and @value="confirm_delete"]')->length === 1, 'No-JS delete confirmation');
$mode = 'edit'; $view = parseView($render());
check($view->query('//form[@id="lsc-policy-form"]')->length === 1, 'No-JS editor remains available');
$mode = ''; $policies = array(); $view = parseView($render());
check($view->query('//table[@id="lsc-policies"]//td[@colspan="6"]')->length === 1, 'Native empty table spans all columns');
foreach (array('rule_assignment', 'line', 'margin_approval', 'margin_request') as $dependency) {
	$db->referenced = $dependency;
	check($rule->delete($user) === -1 && $rule->error === 'LscPolicyInUse', 'Referenced control preserved: '.$dependency);
	check(!$db->deleted && $db->bands === array(7, 8) && !$rule->nativeDeletes, 'No mutation of referenced control');
}
$db->referenced = '';
$db->fail = 'begin'; $db->queries = array();
check($rule->delete($user) === -1 && !$db->queries && !$db->deleted && $db->bands === array(7, 8), 'Failed transaction start prevents every mutation');
foreach (array('query', 'bands', 'native', 'trigger', 'commit') as $failure) {
	$db->fail = $failure;
	check($rule->delete($user) === -1, 'Deletion failure is reported: '.$failure);
	check(!$db->deleted && $db->bands === array(7, 8), 'Rollback restores control and bands');
}
$db->fail = ''; $db->queries = array(); $user->permitted = false;
check($rule->delete($user) === -1 && !$db->queries, 'Admin without explicit right rejected before SQL');
$user->permitted = true; $user->socid = 7;
check($rule->delete($user) === -1 && !$db->queries, 'External actor rejected');
$user->socid = 0; $rule->entity = 2;
check($rule->delete($user) === -1 && !$db->queries, 'Other entity rejected');
$rule->entity = 1;
$rule->triggers = array();
check($rule->delete($user) === 1 && $db->deleted && !$db->bands, 'Unused control and bands deleted together');
check($rule->triggers === array('LMDBSALESCOMMISSIONS_RULE_DELETE'), 'One stable CRUD trigger invalidates policy decisions');
print "Margin policy admin: $tests assertions passed using native Form and CommonObject contracts.\n";
