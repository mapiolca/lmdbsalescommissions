<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * @phpstan-type ResolvedRule array{
 *     assignment_id:int,
 *     rule_id:int,
 *     rule_ref:string,
 *     rule_label:string,
 *     rule_type:string,
 *     source_type:string,
 *     period_type:string,
 *     rate:float|null,
 *     fk_tier_grid:int|null,
 *     fk_payment_term:int|null,
 *     assignment_type:string,
 *     assignment_rank:int,
 *     assignment_priority:int,
 *     rule_priority:int,
 *     source:string,
 *     reason:string
 * }
 * @phpstan-type ResolutionResult array{
 *     selected: array<string, ResolvedRule>,
 *     discarded: array<int, ResolvedRule>,
 *     errors: array<int, string>,
 *     candidates: array<int, ResolvedRule>
 * }
 */

/**
 * Resolve effective commission rules for a sales user.
 */
class LmdbSalesCommissionRuleResolver
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
	 * Resolve effective profile for a user, source and date.
	 *
	 * @param int    $fkUser     Sales user id
	 * @param int    $date       Timestamp, 0 for now
	 * @param int    $entity     Entity id, 0 for current entity
	 * @param string $sourceType Source type filter
	 * @return ResolutionResult
	 */
	public function resolveForUser($fkUser, $date = 0, $entity = 0, $sourceType = '')
	{
		global $conf;

		$result = array(
			'selected' => array(),
			'discarded' => array(),
			'errors' => array(),
			'candidates' => array(),
		);

		if ($fkUser <= 0) {
			$result['errors'][] = 'LmdbSalesCommissionsResolverMissingUser';
			return $result;
		}

		$effectiveDate = $date > 0 ? $date : dol_now();
		$effectiveEntity = $entity > 0 ? $entity : (int) $conf->entity;
		$groups = $this->fetchUserGroups($fkUser, $effectiveEntity);
		$candidates = $this->fetchCandidates($fkUser, $groups, $effectiveDate, $effectiveEntity, $sourceType);
		$result['candidates'] = $candidates;

		$grouped = array();
		foreach ($candidates as $candidate) {
			$ruleType = $candidate['rule_type'];
			if (!isset($grouped[$ruleType])) {
				$grouped[$ruleType] = array();
			}
			$grouped[$ruleType][] = $candidate;
		}

		foreach ($grouped as $ruleType => $rules) {
			$resolved = $this->resolveRuleType($rules);
			if (isset($resolved['selected'])) {
				$result['selected'][$ruleType] = $resolved['selected'];
			}
			foreach ($resolved['discarded'] as $discarded) {
				$result['discarded'][] = $discarded;
			}
			foreach ($resolved['errors'] as $error) {
				$result['errors'][] = $error;
			}
		}

		return $result;
	}

	/**
	 * Fetch user groups for current entity.
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
	 * Fetch active candidate rules.
	 *
	 * @param int         $fkUser     User id
	 * @param array<int, int> $groups User group ids
	 * @param int         $date       Timestamp
	 * @param int         $entity     Entity id
	 * @param string      $sourceType Source type filter
	 * @return array<int, ResolvedRule>
	 */
	private function fetchCandidates($fkUser, array $groups, $date, $entity, $sourceType)
	{
		$candidates = array();
		$dateSql = "'".$this->db->idate($date)."'";

		$sql = 'SELECT a.rowid AS assignment_id, a.assignment_type, a.priority AS assignment_priority, a.fk_payment_term AS assignment_payment_term,';
		$sql .= ' r.rowid AS rule_id, r.ref AS rule_ref, r.label AS rule_label, r.rule_type, r.source_type, r.period_type, r.rate, r.fk_tier_grid, r.fk_payment_term AS rule_payment_term, r.priority AS rule_priority';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule_assignment AS a';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule AS r ON r.rowid = a.fk_rule AND r.entity = a.entity';
		$sql .= ' WHERE a.entity = '.((int) $entity);
		$sql .= ' AND a.active = 1 AND r.active = 1';
		$sql .= ' AND (a.date_start IS NULL OR a.date_start <= '.$dateSql.')';
		$sql .= ' AND (a.date_end IS NULL OR a.date_end >= '.$dateSql.')';
		$sql .= ' AND (r.date_start IS NULL OR r.date_start <= '.$dateSql.')';
		$sql .= ' AND (r.date_end IS NULL OR r.date_end >= '.$dateSql.')';
		if ($sourceType !== '') {
			$sql .= " AND r.source_type = '".$this->db->escape($sourceType)."'";
		}

		$typeClauses = array();
		$typeClauses[] = "(a.assignment_type = 'user' AND a.fk_user = ".((int) $fkUser).')';
		if (!empty($groups)) {
			$typeClauses[] = "(a.assignment_type = 'group' AND a.fk_usergroup IN (".implode(',', array_map('intval', $groups)).'))';
		}
		$typeClauses[] = "(a.assignment_type = 'default')";
		$sql .= ' AND ('.implode(' OR ', $typeClauses).')';
		$sql .= ' ORDER BY r.rule_type ASC, a.assignment_type ASC, a.priority DESC, r.priority DESC, a.rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return $candidates;
		}

		$seen = array();
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$key = (int) $obj->assignment_id.'-'.(int) $obj->rule_id;
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$assignmentType = (string) $obj->assignment_type;
			$rank = $this->getAssignmentRank($assignmentType);
			$candidates[] = array(
				'assignment_id' => (int) $obj->assignment_id,
				'rule_id' => (int) $obj->rule_id,
				'rule_ref' => (string) $obj->rule_ref,
				'rule_label' => (string) $obj->rule_label,
				'rule_type' => (string) $obj->rule_type,
				'source_type' => (string) $obj->source_type,
				'period_type' => (string) $obj->period_type,
				'rate' => $obj->rate !== null ? (float) $obj->rate : null,
				'fk_tier_grid' => $obj->fk_tier_grid !== null ? (int) $obj->fk_tier_grid : null,
				'fk_payment_term' => $obj->assignment_payment_term !== null ? (int) $obj->assignment_payment_term : ($obj->rule_payment_term !== null ? (int) $obj->rule_payment_term : null),
				'assignment_type' => $assignmentType,
				'assignment_rank' => $rank,
				'assignment_priority' => (int) $obj->assignment_priority,
				'rule_priority' => (int) $obj->rule_priority,
				'source' => $assignmentType,
				'reason' => 'candidate',
			);
		}
		$this->db->free($resql);

		return $candidates;
	}

	/**
	 * Resolve a single rule type.
	 *
	 * @param array<int, ResolvedRule> $rules Candidate rules
	 * @return array{selected?: ResolvedRule, discarded: array<int, ResolvedRule>, errors: array<int, string>}
	 */
	private function resolveRuleType(array $rules)
	{
		$result = array(
			'discarded' => array(),
			'errors' => array(),
		);

		if (empty($rules)) {
			return $result;
		}

		usort($rules, array($this, 'compareCandidates'));
		$selected = $rules[0];
		$selected['reason'] = 'selected_highest_priority';

		if (count($rules) > 1) {
			$second = $rules[1];
			if ($selected['assignment_rank'] === $second['assignment_rank'] && $selected['assignment_priority'] === $second['assignment_priority'] && $selected['rule_id'] !== $second['rule_id']) {
				$result['errors'][] = 'LmdbSalesCommissionsResolverConflict'.': '.$selected['rule_type'];
				return $result;
			}
		}

		$result['selected'] = $selected;
		foreach (array_slice($rules, 1) as $discarded) {
			$discarded['reason'] = 'discarded_lower_priority';
			$result['discarded'][] = $discarded;
		}

		return $result;
	}

	/**
	 * Sort candidates by hierarchy and priority.
	 *
	 * @param ResolvedRule $a First candidate
	 * @param ResolvedRule $b Second candidate
	 * @return int
	 */
	private function compareCandidates(array $a, array $b)
	{
		if ($a['assignment_rank'] !== $b['assignment_rank']) {
			return $a['assignment_rank'] <=> $b['assignment_rank'];
		}
		if ($a['assignment_priority'] !== $b['assignment_priority']) {
			return $b['assignment_priority'] <=> $a['assignment_priority'];
		}
		if ($a['rule_priority'] !== $b['rule_priority']) {
			return $b['rule_priority'] <=> $a['rule_priority'];
		}

		return $a['assignment_id'] <=> $b['assignment_id'];
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
