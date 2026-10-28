<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbsalescommissioncommon.class.php';

/**
 * Commission rule.
 */
class LmdbSalesCommissionRule extends LmdbSalesCommissionCommon
{
	public $TRIGGER_PREFIX = 'LMDBSALESCOMMISSIONS_RULE';
	/** @var string|null Margin policy context */
	public $policy_context;
	/** @var string|null sale, commission or both */
	public $policy_effect;
	/** @var string|null offer, category or product */
	public $policy_scope;
	/** @var string|null fixed or percentage */
	public $reward_mode;
	/** @var float|string|null Reward amount or rate */
	public $reward_value;
	public $element = 'lmdbsalescommissions_rule';
	public $table_element = 'lmdbsalescommissions_rule';

	/** Validate the new selection and every stored link before replacing margin targets.
	 * An editor without a complete catalogue view must not remove hidden relations.
	 * @param User $user Actor
	 * @param string $scope offer, category or product
	 * @param list<int> $categories Selected native category IDs
	 * @param list<int> $products Selected native product/service IDs
	 * @return int Positive when all targets are writable, negative on error
	 */
	public function validateMarginTargets($user, $scope, array $categories, array $products)
	{
		global $conf;
		$this->error = 'LscInvalidPolicyScope';
		if (!$user->hasRight('lmdbsalescommissions', 'admin', 'configure') || !$user->admin || !empty($user->socid)
			|| !in_array($scope, array('offer', 'category', 'product'), true)
			|| ($scope === 'category' && !$categories) || ($scope === 'product' && !$products)
			|| ($scope !== 'category' && $categories) || ($scope !== 'product' && $products)) { return -1; }
		$targets = array('category' => $categories, 'product' => $products);
		if ((int) $this->id > 0) {
			if ((int) $this->entity !== (int) $conf->entity) { return -1; }
			foreach (array('category', 'product') as $type) {
				$q = $this->db->query('SELECT fk_'.$type.' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_'.$type.' WHERE entity = '.((int) $this->entity).' AND fk_rule = '.((int) $this->id));
				if (!$q) { $this->error = 'LscPolicyUnavailable'; return -1; }
				while (is_object($row = $this->db->fetch_object($q))) { $targets[$type][] = (int) $row->{'fk_'.$type}; }
				$this->db->free($q);
			}
		}
		foreach ($targets as $type => $ids) {
			foreach ($ids as $targetId) { if (!is_int($targetId) || $targetId <= 0) { return -1; } }
			$ids = array_values(array_unique($ids));
			if (!$ids) { continue; }
			$this->error = (int) $this->id > 0 ? 'LscPolicyScopeInaccessible' : 'LscInvalidPolicyScope';
			if ($type === 'category') {
				if (!isModEnabled('category') || !$user->hasRight('categorie', 'lire')) { return -1; }
				if (!class_exists('Categorie', false)) { require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php'; }
				$nativeCategory = new Categorie($this->db);
				$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'categorie WHERE rowid IN ('.$this->db->sanitize(implode(',', $ids)).') AND type = '.((int) $nativeCategory->MAP_ID[Categorie::TYPE_PRODUCT]).' AND entity IN ('.$this->db->sanitize(getEntity('category')).')';
			} else {
				$types = array();
				if ($user->hasRight('produit', 'lire')) { $types[] = 0; }
				if ($user->hasRight('service', 'lire')) { $types[] = 1; }
				if (!$types) { return -1; }
				$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'product WHERE rowid IN ('.$this->db->sanitize(implode(',', $ids)).') AND entity IN ('.$this->db->sanitize(getEntity('product')).') AND fk_product_type IN ('.$this->db->sanitize(implode(',', $types)).')';
			}
			$q = $this->db->query($sql);
			if (!$q) { $this->error = 'LscPolicyUnavailable'; return -1; }
			$complete = $this->db->num_rows($q) === count($ids);
			$this->db->free($q);
			if (!$complete) { return -1; }
		}
		$this->error = '';
		return 1;
	}

	/** Delete an unused margin policy and its bands atomically; retain referenced policies.
	 * @param User $user Actor
	 * @param int $notrigger Disable native CRUD trigger
	 * @return int Positive on success, negative on failure
	 */
	public function delete($user, $notrigger = 0)
	{
		global $conf;
		if ($this->rule_type !== 'margin_policy') { return parent::delete($user, $notrigger); }
		if (!$user->hasRight('lmdbsalescommissions', 'admin', 'configure') || !$user->admin || !empty($user->socid)
			|| (int) $this->entity !== (int) $conf->entity || (int) $this->id <= 0) {
			$this->error = 'LscPolicyDeleteDenied'; return -1;
		}
		if (!$this->db->begin()) { $this->error = 'LscPolicyUnavailable'; return -1; }
		try {
			$where = 'entity = '.((int) $this->entity).' AND fk_rule = '.((int) $this->id);
			$lock = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.$this->table_element.' WHERE entity = '.((int) $this->entity).' AND rowid = '.((int) $this->id)." AND rule_type = 'margin_policy' FOR UPDATE");
			if (!$lock || !$this->db->num_rows($lock)) { throw new RuntimeException('LscPolicyUnavailable'); }
			$this->db->free($lock);
			foreach (array('rule_assignment', 'line', 'margin_approval', 'margin_request') as $table) {
				$result = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_'.$table.' WHERE '.$where.$this->db->plimit(1));
				if (!$result) { throw new RuntimeException('LscPolicyUnavailable'); }
				$used = $this->db->num_rows($result) > 0;
				$this->db->free($result);
				if ($used) { throw new RuntimeException('LscPolicyInUse'); }
			}
			if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_band WHERE '.$where)) { throw new RuntimeException('LscPolicyUnavailable'); }
			if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_travel_band WHERE '.$where)) { throw new RuntimeException('LscPolicyUnavailable'); }
			if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_complex_site WHERE '.$where)) { throw new RuntimeException('LscPolicyUnavailable'); }
			if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_category WHERE '.$where)) { throw new RuntimeException('LscPolicyUnavailable'); }
			if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_product WHERE '.$where)) { throw new RuntimeException('LscPolicyUnavailable'); }
			// CommonObject v20 derives a different code from the class name; use our stable CRUD prefix.
			if (parent::delete($user, 1) <= 0) { throw new RuntimeException('LscPolicyUnavailable'); }
			if (!$notrigger && $this->call_trigger($this->TRIGGER_PREFIX.'_DELETE', $user) < 0) { throw new RuntimeException('LscPolicyUnavailable'); }
			if (!$this->db->commit()) { throw new RuntimeException('LscPolicyUnavailable'); }
			return 1;
		} catch (Exception $e) {
			$this->db->rollback(); $this->error = $e->getMessage(); return -1;
		}
	}

