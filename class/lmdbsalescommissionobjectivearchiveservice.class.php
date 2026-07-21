<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

require_once __DIR__.'/lmdbsalescommissionobjectivearchive.class.php';
require_once __DIR__.'/lmdbsalescommissionobjectiveresolver.class.php';
require_once __DIR__.'/lmdbsalescommissionturnoverservice.class.php';

/**
 * Archive objective achievement.
 */
class LmdbSalesCommissionObjectiveArchiveService
{
	public const STATUS_ACHIEVED = 1;
	public const STATUS_NOT_ACHIEVED = 2;
	public const STATUS_NO_OBJECTIVE = 3;
	public const STATUS_BLOCKED = 4;

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
	 * Archive objective for a user and period.
	 *
	 * @param int        $fkUser        User id
	 * @param string     $objectiveType monthly or yearly
	 * @param int        $year          Year
	 * @param int        $month         Month for monthly objectives
	 * @param User       $user          User archiving
	 * @param float|null $realizedValue Optional realized value
	 * @param int        $entity        Entity id, 0 for current entity
	 * @return int Archive id, 0 if already archived, -1 on error
	 */
	public function archiveUserPeriod($fkUser, $objectiveType, $year, $month, $user, $realizedValue = null, $entity = 0)
	{
		global $conf;

		$this->error = '';
		$this->errors = array();

		$effectiveEntity = $entity > 0 ? $entity : (int) $conf->entity;
		if ($this->archiveExists($fkUser, $objectiveType, $year, $month, $effectiveEntity)) {
			$this->error = 'LmdbSalesCommissionsObjectiveArchiveAlreadyExists';
			return 0;
		}

		$resolver = new LmdbSalesCommissionObjectiveResolver($this->db);
		$resolution = $resolver->resolveForUser($fkUser, $objectiveType, $year, $month, dol_now(), $effectiveEntity);
		$selected = $resolution['selected'];
		$errors = $resolution['errors'];

		$archive = new LmdbSalesCommissionObjectiveArchive($this->db);
		$archive->entity = $effectiveEntity;
		$archive->fk_user = $fkUser;
		$archive->objective_type = $objectiveType;
		$archive->year = $year;
		$archive->month = $objectiveType === 'monthly' ? $month : null;
		$archive->date_calculation = dol_now();
		$archive->date_archive = dol_now();
		$archive->fk_user_archive = (int) $user->id;
		$archive->realized_value = $realizedValue !== null ? (float) price2num($realizedValue, 'MT') : 0.0;

		if (!empty($errors)) {
			$archive->target_value = 0;
			$archive->achievement_rate = null;
			$archive->status = self::STATUS_BLOCKED;
			$archive->objective_source = null;
			$archive->note_private = implode("\n", $errors);
		} elseif (is_array($selected)) {
			$target = (float) price2num($selected['target_value'], 'MT');
			$archive->fk_objective = (int) $selected['rowid'];
			$archive->target_value = $target;
			$archive->achievement_rate = $target > 0 ? (float) price2num(($archive->realized_value / $target) * 100, 'MT') : null;
			$archive->status = $target > 0 && $archive->realized_value >= $target ? self::STATUS_ACHIEVED : self::STATUS_NOT_ACHIEVED;
			$archive->objective_source = (string) $selected['assignment_type'];
		} else {
			$archive->target_value = 0;
			$archive->achievement_rate = null;
			$archive->status = self::STATUS_NO_OBJECTIVE;
			$archive->objective_source = 'none';
		}

		$result = $archive->create($user);
		if ($result <= 0) {
			$this->error = $archive->error;
			$this->errors = $archive->errors;
			return -1;
		}

		dol_syslog(__METHOD__.': archived objective for user '.$fkUser.' period '.$objectiveType.' '.$year.'-'.$month.' status '.$archive->status, LOG_INFO);

		return $result;
	}

