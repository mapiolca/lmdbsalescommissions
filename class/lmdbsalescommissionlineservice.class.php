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
require_once __DIR__.'/lmdbsalescommissionproposaldispatchservice.class.php';
require_once __DIR__.'/lmdbsalescommissionproposalturnoverdispatchservice.class.php';

/**
 * Commission line service.
 */
class LmdbSalesCommissionLineService
{
	public const STATUS_ESTIMATED = 0;
	public const STATUS_ACQUIRED = 1;
	public const STATUS_CANCELLED = 6;
	public const STATUS_BLOCKED = 7;
	public const MODE_MARGIN = 'margin';
	public const MODE_TIER = 'tier';
	public const MODE_TRACKING = 'tracking';
	public const MODE_DISPATCH = 'dispatch';
	public const MODE_TURNOVER = 'turnover';

	/** @var DoliDB Database handler */
	private $db;

	/** @var string Error message */
	public $error = '';

	/** @var array<int, string> Error list */
	public $errors = array();

	/** @var array{created:int, updated:int, existing:int, tracking:int, turnover_created:int, turnover_updated:int, turnover_defaulted:int, tiers_recalculated:int, errors:int} Last acquisition result counters */
	public $lastResult = array('created' => 0, 'updated' => 0, 'existing' => 0, 'tracking' => 0, 'turnover_created' => 0, 'turnover_updated' => 0, 'turnover_defaulted' => 0, 'tiers_recalculated' => 0, 'errors' => 0);

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
	 * Store estimated commission lines from a validated proposal.
	 *
	 * @param object $proposal Proposal object
	 * @param User   $user     Triggering user
	 * @return int Number of created lines, -1 on error
	 */
	public function estimateFromProposal($proposal, $user)
	{
		$this->resetLastResult();

		if (!is_object($proposal) || empty($proposal->id)) {
			$this->error = 'LmdbSalesCommissionsInvalidProposal';
			$this->lastResult['errors']++;
			return -1;
		}
		$this->fetchProposalLinesIfNeeded($proposal);

		$entity = !empty($proposal->entity) ? (int) $proposal->entity : 1;
		$businessDate = LmdbSalesCommissionProposalService::getValidationDate($proposal);
		if ($businessDate <= 0) {
			$businessDate = dol_now();
		}
		$created = 0;
		$dispatchService = new LmdbSalesCommissionProposalDispatchService($this->db);
		$dispatches = $dispatchService->fetchForProposal((int) $proposal->id, $entity);
		if ($dispatchService->error !== '') {
			$this->error = $dispatchService->error;
			$this->lastResult['errors']++;
			return -1;
		}
		if (!empty($dispatches)) {
			$result = $this->processDispatches($proposal, $user, $dispatches, $businessDate, self::STATUS_ESTIMATED, false);
			if ($result < 0) {
				return -1;
			}
			$created += $result;
		} else {
			if ($this->cancelDispatchEstimatedProposalLines($proposal, $user) < 0) {
				$this->lastResult['errors']++;
				return -1;
			}

			$salesUserId = LmdbSalesCommissionProposalService::resolveSalesUserId($this->db, $proposal);
			if ($salesUserId > 0) {
				$resolver = new LmdbSalesCommissionRuleResolver($this->db);
				$profile = $resolver->resolveForUser($salesUserId, $businessDate, $entity, 'proposal');
				if (!empty($profile['errors'])) {
					$this->errors = $profile['errors'];
					$this->lastResult['errors']++;
					return -1;
				}

				$margin = LmdbSalesCommissionProposalService::getEstimatedMargin($proposal);
				if (isset($profile['selected']['margin']) && is_array($profile['selected']['margin']) && $margin !== null) {
					$rule = $profile['selected']['margin'];
					$totalHt = property_exists($proposal, 'total_ht') && is_numeric($proposal->total_ht) ? (float) price2num($proposal->total_ht, 'MT') : 0.0;
					$commissionableMargin = $this->calculateCommissionableMargin($proposal, $salesUserId, $margin);
					if ($commissionableMargin === null) {
						$this->lastResult['errors']++;
						return -1;
					}
					$base = max(0, $commissionableMargin);
					$rate = (float) ($rule['rate'] ?? 0);
					$result = $this->upsertProposalLine($proposal, $user, $salesUserId, $entity, self::MODE_MARGIN, $totalHt, $commissionableMargin, $rate, (float) price2num($base * $rate / 100, 'MT'), $rule, $businessDate, self::STATUS_ESTIMATED, false);
					if ($result < 0) {
						return -1;
					}
					$created += $result;
				}
			}
		}

		$result = $this->processTurnoverContributions($proposal, $user, $businessDate, self::STATUS_ESTIMATED, false);
		if ($result < 0) {
			return -1;
		}

		return $created + $result;
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
		$this->resetLastResult();

		if (!is_object($proposal) || empty($proposal->id)) {
			$this->error = 'LmdbSalesCommissionsInvalidProposal';
			$this->lastResult['errors']++;
			return -1;
		}
		$this->fetchProposalLinesIfNeeded($proposal);

		$entity = !empty($proposal->entity) ? (int) $proposal->entity : 1;
		$businessDate = LmdbSalesCommissionProposalService::getSignatureDate($proposal);
		if ($businessDate <= 0) {
			$businessDate = dol_now();
		}
		$created = 0;
		$dispatchService = new LmdbSalesCommissionProposalDispatchService($this->db);
		$dispatches = $dispatchService->fetchForProposal((int) $proposal->id, $entity);
		if ($dispatchService->error !== '') {
			$this->error = $dispatchService->error;
			$this->lastResult['errors']++;
			return -1;
		}
		if (!empty($dispatches)) {
			$result = $this->processDispatches($proposal, $user, $dispatches, $businessDate, self::STATUS_ACQUIRED, true);
			if ($result < 0) {
				return -1;
			}
			$created += $result;
		} else {
			$salesUserId = LmdbSalesCommissionProposalService::resolveSalesUserId($this->db, $proposal);
			if ($salesUserId > 0) {
				$resolver = new LmdbSalesCommissionRuleResolver($this->db);
				$profile = $resolver->resolveForUser($salesUserId, $businessDate, $entity, 'proposal');
				if (!empty($profile['errors'])) {
					$this->errors = $profile['errors'];
					$this->lastResult['errors']++;
					return -1;
				}

				$margin = LmdbSalesCommissionProposalService::getEstimatedMargin($proposal);
				$totalHt = property_exists($proposal, 'total_ht') && is_numeric($proposal->total_ht) ? (float) price2num($proposal->total_ht, 'MT') : 0.0;
				$hasCommissionRule = false;

				if (isset($profile['selected']['margin']) && is_array($profile['selected']['margin'])) {
					$hasCommissionRule = true;
				}
				if (isset($profile['selected']['margin']) && is_array($profile['selected']['margin']) && $margin !== null) {
					$rule = $profile['selected']['margin'];
					$commissionableMargin = $this->calculateCommissionableMargin($proposal, $salesUserId, $margin);
					if ($commissionableMargin === null) {
						$this->lastResult['errors']++;
						return -1;
					}
					$base = max(0, $commissionableMargin);
					$rate = (float) ($rule['rate'] ?? 0);
					$result = $this->upsertProposalLine($proposal, $user, $salesUserId, $entity, self::MODE_MARGIN, $totalHt, $commissionableMargin, $rate, (float) price2num($base * $rate / 100, 'MT'), $rule, $businessDate, self::STATUS_ACQUIRED, true);
					if ($result < 0) {
						$this->lastResult['errors']++;
						return -1;
					}
					$created += $result;
				}

				if (isset($profile['selected']['tier']) && is_array($profile['selected']['tier'])) {
					$hasCommissionRule = true;
				}

				if (!$hasCommissionRule) {
					$result = $this->createTrackingLineIfMissing($proposal, $user, $salesUserId, $entity, $totalHt, $margin, $businessDate);
					if ($result < 0) {
						$this->lastResult['errors']++;
						return -1;
					}
					$created += $result;
				}
			}
		}

		$result = $this->processTurnoverContributions($proposal, $user, $businessDate, self::STATUS_ACQUIRED, true);
		if ($result < 0) {
			return -1;
		}
		$created += $result;

		if ($this->cancelRemainingEstimatedProposalLines($proposal, $user) < 0) {
			$this->lastResult['errors']++;
			return -1;
		}

		return $created;
	}

