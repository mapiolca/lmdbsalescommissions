<?php
/** Execute the real catalogue-loading block with bounded SQL doubles; native Form rendered by the included suite. */
require __DIR__.'/margin_admin_test.php';
$catalogueAssertionsStart = $tests;
function GETPOST($key, $type = '') { global $cataloguePost; return $cataloguePost[$key] ?? ''; }
class CatalogueDb extends AdminDb {
	public function query($sql) {
		$this->queries[] = $sql;
		if (strpos($sql, 'SELECT rowid, ref, label FROM admin_test_product') !== 0 || strpos($sql, 'entity IN (1)') === false || !preg_match('/AND rowid IN \(([^)]+)\)/', $sql, $ids)) { throw new RuntimeException('Unbounded or unscoped catalogue query'); }
		$rows = array();
		foreach (explode(',', $ids[1]) as $id) {
			$id = (int) $id;
			if (!isset($this->catalogProducts[$id])) { continue; }
			if (strpos($sql, 'fk_product_type IN (0)') !== false && $this->catalogProducts[$id] !== 0) { continue; }
			$rows[] = array('rowid' => $id, 'ref' => 'P-'.$id, 'label' => 'Selected '.$id);
		}
		return $this->result($rows);
	}
}
class CatalogueForm extends Form {
	public $calls = array();
	public function select_produits_list($selected = 0, $htmlname = 'productid', $filtertype = '', $limit = 20, $price_level = 0, $filterkey = '', $status = 1, $finished = 2, $outputmode = 0, $socid = 0, $showempty = '1', $forcecombo = 0, $morecss = 'maxwidth500', $hidepriceinlabel = 0, $warehouseStatus = '', $status_purchase = -1, $warehouseId = 0) {
		$this->calls[] = func_get_args();
		return array(array('key' => 21, 'value' => 'Found', 'label2' => 'Product'), array('key' => 'invalid', 'value' => 'Bad', 'label2' => 'Bad'));
	}
}
$source = file_get_contents($argv[2] ?? __DIR__.'/../admin/marginpolicies.php');
$start = strpos($source, '$categoryOptions = array();');
$end = strpos($source, 'llxHeader(', $start);
if ($start === false || $end === false) { throw new RuntimeException('Catalogue block missing'); }
$catalogueCode = substr($source, $start, $end - $start);
$categoryEnabled = false; $mode = 'edit'; $cataloguePost = array(); $selectedProducts = array();
$db = new CatalogueDb(); $form = new CatalogueForm($db); $user = new User();
$db->catalogProducts = array_fill(1, 80000, 0); $db->catalogProducts[22] = 1;
$conf->use_javascript_ajax = 1;
eval($catalogueCode);
check($productOptions === array() && $db->queries === array(), 'An 80000-item catalogue needs no product query when opening a general rule');
$selectedProducts = array('22'); eval($catalogueCode);
check(array_keys($productOptions) === array(22) && count($db->queries) === 1, 'Opening an existing selection loads only its ids');
$user->denied = array('service.lire'); eval($catalogueCode);
check($productOptions === array() && $productSearchType === '0', 'Product-only access cannot preload a selected service');
$user->denied = array('service.lire', 'produit.lire'); $db->queries = array(); eval($catalogueCode);
check($productOptions === array() && !$db->queries && !$productAjaxEnabled, 'No product read right means no catalogue read or exposed Ajax search');
$user->denied = array(); $conf->use_javascript_ajax = 1; $cataloguePost['product_search'] = "P' <&"; eval($catalogueCode);
check(array_keys($productOptions) === array(22, 21), 'A no-JS browser search works with Ajax enabled in Dolibarr and keeps the saved selection');
$call = $form->calls[0];
check($call[3] > 0 && $call[5] === "P' <&" && $call[6] === -1 && $call[8] === 1, 'Fallback delegates escaping, search and bounded results to native Form');
// The search action is a read-only form redisplay, before the mutation block.
$start = strpos($source, '$policyFormPosted ='); $end = strpos($source, 'if ($mode ===', $start);
$action = 'savepolicy'; $id = 1; $_SERVER['REQUEST_METHOD'] = 'POST'; $cataloguePost = array('search_products' => '1', 'token' => 'test-csrf');
eval(substr($source, $start, $end - $start));
check($action === '' && $mode === 'edit' && $policyFormPosted, 'Search cannot execute savepolicy and keeps submitted values for redisplay');
$cataloguePost += array('ref' => 'Pending ref', 'label' => 'Unsaved label', 'rate' => '42', 'policy_context' => 'general', 'policy_scope' => 'product', 'policy_effect' => 'sale', 'products' => array('22'));
$start = strpos($source, '$formValues = array();'); $end = strpos($source, '$policies = array();', $start);
eval(substr($source, $start, $end - $start));
check($formValues['ref'] === 'Pending ref' && $formValues['rate'] === '42' && $selectedProducts === array('22'), 'No-JS search preserves unsaved rule fields and product selections');
$nativeEndpoint = file_get_contents(DOL_DOCUMENT_ROOT.'/product/ajax/products.php');
check(strpos($nativeEndpoint, 'select_produits_list') !== false && strpos($nativeEndpoint, "GETPOST(\$htmlname") !== false && (strpos($nativeEndpoint, "GETPOST('outjson'") !== false || strpos($nativeEndpoint, "GETPOSTINT('outjson'") !== false), 'Pinned native product endpoint supports the query and JSON contract');
print 'Margin policy catalogue: '.($tests - $catalogueAssertionsStart)." assertions passed (80000 product fixture; SQL and search results simulated).\n";
