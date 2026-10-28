<?php
/** Native FormMargin on selected proposal lines; database lookups are simulated. */
class Categorie {
	public const TYPE_PRODUCT = 'product';
	public $MAP_ID = array('product' => 0);
	public function __construct($db) {}
}
require __DIR__.'/margin_service_test.php';

$categoryEnabled = true;
$db = new PolicyDb();
$db->visibleProducts = array(101, 202, 303);
$db->productCategories = array(array('fk_product' => 202, 'fk_categorie' => 77));
$service = new PolicyService($db);
$proposal = new PolicyProposal();
$proposal->lines = array(
	(object) array('id' => 1, 'fk_product' => 101, 'total_ht' => 125.0, 'subprice' => 125.0, 'pa_ht' => 100.0, 'qty' => 1.0, 'remise_percent' => 0, 'product_type' => 0, 'fk_product_type' => 0),
	(object) array('id' => 2, 'fk_product' => 202, 'total_ht' => 160.0, 'subprice' => 160.0, 'pa_ht' => 100.0, 'qty' => 1.0, 'remise_percent' => 0, 'product_type' => 0, 'fk_product_type' => 0),
	(object) array('id' => 3, 'fk_product' => 303, 'total_ht' => 120.0, 'subprice' => 120.0, 'pa_ht' => 100.0, 'qty' => 1.0, 'remise_percent' => 0, 'product_type' => 1, 'fk_product_type' => 1),
);
$proposal->total_ht = 405.0;
$product = policy(101, 'general', 'sale', 50.0);
$product['scope'] = 'product'; $product['products'] = array(101, 202); $product['categories'] = array();
$category = policy(102, 'general', 'sale', 50.0);
$category['scope'] = 'category'; $category['categories'] = array(77, 88); $category['products'] = array();
$offer = policy(103, 'general', 'sale', 15.0);
$offer['scope'] = 'offer'; $offer['products'] = $offer['categories'] = array();
$service->rules = array(7 => array($product, $category, $offer));
$decision = $service->assess($proposal)[7];
expect($decision['sale'], 'deny', 'product threshold controls the two specified products together');
expect(count($decision['checks']), 2, 'category and offer are shadowed for claimed lines');
expect($decision['checks'][0]['target_lines'], array(1, 2), 'multiple products share one targeted margin');
expect($decision['checks'][1]['target_lines'], array(3), 'offer applies to the remaining service line');
expect($decision['checks'][1]['state'], 'allow', 'remaining line margin is evaluated independently');

$service->rules[7][0]['products'] = array(101);
$decision = $service->assess($proposal)[7];
expect(count($decision['checks']), 3, 'product, category and offer each control their own lines');
expect($decision['checks'][0]['target_lines'], array(1), 'product claims its matching line');
expect($decision['checks'][1]['target_lines'], array(2), 'category claims its matching line');
expect($decision['checks'][2]['target_lines'], array(3), 'offer receives only unclaimed line');
expect($decision['checks'][1]['state'], 'allow', 'category uses selected line margin of 60 percent');
$db->productCategories[] = array('fk_product' => 303, 'fk_categorie' => 88);
$decision = $service->assess($proposal)[7];
expect(count($decision['checks']), 2, 'one rule pools lines from several selected categories');
expect($decision['checks'][1]['target_lines'], array(2, 3), 'multiple categories select both products and services');
expect($decision['checks'][1]['state'], 'deny', 'category threshold uses pooled 40 percent margin');
$db->productCategories = array(array('fk_product' => 202, 'fk_categorie' => 77));
$decision = $service->assess($proposal)[7];
$before = $decision['fingerprint'];
$db->productCategories = array();
$decision = $service->assess($proposal)[7];
expect($decision['fingerprint'] === $before, false, 'native category membership changes the approval fingerprint');
expect($decision['checks'][1]['target_lines'], array(2, 3), 'offer inherits line when category membership is removed');

$db->productCategories = array(array('fk_product' => 202, 'fk_categorie' => 77), array('fk_product' => 202, 'fk_categorie' => 88));
$otherCategory = policy(104, 'general', 'sale', 10.0);
$otherCategory['scope'] = 'category'; $otherCategory['categories'] = array(88); $otherCategory['products'] = array();
$service->rules[7][] = $otherCategory;
$decision = $service->assess($proposal)[7];
expect($decision['sale'], 'unknown', 'overlapping equal-rank category rules cannot silently win');
expect($decision['checks'][1]['reason'], 'conflict', 'category overlap is reported');

$service->rules[7][0]['products'] = array(101, 202);
$decision = $service->assess($proposal)[7];
expect($decision['sale'], 'deny', 'product rule outranks conflicting category rules');
expect(count($decision['checks']), 2, 'shadowed category rules are not evaluated');

$categoryEnabled = false;
try { $service->assess($proposal); throw new RuntimeException('Disabled category module accepted'); }
catch (RuntimeException $error) { expect($error->getMessage(), 'LscPolicyUnavailable', 'category rules fail closed when native Categories is unavailable'); }
print "Scoped margin controls: $tests total assertions passed.\n";