	/**
	 * Synchronize only turnover contributions without changing commission lines or dues.
	 *
	 * @param object $proposal Proposal
	 * @param User   $user     Triggering user
	 * @param bool   $acquired True for a signed proposal, false for an estimate
	 * @return int Number of created turnover contributions, -1 on error
	 */
	public function syncTurnoverFromProposal($proposal, $user, $acquired = true)
	{
		$this->resetLastResult();
		if (!is_object($proposal) || empty($proposal->id)) {
			$this->error = 'LmdbSalesCommissionsInvalidProposal';
			$this->lastResult['errors']++;
			return -1;
		}
		$this->fetchProposalLinesIfNeeded($proposal);
		$businessDate = $acquired ? LmdbSalesCommissionProposalService::getSignatureDate($proposal) : LmdbSalesCommissionProposalService::getValidationDate($proposal);
		if ($businessDate <= 0) {
			$businessDate = dol_now();
		}

		return $this->processTurnoverContributions(
			$proposal,
			$user,
			$businessDate,
			$acquired ? self::STATUS_ACQUIRED : self::STATUS_ESTIMATED,
			$acquired
		);
	}

	/**
	 * Create dedicated turnover contribution lines for objectives and tiers.
	 *
	 * @param object $proposal        Proposal
	 * @param User   $user            User
	 * @param int    $businessDate    Business date
	 * @param int    $status          Estimated or acquired status
	 * @param bool   $requireComplete Require a complete explicit allocation
	 * @return int
	 */
	private function processTurnoverContributions($proposal, $user, $businessDate, $status, $requireComplete)
	{
		$service = new LmdbSalesCommissionProposalTurnoverDispatchService($this->db);
		$allocations = $service->resolveForProposal($proposal, $requireComplete);
		if (!is_array($allocations)) {
			if ((int) $status === self::STATUS_ESTIMATED && $service->error === 'LmdbSalesCommissionsProposalWithoutSalesUser') {
				return 0;
			}
			$this->error = $service->error;
			$this->errors = $service->errors;
			$this->lastResult['errors']++;
			return -1;
		}

		$entity = (int) $proposal->entity;
		$keptUsers = array();
		$tierUsers = array();
		if ((int) $status === self::STATUS_ACQUIRED) {
			$tierUsers = $this->fetchTurnoverUsersForProposal((int) $proposal->id, (int) $proposal->entity);
			if ($this->error !== '') {
				return -1;
			}
		}
		$created = 0;
		$defaultCounted = false;
		foreach ($allocations as $allocation) {
			$amount = (float) price2num($allocation['amount'], 'MT');
			if ($amount <= 0) {
				continue;
			}
			$userId = (int) $allocation['user_id'];
			$keptUsers[] = $userId;

			$resolver = new LmdbSalesCommissionRuleResolver($this->db);
			$profile = $resolver->resolveForUser($userId, $businessDate, $entity, 'proposal');
			if (!empty($profile['errors'])) {
				$this->errors = $profile['errors'];
				$this->error = $profile['errors'][0];
				$this->lastResult['errors']++;
				return -1;
			}
			$tierRule = isset($profile['selected']['tier']) && is_array($profile['selected']['tier']) ? $profile['selected']['tier'] : null;
			$ruleId = is_array($tierRule) ? (int) $tierRule['rule_id'] : 0;
			$existingId = $this->fetchTurnoverLineId($entity, $userId, (int) $proposal->id);
			if ($existingId < 0) {
				return -1;
			}

			$line = new LmdbSalesCommissionLine($this->db);
			if ($existingId > 0 && $line->fetch($existingId) <= 0) {
				$this->error = $line->error;
				$this->errors = $line->errors;
				return -1;
			}
			if ($existingId > 0 && (int) $line->status === self::STATUS_ACQUIRED && (int) $status === self::STATUS_ESTIMATED) {
				$this->lastResult['existing']++;
				continue;
			}

			$line->entity = $entity;
			$line->fk_user = $userId;
			$line->fk_soc = property_exists($proposal, 'socid') ? (int) $proposal->socid : null;
			$line->source_type = 'proposal';
			$line->fk_source = (int) $proposal->id;
			$line->source_ref = property_exists($proposal, 'ref') ? (string) $proposal->ref : '';
			$line->mode = self::MODE_TURNOVER;
			$line->amount_base = $amount;
			$line->margin_base = null;
			$line->rate = null;
			$line->fk_tier = null;
			$line->commission_total = 0.0;
			$line->payable_total = 0.0;
			$line->paid_total = 0.0;
			$line->status = (int) $status;
			$line->date_acquired = $businessDate;
			$line->fk_rule = $ruleId;
			$line->fk_payment_term = null;
			$line->fk_proposal_dispatch = null;
			$line->fk_proposal_turnover_dispatch = $allocation['dispatch_id'] > 0 ? (int) $allocation['dispatch_id'] : null;
			$line->rule_source = is_array($tierRule) ? (string) $tierRule['source'] : ($allocation['is_default'] ? 'automatic' : self::MODE_TURNOVER);
			$line->snapshot_rule_label = is_array($tierRule) ? (string) $tierRule['rule_label'] : 'LmdbSalesCommissionsTurnoverContribution';
			$line->snapshot_rule_rate = null;
			$line->snapshot_base_type = LmdbSalesCommissionProposalDispatchService::BASE_TURNOVER;
			$line->snapshot_value_type = (string) $allocation['value_type'];
			$line->snapshot_value = (float) $allocation['value'];

			$result = $existingId > 0 ? $line->update($user) : $line->create($user);
			if ($result <= 0) {
				$this->error = $line->error;
				$this->errors = $line->errors;
				return -1;
			}
			if ($existingId > 0) {
				$this->lastResult['updated']++;
				$this->lastResult['turnover_updated']++;
			} else {
				$created++;
				$this->lastResult['created']++;
				$this->lastResult['turnover_created']++;
			}
			if ($allocation['is_default'] && !$defaultCounted) {
				$this->lastResult['turnover_defaulted']++;
				$defaultCounted = true;
			}
			$tierUsers[$userId] = $userId;
		}

		if ($this->cancelStaleTurnoverProposalLines($proposal, $user, $keptUsers) < 0) {
			$this->lastResult['errors']++;
			return -1;
		}
		if ((int) $status === self::STATUS_ACQUIRED && $this->cancelLegacyTierProposalLines($proposal, $user) < 0) {
			$this->lastResult['errors']++;
			return -1;
		}

		if ((int) $status === self::STATUS_ACQUIRED && !empty($tierUsers)) {
			require_once __DIR__.'/lmdbsalescommissiontierservice.class.php';
			$tierService = new LmdbSalesCommissionTierService($this->db);
			foreach ($tierUsers as $tierUserId) {
				$tierResult = $tierService->calculateForUser($tierUserId, $user, $businessDate, $entity);
				if (isset($tierResult['status']) && $tierResult['status'] === 'error') {
					$this->error = $tierService->error;
					$this->errors = $tierService->errors;
					$this->lastResult['errors']++;
					return -1;
				}
				if (isset($tierResult['status']) && $tierResult['status'] === 'ok') {
					$this->lastResult['tiers_recalculated']++;
				}
			}
		}

		return $created;
	}

