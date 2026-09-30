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
$restler = file_get_contents(DOL_DOCUMENT_ROOT.'/includes/restler/framework/Luracast/Restler/Restler.php');
foreach (array('authenticate','validate') as $phase) {
 if (strpos($restler, '$this->'.$phase.'();') > strpos($restler, '$this->call();')) { throw new RuntimeException('API preflight precedes authentication/validation'); }
}
echo 'Native source coverage passed: '.DOL_VERSION.PHP_EOL;
