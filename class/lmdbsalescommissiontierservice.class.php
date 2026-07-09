<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

require_once __DIR__.'/lmdbsalescommissionline.class.php';
require_once __DIR__.'/lmdbsalescommissionlineservice.class.php';
require_once __DIR__.'/lmdbsalescommissionruleresolver.class.php';

/**
 * Tier bonus calculation service.
 */
class LmdbSalesCommissionTierService
{
	/** @var DoliDB Database handler */
	private $db;

	/** @var string Error message */
	public $error = '';

	/** @var array<int, string> Error list */
	public $errors = array();

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Calculate tier bonus for a user and date.
	 *
	 * @param int  $fkUser User id
	 * @param User $user   Triggering user
	 * @param int  $date   Timestamp, 0 for now
	 * @param int  $entity Entity id, 0 for current entity
	 * @return array<string, mixed>
	 */
	public function calculateForUser($fkUser, $user, $date = 0, $entity = 0)
	{
		global $conf;

		$this->error = '';
		$this->errors = array();

		$effectiveDate = $date > 0 ? $date : dol_now();
		$effectiveEntity = $entity > 0 ? $entity : (int) $conf->entity;
		$resolver = new LmdbSalesCommissionRuleResolver($this->db);
		$profile = $resolver->resolveForUser($fkUser, $effectiveDate, $effectiveEntity, 'proposal');
		if (!empty($profile['errors'])) {
			$this->errors = $profile['errors'];
			return array('status' => 'blocked', 'errors' => $this->errors);
		}
		if (empty($profile['selected']['tier']) || !is_array($profile['selected']['tier'])) {
			return array('status' => 'no_rule');
		}

		$rule = $profile['selected']['tier'];
		$period = $this->getPeriodBounds((string) $rule['period_type'], $effectiveDate);
		$turnover = $this->sumTurnover($fkUser, (int) $rule['rule_id'], $period['start'], $period['end'], $effectiveEntity);
		$tiers = $this->fetchTiers((int) $rule['fk_tier_grid'], $effectiveEntity);
		$reached = null;
		$next = null;
		foreach ($tiers as $tier) {
			if ($turnover >= $tier['threshold_amount']) {
				$reached = $tier;
				continue;
			}
			$next = $tier;
			break;
		}

		$bonus = is_array($reached) ? (float) price2num($reached['bonus_amount'], 'MT') : 0.0;
		$lineResult = $this->upsertPeriodLine($fkUser, $user, $effectiveEntity, $rule, $period, $turnover, $bonus, $reached, $effectiveDate);
		if ($lineResult < 0) {
			return array('status' => 'error', 'error' => $this->error);
		}

		return array(
			'status' => 'ok',
			'rule' => $rule,
			'period' => $period,
			'turnover' => $turnover,
			'reached_tier' => $reached,
			'next_tier' => $next,
			'remaining_to_next' => is_array($next) ? (float) price2num(max(0, (float) $next['threshold_amount'] - $turnover), 'MT') : 0.0,
			'bonus' => $bonus,
			'line_id' => $lineResult,
		);
	}

	/**
	 * Get period bounds.
	 *
	 * @param string $periodType Period type
	 * @param int    $date       Timestamp
	 * @return array{start:int, end:int, key:int, label:string}
	 */
	private function getPeriodBounds($periodType, $date)
	{
		$year = (int) dol_print_date($date, '%Y');
		$month = (int) dol_print_date($date, '%m');

		if ($periodType === 'yearly') {
			return array(
				'start' => dol_mktime(0, 0, 0, 1, 1, $year),
				'end' => dol_mktime(23, 59, 59, 12, 31, $year),
				'key' => $year,
				'label' => (string) $year,
			);
		}

		if ($periodType === 'quarterly') {
			$quarter = (int) ceil($month / 3);
			$startMonth = (($quarter - 1) * 3) + 1;
			$endMonth = $startMonth + 2;
			return array(
				'start' => dol_mktime(0, 0, 0, $startMonth, 1, $year),
				'end' => dol_mktime(23, 59, 59, $endMonth + 1, 0, $year),
				'key' => ($year * 10) + $quarter,
				'label' => $year.' Q'.$quarter,
			);
		}

		return array(
			'start' => dol_mktime(0, 0, 0, $month, 1, $year),
			'end' => dol_mktime(23, 59, 59, $month + 1, 0, $year),
			'key' => ($year * 100) + $month,
			'label' => sprintf('%04d-%02d', $year, $month),
		);
	}

	/**
	 * Sum signed turnover contributions.
	 *
	 * @param int $fkUser User id
	 * @param int $ruleId Rule id
	 * @param int $start  Start timestamp
	 * @param int $end    End timestamp
	 * @param int $entity Entity id
	 * @return float
	 */
	private function sumTurnover($fkUser, $ruleId, $start, $end, $entity)
	{
		$sql = 'SELECT SUM(amount_base) AS total';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
		$sql .= ' WHERE entity = '.((int) $entity);
		$sql .= ' AND fk_user = '.((int) $fkUser);
		$sql .= " AND source_type = 'proposal'";
		$sql .= " AND mode = 'tier'";
		$sql .= ' AND fk_rule = '.((int) $ruleId);
		$sql .= ' AND status = '.LmdbSalesCommissionLineService::STATUS_ACQUIRED;
		$sql .= " AND date_acquired >= '".$this->db->idate($start)."'";
		$sql .= " AND date_acquired <= '".$this->db->idate($end)."'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return 0.0;
		}