	/**
	 * Cancel removed turnover estimates or contributions.
	 *
	 * @param object     $proposal  Proposal
	 * @param User       $user      User
	 * @param array<int> $keptUsers Kept user ids
	 * @return int
	 */
	private function cancelStaleTurnoverProposalLines($proposal, $user, array $keptUsers)
	{
		$keptUsers = array_values(array_unique(array_filter(array_map('intval', $keptUsers))));
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'lmdbsalescommissions_line SET status = '.self::STATUS_CANCELLED.', fk_user_modif = '.((int) $user->id);
		$sql .= ' WHERE entity = '.((int) $proposal->entity)." AND source_type = 'proposal' AND fk_source = ".((int) $proposal->id);
		$sql .= " AND mode = '".self::MODE_TURNOVER."'";
		if (!empty($keptUsers)) {
			$sql .= ' AND fk_user NOT IN ('.implode(',', $keptUsers).')';
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Cancel legacy proposal tier bases after dedicated turnover contributions exist.
	 *
	 * Period tier bonus lines remain active and are recalculated from turnover mode.
	 *
	 * @param object $proposal Proposal
	 * @param User   $user     User
	 * @return int
	 */
	private function cancelLegacyTierProposalLines($proposal, $user)
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
		$sql .= ' SET status = '.self::STATUS_CANCELLED.', fk_user_modif = '.((int) $user->id);
		$sql .= ' WHERE entity = '.((int) $proposal->entity)." AND source_type = 'proposal' AND fk_source = ".((int) $proposal->id);
		$sql .= " AND mode = '".self::MODE_TIER."' AND status <> ".self::STATUS_CANCELLED;
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
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
		$turnoverUsers = array();
		$sql = 'SELECT fk_user, date_acquired FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
		$sql .= ' WHERE entity = '.$entity." AND source_type = 'proposal' AND fk_source = ".((int) $proposal->id);
		$sql .= " AND mode = '".self::MODE_TURNOVER."' AND status = ".self::STATUS_ACQUIRED;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$turnoverUsers[(int) $obj->fk_user] = !empty($obj->date_acquired) ? (int) $this->db->jdate($obj->date_acquired) : dol_now();
		}
		$this->db->free($resql);

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
		if (!empty($turnoverUsers)) {
			require_once __DIR__.'/lmdbsalescommissiontierservice.class.php';
			$tierService = new LmdbSalesCommissionTierService($this->db);
			foreach ($turnoverUsers as $turnoverUserId => $turnoverDate) {
				$result = $tierService->calculateForUser((int) $turnoverUserId, $user, (int) $turnoverDate, $entity);
				if (isset($result['status']) && $result['status'] === 'error') {
					$this->error = $tierService->error;
					$this->errors = $tierService->errors;
					return -1;
				}
			}
		}

		return 1;
	}

