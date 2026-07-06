<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

require_once __DIR__.'/lmdbsalescommissionline.class.php';
require_once __DIR__.'/lmdbsalescommissionproposalservice.class.php';
require_once __DIR__.'/lmdbsalescommissionruleresolver.class.php';

/**
 * Commission line service.
 */
class LmdbSalesCommissionLineService
{
	public const STATUS_ESTIMATED = 0;
	public const STATUS_ACQUIRED = 1;
	public const STATUS_CANCELLED = 6;
	public const STATUS_BLOCKED = 7;

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
	 * Acquire commission lines from a signed proposal.
	 *
	 * @param object $proposal Proposal object
	 * @param User   $user     Triggering user
	 * @return int Number of created lines, -1 on error
	 */
	public function acquireFromProposal($proposal, $user)
	{
		$this->error = '';
		$this->errors = array();

		if (!is_object($proposal) || empty($proposal->id)) {
			$this->error = 'LmdbSalesCommissionsInvalidProposal';
			return -1;
		}

		$salesUserId = LmdbSalesCommissionProposalService::getSalesUserId($proposal);
		if ($salesUserId <= 0) {
			$this->error = 'LmdbSalesCommissionsProposalWithoutSalesUser';
			return 0;
		}

		$entity = !empty($proposal->entity) ? (int) $proposal->entity : 1;
		$resolver = new LmdbSalesCommissionRuleResolver($this->db);
		$profile = $resolver->resolveForUser($salesUserId, dol_now(), $entity, 'proposal');
		if (!empty($profile['errors'])) {
			$this->errors = $profile['errors'];
			return -1;
		}

		$created = 0;
		$margin = LmdbSalesCommissionProposalService::getEstimatedMargin($proposal);
		$totalHt = property_exists($proposal, 'total_ht') && is_numeric($proposal->total_ht) ? (float) $proposal->total_ht : 0.0;

		if (isset($profile['selected']['margin']) && is_array($profile['selected']['margin']) && $margin !== null) {
			$rule = $profile['selected']['margin'];
			$base = max(0, $margin);
			$rate = (float) ($rule['rate'] ?? 0);
			$created += $this->createLineIfMissing($proposal, $user, $salesUserId, $entity, 'margin', $totalHt, $margin, $rate, $base * $rate / 100, $rule);
		}

		if (isset($profile['selected']['tier']) && is_array($profile['selected']['tier'])) {
			$rule = $profile['selected']['tier'];
			$created += $this->createLineIfMissing($proposal, $user, $salesUserId, $entity, 'tier', $totalHt, null, null, 0.0, $rule);
			require_once __DIR__.'/lmdbsalescommissiontierservice.class.php';
			$tierService = new LmdbSalesCommissionTierService($this->db);
			$tierService->calculateForUser($salesUserId, $user, dol_now(), $entity);
		}

		return $created;
	}

	/**
	 * Cancel commission lines linked to a proposal.
	 *
	 * @param object $proposal Proposal object
	 * @param User   $user     Triggering user
	 * @return int
	 */
	public function cancelProposalLines($proposal, $user)
	{
		if (!is_object($proposal) || empty($proposal->id)) {
			return 0;
		}

		$entity = !empty($proposal->entity) ? (int) $proposal->entity : 1;
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
		$sql .= ' SET status = '.self::STATUS_CANCELLED.', fk_user_modif = '.((int) $user->id);
		$sql .= ' WHERE entity = '.$entity;
		$sql .= " AND source_type = 'proposal'";
		$sql .= ' AND fk_source = '.((int) $proposal->id);
		$sql .= ' AND status <> '.self::STATUS_CANCELLED;

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Create line if the unique business key does not exist yet.
	 *
	 * @param object               $proposal    Proposal object
	 * @param User                 $user        Triggering user
	 * @param int                  $salesUserId Sales user id
	 * @param int                  $entity      Entity id
	 * @param string               $mode        Commission mode
	 * @param float                $amountBase  Source amount
	 * @param float|null           $marginBase  Margin base
	 * @param float|null           $rate        Rate
	 * @param float                $amount      Commission amount
	 * @param array<string, mixed> $rule        Resolved rule
	 * @return int
	 */
	private function createLineIfMissing($proposal, $user, $salesUserId, $entity, $mode, $amountBase, $marginBase, $rate, $amount, array $rule)
	{
		if ($this->lineExists($entity, $salesUserId, (int) $proposal->id, $mode, (int) $rule['rule_id'])) {
			return 0;
		}

		$line = new LmdbSalesCommissionLine($this->db);
		$line->entity = $entity;
		$line->fk_user = $salesUserId;
		$line->fk_soc = property_exists($proposal, 'socid') ? (int) $proposal->socid : null;
		$line->source_type = 'proposal';
		$line->fk_source = (int) $proposal->id;
		$line->source_ref = property_exists($proposal, 'ref') ? (string) $proposal->ref : '';
		$line->mode = $mode;
		$line->amount_base = $amountBase;
		$line->margin_base = $marginBase;
		$line->rate = $rate;
		$line->fk_tier = null;
		$line->commission_total = $amount;
		$line->payable_total = 0;
		$line->paid_total = 0;
		$line->status = self::STATUS_ACQUIRED;
		$line->date_acquired = dol_now();
		$line->fk_rule = (int) $rule['rule_id'];
		$line->fk_payment_term = isset($rule['fk_payment_term']) ? (int) $rule['fk_payment_term'] : null;
		$line->rule_source = (string) $rule['source'];
		$line->snapshot_rule_label = (string) $rule['rule_label'];
		$line->snapshot_rule_rate = $rate;

		$result = $line->create($user);
		if ($result <= 0) {
			$this->error = $line->error;
			$this->errors = $line->errors;
			return -1;
		}

		if ($amount > 0) {
			require_once __DIR__.'/lmdbsalescommissiondueservice.class.php';
			$line->id = $result;
			$dueService = new LmdbSalesCommissionDueService($this->db);
			if ($dueService->generateForLine($line, $user) < 0) {
				$this->error = $dueService->error;
				$this->errors = $dueService->errors;
				return -1;
			}
		}

		dol_syslog(__METHOD__.': acquired '.$mode.' commission line '.$result.' for proposal '.$proposal->id, LOG_INFO);

		return 1;
	}

	/**
	 * Check if a line already exists.
	 *
	 * @param int    $entity      Entity id
	 * @param int    $salesUserId User id
	 * @param int    $proposalId  Proposal id
	 * @param string $mode        Commission mode
	 * @param int    $ruleId      Rule id
	 * @return bool
	 */
	private function lineExists($entity, $salesUserId, $proposalId, $mode, $ruleId)
	{
		$sql = 'SELECT rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
		$sql .= ' WHERE entity = '.((int) $entity);
		$sql .= ' AND fk_user = '.((int) $salesUserId);
		$sql .= " AND source_type = 'proposal'";
		$sql .= ' AND fk_source = '.((int) $proposalId);
		$sql .= " AND mode = '".$this->db->escape($mode)."'";
		$sql .= ' AND fk_rule = '.((int) $ruleId);
		$sql .= ' LIMIT 1';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return true;
		}

		$exists = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);

		return $exists;
	}
}