	/**
	 * Refresh existing objective archives affected by a turnover recalculation.
	 *
	 * The original archive date and author are preserved. The calculation date and
	 * modifier identify the maintenance run that refreshed the realized turnover.
	 *
	 * @param array<int> $userIds  Affected sales users
	 * @param int        $dateStart Recalculation start
	 * @param int        $dateEnd   Recalculation end
	 * @param User       $user      Maintenance user
	 * @param int        $entity    Entity id
	 * @return int Number of refreshed archives, -1 on error
	 */
	public function refreshExistingArchives(array $userIds, $dateStart, $dateEnd, $user, $entity)
	{
		$this->error = '';
		$this->errors = array();
		$userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
		if (empty($userIds) || $dateStart <= 0 || $dateEnd < $dateStart || $entity <= 0) {
			return 0;
		}

		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective_archive';
		$sql .= ' WHERE entity = '.((int) $entity).' AND fk_user IN ('.implode(',', $userIds).')';
		$sql .= ' ORDER BY rowid ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$archiveIds = array();
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$archiveIds[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		$updated = 0;
		foreach ($archiveIds as $archiveId) {
			$archive = new LmdbSalesCommissionObjectiveArchive($this->db);
			if ($archive->fetch($archiveId) <= 0) {
				$this->error = $archive->error ?: 'ErrorRecordNotFound';
				return -1;
			}
			$period = $this->getArchivePeriod($archive);
			if ($period['end'] < $dateStart || $period['start'] > $dateEnd) {
				continue;
			}

			$realizedTurnover = $this->calculateRealizedTurnover((int) $archive->fk_user, $period['start'], $period['end'], (int) $archive->entity);
			if ($realizedTurnover === null) {
				return -1;
			}
			$archive->realized_value = $realizedTurnover;
			$target = (float) price2num($archive->target_value, 'MT');
			if ($target > 0) {
				$archive->achievement_rate = (float) price2num(((float) $archive->realized_value / $target) * 100, 'MT');
				$archive->status = (float) $archive->realized_value >= $target ? self::STATUS_ACHIEVED : self::STATUS_NOT_ACHIEVED;
			} elseif ((int) $archive->status !== self::STATUS_BLOCKED) {
				$archive->achievement_rate = null;
				$archive->status = self::STATUS_NO_OBJECTIVE;
			}
			$archive->date_calculation = dol_now();
			if ($archive->update($user) <= 0) {
				$this->error = $archive->error;
				$this->errors = $archive->errors;
				return -1;
			}
			$updated++;
		}

		dol_syslog(__METHOD__.': refreshed '.$updated.' objective archives for turnover recalculation', LOG_INFO);

		return $updated;
	}

	/**
	 * Return archive period bounds.
	 *
	 * @param LmdbSalesCommissionObjectiveArchive $archive Archive
	 * @return array{start:int,end:int}
	 */
	private function getArchivePeriod($archive)
	{
		$year = (int) $archive->year;
		if ((string) $archive->objective_type === 'yearly') {
			return array('start' => dol_mktime(0, 0, 0, 1, 1, $year), 'end' => dol_mktime(23, 59, 59, 12, 31, $year));
		}
		$month = max(1, min(12, (int) $archive->month));

		return array('start' => dol_mktime(0, 0, 0, $month, 1, $year), 'end' => dol_mktime(23, 59, 59, $month + 1, 0, $year));
	}

	/**
	 * Sum attributed turnover, with a legacy fallback for periods not backfilled yet.
	 *
	 * @param int $fkUser User id
	 * @param int $start  Period start
	 * @param int $end    Period end
	 * @param int $entity Entity id
	 * @return float|null
	 */
	private function calculateRealizedTurnover($fkUser, $start, $end, $entity)
	{
		$sql = 'SELECT SUM(src.amount_base) AS total FROM (';
		$sql .= ' SELECT l.source_type, l.fk_source,';
		$sql .= ' '.LmdbSalesCommissionTurnoverService::buildAttributedAmountExpression('l', false).' AS amount_base';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
		$sql .= ' WHERE l.entity = '.((int) $entity).' AND l.fk_user = '.((int) $fkUser);
		$sql .= ' AND l.status = 1';
		$sql .= " AND l.source_type = 'proposal'";
		$sql .= " AND l.date_acquired >= '".$this->db->idate($start)."'";
		$sql .= " AND l.date_acquired <= '".$this->db->idate($end)."'";
		$sql .= ' GROUP BY l.source_type, l.fk_source';
		$sql .= ') AS src';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		$total = is_object($obj) && is_numeric($obj->total) ? (float) $obj->total : 0.0;
		$this->db->free($resql);

		return (float) price2num($total, 'MT');
	}

	/**
	 * Check if archive already exists.
	 *
	 * @param int    $fkUser        User id
	 * @param string $objectiveType Objective type
	 * @param int    $year          Year
	 * @param int    $month         Month
	 * @param int    $entity        Entity id
	 * @return bool
	 */
	private function archiveExists($fkUser, $objectiveType, $year, $month, $entity)
	{
		$sql = 'SELECT rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective_archive';
		$sql .= ' WHERE entity = '.((int) $entity);
		$sql .= ' AND fk_user = '.((int) $fkUser);
		$sql .= " AND objective_type = '".$this->db->escape($objectiveType)."'";
		$sql .= ' AND year = '.((int) $year);
		if ($objectiveType === 'monthly') {
			$sql .= ' AND month = '.((int) $month);
		} else {
			$sql .= ' AND month IS NULL';
		}
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