	/**
	 * Calculate and persist manual dispatch commission lines.
	 *
	 * @param object                                             $proposal     Proposal
	 * @param User                                               $user         Triggering user
	 * @param array<int, LmdbSalesCommissionProposalDispatch>    $dispatches   Dispatch rows
	 * @param int                                                $businessDate Business date
	 * @param int                                                $status       Estimated or acquired status
	 * @param bool                                               $generateDues Generate payment due dates
	 * @return int Number of created lines, -1 on error
	 */
	private function processDispatches($proposal, $user, array $dispatches, $businessDate, $status, $generateDues)
	{
		$dispatchService = new LmdbSalesCommissionProposalDispatchService($this->db);
		$created = 0;
		$keptUsers = array();
		foreach ($dispatches as $dispatch) {
			$calculation = $dispatchService->calculate($dispatch, $proposal, $businessDate);
			if (!is_array($calculation)) {
				$this->error = $dispatchService->error;
				$this->errors = $dispatchService->errors;
				$this->lastResult['errors']++;
				return -1;
			}

			$result = $this->upsertDispatchLine($proposal, $user, $dispatch, $calculation, $businessDate, $status, $generateDues);
			if ($result < 0) {
				$this->lastResult['errors']++;
				return -1;
			}
			$created += $result;
			$keptUsers[] = (int) $dispatch->fk_user;
		}

		if ((int) $status === self::STATUS_ESTIMATED) {
			if ($this->cancelStaleEstimatedProposalLines($proposal, $user, $keptUsers) < 0) {
				$this->lastResult['errors']++;
				return -1;
			}
		} elseif ($this->cancelRemainingEstimatedProposalLines($proposal, $user) < 0) {
			$this->lastResult['errors']++;
			return -1;
		}

		return $created;
	}

