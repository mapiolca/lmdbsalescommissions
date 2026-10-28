<?php
/* Behavioral tests, independent of a Dolibarr instance. */
require_once __DIR__.'/../class/lmdbsalescommissionmarginengine.class.php';
$tests = 0;
function expect($actual, $expected, $name) {
	global $tests; $tests++;
	if ($actual !== $expected) { throw new RuntimeException($name.': '.var_export($actual, true).' != '.var_export($expected, true)); }
}
function policy($id, $context, $effect, $threshold, $rank = 3, $bands = array()) {
	return array('rule_id' => $id, 'context' => $context, 'effect' => $effect, 'threshold' => $threshold, 'rank' => $rank, 'origin' => 'fixture', 'bands' => $bands);
}
function band($lo, $hi, $threshold, $inclusive = 0, $storageLo = null, $storageHi = null, $storageInclusive = 0) {
	return array('kwc_min' => $lo, 'kwc_max' => $hi, 'kwc_inclusive' => $inclusive, 'kwh_min' => $storageLo, 'kwh_max' => $storageHi, 'kwh_inclusive' => $storageInclusive, 'threshold' => $threshold);
}
$general = array(policy(1, 'general', 'sale', 30.0), policy(2, 'general', 'commission', 60.0));
expect(LmdbSalesCommissionMarginEngine::evaluate($general, (1.76 - 1.1) / 1.1 * 100, null, null)['commission'], 'allow', 'decimal equality is sufficient despite floating point noise');
expect(LmdbSalesCommissionMarginEngine::evaluate($general, 59.99999999, null, null)['commission'], 'deny', 'a genuinely lower rate stays below target');
foreach (array(array(25.0,'deny','deny'),array(40.0,'allow','deny'),array(65.0,'allow','allow'),array(30.0,'allow','deny'),array(60.0,'allow','allow'),array(null,'unknown','unknown')) as $case) {
	$r = LmdbSalesCommissionMarginEngine::evaluate($general, $case[0], null, null);
	expect($r['sale'], $case[1], 'sale without PV dependency'); expect($r['commission'], $case[2], 'commission independent from sale');
}
expect(LmdbSalesCommissionMarginEngine::evaluate(array(), null, null, null)['sale'], 'allow', 'no policy retains legacy');
$bands = array(band(1.0, 3.0, 80.0, 1), band(3.0, 4.5, 60.0));
expect(LmdbSalesCommissionMarginEngine::validBands($bands, 'pv'), true, 'adjacent open/closed bands');
expect(LmdbSalesCommissionMarginEngine::validBands(array($bands[0],band(3.0, 4.5, 60.0, 1)), 'pv'), false, 'shared inclusive boundary rejected');
$pv = policy(3, 'pv', 'commission', null, 1, $bands);
foreach (array(array(3.0,'deny'),array(3.00000001,'allow'),array(4.5,'allow'),array(4.50000001,'unknown'),array(0.999,'unknown')) as $case) {
	expect(LmdbSalesCommissionMarginEngine::evaluate(array($pv), 65.0, $case[0], 0.0)['commission'], $case[1], 'exact kWc boundary');
}
$mix = policy(4, 'mixed', 'commission', null, 3, array(band(0.0, 9.0, 110.0, 0, 0.0, 10.0), band(9.0, null, 90.0, 0, 0.0, 10.0)));
expect(LmdbSalesCommissionMarginEngine::evaluate(array($mix), 110.0, 6.0, 7.0)['commission'], 'allow', '>100% accepted');
expect(LmdbSalesCommissionMarginEngine::evaluate(array($mix), 100.0, 6.0, 7.0)['commission'], 'deny', '2D target');
expect(LmdbSalesCommissionMarginEngine::evaluate(array($mix), 200.0, 6.0, 11.0)['commission'], 'unknown', 'outside storage axis');
expect(LmdbSalesCommissionMarginEngine::evaluate(array($pv, $mix), 100.0, 3.0, 7.0)['commission'], 'deny', 'mixed only, no PV fallback');
expect(LmdbSalesCommissionMarginEngine::evaluate(array($pv), 65.0, 3.0, null)['commission'], 'unknown', 'absent storage is not zero');
expect(LmdbSalesCommissionMarginEngine::evaluate(array($pv), 65.0, 0.0, 0.0)['commission'], 'allow', 'explicit zero means non-technical');
$storage = policy(5,'storage','commission',null,3,array(band(null,null,70.0,0,0.0,10.0)));
expect(LmdbSalesCommissionMarginEngine::evaluate(array($storage),70.0,0.0,10.0)['commission'],'allow','useful storage boundary');
expect(LmdbSalesCommissionMarginEngine::evaluate(array_merge($general,array($pv)),40.0,3.1,0.0)['sale'],'allow','technical target never blocks sale');
expect(LmdbSalesCommissionMarginEngine::evaluate(array_merge($general,array($pv)),65.0,3.0,0.0)['commission'],'deny','general and technical accumulate');
$specific = policy(6,'general','commission',20.0,1);
expect(LmdbSalesCommissionMarginEngine::evaluate(array_merge($general,array($specific)),25.0,0.0,0.0)['sale'],'deny','individual commission does not replace sale');
expect(LmdbSalesCommissionMarginEngine::evaluate(array_merge($general,array($specific)),25.0,0.0,0.0)['commission'],'allow','user beats default per effect');
expect(LmdbSalesCommissionMarginEngine::evaluate(array($specific,policy(7,'general','commission',5.0,1)),70.0,0.0,0.0)['commission'],'unknown','same-rank conflict');
expect(LmdbSalesCommissionMarginEngine::evaluate(array($specific,$specific),70.0,0.0,0.0)['commission'],'allow','same rule via multiple groups is not conflict');
expect(LmdbSalesCommissionMarginEngine::validBands(array(band(5.0,4.0,10.0)), 'pv'), false, 'inverted band');
$r=LmdbSalesCommissionMarginEngine::evaluate($general,25.0,null,null);
$r['checks'][0]['state']='allow'; $r['checks'][0]['reason']='approved';
$r=LmdbSalesCommissionMarginEngine::aggregate($r);
expect($r['sale'],'allow','sale approval'); expect($r['commission'],'deny','sale approval never pays commission');
$travelPolicy = policy(8, 'general', 'sale', 50.0);
$travelPolicy['travel_bands'] = array(array('metric' => 'minutes', 'min_value' => 105.0, 'uplift' => 10.0));
expect(LmdbSalesCommissionMarginEngine::validTravelBands($travelPolicy['travel_bands']), true, 'valid journey threshold');
foreach (array(array(105.0, 'allow', 50.0), array(106.0, 'deny', 60.0), array(120.0, 'deny', 60.0)) as $case) {
	$decision = LmdbSalesCommissionMarginEngine::evaluate(array($travelPolicy), 50.0, null, null, array('minutes' => $case[0], 'kilometres' => null));
	expect($decision['sale'], $case[1], 'round-trip threshold is strict');
	expect($decision['checks'][0]['threshold'], $case[2], 'uplift adds percentage points');
}
expect(LmdbSalesCommissionMarginEngine::evaluate(array($travelPolicy), 60.0, null, null, array('minutes' => 106.0, 'kilometres' => null))['sale'], 'allow', 'effective threshold is accepted');
expect(LmdbSalesCommissionMarginEngine::evaluate(array($travelPolicy), 60.0, null, null)['sale'], 'unknown', 'missing route cannot waive uplift');
$travelPolicy['travel_bands'] = array(array('metric' => 'kilometres', 'min_value' => 80.0, 'uplift' => 10.0), array('metric' => 'kilometres', 'min_value' => 150.0, 'uplift' => 20.0));
expect(LmdbSalesCommissionMarginEngine::evaluate(array($travelPolicy), 65.0, null, null, array('minutes' => null, 'kilometres' => 151.0))['checks'][0]['threshold'], 70.0, 'highest crossed distance tier wins');
expect(LmdbSalesCommissionMarginEngine::validTravelBands(array(array('metric' => 'minutes', 'min_value' => 105.0, 'uplift' => 10.0), array('metric' => 'kilometres', 'min_value' => 80.0, 'uplift' => 10.0))), false, 'one metric per policy');
expect(LmdbSalesCommissionMarginEngine::validTravelBands(array(array('metric' => 'minutes', 'min_value' => 0.0, 'uplift' => -0.5))), false, 'negative uplift rejected');
expect(LmdbSalesCommissionMarginEngine::validTravelBands(array(array('metric' => 'minutes', 'min_value' => 105.0, 'uplift' => 10.0), array('metric' => 'minutes', 'min_value' => 120.0, 'uplift' => 5.0))), false, 'higher tier cannot reduce required margin');
$complexPolicy = policy(9, 'general', 'sale', 100.0);
$complexPolicy['complex_site'] = array('uplift_without_travel' => 15.0, 'uplift_with_travel' => 20.0);
$complexPolicy['travel_bands'] = array(array('metric' => 'minutes', 'min_value' => 105.0, 'uplift' => 10.0));
$short = array('minutes' => 105.0, 'kilometres' => null);
$long = array('minutes' => 106.0, 'kilometres' => null);
foreach (array(array(false, $short, 100.0), array(true, $short, 115.0), array(false, $long, 110.0), array(true, $long, 130.0)) as $case) {
	$commissionPolicy = $complexPolicy; $commissionPolicy['effect'] = 'commission';
	$decision = LmdbSalesCommissionMarginEngine::evaluate(array($complexPolicy, $commissionPolicy), $case[2], null, null, $case[1], $case[0]);
	expect($decision['checks'][0]['threshold'], $case[2], 'complex and travel produce the exact effective threshold');
	expect($decision['sale'], 'allow', 'general sale threshold met');
	expect($decision['commission'], 'allow', 'general commission threshold met');
}
expect(LmdbSalesCommissionMarginEngine::evaluate(array($complexPolicy), 129.999, null, null, $long, true)['sale'], 'deny', 'combined margin remains strict below 130');
$complexPolicy['complex_site']['uplift_with_travel'] = null;
expect(LmdbSalesCommissionMarginEngine::evaluate(array($complexPolicy), 125.0, null, null, $long, true)['checks'][0]['threshold'], 125.0, 'omitted combined value defaults to standalone uplift');
expect(LmdbSalesCommissionMarginEngine::evaluate(array($complexPolicy), 100.0, null, null, $short, null)['sale'], 'unknown', 'missing qualification cannot be treated as OFF');
expect(LmdbSalesCommissionMarginEngine::evaluate(array($complexPolicy), 100.0, null, null, null, false)['sale'], 'unknown', 'missing route remains indeterminate with complex configuration');
unset($complexPolicy['travel_bands']);
expect(LmdbSalesCommissionMarginEngine::evaluate(array($complexPolicy), 115.0, null, null, null, true)['checks'][0]['threshold'], 115.0, 'complex uplift works without travel configuration');
$technicalComplex = policy(10, 'pv', 'commission', null, 3, array(band(0.0, null, 100.0)));
$technicalComplex['complex_site'] = array('uplift_without_travel' => 15.0, 'uplift_with_travel' => null);
$decision = LmdbSalesCommissionMarginEngine::evaluate(array($technicalComplex), 115.0, 4.0, 0.0, null, true);
expect($decision['sale'], 'allow', 'technical complex rule never blocks sale');
expect($decision['commission'], 'allow', 'technical complex rule adjusts commission threshold');
expect(LmdbSalesCommissionMarginEngine::validComplexSiteUplift(array('uplift_without_travel' => -1.0, 'uplift_with_travel' => null)), false, 'negative complex uplift rejected');
print "Margin engine: $tests assertions passed.\n";
