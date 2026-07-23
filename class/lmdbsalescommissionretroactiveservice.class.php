<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once __DIR__.'/lmdbsalescommissionlineservice.class.php';
require_once __DIR__.'/lmdbsalescommissiondueservice.class.php';
require_once __DIR__.'/lmdbsalescommissionproposalservice.class.php';
require_once __DIR__.'/lmdbsalescommissionproposaldispatchservice.class.php';
require_once __DIR__.'/lmdbsalescommissionproposalturnoverdispatchservice.class.php';
require_once __DIR__.'/lmdbsalescommissionobjectivearchiveservice.class.php';

/**
 * Retroactive signed proposal backfill service.
 */
class LmdbSalesCommissionRetroactiveService
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
	 * Backfill estimated and acquired commission tracking for proposals.
	 *
	 * @param int  $dateStart Start timestamp
	 * @param int  $dateEnd   End timestamp
	 * @param User $user      Triggering user
	 * @param int  $fkUser    Optional sales user filter
	 * @param int  $entity    Optional strict entity filter
	 * @return array{analysed:int, estimated_processed:int, processed:int, created:int, updated:int, existing:int, tracking:int, turnover_created:int, turnover_updated:int, turnover_defaulted:int, tiers_recalculated:int, objective_archives_updated:int, paid_due_anomalies:int, payable_detected:int, skipped_no_user:int, errors:int}
	 */
	public function backfillSignedProposals($dateStart, $dateEnd, $user, $fkUser = 0, $entity = 0)
	{
		$this->error = '';
		$this->errors = array();

		$stats = array(
			'analysed' => 0,
			'estimated_processed' => 0,
			'processed' => 0,
			'created' => 0,
			'updated' => 0,
			'existing' => 0,
			'tracking' => 0,
			'turnover_created' => 0,
			'turnover_updated' => 0,
			'turnover_defaulted' => 0,
			'tiers_recalculated' => 0,
			'objective_archives_updated' => 0,
			'paid_due_anomalies' => 0,
			'payable_detected' => 0,
			'skipped_no_user' => 0,
			'errors' => 0,
		);

		if ($dateStart <= 0 || $dateEnd <= 0 || $dateEnd < $dateStart) {
			$this->error = 'LmdbSalesCommissionsBackfillInvalidPeriod';
			$stats['errors']++;
			return $stats;
		}

		$lineService = new LmdbSalesCommissionLineService($this->db);
		$dispatchService = new LmdbSalesCommissionProposalDispatchService($this->db);
		$turnoverDispatchService = new LmdbSalesCommissionProposalTurnoverDispatchService($this->db);
		$proposalEntitySql = $this->getProposalEntitySql($entity);
		$affectedUsersByEntity = array();

		$sql = 'SELECT p.rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'propal AS p';
		$sql .= ' WHERE p.entity IN ('.$proposalEntitySql.')';
		$sql .= ' AND p.fk_statut = '.Propal::STATUS_VALIDATED;
		$sql .= ' AND p.date_signature IS NULL';
		$sql .= " AND p.date_valid >= '".$this->db->idate($dateStart)."'";
		$sql .= " AND p.date_valid <= '".$this->db->idate($dateEnd)."'";
		$sql .= ' ORDER BY p.date_valid ASC, p.rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$stats['errors']++;
			return $stats;
		}

		while (is_object($obj = $this->db->fetch_object($resql))) {
			$stats['analysed']++;

			$proposal = new Propal($this->db);
			if ($proposal->fetch((int) $obj->rowid) <= 0) {
				$stats['errors']++;
				$this->errors[] = $proposal->error ?: 'ErrorRecordNotFound';
				continue;
			}
			if (method_exists($proposal, 'fetch_lines')) {
				$proposal->fetch_lines();
			}

			$dispatches = $dispatchService->fetchForProposal((int) $proposal->id, (int) $proposal->entity);
			if ($dispatchService->error !== '') {
				$stats['errors']++;
				$this->error = $dispatchService->error;
				$this->errors = array_merge($this->errors, $dispatchService->errors);
				continue;
			}
			$turnoverDispatches = $turnoverDispatchService->fetchForProposal((int) $proposal->id, (int) $proposal->entity);
			if ($turnoverDispatchService->error !== '') {
				$stats['errors']++;
				$this->error = $turnoverDispatchService->error;
				$this->errors = array_merge($this->errors, $turnoverDispatchService->errors);
				continue;
			}
			$salesUserId = LmdbSalesCommissionProposalService::resolveSalesUserId($this->db, $proposal);
			if (empty($turnoverDispatches) && $salesUserId <= 0) {
				$stats['skipped_no_user']++;
				continue;
			}
			if ($fkUser > 0 && !$this->proposalMatchesUserFilter($dispatches, $turnoverDispatches, $salesUserId, $fkUser)) {
				continue;
			}

			$stats['estimated_processed']++;
			$hasCommissionLines = $this->proposalHasCommissionLines((int) $proposal->id, (int) $proposal->entity, LmdbSalesCommissionLineService::STATUS_ESTIMATED);
			if ($hasCommissionLines < 0) {
				$stats['errors']++;
				continue;
			}
			$result = $hasCommissionLines > 0
				? $lineService->syncTurnoverFromProposal($proposal, $user, false)
				: $lineService->estimateFromProposal($proposal, $user);
			if ($result < 0) {
				$stats['errors']++;
				$this->error = $lineService->error;
				$this->errors = array_merge($this->errors, $lineService->errors);
				dol_syslog(__METHOD__.': '.$lineService->error.' while estimating proposal '.$proposal->id, LOG_ERR);
				continue;
			}

			$stats['created'] += (int) $lineService->lastResult['created'];
			$stats['updated'] += (int) $lineService->lastResult['updated'];
			$stats['existing'] += (int) $lineService->lastResult['existing'];
			$stats['turnover_created'] += (int) $lineService->lastResult['turnover_created'];
			$stats['turnover_updated'] += (int) $lineService->lastResult['turnover_updated'];
			$stats['turnover_defaulted'] += (int) $lineService->lastResult['turnover_defaulted'];
			$stats['errors'] += (int) $lineService->lastResult['errors'];
		}
		$this->db->free($resql);

		$sql = 'SELECT p.rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'propal AS p';
		$sql .= ' WHERE p.entity IN ('.$proposalEntitySql.')';
		$sql .= ' AND p.fk_statut IN ('.Propal::STATUS_SIGNED.','.Propal::STATUS_BILLED.')';
		$sql .= ' AND p.date_signature IS NOT NULL';
		$sql .= " AND p.date_signature >= '".$this->db->idate($dateStart)."'";
		$sql .= " AND p.date_signature <= '".$this->db->idate($dateEnd)."'";
		$sql .= ' ORDER BY p.date_signature ASC, p.rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$stats['errors']++;
			return $stats;
		}

		$processedProposalIds = array();
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$stats['analysed']++;

			$proposal = new Propal($this->db);
			if ($proposal->fetch((int) $obj->rowid) <= 0) {
				$stats['errors']++;
				$this->errors[] = $proposal->error ?: 'ErrorRecordNotFound';
				continue;
			}
			if (method_exists($proposal, 'fetch_lines')) {
				$proposal->fetch_lines();
			}

			$dispatches = $dispatchService->fetchForProposal((int) $proposal->id, (int) $proposal->entity);
			if ($dispatchService->error !== '') {
				$stats['errors']++;
				$this->error = $dispatchService->error;
				$this->errors = array_merge($this->errors, $dispatchService->errors);
				continue;
			}
			$turnoverDispatches = $turnoverDispatchService->fetchForProposal((int) $proposal->id, (int) $proposal->entity);
			if ($turnoverDispatchService->error !== '') {
				$stats['errors']++;
				$this->error = $turnoverDispatchService->error;
				$this->errors = array_merge($this->errors, $turnoverDispatchService->errors);
				continue;
			}
			$salesUserId = LmdbSalesCommissionProposalService::resolveSalesUserId($this->db, $proposal);
			if (empty($turnoverDispatches) && $salesUserId <= 0) {
				$stats['skipped_no_user']++;
				continue;
			}
			if ($fkUser > 0 && !$this->proposalMatchesUserFilter($dispatches, $turnoverDispatches, $salesUserId, $fkUser)) {
				continue;
			}

			$stats['processed']++;
			$hasCommissionLines = $this->proposalHasCommissionLines((int) $proposal->id, (int) $proposal->entity, LmdbSalesCommissionLineService::STATUS_ACQUIRED);
			if ($hasCommissionLines < 0) {
				$stats['errors']++;
				continue;
			}
			$result = $hasCommissionLines > 0
				? $lineService->syncTurnoverFromProposal($proposal, $user, true)
				: $lineService->acquireFromProposal($proposal, $user);
			if ($result < 0) {
				$stats['errors']++;
				$this->error = $lineService->error;
				$this->errors = array_merge($this->errors, $lineService->errors);
				dol_syslog(__METHOD__.': '.$lineService->error.' for proposal '.$proposal->id, LOG_ERR);
				continue;
			}

			$stats['created'] += (int) $lineService->lastResult['created'];
			$stats['updated'] += (int) $lineService->lastResult['updated'];
			$stats['existing'] += (int) $lineService->lastResult['existing'];
			$stats['tracking'] += (int) $lineService->lastResult['tracking'];
			$stats['turnover_created'] += (int) $lineService->lastResult['turnover_created'];
			$stats['turnover_updated'] += (int) $lineService->lastResult['turnover_updated'];
			$stats['turnover_defaulted'] += (int) $lineService->lastResult['turnover_defaulted'];
			$stats['tiers_recalculated'] += (int) $lineService->lastResult['tiers_recalculated'];
			$stats['errors'] += (int) $lineService->lastResult['errors'];
			$processedProposalIds[] = (int) $proposal->id;
			$affectedUserIds = $this->getTurnoverBeneficiaryIds($turnoverDispatches, $salesUserId);
			foreach ($affectedUserIds as $affectedUserId) {
				$affectedUsersByEntity[(int) $proposal->entity][$affectedUserId] = $affectedUserId;
			}
		}
		$this->db->free($resql);

		if (!empty($processedProposalIds)) {
			$dueService = new LmdbSalesCommissionDueService($this->db);
			$payableDetected = $dueService->detectPayableDueDates($user, $processedProposalIds);
			if ($payableDetected < 0) {
				$stats['errors']++;
				$this->error = $dueService->error;
				$this->errors = array_merge($this->errors, $dueService->errors);
				dol_syslog(__METHOD__.': '.$dueService->error.' while detecting payable due dates after backfill', LOG_ERR);
			} else {
				$stats['payable_detected'] = $payableDetected;
			}
		}

		$archiveService = new LmdbSalesCommissionObjectiveArchiveService($this->db);
		foreach ($affectedUsersByEntity as $affectedEntity => $affectedUsers) {
			$archiveResult = $archiveService->refreshExistingArchives(array_values($affectedUsers), $dateStart, $dateEnd, $user, (int) $affectedEntity);
			if ($archiveResult < 0) {
				$stats['errors']++;
				$this->error = $archiveService->error;
				$this->errors = array_merge($this->errors, $archiveService->errors);
			} else {
				$stats['objective_archives_updated'] += $archiveResult;
			}
			$stats['paid_due_anomalies'] += $this->countPaidTierDueAnomalies(array_values($affectedUsers), $dateStart, $dateEnd, (int) $affectedEntity);
		}

		dol_syslog(__METHOD__.': analysed '.$stats['analysed'].' proposals, estimated '.$stats['estimated_processed'].' proposals, processed '.$stats['processed'].' signed proposals, created '.$stats['created'].' lines, updated '.$stats['updated'].' lines, turnover created '.$stats['turnover_created'].', turnover updated '.$stats['turnover_updated'].', archives updated '.$stats['objective_archives_updated'].', paid due anomalies '.$stats['paid_due_anomalies'], LOG_INFO);

		return $stats;
	}

	/**
	 * Check the optional backfill user filter against dispatch beneficiaries or legacy owner.
	 *
	 * @param array<int, LmdbSalesCommissionProposalDispatch>         $dispatches Commission dispatches
	 * @param array<int, LmdbSalesCommissionProposalTurnoverDispatch> $turnoverDispatches Turnover dispatches
	 * @param int                                                      $salesUserId Legacy sales user
	 * @param int                                                      $filterUserId Requested user
	 * @return bool
	 */
	private function proposalMatchesUserFilter(array $dispatches, array $turnoverDispatches, $salesUserId, $filterUserId)
	{
		if ($filterUserId <= 0) {
			return true;
		}
		foreach ($dispatches as $dispatch) {
			if ((int) $dispatch->fk_user === $filterUserId) {
				return true;
			}
		}
		foreach ($turnoverDispatches as $dispatch) {
			if ((int) $dispatch->fk_user === $filterUserId) {
				return true;
			}
		}
		if (empty($dispatches) && empty($turnoverDispatches)) {
			return $salesUserId === $filterUserId;
		}

		return false;
	}

	/**
	 * Resolve turnover beneficiaries used by objective archive refresh.
	 *
	 * @param array<int, LmdbSalesCommissionProposalTurnoverDispatch> $turnoverDispatches Turnover dispatches
	 * @param int                                                      $salesUserId Main sales user
	 * @return array<int>
	 */
	private function getTurnoverBeneficiaryIds(array $turnoverDispatches, $salesUserId)
	{
		$ids = array();
		foreach ($turnoverDispatches as $dispatch) {
			$userId = (int) $dispatch->fk_user;
			if ($userId > 0) {
				$ids[$userId] = $userId;
			}
		}
		if (empty($ids) && $salesUserId > 0) {
			$ids[$salesUserId] = $salesUserId;
		}

		return array_values($ids);
	}

	/**
	 * Count tier commissions whose preserved due schedule differs from the recalculated commission.
	 *
	 * Paid rows are never changed by the turnover backfill.
	 *
	 * @param array<int> $userIds Users
	 * @param int        $dateStart Start date
	 * @param int        $dateEnd End date
	 * @param int        $entity Entity
	 * @return int
	 */
	private function countPaidTierDueAnomalies(array $userIds, $dateStart, $dateEnd, $entity)
	{
		$userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
		if (empty($userIds)) {
			return 0;
		}
		$sql = 'SELECT l.rowid, l.commission_total, SUM(d.amount) AS due_amount';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d ON d.fk_commission_line = l.rowid AND d.entity = l.entity AND d.status <> '.LmdbSalesCommissionDueService::STATUS_CANCELLED;
		$sql .= ' WHERE l.entity = '.((int) $entity).' AND l.fk_user IN ('.implode(',', $userIds).')';
		$sql .= " AND l.source_type = 'tier_period' AND l.mode = 'tier'";
		$sql .= " AND l.date_acquired >= '".$this->db->idate($dateStart)."' AND l.date_acquired <= '".$this->db->idate($dateEnd)."'";
		$sql .= ' GROUP BY l.rowid, l.commission_total';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return 0;
		}
		$count = 0;
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$commission = (float) price2num($obj->commission_total, 'MT');
			$dueAmount = (float) price2num($obj->due_amount, 'MT');
			$difference = (float) price2num($dueAmount - $commission, 'MT');
			if ($difference != 0.0) {
				$count++;
			}
		}
		$this->db->free($resql);

		return $count;
	}

	/**
	 * Check whether the existing backfill has already produced commission data.
	 *
	 * Turnover-only synchronization is then used so the maintenance action cannot
	 * alter commissions or their due dates on subsequent runs.
	 *
	 * @param int $proposalId Proposal id
	 * @param int $entity     Entity id
	 * @param int $status     Line status
	 * @return int 1 when found, 0 when absent, -1 on SQL error
	 */
	private function proposalHasCommissionLines($proposalId, $entity, $status)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
		$sql .= ' WHERE entity = '.((int) $entity)." AND source_type = 'proposal' AND fk_source = ".((int) $proposalId);
		$sql .= " AND mode <> 'turnover' AND status = ".((int) $status).' LIMIT 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}
		$exists = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);

		return $exists ? 1 : 0;
	}

	/**
	 * Return proposal entity SQL list.
	 *
	 * @param int $entity Optional strict entity filter
	 * @return string
	 */
	private function getProposalEntitySql($entity)
	{
		if ($entity > 0) {
			return (string) ((int) $entity);
		}

		return $this->db->sanitize(getEntity('propal'));
	}
}