	/**
	 * Create or update one manual dispatch commission line.
	 *
	 * @param object                                          $proposal     Proposal
	 * @param User                                            $user         Triggering user
	 * @param LmdbSalesCommissionProposalDispatch             $dispatch     Dispatch row
	 * @param array{turnover:float,margin:float|null,base:float,commission:float,rate:float|null,payment_term_id:int,payment_term_label:string} $calculation Calculated values
	 * @param int                                             $businessDate Business date
	 * @param int                                             $status       Target status
	 * @param bool                                            $generateDues Generate due dates
	 * @return int
	 */
	private function upsertDispatchLine($proposal, $user, $dispatch, array $calculation, $businessDate, $status, $generateDues)
	{
		$entity = (int) $dispatch->entity;
		$beneficiaryId = (int) $dispatch->fk_user;
		$existingId = $this->fetchLineId($entity, $beneficiaryId, (int) $proposal->id, self::MODE_DISPATCH, 0);
		if ($existingId < 0) {
			return -1;
		}

		$line = new LmdbSalesCommissionLine($this->db);
		if ($existingId > 0 && $line->fetch($existingId) <= 0) {
			$this->error = $line->error;
			$this->errors = $line->errors;
			return -1;
		}
		if ($existingId > 0 && !in_array((int) $line->status, array(self::STATUS_ESTIMATED, self::STATUS_CANCELLED), true)) {
			$this->lastResult['existing']++;
			return 0;
		}

		$line->entity = $entity;
		$line->fk_user = $beneficiaryId;
		$line->fk_soc = property_exists($proposal, 'socid') ? (int) $proposal->socid : null;
		$line->source_type = 'proposal';
		$line->fk_source = (int) $proposal->id;
		$line->source_ref = property_exists($proposal, 'ref') ? (string) $proposal->ref : '';
		$line->mode = self::MODE_DISPATCH;
		$line->amount_base = (float) price2num($calculation['turnover'], 'MT');
		$line->margin_base = $calculation['margin'] !== null ? (float) price2num($calculation['margin'], 'MT') : null;
		$line->rate = $calculation['rate'];
		$line->fk_tier = null;
		$line->commission_total = (float) price2num($calculation['commission'], 'MT');
		$line->payable_total = 0.0;
		$line->paid_total = 0.0;
		$line->status = (int) $status;
		$line->date_acquired = $businessDate;
		$line->fk_rule = 0;
		$line->fk_payment_term = $calculation['payment_term_id'] > 0 ? (int) $calculation['payment_term_id'] : null;
		$line->fk_proposal_dispatch = !empty($dispatch->id) ? (int) $dispatch->id : (int) $dispatch->rowid;
		$line->fk_proposal_turnover_dispatch = null;
		$line->rule_source = self::MODE_DISPATCH;
		$line->snapshot_rule_label = 'LmdbSalesCommissionsManualDispatch';
		$line->snapshot_rule_rate = $calculation['rate'];
		$line->snapshot_base_type = (string) $dispatch->base_type;
		$line->snapshot_value_type = (string) $dispatch->value_type;
		$line->snapshot_value = (float) $dispatch->value;

		$result = $existingId > 0 ? $line->update($user) : $line->create($user);
		if ($result <= 0) {
			$this->error = $line->error;
			$this->errors = $line->errors;
			return -1;
		}
		if ($existingId > 0) {
			$line->id = $existingId;
			$line->rowid = $existingId;
			$this->lastResult['updated']++;
		} else {
			$line->id = $result;
			$line->rowid = $result;
			$this->lastResult['created']++;
		}

		if ($generateDues && (int) $line->status === self::STATUS_ACQUIRED && (float) $line->commission_total > 0) {
			require_once __DIR__.'/lmdbsalescommissiondueservice.class.php';
			$dueService = new LmdbSalesCommissionDueService($this->db);
			if ($dueService->generateForLine($line, $user) < 0) {
				$this->error = $dueService->error;
				$this->errors = $dueService->errors;
				return -1;
			}
		}

		dol_syslog(__METHOD__.': dispatch commission line '.((int) $line->id).' for proposal '.((int) $proposal->id).' and user '.$beneficiaryId, LOG_INFO);

		return $existingId > 0 ? 0 : 1;
	}

