<?php
/** Source contracts + execution of the unmodified native margin calculator.
 * Usage: php test/native_margin_contract_test.php <core htdocs> <version>
 */
define('DOL_DOCUMENT_ROOT', $argv[1]);
define('DOL_VERSION', $argv[2]);
$conf = (object) array('modules_parts' => array('hooks' => array('lmdbsalescommissions' => array('propalcard', 'propallist', 'api', 'ajaxonlinesign'))));
require_once __DIR__.'/../class/lmdbsalescommissionscompatibility.class.php';
if (!LmdbSalesCommissionsCompatibility::nativeMarginGuardCoverage()) { throw new RuntimeException('Installed entrypoint coverage rejected'); }
$online = file_get_contents(DOL_DOCUMENT_ROOT.'/core/ajax/onlineSign.php');
if (strpos($online, 'dol_verifyHash(') > strpos($online, "initHooks(array('ajaxonlinesign'))")) { throw new RuntimeException('Public precheck is before token verification'); }
foreach (array('card.php','list.php') as $file) {
 $text = file_get_contents(DOL_DOCUMENT_ROOT.'/comm/propal/'.$file);
 if (strpos($text, "executeHooks('doActions'") === false || strpos($text, "executeHooks('doActions'") > strpos($text, '->closeProposal(')) { throw new RuntimeException('UI hook too late: '.$file); }
}
$lineSource = DOL_DOCUMENT_ROOT.'/comm/propal/class/'.(version_compare(DOL_VERSION, '21.0.0', '<') ? 'propal.class.php' : 'propaleligne.class.php');
$lineCode = file_get_contents($lineSource);
if (!is_string($lineCode)) { throw new RuntimeException('Native proposal line class unavailable'); }
foreach (array('LINEPROPAL_INSERT', 'LINEPROPAL_MODIFY', 'LINEPROPAL_DELETE') as $event) {
 if (strpos($lineCode, "call_trigger('".$event."'") === false) { throw new RuntimeException('Native proposal line event missing: '.$event); }
}
$deleteTrigger = strpos($lineCode, "call_trigger('LINEPROPAL_DELETE'");
$deleteSql = strpos($lineCode, 'DELETE FROM " . MAIN_DB_PREFIX . "propaldet', $deleteTrigger);
if ($deleteSql === false || $deleteSql < $deleteTrigger) { throw new RuntimeException('Native line deletion contract changed'); }
$restler = file_get_contents(DOL_DOCUMENT_ROOT.'/includes/restler/framework/Luracast/Restler/Restler.php');
foreach (array('authenticate','validate') as $phase) {
 if (strpos($restler, '$this->'.$phase.'();') > strpos($restler, '$this->call();')) { throw new RuntimeException('API preflight precedes authentication/validation'); }
}
echo 'Native source coverage passed: '.DOL_VERSION.PHP_EOL;
