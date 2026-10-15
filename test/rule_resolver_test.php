<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
/** Regression tests with simulated SQL and the real module translation catalogs. */
define('MAIN_DB_PREFIX', 'regression_');
function dol_now() { return 1800000000; }
function dol_syslog($message, $level = 0) {}
class ResolverTranslations
{
 public $locale = 'fr_FR';
 public $values = array();
 public function load($catalog) {
  $this->values = array();
  foreach (file(dirname(__DIR__).'/langs/'.$this->locale.'/lmdbsalescommissions.lang', FILE_IGNORE_NEW_LINES) as $line) {
   if (strpos($line, '=') !== false) { list($key, $value) = explode('=', $line, 2); $this->values[$key] = $value; }
  }
 }
 public function trans($key, ...$args) {
  $value = $this->values[$key] ?? $key;
  return $args ? vsprintf($value, $args) : $value;
 }
}
class ResolverDatabase
{
 public $rules = array();
 public function idate($date) { return '2026-10-07 12:00:00'; }
 public function escape($value) { return str_replace("'", "''", $value); }
 public function query($sql) {
  $rows = strpos($sql, 'AS assignment_id') !== false ? $this->rules : array();
  if (preg_match("/r\.rule_type IN \(([^)]+)\)/", $sql, $matches)) {
   $types = array_map(static function ($type) { return trim($type, " '\t"); }, explode(',', $matches[1]));
   $rows = array_values(array_filter($rows, static function ($row) use ($types) { return in_array($row->rule_type, $types, true); }));
  }
  return (object) array('rows' => $rows);
 }
 public function fetch_object($result) { return array_shift($result->rows); }
 public function free($result) {}
}
function candidate($id, $type) {
 return (object) array('assignment_id'=>$id, 'assignment_type'=>'user', 'assignment_priority'=>0,
  'assignment_payment_term'=>null, 'rule_id'=>$id, 'rule_ref'=>'R'.$id, 'rule_label'=>'Rule '.$id,
  'rule_type'=>$type, 'source_type'=>'proposal', 'period_type'=>'monthly', 'rate'=>10,
  'fk_tier_grid'=>null, 'rule_payment_term'=>null, 'rule_priority'=>0);
}
$checks = 0;
function check($condition, $message) {
 global $checks;
 if (!$condition) { throw new RuntimeException($message); }
 $checks++;
}
$langs = new ResolverTranslations();
$conf = (object) array('entity'=>1);
$db = new ResolverDatabase();
$db->rules = array(candidate(1, 'margin'), candidate(2, 'margin_policy'), candidate(3, 'margin_policy'));
$before = shell_exec('git show main:class/lmdbsalescommissionruleresolver.class.php');
if (is_string($before) && strpos($before, 'class LmdbSalesCommissionRuleResolver') !== false) {
 eval(str_replace('class LmdbSalesCommissionRuleResolver', 'class ResolverBeforePatch', preg_replace('/^<\?php/', '', $before)));
 $profile = (new ResolverBeforePatch($db))->resolveForUser(10, 1800000000, 1, 'proposal');
 check($profile['errors'] === array('LmdbSalesCommissionsResolverConflict: margin_policy'), 'before: unrelated policies block commission calculation');
}
require_once dirname(__DIR__).'/class/lmdbsalescommissionruleresolver.class.php';
$resolver = new LmdbSalesCommissionRuleResolver($db);
$profile = $resolver->resolveForUser(10, 1800000000, 1, 'proposal');
check($profile['errors'] === array() && $profile['selected']['margin']['rule_id'] === 1 && count($profile['candidates']) === 1, 'policies excluded without removing commission');
$db->rules = array(candidate(2, 'margin_policy'), candidate(3, 'margin_policy'));
$profile = $resolver->resolveForUser(10, 1800000000, 1, 'proposal');
check($profile['errors'] === array() && $profile['selected'] === array(), 'policies alone do not invent a commission or conflict');
foreach (array('fr_FR', 'en_US') as $locale) {
 $langs->locale = $locale;
 foreach (array('margin', 'tier') as $type) {
  $db->rules = array(candidate(1, $type), candidate(2, $type));
  $profile = $resolver->resolveForUser(10, 1800000000, 1, 'proposal');
  $expected = $locale === 'fr_FR' ? 'Conflit de règles sans priorité suffisante' : 'Rule conflict without sufficient priority';
  check(count($profile['errors']) === 1 && strpos($profile['errors'][0], $expected) === 0
   && strpos($profile['errors'][0], 'LmdbSalesCommissions') === false && strpos($profile['errors'][0], '%s') === false,
   'real '.$type.' conflict remains blocked and translated in '.$locale);
 }
}
echo 'OK '.$checks.' assertions (simulated SQL, fr_FR/en_US catalogs)'.PHP_EOL;