	/**
	 * Create or update a proposal commission line.
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
	 * @param int                  $dateAcquired Acquisition or estimation date
	 * @param int                  $status       Target line status
	 * @param bool                 $generateDues Generate due dates when acquired
	 * @return int
	 */
	private function upsertProposalLine($proposal, $user, $salesUserId, $entity, $mode, $amountBase, $marginBase, $rate, $amount, array $rule, $dateAcquired, $status, $generateDues)
	{
		$existingId = $this->fetchLineId($entity, $salesUserId, (int) $proposal->id, $mode, (int) $rule['rule_id']);
		if ($existingId < 0) {
			return -1;
		}
		$line = new LmdbSalesCommissionLine($this->db);
		if ($existingId > 0 && $line->fetch($existingId) <= 0) {
			$this->error = $line->error;
			$this->errors = $line->errors;
			return -1;
		}
		if ($existingId > 0 && !in_array((int) $line->status, array(self::STATUS_ESTIMATED, self::STATUS_CANCELLED), true)) {
			$this->lastResult['existing']++;
			return 0;
		}

		$line->entity = $entity;
		$line->fk_user = $salesUserId;
		$line->fk_soc = property_exists($proposal, 'socid') ? (int) $proposal->socid : null;
		$line->source_type = 'proposal';
		$line->fk_source = (int) $proposal->id;
		$line->source_ref = property_exists($proposal, 'ref') ? (string) $proposal->ref : '';
		$line->mode = $mode;
		$line->amount_base = (float) price2num($amountBase, 'MT');
		$line->margin_base = $marginBase !== null ? (float) price2num($marginBase, 'MT') : null;
		$line->rate = $rate;
		$line->fk_tier = null;
		$line->commission_total = (float) price2num($amount, 'MT');
		$line->payable_total = 0.0;
		$line->paid_total = 0.0;
		$line->status = (int) $status;
		$line->date_acquired = $dateAcquired;
		$line->fk_rule = (int) $rule['rule_id'];
		$line->fk_payment_term = isset($rule['fk_payment_term']) ? (int) $rule['fk_payment_term'] : null;
		$line->fk_proposal_dispatch = null;
		$line->fk_proposal_turnover_dispatch = null;
		$line->rule_source = (string) $rule['source'];
		$line->snapshot_rule_label = (string) $rule['rule_label'];
		$line->snapshot_rule_rate = $rate;
		$line->snapshot_base_type = null;
		$line->snapshot_value_type = null;
		$line->snapshot_value = null;

		$result = $existingId > 0 ? $line->update($user) : $line->create($user);
		if ($result <= 0) {
			$this->error = $line->error;
			$this->errors = $line->errors;
			return -1;
		}

		if ($existingId > 0) {
			$line->id = $existingId;
			$line->rowid = $existingId;
			$this->lastResult['updated']++;
		} else {
			$line->id = $result;
			$line->rowid = $result;
			$this->lastResult['created']++;
		}

		if ($generateDues && (int) $line->status === self::STATUS_ACQUIRED && (float) $line->commission_total > 0) {
			require_once __DIR__.'/lmdbsalescommissiondueservice.class.php';
			$dueService = new LmdbSalesCommissionDueService($this->db);
			if ($dueService->generateForLine($line, $user) < 0) {
				$this->error = $dueService->error;
				$this->errors = $dueService->errors;
				return -1;
			}
		}

		$actionLabel = (int) $status === self::STATUS_ESTIMATED ? 'estimated' : 'acquired';
		dol_syslog(__METHOD__.': '.$actionLabel.' '.$mode.' commission line '.(int) $line->id.' for proposal '.$proposal->id, LOG_INFO);

		return $existingId > 0 ? 0 : 1;
	}

