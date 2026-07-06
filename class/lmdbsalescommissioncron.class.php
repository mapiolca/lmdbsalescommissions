<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbsalescommissionobjectivearchiveservice.class.php';
require_once __DIR__.'/lmdbsalescommissiondueservice.class.php';
require_once __DIR__.'/lmdbsalescommissionline.class.php';

/**
 * Native scheduled jobs for sales commissions.
 */
class LmdbSalesCommissionCron
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
	 * Archive previous monthly objectives.
	 *
	 * @return int
	 */
	public function archiveMonthlyObjectives()
	{
		$period = dol_time_plus_duree(dol_now(), -1, 'm');
		$year = (int) date('Y', $period);
		$month = (int) date('n', $period);

		return $this->archiveObjectives('monthly', $year, $month);
	}

	/**
	 * Archive previous yearly objectives.
	 *
	 * @return int
	 */
	public function archiveYearlyObjectives()
	{
		$year = (int) date('Y', dol_time_plus_duree(dol_now(), -1, 'y'));

		return $this->archiveObjectives('yearly', $year, 0);
	}

	/**
	 * Generate missing due dates for acquired lines.
	 *
	 * @return int
	 */
	public function generateMissingDueDates()
	{
		global $user;

		$cronUser = is_object($user) ? $user : null;
		if (!is_object($cronUser)) {
			$this->error = 'ErrorNoUser';
			return -1;
		}

		$sql = 'SELECT rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
		$sql .= ' WHERE entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_line')).')';
		$sql .= ' AND status = 1';
		$sql .= ' AND commission_total > 0';
		$sql .= ' ORDER BY rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$service = new LmdbSalesCommissionDueService($this->db);
		$processed = 0;
		$created = 0;
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$line = new LmdbSalesCommissionLine($this->db);
			if ($line->fetch((int) $obj->rowid) <= 0) {
				continue;
			}
			$processed++;
			$result = $service->generateForLine($line, $cronUser);
			if ($result < 0) {
				$this->error = $service->error;
				$this->errors = $service->errors;
				$this->db->free($resql);
				return -1;
			}
			$created += $result;
		}
		$this->db->free($resql);

		dol_syslog(__METHOD__.': processed '.$processed.' lines, created '.$created.' due dates', LOG_INFO);

		return $processed;
	}

	/**
	 * Detect due dates already payable from their native event.
	 *
	 * V1 marks proposal signature due dates at generation time; payment event reconciliation is kept
	 * conservative until invoice/deposit source mapping is explicitly configured.
	 *
	 * @return int
	 */
	public function detectPayableDueDates()
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'lmdbsalescommissions_due';
		$sql .= ' SET status = '.LmdbSalesCommissionDueService::STATUS_DUE.', date_due = COALESCE(date_due, NOW())';
		$sql .= ' WHERE entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_due')).')';
		$sql .= " AND event_type = 'proposal_signed'";
		$sql .= ' AND status = '.LmdbSalesCommissionDueService::STATUS_WAITING;

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$count = $this->db->affected_rows($this->db->lastqueryid);
		dol_syslog(__METHOD__.': marked '.$count.' signature due dates as payable', LOG_INFO);

		return $count;
	}

	/**
	 * Archive objectives for all active users in current entity scope.
	 *
	 * @param string $objectiveType Objective type
	 * @param int    $year          Year
	 * @param int    $month         Month
	 * @return int
	 */
	private function archiveObjectives($objectiveType, $year, $month)
	{
		global $user;

		$cronUser = is_object($user) ? $user : null;
		if (!is_object($cronUser)) {
			$this->error = 'ErrorNoUser';
			return -1;
		}

		$sql = 'SELECT rowid, entity';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'user';
		$sql .= ' WHERE statut = 1';
		$sql .= ' AND entity IN ('.$this->db->sanitize(getEntity('user')).')';
		$sql .= ' ORDER BY rowid ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$service = new LmdbSalesCommissionObjectiveArchiveService($this->db);
		$processed = 0;
		$archived = 0;
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$processed++;
			$realized = $this->sumRealized((int) $obj->rowid, $objectiveType, $year, $month, (int) $obj->entity);
			$result = $service->archiveUserPeriod((int) $obj->rowid, $objectiveType, $year, $month, $cronUser, $realized, (int) $obj->entity);
			if ($result < 0) {
				$this->error = $service->error;
				$this->errors = $service->errors;
				$this->db->free($resql);
				return -1;
			}
			if ($result > 0) {
				$archived++;
			}
		}
		$this->db->free($resql);

		dol_syslog(__METHOD__.': archived '.$archived.' '.$objectiveType.' objectives over '.$processed.' users for '.$year.'-'.$month, LOG_INFO);

		return $archived;
	}

	/**
	 * Sum realized signed turnover for objective period.
	 *
	 * @param int    $fkUser        User id
	 * @param string $objectiveType Objective type
	 * @param int    $year          Year
	 * @param int    $month         Month
	 * @param int    $entity        Entity id
	 * @return float
	 */
	private function sumRealized($fkUser, $objectiveType, $year, $month, $entity)
	{
		$dateStart = $objectiveType === 'monthly' ? dol_mktime(0, 0, 0, $month, 1, $year) : dol_mktime(0, 0, 0, 1, 1, $year);
		$dateEnd = $objectiveType === 'monthly' ? dol_time_plus_duree(dol_time_plus_duree($dateStart, 1, 'm'), -1, 's') : dol_mktime(23, 59, 59, 12, 31, $year);

		$sql = 'SELECT SUM(amount_base) AS realized';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
		$sql .= ' WHERE entity = '.((int) $entity);
		$sql .= ' AND fk_user = '.((int) $fkUser);
		$sql .= ' AND status = 1';
		$sql .= " AND date_acquired >= '".$this->db->idate($dateStart)."'";
		$sql .= " AND date_acquired <= '".$this->db->idate($dateEnd)."'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return 0.0;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return is_object($obj) ? (float) $obj->realized : 0.0;
	}
}