	/** @var string|null Reference */
	public $ref;
	/** @var string|null Label */
	public $label;
	/** @var string|null Rule type: margin, tier, margin_policy or margin_excess */
	public $rule_type;
	/** @var float|string|null Commission rate */
	public $rate;
	/** @var int|string|null Tier grid id */
	public $fk_tier_grid;
	/** @var int|string|null Payment term id */
	public $fk_payment_term;
	/** @var string|null Source type */
	public $source_type;
	/** @var string|null Period type */
	public $period_type;
	/** @var int|string|null Cumulative flag */
	public $cumulative;
	/** @var int|string|null Priority */
	public $priority;
	/** @var int|string|null Active flag */
	public $active;
	/** @var int|string|null Start date timestamp/string */
	public $date_start;
	/** @var int|string|null End date timestamp/string */
	public $date_end;
	/** @var string|null Negative margin behavior */
	public $negative_margin_mode;
	/** @var string|null Description */
	public $description;
	/** @var string|null Private note */
	public $note_private;
	/** @var int|string|null Creation date */
	public $date_creation;
	/** @var int|string|null Timestamp */
	public $tms;

	/** @var array<string, array<string, mixed>> Field definitions */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => '1', 'visible' => 0, 'notnull' => 1, 'default' => '1', 'position' => 5),
		'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 10),
		'label' => array('type' => 'varchar(255)', 'label' => 'Label', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 20),
		'rule_type' => array('type' => 'varchar(32)', 'label' => 'Type', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 30),
		'reward_mode' => array('type' => 'varchar(16)', 'label' => 'LscRewardMode', 'enabled' => '1', 'visible' => 0),
		'reward_value' => array('type' => 'double(24,8)', 'label' => 'LscRewardValue', 'enabled' => '1', 'visible' => 0),
		'policy_context' => array('type' => 'varchar(16)', 'label' => 'LscPolicyContext', 'enabled' => '1', 'visible' => 0),
		'policy_effect' => array('type' => 'varchar(16)', 'label' => 'LscPolicyEffect', 'enabled' => '1', 'visible' => 0),
		'policy_scope' => array('type' => 'varchar(16)', 'label' => 'LscPolicyScope', 'enabled' => '1', 'visible' => 0, 'default' => 'offer'),
		'rate' => array('type' => 'double(10,4)', 'label' => 'Rate', 'enabled' => '1', 'visible' => 1, 'position' => 40),
		'fk_tier_grid' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsTierGrid', 'enabled' => '1', 'visible' => 1, 'position' => 50),
		'fk_payment_term' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsPaymentTerms', 'enabled' => '1', 'visible' => 1, 'position' => 55),
		'source_type' => array('type' => 'varchar(32)', 'label' => 'Source', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => 'proposal', 'position' => 60),
		'period_type' => array('type' => 'varchar(32)', 'label' => 'Period', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => 'monthly', 'position' => 70),
		'cumulative' => array('type' => 'integer', 'label' => 'Cumulative', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '1', 'position' => 80),
		'priority' => array('type' => 'integer', 'label' => 'Priority', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 90),
		'active' => array('type' => 'integer', 'label' => 'Active', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '1', 'position' => 100),
		'date_start' => array('type' => 'date', 'label' => 'DateStart', 'enabled' => '1', 'visible' => 1, 'position' => 110),
		'date_end' => array('type' => 'date', 'label' => 'DateEnd', 'enabled' => '1', 'visible' => 1, 'position' => 120),
		'negative_margin_mode' => array('type' => 'varchar(32)', 'label' => 'LmdbSalesCommissionsNegativeMarginMode', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => 'zero', 'position' => 130),
		'description' => array('type' => 'text', 'label' => 'Description', 'enabled' => '1', 'visible' => 1, 'position' => 140),
		'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => '1', 'visible' => 0, 'position' => 150),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 500),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'visible' => -2, 'noteditable' => 1, 'position' => 501),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => '1', 'visible' => -2, 'position' => 510),
		'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => '1', 'visible' => -2, 'position' => 520),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => '1', 'visible' => -2, 'position' => 530),
	);

	/** Validate the cross-field reward contract before every write.
	 * @return bool */
	private function validateReward()
	{
		if ($this->rule_type !== 'margin_excess') { return true; }
		require_once __DIR__.'/lmdbsalescommissionrewardservice.class.php';
		if (!is_numeric($this->reward_value) || !LmdbSalesCommissionRewardService::validValue((string) $this->reward_mode, (float) $this->reward_value) || $this->source_type !== 'proposal') {
			$this->setFieldError('reward_value', 'LscRewardInvalid');
			$this->error = 'LscRewardInvalid';
			return false;
		}
		$this->reward_value = price2num($this->reward_value, $this->reward_mode === 'fixed' ? 'MT' : '');
		if ((float) $this->reward_value <= 0) { $this->setFieldError('reward_value', 'LscRewardInvalid'); $this->error = 'LscRewardInvalid'; return false; }
		$this->cumulative = 1;
		$this->fk_payment_term = null;
		return true;
	}

	/** @param User $user Actor
	 * @param int $notrigger Disable triggers
	 * @return int */
	public function create($user, $notrigger = 0)
	{
		return $this->validateReward() ? parent::create($user, $notrigger) : -1;
	}

	/** @param User $user Actor
	 * @param int $notrigger Disable triggers
	 * @return int */
	public function update($user, $notrigger = 0)
	{
		return $this->validateReward() ? parent::update($user, $notrigger) : -1;
	}
}