	/**
	 * Create a zero-commission tracking line if no rule applies.
	 *
	 * @param object     $proposal     Proposal object
	 * @param User       $user         Triggering user
	 * @param int        $salesUserId  Sales user id
	 * @param int        $entity       Entity id
	 * @param float      $amountBase   Source amount
	 * @param float|null $marginBase   Margin base
	 * @param int        $dateAcquired Acquisition date
	 * @return int
	 */
	private function createTrackingLineIfMissing($proposal, $user, $salesUserId, $entity, $amountBase, $marginBase, $dateAcquired)
	{
		$existingId = $this->fetchLineId($entity, $salesUserId, (int) $proposal->id, self::MODE_TRACKING, 0);
		if ($existingId !== 0) {
			if ($existingId < 0) {
				return -1;
			}
			$this->lastResult['existing']++;
			$this->lastResult['tracking']++;
			return 0;
		}

		$line = new LmdbSalesCommissionLine($this->db);
		$line->entity = $entity;
		$line->fk_user = $salesUserId;
		$line->fk_soc = property_exists($proposal, 'socid') ? (int) $proposal->socid : null;
		$line->source_type = 'proposal';
		$line->fk_source = (int) $proposal->id;
		$line->source_ref = property_exists($proposal, 'ref') ? (string) $proposal->ref : '';
		$line->mode = self::MODE_TRACKING;
		$line->amount_base = (float) price2num($amountBase, 'MT');
		$line->margin_base = $marginBase !== null ? (float) price2num($marginBase, 'MT') : null;
		$line->rate = null;
		$line->fk_tier = null;
		$line->commission_total = 0.0;
		$line->payable_total = 0.0;
		$line->paid_total = 0.0;
		$line->status = self::STATUS_ACQUIRED;
		$line->date_acquired = $dateAcquired;
		$line->fk_rule = 0;
		$line->fk_payment_term = null;
		$line->fk_proposal_dispatch = null;
		$line->fk_proposal_turnover_dispatch = null;
		$line->rule_source = 'none';
		$line->snapshot_rule_label = 'LmdbSalesCommissionsTrackingWithoutRule';
		$line->snapshot_rule_rate = null;
		$line->snapshot_base_type = null;
		$line->snapshot_value_type = null;
		$line->snapshot_value = null;

		$result = $line->create($user);
		if ($result <= 0) {
			$this->error = $line->error;
			$this->errors = $line->errors;
			return -1;
		}

		dol_syslog(__METHOD__.': acquired tracking line '.$result.' for proposal '.$proposal->id, LOG_INFO);

		$this->lastResult['created']++;
		$this->lastResult['tracking']++;

		return 1;
	}

	/**
	 * Resolve the margin attributable to a commission beneficiary.
	 *
	 * @param object $proposal Proposal
	 * @param int    $userId   Beneficiary user id
	 * @param float  $margin   Full proposal margin
	 * @return float|null Commissionable margin, or null on error
	 */
	private function calculateCommissionableMargin($proposal, $userId, $margin)
	{
		$service = new LmdbSalesCommissionProposalTurnoverDispatchService($this->db);
		$result = $service->calculateCommissionableMarginForUser($proposal, $userId, $margin);
		if ($result === null) {
			$this->error = $service->error;
			$this->errors = $service->errors;
			return null;
		}

		return $result;
	}

	/**
	 * Reset last service counters.
	 *
	 * @return void
	 */
	private function resetLastResult()
	{
		$this->error = '';
		$this->errors = array();
		$this->lastResult = array('created' => 0, 'updated' => 0, 'existing' => 0, 'tracking' => 0, 'turnover_created' => 0, 'turnover_updated' => 0, 'turnover_defaulted' => 0, 'tiers_recalculated' => 0, 'errors' => 0);
	}

