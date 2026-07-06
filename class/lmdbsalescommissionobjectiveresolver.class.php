<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * Resolve effective sales objectives for a user.
 */
class LmdbSalesCommissionObjectiveResolver
{
	/** @var DoliDB Database handler */
	private $db;

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
	 * Resolve objective for a user and period.
	 *
	 * @param int    $fkUser        User id
	 * @param string $objectiveType monthly or yearly
	 * @param int    $year          Year
	 * @param int    $month         Month for monthly objectives
	 * @param int    $date          Timestamp, 0 for now
	 * @param int    $entity        Entity id, 0 for current entity
	 * @return array{selected: array<string, mixed>|null, discarded: array<int, array<string, mixed>>, errors: array<int, string>, candidates: array<int, array<string, mixed>>}
	 */
	public function resolveForUser($fkUser, $objectiveType, $year, $month = 0, $date = 0, $entity = 0)
	{
		global $conf;

		$result = array(
			'selected' => null,
			'discarded' => array(),
			'errors' => array(),
			'candidates' => array(),
		);

		if ($fkUser <= 0) {
			$result['errors'][] = 'LmdbSalesCommissionsResolverMissingUser';
			return $result;
		}
		if ($objectiveType !== 'monthly' && $objectiveType !== 'yearly') {
			$result['errors'][] = 'LmdbSalesCommissionsObjectiveInvalidType';
			return $result;
		}
		if ($year <= 0 || ($objectiveType === 'monthly' && ($month < 1 || $month > 12))) {
			$result['errors'][] = 'LmdbSalesCommissionsObjectiveInvalidPeriod';
			return $result;
		}

		$effectiveDate = $date > 0 ? $date : dol_now();
		$effectiveEntity = $entity > 0 ? $entity : (int) $conf->entity;
		$groups = $this->fetchUserGroups($fkUser, $effectiveEntity);
		$candidates = $this->fetchCandidates($fkUser, $groups, $objectiveType, $year, $month, $effectiveDate, $effectiveEntity);
		$result['candidates'] = $candidates;

		if (empty($candidates)) {
			return $result;
		}

		usort($candidates, array($this, 'compareCandidates'));
		$selected = $candidates[0];
		if (count($candidates) > 1) {
			$second = $candidates[1];
			if ($selected['assignment_rank'] === $second['assignment_rank'] && $selected['priority'] === $second['priority'] && $selected['rowid'] !== $second['rowid']) {
				$result['errors'][] = 'LmdbSalesCommissionsObjectiveConflict';
				return $result;
			}
		}

		$selected['reason'] = 'selected_highest_priority';
		$result['selected'] = $selected;
		foreach (array_slice($candidates, 1) as $discarded) {
			$discarded['reason'] = 'discarded_lower_priority';
			$result['discarded'][] = $discarded;
		}

		return $result;
	}

	/**
	 * Fetch user groups.
	 *
	 * @param int $fkUser User id
	 * @param int $entity Entity id
	 * @return array<int, int>
	 */
	private function fetchUserGroups($fkUser, $entity)
	{
		$groups = array();
		$sql = 'SELECT fk_usergroup';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'usergroup_user';
		$sql .= ' WHERE fk_user = '.((int) $fkUser);
		$sql .= ' AND entity = '.((int) $entity);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return $groups;
		}

		while (is_object($obj = $this->db->fetch_object($resql))) {
			$groups[] = (int) $obj->fk_usergroup;
		}
		$this->db->free($resql);

		return $groups;
	}

	/**
	 * Fetch candidate objectives.
	 *
	 * @param int         $fkUser        User id
	 * @param array<int, int> $groups    Group ids
	 * @param string      $objectiveType Objective type
	 * @param int         $year          Year
	 * @param int         $month         Month
	 * @param int         $date          Timestamp
	 * @param int         $entity        Entity id
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchCandidates($fkUser, array $groups, $objectiveType, $year, $month, $date, $entity)
	{
		$candidates = array();
		$dateSql = "'".$this->db->idate($date)."'";

		$sql = 'SELECT rowid, assignment_type, fk_user, fk_usergroup, objective_type, year, month, base_type, target_value, priority';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective';
		$sql .= ' WHERE entity = '.((int) $entity);
		$sql .= ' AND active = 1';
		$sql .= " AND objective_type = '".$this->db->escape($objectiveType)."'";
		$sql .= ' AND year = '.((int) $year);
		if ($objectiveType === 'monthly') {
			$sql .= ' AND month = '.((int) $month);
		}
		$sql .= ' AND (date_start IS NULL OR date_start <= '.$dateSql.')';
		$sql .= ' AND (date_end IS NULL OR date_end >= '.$dateSql.')';

		$typeClauses = array();
		$typeClauses[] = "(assignment_type = 'user' AND fk_user = ".((int) $fkUser).')';
		if (!empty($groups)) {
			$typeClauses[] = "(assignment_type = 'group' AND fk_usergroup IN (".implode(',', array_map('intval', $groups)).'))';
		}
		$typeClauses[] = "(assignment_type = 'default')";
		$sql .= ' AND ('.implode(' OR ', $typeClauses).')';

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return $candidates;
		}

		while (is_object($obj = $this->db->fetch_object($resql))) {
			$assignmentType = (string) $obj->assignment_type;
			$candidates[] = array(
				'rowid' => (int) $obj->rowid,
				'assignment_type' => $assignmentType,
				'assignment_rank' => $this->getAssignmentRank($assignmentType),
				'fk_user' => $obj->fk_user !== null ? (int) $obj->fk_user : null,
				'fk_usergroup' => $obj->fk_usergroup !== null ? (int) $obj->fk_usergroup : null,
				'objective_type' => (string) $obj->objective_type,
				'year' => (int) $obj->year,
				'month' => $obj->month !== null ? (int) $obj->month : null,
				'base_type' => (string) $obj->base_type,
				'target_value' => (float) $obj->target_value,
				'priority' => (int) $obj->priority,
				'reason' => 'candidate',
			);
		}
		$this->db->free($resql);

		return $candidates;
	}

	/**
	 * Sort candidates.
	 *
	 * @param array<string, mixed> $a First candidate
	 * @param array<string, mixed> $b Second candidate
	 * @return int
	 */
	private function compareCandidates(array $a, array $b)
	{
		if ($a['assignment_rank'] !== $b['assignment_rank']) {
			return $a['assignment_rank'] <=> $b['assignment_rank'];
		}
		if ($a['priority'] !== $b['priority']) {
			return $b['priority'] <=> $a['priority'];
		}

		return $a['rowid'] <=> $b['rowid'];
	}

	/**
	 * Convert assignment type to priority rank.
	 *
	 * @param string $assignmentType Assignment type
	 * @return int
	 */
	private function getAssignmentRank($assignmentType)
	{
		if ($assignmentType === 'user') {
			return 1;
		}
		if ($assignmentType === 'group') {
			return 2;
		}

		return 3;
	}
}
