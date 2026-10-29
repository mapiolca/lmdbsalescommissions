<?php
/** Analysis constants only: no session, database, authentication or ERP bootstrap. */
$nativeRoot = getenv('LSC_PHPSTAN_CORE');
$nativeVersion = getenv('LSC_PHPSTAN_VERSION');
if (!is_string($nativeRoot) || !is_file($nativeRoot.'/main.inc.php') || !is_string($nativeVersion) || $nativeVersion === '') {
	throw new RuntimeException('Pass the complete native source directory and its version to test/run_phpstan.py');
}
define('DOL_DOCUMENT_ROOT', $nativeRoot);
define('DOL_VERSION', $nativeVersion);
define('MAIN_DB_PREFIX', 'phpstan_');
define('DOL_URL_ROOT', '/erp');