	/**
	 * Load proposal lines when the trigger object does not already carry them.
	 *
	 * @param object $proposal Proposal object
	 * @return void
	 */
	private function fetchProposalLinesIfNeeded($proposal)
	{
		if (method_exists($proposal, 'fetch_lines') && (!property_exists($proposal, 'lines') || !is_array($proposal->lines) || empty($proposal->lines))) {
			$proposal->fetch_lines();
		}
	}

	/**
	 * Cancel proposal estimate lines that were not converted to acquired lines.
	 *
	 * @param object $proposal Proposal object
	 * @param User   $user     Triggering user
	 * @return int
	 */
	private function cancelRemainingEstimatedProposalLines($proposal, $user)
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
		$sql .= ' AND status = '.self::STATUS_ESTIMATED;

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Cancel dispatch estimates when the proposal has returned to automatic calculation.
	 *
	 * @param object $proposal Proposal
	 * @param User   $user     User
	 * @return int
	 */
	private function cancelDispatchEstimatedProposalLines($proposal, $user)
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
		$sql .= " AND mode = '".self::MODE_DISPATCH."'";
		$sql .= ' AND status = '.self::STATUS_ESTIMATED;
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Cancel automatic and removed-beneficiary estimates after manual synchronization.
	 *
	 * @param object     $proposal Proposal
	 * @param User       $user     User
	 * @param array<int> $keptUsers Current beneficiary ids
	 * @return int
	 */
	private function cancelStaleEstimatedProposalLines($proposal, $user, array $keptUsers)
	{
		$entity = !empty($proposal->entity) ? (int) $proposal->entity : 1;
		$keptUsers = array_values(array_unique(array_filter(array_map('intval', $keptUsers))));
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
		$sql .= ' SET status = '.self::STATUS_CANCELLED.', fk_user_modif = '.((int) $user->id);
		$sql .= ' WHERE entity = '.$entity;
		$sql .= " AND source_type = 'proposal'";
		$sql .= ' AND fk_source = '.((int) $proposal->id);
		$sql .= ' AND status = '.self::STATUS_ESTIMATED;
		$sql .= " AND (mode <> '".self::MODE_DISPATCH."'";
		if (!empty($keptUsers)) {
			$sql .= ' OR fk_user NOT IN ('.implode(',', $keptUsers).')';
		}
		$sql .= ')';
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Fetch an existing turnover contribution independently from its snapshotted tier rule.
	 *
	 * @param int $entity     Entity id
	 * @param int $userId     User id
	 * @param int $proposalId Proposal id
	 * @return int
	 */
	private function fetchTurnoverLineId($entity, $userId, $proposalId)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
		$sql .= ' WHERE entity = '.((int) $entity).' AND fk_user = '.((int) $userId);
		$sql .= " AND source_type = 'proposal' AND fk_source = ".((int) $proposalId);
		$sql .= " AND mode = '".self::MODE_TURNOVER."' ORDER BY rowid DESC LIMIT 1";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return is_object($obj) ? (int) $obj->rowid : 0;
	}

	/**
	 * Fetch current turnover beneficiaries before stale rows are cancelled.
	 *
	 * @param int $proposalId Proposal id
	 * @param int $entity     Entity id
	 * @return array<int, int>
	 */
	private function fetchTurnoverUsersForProposal($proposalId, $entity)
	{
		$users = array();
		$sql = 'SELECT DISTINCT fk_user FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
		$sql .= ' WHERE entity = '.((int) $entity)." AND source_type = 'proposal' AND fk_source = ".((int) $proposalId);
		$sql .= " AND mode = '".self::MODE_TURNOVER."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $users;
		}
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$userId = (int) $obj->fk_user;
			if ($userId > 0) {
				$users[$userId] = $userId;
			}
		}
		$this->db->free($resql);

		return $users;
	}

	/**
	 * Fetch an existing proposal commission line id.
	 *
	 * @param int    $entity      Entity id
	 * @param int    $salesUserId User id
	 * @param int    $proposalId  Proposal id
	 * @param string $mode        Commission mode
	 * @param int    $ruleId      Rule id
	 * @return int Positive rowid if found, 0 if not found, -1 on error
	 */
	private function fetchLineId($entity, $salesUserId, $proposalId, $mode, $ruleId)
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
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		$rowid = is_object($obj) ? (int) $obj->rowid : 0;
		$this->db->free($resql);

		return $rowid;
	}
}
