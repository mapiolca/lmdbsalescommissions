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
	 * Backfill acquired commission tracking for signed proposals.
	 *
	 * @param int  $dateStart Start timestamp
	 * @param int  $dateEnd   End timestamp
	 * @param User $user      Triggering user
	 * @param int  $fkUser    Optional sales user filter
	 * @param int  $entity    Optional strict entity filter
	 * @return array{analysed:int, processed:int, created:int, existing:int, tracking:int, payable_detected:int, skipped_no_user:int, errors:int}
	 */
	public function backfillSignedProposals($dateStart, $dateEnd, $user, $fkUser = 0, $entity = 0)
	{
		$this->error = '';
		$this->errors = array();

		$stats = array(
			'analysed' => 0,
			'processed' => 0,
			'created' => 0,
			'existing' => 0,
			'tracking' => 0,
			'payable_detected' => 0,
			'skipped_no_user' => 0,
			'errors' => 0,
		);

		if ($dateStart <= 0 || $dateEnd <= 0 || $dateEnd < $dateStart) {
			$this->error = 'LmdbSalesCommissionsBackfillInvalidPeriod';
			$stats['errors']++;
			return $stats;
		}

		$sql = 'SELECT p.rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'propal AS p';
		$sql .= ' WHERE p.entity IN ('.$this->getProposalEntitySql($entity).')';
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

		$lineService = new LmdbSalesCommissionLineService($this->db);
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

			$salesUserId = LmdbSalesCommissionProposalService::resolveSalesUserId($this->db, $proposal);
			if ($salesUserId <= 0) {
				$stats['skipped_no_user']++;
				continue;
			}
			if ($fkUser > 0 && $salesUserId !== (int) $fkUser) {
				continue;
			}

			$stats['processed']++;
			$result = $lineService->acquireFromProposal($proposal, $user);
			if ($result < 0) {
				$stats['errors']++;
				$this->error = $lineService->error;
				$this->errors = array_merge($this->errors, $lineService->errors);
				dol_syslog(__METHOD__.': '.$lineService->error.' for proposal '.$proposal->id, LOG_ERR);
				continue;
			}

			$stats['created'] += (int) $lineService->lastResult['created'];
			$stats['existing'] += (int) $lineService->lastResult['existing'];
			$stats['tracking'] += (int) $lineService->lastResult['tracking'];
			$stats['errors'] += (int) $lineService->lastResult['errors'];
			$processedProposalIds[] = (int) $proposal->id;
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

		dol_syslog(__METHOD__.': analysed '.$stats['analysed'].' signed proposals, created '.$stats['created'].' lines, detected '.$stats['payable_detected'].' payable due dates', LOG_INFO);

		return $stats;
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