		$obj = $this->db->fetch_object($resql);
		$total = is_object($obj) ? (float) $obj->total : 0.0;
		$this->db->free($resql);

		return (float) price2num($total, 'MT');
	}

	/**
	 * Fetch active tiers for a grid.
	 *
	 * @param int $gridId Grid id
	 * @param int $entity Entity id
	 * @return array<int, array{rowid:int, threshold_amount:float, bonus_amount:float}>
	 */
	private function fetchTiers($gridId, $entity)
	{
		$tiers = array();
		if ($gridId <= 0) {
			return $tiers;
		}

		$sql = 'SELECT rowid, threshold_amount, bonus_amount';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_tier';
		$sql .= ' WHERE entity = '.((int) $entity);
		$sql .= ' AND fk_tier_grid = '.((int) $gridId);
		$sql .= ' AND active = 1';
		$sql .= ' ORDER BY threshold_amount ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $tiers;
		}

		while (is_object($obj = $this->db->fetch_object($resql))) {
			$tiers[] = array(
				'rowid' => (int) $obj->rowid,
				'threshold_amount' => (float) price2num($obj->threshold_amount, 'MT'),
				'bonus_amount' => (float) price2num($obj->bonus_amount, 'MT'),
			);
		}
		$this->db->free($resql);

		return $tiers;
	}

	/**
	 * Create or update period summary line.
	 *
	 * @param int                    $fkUser   User id
	 * @param User                   $user     Triggering user
	 * @param int                    $entity   Entity id
	 * @param array<string, mixed>   $rule     Resolved rule
	 * @param array<string, mixed>   $period   Period data
	 * @param float                  $turnover Turnover
	 * @param float                     $bonus         Bonus
	 * @param array<string, mixed>|null $tier          Reached tier
	 * @param int                       $dateAcquired  Acquisition date
	 * @return int
	 */
	private function upsertPeriodLine($fkUser, $user, $entity, array $rule, array $period, $turnover, $bonus, $tier, $dateAcquired)
	{
		$existingId = $this->fetchPeriodLineId($entity, $fkUser, (int) $period['key'], (int) $rule['rule_id']);
		$line = new LmdbSalesCommissionLine($this->db);
		if ($existingId > 0 && $line->fetch($existingId) <= 0) {
			$this->error = $line->error;
			return -1;
		}

		$line->entity = $entity;
		$line->fk_user = $fkUser;
		$line->fk_soc = null;
		$line->source_type = 'tier_period';
		$line->fk_source = (int) $period['key'];
		$line->source_ref = (string) $period['label'];
		$line->mode = 'tier';
		$line->amount_base = (float) price2num($turnover, 'MT');
		$line->margin_base = null;
		$line->rate = null;
		$line->fk_tier = is_array($tier) ? (int) $tier['rowid'] : null;
		$line->commission_total = (float) price2num($bonus, 'MT');
		$line->payable_total = 0.0;
		$line->paid_total = 0.0;
		$line->status = LmdbSalesCommissionLineService::STATUS_ACQUIRED;
		$line->date_acquired = $dateAcquired;
		$line->fk_rule = (int) $rule['rule_id'];
		$line->fk_payment_term = isset($rule['fk_payment_term']) ? (int) $rule['fk_payment_term'] : null;
		$line->rule_source = (string) $rule['source'];
		$line->snapshot_rule_label = (string) $rule['rule_label'];
		$line->snapshot_rule_rate = null;

		if ($existingId > 0) {
			$result = $line->update($user);
			if ($result <= 0) {
				return -1;
			}
			$this->generateDuesIfNeeded($line, $user);
			return $existingId;
		}

		$result = $line->create($user);
		if ($result > 0) {
			$line->id = $result;
			$this->generateDuesIfNeeded($line, $user);
		}

		return $result;
	}

	/**
	 * Generate due dates for a positive bonus line.
	 *
	 * @param LmdbSalesCommissionLine $line Commission line
	 * @param User                    $user User
	 * @return void
	 */
	private function generateDuesIfNeeded($line, $user)
	{
		if ((float) $line->commission_total <= 0) {
			return;
		}

		require_once __DIR__.'/lmdbsalescommissiondueservice.class.php';
		$dueService = new LmdbSalesCommissionDueService($this->db);
		$dueService->generateForLine($line, $user);
	}

	/**
	 * Fetch existing period line id.
	 *
	 * @param int $entity Entity id
	 * @param int $fkUser User id
	 * @param int $periodKey Period key
	 * @param int $ruleId Rule id
	 * @return int
	 */
	private function fetchPeriodLineId($entity, $fkUser, $periodKey, $ruleId)
	{
		$sql = 'SELECT rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
		$sql .= ' WHERE entity = '.((int) $entity);
		$sql .= ' AND fk_user = '.((int) $fkUser);
		$sql .= " AND source_type = 'tier_period'";
		$sql .= ' AND fk_source = '.((int) $periodKey);
		$sql .= " AND mode = 'tier'";
		$sql .= ' AND fk_rule = '.((int) $ruleId);
		$sql .= ' LIMIT 1';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return 0;
		}

		$obj = $this->db->fetch_object($resql);
		$id = is_object($obj) ? (int) $obj->rowid : 0;
		$this->db->free($resql);

		return $id;
	}
}
