<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissiondueservice.class.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionobjectiveresolver.class.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissiontierservice.class.php', 0);
require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionturnoverservice.class.php', 0);

/**
 * @phpstan-type DashboardFilters array{
 *     fk_user:int,
 *     fk_usergroup:int,
 *     year:int,
 *     month:int,
 *     date_start:int,
 *     date_end:int,
 *     source:string,
 *     commission_type:string,
 *     status:string,
 *     objective_status:string,
 *     entity:int
 * }
 * @phpstan-type DashboardKpis array<string, float|int|null>
 * @phpstan-type DashboardRow array<string, mixed>
 */

/**
 * Centralized dashboard aggregation service.
 */
class LmdbSalesCommissionDashboardService
{
	/** @var DoliDB Database handler */
	private $db;

	/** @var string Last error */
	public $error = '';

	/** @var array<int, string> Last errors */
	public $errors = array();

	/** @var string Permission scope mode: read or export */
	private $scopeMode = 'read';

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
	 * Set permission scope mode.
	 *
	 * @param string $scopeMode read or export
	 * @return void
	 */
	public function setScopeMode($scopeMode)
	{
		if ($scopeMode === 'export') {
			$this->scopeMode = 'export';
		} else {
			$this->scopeMode = 'read';
		}
	}

	/**
	 * Return current default dashboard filters.
	 *
	 * @param User $user Current user
	 * @return DashboardFilters
	 */
	public function getDefaultFilters($user)
	{
		global $conf;

		$now = dol_now();
		$year = (int) date('Y', $now);
		$month = (int) date('n', $now);
		$filters = array(
			'fk_user' => 0,
			'fk_usergroup' => 0,
			'year' => $year,
			'month' => $month,
			'date_start' => 0,
			'date_end' => 0,
			'source' => 'all',
			'commission_type' => 'all',
			'status' => 'all',
			'objective_status' => 'all',
			'entity' => (int) $conf->entity,
		);

		if (is_object($user) && empty($user->admin)) {
			if ($this->scopeMode === 'export') {
				if (!$user->hasRight('lmdbsalescommissions', 'export', 'all')) {
					$filters['fk_user'] = (int) $user->id;
				}
			} elseif (!$user->hasRight('lmdbsalescommissions', 'commission', 'readall') && !$user->hasRight('lmdbsalescommissions', 'commission', 'readgroup')) {
				$filters['fk_user'] = (int) $user->id;
			}
		}

		return $filters;
	}

	/**
	 * Normalize and restrict filters.
	 *
	 * @param array<string, mixed> $filters Raw filters
	 * @param User                 $user    Current user
	 * @return DashboardFilters
	 */
	public function normalizeFilters(array $filters, $user)
	{
		$normalized = $this->getDefaultFilters($user);
		foreach ($normalized as $key => $value) {
			if (array_key_exists($key, $filters)) {
				if (is_int($value)) {
					$normalized[$key] = (int) $filters[$key];
				} else {
					$normalized[$key] = (string) $filters[$key];
				}
			}
		}

		if (!in_array($normalized['source'], array('all', 'proposal', 'order', 'contract'), true)) {
			$normalized['source'] = 'all';
		}
		if (!in_array($normalized['commission_type'], array('all', 'margin', 'tier', 'dispatch', 'turnover'), true)) {
			$normalized['commission_type'] = 'all';
		}
		if (!in_array($normalized['status'], array('all', 'estimated', 'acquired', 'payable', 'paid', 'cancelled', 'blocked'), true)) {
			$normalized['status'] = 'all';
		}
		if (!in_array($normalized['objective_status'], array('all', 'achieved', 'not_achieved', 'no_objective'), true)) {
			$normalized['objective_status'] = 'all';
		}
		if ($normalized['month'] < 0 || $normalized['month'] > 12) {
			$normalized['month'] = 0;
		}

		if (is_object($user) && empty($user->admin)) {
			if ($this->scopeMode === 'export') {
				if (!$user->hasRight('lmdbsalescommissions', 'export', 'all')) {
					if ($normalized['fk_user'] > 0 && !lmdbsalescommissionsCanExportUserScope($user, $normalized['fk_user'])) {
						$normalized['fk_user'] = (int) $user->id;
					}
					$normalized['fk_user'] = $normalized['fk_user'] > 0 ? $normalized['fk_user'] : (int) $user->id;
					$normalized['fk_usergroup'] = 0;
				}
			} elseif (!$user->hasRight('lmdbsalescommissions', 'commission', 'readall')) {
				if ($normalized['fk_user'] > 0 && !lmdbsalescommissionsCanReadUserScope($user, $normalized['fk_user'])) {
					$normalized['fk_user'] = (int) $user->id;
				}
				if (!$user->hasRight('lmdbsalescommissions', 'commission', 'readgroup')) {
					$normalized['fk_user'] = (int) $user->id;
					$normalized['fk_usergroup'] = 0;
				}
			}
		}

		return $normalized;
	}

	/**
	 * Return KPI summary.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @return DashboardKpis
	 */
	public function getKpiSummary(array $filters, $user)
	{
		$where = $this->buildLineWhere('l', $filters, $user, 'date_acquired');
		$kpis = array(
			'turnover_signed' => 0.0,
			'margin_signed' => 0.0,
			'margin_rate' => null,
			'commission_estimated' => 0.0,
			'commission_acquired' => 0.0,
			'tier_bonus' => 0.0,
			'commission_total' => 0.0,
			'commission_due' => 0.0,
			'commission_paid' => 0.0,
			'remaining_due' => 0.0,
			'payment_rate' => null,
			'commission_margin_rate' => null,
			'commission_turnover_rate' => null,
			'signed_deals_count' => 0,
			'average_signed_deal' => 0.0,
			'monthly_objective' => null,
			'monthly_realized' => 0.0,
			'monthly_objective_rate' => null,
			'annual_objective' => null,
			'annual_realized' => 0.0,
			'annual_objective_rate' => null,
		);

		$sql = 'SELECT';
		$sql .= ' SUM(CASE WHEN l.status = 0 THEN l.commission_total ELSE 0 END) AS commission_estimated,';
		$sql .= ' SUM(CASE WHEN l.status = 1 THEN l.commission_total ELSE 0 END) AS commission_acquired,';
		$sql .= " SUM(CASE WHEN l.status = 1 AND l.mode = 'tier' THEN l.commission_total ELSE 0 END) AS tier_bonus,";
		$sql .= ' SUM(CASE WHEN l.status IN (0,1) THEN l.commission_total ELSE 0 END) AS commission_total,';
		$sql .= ' SUM(l.payable_total) AS payable_total,';
		$sql .= ' SUM(l.paid_total) AS paid_total';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
		$sql .= ' WHERE 1 = 1'.$where;
		$row = $this->fetchSingleRow($sql);
		if (!empty($row)) {
			$kpis['commission_estimated'] = (float) $row['commission_estimated'];
			$kpis['commission_acquired'] = (float) $row['commission_acquired'];
			$kpis['tier_bonus'] = (float) $row['tier_bonus'];
			$kpis['commission_total'] = (float) $row['commission_total'];
			$kpis['commission_paid'] = (float) $row['paid_total'];
			$kpis['remaining_due'] = max(0, (float) $row['payable_total'] - (float) $row['paid_total']);
		}

		$base = $this->getSignedBaseTotals($filters, $user);
		$kpis['turnover_signed'] = $base['turnover'];
		$kpis['margin_signed'] = $base['margin'];
		$kpis['signed_deals_count'] = $base['count'];
		$kpis['margin_rate'] = $base['turnover'] > 0 ? ($base['margin'] / $base['turnover']) * 100 : null;
		$kpis['average_signed_deal'] = $base['count'] > 0 ? $base['turnover'] / $base['count'] : 0.0;
		$kpis['commission_margin_rate'] = $base['margin'] > 0 ? ((float) $kpis['commission_acquired'] / $base['margin']) * 100 : null;
		$kpis['commission_turnover_rate'] = $base['turnover'] > 0 ? ((float) $kpis['commission_acquired'] / $base['turnover']) * 100 : null;

		$kpis['commission_due'] = $this->getDueAmount($filters, $user, LmdbSalesCommissionDueService::STATUS_DUE);
		$paidFromDue = $this->getDueAmount($filters, $user, LmdbSalesCommissionDueService::STATUS_PAID);
		if ($paidFromDue > 0) {
			$kpis['commission_paid'] = $paidFromDue;
		}
		$payableAndPaid = (float) $kpis['commission_due'] + (float) $kpis['commission_paid'];
		$kpis['payment_rate'] = $payableAndPaid > 0 ? ((float) $kpis['commission_paid'] / $payableAndPaid) * 100 : null;

		$monthly = $this->getObjectiveProgress('monthly', $filters, $user);
		$annual = $this->getObjectiveProgress('yearly', $filters, $user);
		$kpis['monthly_objective'] = $monthly['objective'];
		$kpis['monthly_realized'] = $monthly['realized'];
		$kpis['monthly_objective_rate'] = $monthly['rate'];
		$kpis['annual_objective'] = $annual['objective'];
		$kpis['annual_realized'] = $annual['realized'];
		$kpis['annual_objective_rate'] = $annual['rate'];

		return $kpis;
	}

	/**
	 * Return month-by-month acquired commission comparison for year and previous year.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @return array<int, array{month:int, current:float, previous:float}>
	 */
	public function getMonthlyCommissionCompare(array $filters, $user)
	{
		$year = $filters['year'] > 0 ? (int) $filters['year'] : (int) date('Y', dol_now());
		$current = $this->getMonthlySums($filters, $user, $year, 'commission_total');
		$previous = $this->getMonthlySums($filters, $user, $year - 1, 'commission_total');
		$rows = array();
		for ($month = 1; $month <= 12; $month++) {
			$rows[] = array(
				'month' => $month,
				'current' => isset($current[$month]) ? $current[$month] : 0.0,
				'previous' => isset($previous[$month]) ? $previous[$month] : 0.0,
			);
		}

		return $rows;
	}

	/**
	 * Return monthly turnover, margin and commissions.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @return array<int, array{month:int, turnover:float, margin:float, commission:float}>
	 */
	public function getTurnoverMarginCommissionSeries(array $filters, $user)
	{
		$year = $filters['year'] > 0 ? (int) $filters['year'] : (int) date('Y', dol_now());
		$turnover = $this->getMonthlySums($filters, $user, $year, 'amount_base');
		$margin = $this->getMonthlySums($filters, $user, $year, 'margin_base');
		$commission = $this->getMonthlySums($filters, $user, $year, 'commission_total');
		$rows = array();
		for ($month = 1; $month <= 12; $month++) {
			$rows[] = array(
				'month' => $month,
				'turnover' => isset($turnover[$month]) ? $turnover[$month] : 0.0,
				'margin' => isset($margin[$month]) ? $margin[$month] : 0.0,
				'commission' => isset($commission[$month]) ? $commission[$month] : 0.0,
			);
		}

		return $rows;
	}

	/**
	 * Return commission funnel values.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @return array<string, float>
	 */
	public function getCommissionFunnel(array $filters, $user)
	{
		$summary = $this->getKpiSummary($filters, $user);

		return array(
			'estimated' => (float) $summary['commission_estimated'],
			'acquired' => (float) $summary['commission_acquired'],
			'payable' => (float) $summary['commission_due'],
			'paid' => (float) $summary['commission_paid'],
			'remaining' => (float) $summary['remaining_due'],
		);
	}

	/**
	 * Return commissions by agent.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @param int              $limit   Max rows
	 * @return array<int, DashboardRow>
	 */
	public function getCommissionsByAgent(array $filters, $user, $limit = 10)
	{
		$where = $this->buildLineWhere('l', $filters, $user, 'date_acquired');
		$sql = 'SELECT l.fk_user, u.lastname, u.firstname, u.login, u.statut AS user_status, u.photo AS user_photo, u.email AS user_email, SUM(l.commission_total) AS commission_total';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = l.fk_user';
		$sql .= ' WHERE l.status = 1'.$where;
		$sql .= " AND (l.fk_rule > 0 OR l.mode = 'dispatch')";
		$sql .= ' GROUP BY l.fk_user, u.lastname, u.firstname, u.login, u.statut, u.photo, u.email';
		$sql .= ' ORDER BY commission_total DESC';
		$sql .= $this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * Return objective progress for monthly or yearly objective.
	 *
	 * @param string           $objectiveType monthly or yearly
	 * @param DashboardFilters $filters       Normalized filters
	 * @param User             $user          Current user
	 * @return array{objective:float|null, realized:float, rate:float|null}
	 */
	public function getObjectiveProgress($objectiveType, array $filters, $user)
	{
		$year = $filters['year'] > 0 ? (int) $filters['year'] : (int) date('Y', dol_now());
		$month = $filters['month'] > 0 ? (int) $filters['month'] : (int) date('n', dol_now());
		$objective = $this->getObjectiveTarget($objectiveType, $filters, $user, $year, $month);

		$periodFilters = $filters;
		$periodFilters['commission_type'] = 'all';
		$periodFilters['year'] = $year;
		if ($objectiveType === 'monthly') {
			$periodFilters['month'] = $month;
		} else {
			$periodFilters['month'] = 0;
		}
		$realized = $this->getSignedBaseTotals($periodFilters, $user, true);
		$realizedValue = (float) $realized['turnover'];

		return array(
			'objective' => $objective,
			'realized' => $realizedValue,
			'rate' => $objective !== null && $objective > 0 ? ($realizedValue / $objective) * 100 : null,
		);
	}

	/**
	 * Return tier progress for the current scope.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @return array{calculation_mode:string|null, turnover:float, reached_threshold:float|null, next_threshold:float|null, remaining:float|null, current_commission:float|null, commission_at_next_threshold:float|null, potential_bonus:float|null, rate:float|null}
	 */
	public function getTierProgress(array $filters, $user)
	{
		if ($filters['fk_user'] <= 0) {
			$turnoverFilters = $filters;
			$turnoverFilters['commission_type'] = 'all';
			$base = $this->getSignedBaseTotals($turnoverFilters, $user, true);

			return array(
				'calculation_mode' => null,
				'turnover' => (float) $base['turnover'],
				'reached_threshold' => null,
				'next_threshold' => null,
				'remaining' => null,
				'current_commission' => null,
				'commission_at_next_threshold' => null,
				'potential_bonus' => null,
				'rate' => null,
			);
		}

		$year = $filters['year'] > 0 ? $filters['year'] : (int) date('Y', dol_now());
		$month = $filters['month'] > 0 ? $filters['month'] : 1;
		$referenceDate = dol_mktime(12, 0, 0, $month, 1, $year);
		$tierService = new LmdbSalesCommissionTierService($this->db);
		$progress = $tierService->getProgressForUser($filters['fk_user'], $referenceDate, $filters['entity']);
		if (!isset($progress['status']) || $progress['status'] !== 'ok') {
			return array(
				'calculation_mode' => null,
				'turnover' => 0.0,
				'reached_threshold' => null,
				'next_threshold' => null,
				'remaining' => null,
				'current_commission' => null,
				'commission_at_next_threshold' => null,
				'potential_bonus' => null,
				'rate' => null,
			);
		}

		$turnover = (float) $progress['turnover'];
		$reachedTier = is_array($progress['reached_tier']) ? $progress['reached_tier'] : null;
		$nextTier = is_array($progress['next_tier']) ? $progress['next_tier'] : null;
		$reached = is_array($reachedTier) ? (float) $reachedTier['threshold_amount'] : null;
		$next = is_array($nextTier) ? (float) $nextTier['threshold_amount'] : null;

		return array(
			'calculation_mode' => (string) $progress['calculation_mode'],
			'turnover' => $turnover,
			'reached_threshold' => $reached,
			'next_threshold' => $next,
			'remaining' => $next !== null ? (float) price2num(max(0, $next - $turnover), 'MT') : null,
			'current_commission' => (float) $progress['current_commission'],
			'commission_at_next_threshold' => $progress['commission_at_next_threshold'] !== null ? (float) $progress['commission_at_next_threshold'] : null,
			'potential_bonus' => $progress['commission_at_next_threshold'] !== null ? (float) $progress['commission_at_next_threshold'] : null,
			'rate' => $next !== null && $next > 0 ? min(100, ($turnover / $next) * 100) : null,
		);
	}

	/**
	 * Return due commission rows.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @param int              $limit   Max rows
	 * @return array<int, DashboardRow>
	 */
	public function getDueCommissions(array $filters, $user, $limit = 10)
	{
		$where = $this->buildLineWhere('l', $filters, $user, 'date_acquired');
		$sql = 'SELECT d.rowid, d.event_type, d.amount, d.status, d.date_due, l.source_type, l.fk_source, l.source_ref, l.mode,';
		$sql .= ' u.rowid AS user_id, u.lastname, u.firstname, u.login, u.statut AS user_status, u.photo AS user_photo, u.email AS user_email,';
		$sql .= ' s.rowid AS socid, s.nom AS thirdparty_name';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l ON l.rowid = d.fk_commission_line AND l.entity = d.entity';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = l.fk_user';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = l.fk_soc';
		$sql .= ' WHERE d.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_due')).')';
		$sql .= ' AND d.status = '.LmdbSalesCommissionDueService::STATUS_DUE.$where;
		$sql .= ' ORDER BY d.date_due ASC, d.rowid ASC';
		$sql .= $this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * Return agents near a tier.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @param int              $limit   Max rows
	 * @return array<int, DashboardRow>
	 */
	public function getAgentsNearTier(array $filters, $user, $limit = 10)
	{
		$agents = $this->getCommissionsByAgent($filters, $user, 100);
		$rows = array();
		foreach ($agents as $agent) {
			$agentFilters = $filters;
			$agentFilters['fk_user'] = (int) $agent['fk_user'];
			$progress = $this->getTierProgress($agentFilters, $user);
			if ($progress['next_threshold'] === null || $progress['remaining'] === null) {
				continue;
			}
			$row = $agent;
			$row['turnover'] = $progress['turnover'];
			$row['reached_threshold'] = $progress['reached_threshold'];
			$row['next_threshold'] = $progress['next_threshold'];
			$row['remaining'] = $progress['remaining'];
			$row['calculation_mode'] = $progress['calculation_mode'];
			$row['current_commission'] = $progress['current_commission'];
			$row['commission_at_next_threshold'] = $progress['commission_at_next_threshold'];
			$row['potential_bonus'] = $progress['potential_bonus'];
			$row['rate'] = $progress['rate'];
			$rows[] = $row;
		}
		usort($rows, array($this, 'sortNearTierRows'));

		return array_slice($rows, 0, $limit);
	}

	/**
	 * Return late objective rows.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @param int              $limit   Max rows
	 * @return array<int, DashboardRow>
	 */
	public function getLateObjectives(array $filters, $user, $limit = 10)
	{
		$year = $filters['year'] > 0 ? (int) $filters['year'] : (int) date('Y', dol_now());
		$month = $filters['month'] > 0 ? (int) $filters['month'] : (int) date('n', dol_now());
		$users = $this->getObjectiveUsers($filters, $user, $limit * 3);
		$rows = array();
		foreach ($users as $objectiveUser) {
			foreach (array('monthly', 'yearly') as $objectiveType) {
				$agentFilters = $filters;
				$agentFilters['fk_user'] = (int) $objectiveUser['fk_user'];
				$agentFilters['year'] = $year;
				$agentFilters['month'] = $objectiveType === 'monthly' ? $month : 0;
				$progress = $this->getObjectiveProgress($objectiveType, $agentFilters, $user);
				if ($progress['objective'] === null || $progress['objective'] <= 0 || $progress['rate'] === null || $progress['rate'] >= 100) {
					continue;
				}
				$row = $objectiveUser;
				$row['objective_type'] = $objectiveType;
				$row['period'] = $objectiveType === 'monthly' ? sprintf('%04d-%02d', $year, $month) : (string) $year;
				$row['objective'] = $progress['objective'];
				$row['realized'] = $progress['realized'];
				$row['rate'] = $progress['rate'];
				$row['gap'] = (float) $progress['objective'] - (float) $progress['realized'];
				$rows[] = $row;
			}
		}
		usort($rows, array($this, 'sortObjectiveRows'));

		return array_slice($rows, 0, $limit);
	}

	/**
	 * Return top commissioned deals.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @param int              $limit   Max rows
	 * @return array<int, DashboardRow>
	 */
	public function getTopCommissionedDeals(array $filters, $user, $limit = 10)
	{
		$where = $this->buildLineWhere('l', $filters, $user, 'date_acquired');
		$sql = 'SELECT l.fk_user, l.fk_soc, l.source_type, l.fk_source, l.source_ref, MAX(l.date_acquired) AS date_acquired,';
		$sql .= ' '.LmdbSalesCommissionTurnoverService::buildAttributedAmountExpression('l', false).' AS amount_base,';
		$sql .= " MAX(CASE WHEN l.mode <> 'turnover' THEN l.margin_base END) AS margin_base,";
		$sql .= " SUM(CASE WHEN l.mode = 'margin' THEN l.commission_total ELSE 0 END) AS margin_commission,";
		$sql .= " SUM(CASE WHEN l.mode = 'tier' THEN l.commission_total ELSE 0 END) AS tier_commission,";
		$sql .= ' SUM(l.commission_total) AS commission_total, MAX(l.status) AS status,';
		$sql .= ' u.lastname, u.firstname, u.login, u.statut AS user_status, u.photo AS user_photo, u.email AS user_email, s.nom AS thirdparty_name';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = l.fk_user';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = l.fk_soc';
		$sql .= ' WHERE l.status IN (0,1)'.$where;
		$sql .= ' GROUP BY l.entity, l.fk_user, l.fk_soc, l.source_type, l.fk_source, l.source_ref, u.lastname, u.firstname, u.login, u.statut, u.photo, u.email, s.nom';
		$sql .= ' ORDER BY commission_total DESC';
		$sql .= $this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * Return due commission aging buckets.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @return array<int, array{label:string, amount:float, count:int}>
	 */
	public function getDueCommissionsAging(array $filters, $user)
	{
		$where = $this->buildLineWhere('l', $filters, $user, 'date_acquired');
		$sql = 'SELECT';
		$sql .= ' SUM(CASE WHEN DATEDIFF(NOW(), d.date_due) BETWEEN 0 AND 30 THEN d.amount ELSE 0 END) AS bucket_0_30,';
		$sql .= ' SUM(CASE WHEN DATEDIFF(NOW(), d.date_due) BETWEEN 31 AND 60 THEN d.amount ELSE 0 END) AS bucket_31_60,';
		$sql .= ' SUM(CASE WHEN DATEDIFF(NOW(), d.date_due) BETWEEN 61 AND 90 THEN d.amount ELSE 0 END) AS bucket_61_90,';
		$sql .= ' SUM(CASE WHEN DATEDIFF(NOW(), d.date_due) > 90 THEN d.amount ELSE 0 END) AS bucket_90_plus,';
		$sql .= ' SUM(CASE WHEN DATEDIFF(NOW(), d.date_due) BETWEEN 0 AND 30 THEN 1 ELSE 0 END) AS count_0_30,';
		$sql .= ' SUM(CASE WHEN DATEDIFF(NOW(), d.date_due) BETWEEN 31 AND 60 THEN 1 ELSE 0 END) AS count_31_60,';
		$sql .= ' SUM(CASE WHEN DATEDIFF(NOW(), d.date_due) BETWEEN 61 AND 90 THEN 1 ELSE 0 END) AS count_61_90,';
		$sql .= ' SUM(CASE WHEN DATEDIFF(NOW(), d.date_due) > 90 THEN 1 ELSE 0 END) AS count_90_plus';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l ON l.rowid = d.fk_commission_line AND l.entity = d.entity';
		$sql .= ' WHERE d.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_due')).')';
		$sql .= ' AND d.status = '.LmdbSalesCommissionDueService::STATUS_DUE.$where;
		$row = $this->fetchSingleRow($sql);

		return array(
			array('label' => 'LmdbSalesCommissionsAging0To30', 'amount' => (float) ($row['bucket_0_30'] ?? 0), 'count' => (int) ($row['count_0_30'] ?? 0)),
			array('label' => 'LmdbSalesCommissionsAging31To60', 'amount' => (float) ($row['bucket_31_60'] ?? 0), 'count' => (int) ($row['count_31_60'] ?? 0)),
			array('label' => 'LmdbSalesCommissionsAging61To90', 'amount' => (float) ($row['bucket_61_90'] ?? 0), 'count' => (int) ($row['count_61_90'] ?? 0)),
			array('label' => 'LmdbSalesCommissionsAgingMoreThan90', 'amount' => (float) ($row['bucket_90_plus'] ?? 0), 'count' => (int) ($row['count_90_plus'] ?? 0)),
		);
	}

	/**
	 * Return dashboard anomalies.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @param int              $limit   Max rows
	 * @return array<int, DashboardRow>
	 */
	public function getAnomalies(array $filters, $user, $limit = 20)
	{
		if (!lmdbsalescommissionsCanConfigure($user) && !lmdbsalescommissionsCanDo($user, 'maintenance', 'recalculate')) {
			return array();
		}

		$rows = array();
		$this->appendAnomalyRows($rows, 'error', 'LmdbSalesCommissionsCheckIncompleteRules', 'LmdbSalesCommissionsCheckIncompleteRulesDesc', 'admin/rules.php', $this->getIncompleteRuleRows($limit));
		$this->appendAnomalyRows($rows, 'error', 'LmdbSalesCommissionsCheckInvalidPaymentTerms', 'LmdbSalesCommissionsCheckInvalidPaymentTermsDesc', 'admin/paymentterms.php', $this->getInvalidPaymentTermRows($limit));
		$this->appendAnomalyRows($rows, 'error', 'LmdbSalesCommissionsCheckInvalidTierGrids', 'LmdbSalesCommissionsCheckInvalidTierGridsDesc', 'admin/tiergrids.php', $this->getInvalidTierGridRows($limit));
		$this->appendAnomalyRows($rows, 'error', 'LmdbSalesCommissionsCheckInvalidDispatches', 'LmdbSalesCommissionsCheckInvalidDispatchesDesc', 'admin/checks.php', $this->getInvalidDispatchRows($limit));
		$this->appendAnomalyRows($rows, 'error', 'LmdbSalesCommissionsCheckInvalidTurnoverDispatches', 'LmdbSalesCommissionsCheckInvalidTurnoverDispatchesDesc', 'admin/checks.php', $this->getInvalidTurnoverDispatchRows($limit));
		$this->appendAnomalyRows($rows, 'warning', 'LmdbSalesCommissionsCheckOrphanLines', 'LmdbSalesCommissionsCheckOrphanLinesDesc', 'list.php', $this->getOrphanLineRows($filters, $user, $limit));
		$this->appendAnomalyRows($rows, 'error', 'LmdbSalesCommissionsCheckDueMismatch', 'LmdbSalesCommissionsCheckDueMismatchDesc', 'due.php', $this->getDueMismatchRows($filters, $user, $limit));
		$this->appendAnomalyRows($rows, 'warning', 'LmdbSalesCommissionsCheckObjectivesWithoutUser', 'LmdbSalesCommissionsCheckObjectivesWithoutUserDesc', 'admin/objectives.php', $this->getObjectivesWithoutUserRows($limit));
		$this->appendAnomalyRows($rows, 'error', 'LmdbSalesCommissionsCheckInvalidObjectives', 'LmdbSalesCommissionsCheckInvalidObjectivesDesc', 'admin/objectives.php', $this->getInvalidObjectiveRows($limit));
		$this->appendAnomalyRows($rows, 'warning', 'LmdbSalesCommissionsCheckMissingArchives', 'LmdbSalesCommissionsCheckMissingArchivesDesc', 'admin/maintenance.php', $this->getMissingArchiveRows($limit));

		return array_slice($rows, 0, $limit);
	}

	/**
	 * Return signed base totals with source deduplication.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @param bool             $excludeDispatch Exclude manual dispatches from performance bases
	 * @return array{turnover:float, margin:float, count:int}
	 */
	private function getSignedBaseTotals(array $filters, $user, $excludeDispatch = false)
	{
		$where = $this->buildLineWhere('l', $filters, $user, 'date_acquired');
		if ($excludeDispatch) {
			$where .= " AND l.mode <> 'dispatch'";
		}
		$sql = 'SELECT SUM(src.amount_base) AS turnover, SUM(src.margin_base) AS margin, COUNT(*) AS nb';
		$sql .= ' FROM (';
		$sql .= ' SELECT l.entity, l.source_type, l.fk_source,';
		$sql .= ' '.LmdbSalesCommissionTurnoverService::buildAttributedAmountExpression('l', true).' AS amount_base,';
		$sql .= " MAX(CASE WHEN l.mode <> 'turnover' THEN l.margin_base END) AS margin_base";
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
		$sql .= ' WHERE l.status = 1'.$where;
		$sql .= ' GROUP BY l.entity, l.source_type, l.fk_source';
		$sql .= ') AS src';
		$row = $this->fetchSingleRow($sql);

		return array(
			'turnover' => (float) ($row['turnover'] ?? 0),
			'margin' => (float) ($row['margin'] ?? 0),
			'count' => (int) ($row['nb'] ?? 0),
		);
	}

	/**
	 * Return due amount by status.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @param int              $status  Due status
	 * @return float
	 */
	private function getDueAmount(array $filters, $user, $status)
	{
		$where = $this->buildLineWhere('l', $filters, $user, 'date_acquired');
		$sql = 'SELECT SUM(d.amount) AS amount';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l ON l.rowid = d.fk_commission_line AND l.entity = d.entity';
		$sql .= ' WHERE d.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_due')).')';
		$sql .= ' AND d.status = '.((int) $status).$where;
		$row = $this->fetchSingleRow($sql);

		return (float) ($row['amount'] ?? 0);
	}

	/**
	 * Return monthly sums for acquired lines.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @param int              $year    Year
	 * @param string           $field   Numeric field
	 * @return array<int, float>
	 */
	private function getMonthlySums(array $filters, $user, $year, $field)
	{
		$allowedFields = array('amount_base', 'margin_base', 'commission_total');
		if (!in_array($field, $allowedFields, true)) {
			return array();
		}
		$localFilters = $filters;
		$localFilters['year'] = $year;
		$localFilters['month'] = 0;
		$where = $this->buildLineWhere('l', $localFilters, $user, 'date_acquired');
		if ($field === 'commission_total') {
			$sql = 'SELECT MONTH(l.date_acquired) AS monthnum, SUM(l.commission_total) AS amount';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
			$sql .= ' WHERE l.status = 1'.$where;
			$sql .= ' GROUP BY MONTH(l.date_acquired)';
		} elseif ($field === 'amount_base') {
			$sql = 'SELECT src.monthnum, SUM(src.amount) AS amount FROM (';
			$sql .= ' SELECT MONTH(l.date_acquired) AS monthnum, l.entity, l.source_type, l.fk_source,';
			$sql .= ' '.LmdbSalesCommissionTurnoverService::buildAttributedAmountExpression('l', true).' AS amount';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
			$sql .= ' WHERE l.status = 1'.$where;
			$sql .= ' GROUP BY MONTH(l.date_acquired), l.entity, l.source_type, l.fk_source';
			$sql .= ') AS src GROUP BY src.monthnum';
		} else {
			$sql = 'SELECT src.monthnum, SUM(src.amount) AS amount FROM (';
			$sql .= ' SELECT MONTH(l.date_acquired) AS monthnum, l.entity, l.source_type, l.fk_source, MAX(l.'.$field.') AS amount';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
			$sql .= ' WHERE l.status = 1'.$where;
			$sql .= ' GROUP BY MONTH(l.date_acquired), l.entity, l.source_type, l.fk_source';
			$sql .= ') AS src GROUP BY src.monthnum';
		}
		$rows = $this->fetchRows($sql);
		$values = array();
		foreach ($rows as $row) {
			$values[(int) $row['monthnum']] = (float) $row['amount'];
		}

		return $values;
	}

	/**
	 * Return objective target for a scope.
	 *
	 * @param string           $objectiveType Objective type
	 * @param DashboardFilters $filters       Normalized filters
	 * @param User             $user          Current user
	 * @param int              $year          Year
	 * @param int              $month         Month
	 * @return float|null
	 */
	private function getObjectiveTarget($objectiveType, array $filters, $user, $year, $month)
	{
		if ($filters['fk_user'] > 0) {
			$resolver = new LmdbSalesCommissionObjectiveResolver($this->db);
			$resolved = $resolver->resolveForUser($filters['fk_user'], $objectiveType, $year, $month, dol_now(), $filters['entity']);
			if (isset($resolved['selected']) && is_array($resolved['selected'])) {
				return (float) $resolved['selected']['target_value'];
			}

			return null;
		}

		$sql = 'SELECT SUM(o.target_value) AS target_value';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective AS o';
		$sql .= ' WHERE o.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_objective')).')';
		$sql .= ' AND o.active = 1';
		$sql .= " AND o.objective_type = '".$this->db->escape($objectiveType)."'";
		$sql .= ' AND o.year = '.((int) $year);
		if ($objectiveType === 'monthly') {
			$sql .= ' AND o.month = '.((int) $month);
		}
		if ($filters['fk_usergroup'] > 0) {
			$sql .= " AND ((o.assignment_type = 'group' AND o.fk_usergroup = ".((int) $filters['fk_usergroup']).")";
			$sql .= " OR (o.assignment_type = 'user' AND EXISTS (SELECT ugu.rowid FROM ".MAIN_DB_PREFIX.'usergroup_user AS ugu WHERE ugu.fk_user = o.fk_user AND ugu.fk_usergroup = '.((int) $filters['fk_usergroup']).' AND ugu.entity IN ('.$this->db->sanitize(getEntity('usergroup')).'))))';
		}
		$row = $this->fetchSingleRow($sql);
		$value = (float) ($row['target_value'] ?? 0);

		return $value > 0 ? $value : null;
	}

	/**
	 * Return users with objectives or commission lines in scope.
	 *
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @param int              $limit   Max rows
	 * @return array<int, DashboardRow>
	 */
	private function getObjectiveUsers(array $filters, $user, $limit)
	{
		if ($filters['fk_user'] > 0) {
			$sql = 'SELECT u.rowid AS fk_user, u.lastname, u.firstname, u.login, u.statut AS user_status, u.photo AS user_photo, u.email AS user_email, "" AS group_name';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'user AS u';
			$sql .= ' WHERE u.rowid = '.((int) $filters['fk_user']);
			return $this->fetchRows($sql);
		}

		$where = $this->buildLineWhere('l', $filters, $user, 'date_acquired');
		$sql = 'SELECT l.fk_user, u.lastname, u.firstname, u.login, u.statut AS user_status, u.photo AS user_photo, u.email AS user_email, "" AS group_name';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = l.fk_user';
		$sql .= ' WHERE 1 = 1'.$where;
		$sql .= ' GROUP BY l.fk_user, u.lastname, u.firstname, u.login, u.statut, u.photo, u.email';
		$sql .= ' ORDER BY u.lastname ASC, u.firstname ASC, u.login ASC';
		$sql .= $this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * Build SQL conditions for commission lines.
	 *
	 * @param string           $alias     Line table alias
	 * @param DashboardFilters $filters   Normalized filters
	 * @param User             $user      Current user
	 * @param string           $dateField Date field
	 * @return string
	 */
	private function buildLineWhere($alias, array $filters, $user, $dateField)
	{
		$sql = ' AND '.$alias.'.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_line')).')';
		$sql .= $this->scopeMode === 'export' ? lmdbsalescommissionsBuildExportScopeSql($user, $alias) : lmdbsalescommissionsBuildCommissionScopeSql($this->db, $user, $alias);
		if ($filters['fk_user'] > 0) {
			$canUseUserScope = $this->scopeMode === 'export' ? lmdbsalescommissionsCanExportUserScope($user, $filters['fk_user']) : lmdbsalescommissionsCanReadUserScope($user, $filters['fk_user']);
			if (!$canUseUserScope) {
				return ' AND 1 = 0';
			}
			$sql .= ' AND '.$alias.'.fk_user = '.((int) $filters['fk_user']);
		}
		if ($filters['fk_usergroup'] > 0) {
			$sql .= ' AND EXISTS (SELECT ugu.rowid FROM '.MAIN_DB_PREFIX.'usergroup_user AS ugu WHERE ugu.fk_user = '.$alias.'.fk_user AND ugu.fk_usergroup = '.((int) $filters['fk_usergroup']).' AND ugu.entity IN ('.$this->db->sanitize(getEntity('usergroup')).'))';
		}
		if ($filters['source'] !== 'all') {
			$sql .= " AND ".$alias.".source_type = '".$this->db->escape($filters['source'])."'";
		}
		if ($filters['commission_type'] !== 'all') {
			$sql .= " AND ".$alias.".mode = '".$this->db->escape($filters['commission_type'])."'";
		}
		if ($filters['year'] > 0) {
			$sql .= ' AND YEAR('.$alias.'.'.$dateField.') = '.((int) $filters['year']);
		}
		if ($filters['month'] > 0) {
			$sql .= ' AND MONTH('.$alias.'.'.$dateField.') = '.((int) $filters['month']);
		}
		if ($filters['date_start'] > 0) {
			$sql .= ' AND '.$alias.'.'.$dateField." >= '".$this->db->idate($filters['date_start'])."'";
		}
		if ($filters['date_end'] > 0) {
			$sql .= ' AND '.$alias.'.'.$dateField." <= '".$this->db->idate($filters['date_end'])."'";
		}
		$statusMap = array(
			'estimated' => 0,
			'acquired' => 1,
			'cancelled' => 6,
			'blocked' => 7,
		);
		if (isset($statusMap[$filters['status']])) {
			$sql .= ' AND '.$alias.'.status = '.((int) $statusMap[$filters['status']]);
		} elseif ($filters['status'] === 'payable') {
			$sql .= ' AND '.$alias.'.payable_total > '.$alias.'.paid_total';
		} elseif ($filters['status'] === 'paid') {
			$sql .= ' AND '.$alias.'.paid_total > 0';
		}

		return $sql;
	}

	/**
	 * Fetch one SQL row.
	 *
	 * @param string $sql SQL query
	 * @return array<string, mixed>
	 */
	private function fetchSingleRow($sql)
	{
		$rows = $this->fetchRows($sql, 1);

		return isset($rows[0]) ? $rows[0] : array();
	}

	/**
	 * Fetch SQL rows.
	 *
	 * @param string $sql   SQL query
	 * @param int    $limit Optional max rows
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchRows($sql, $limit = 0)
	{
		$rows = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			dol_syslog(__METHOD__.': '.$this->error, LOG_ERR);
			return $rows;
		}

		$count = 0;
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$row = array();
			foreach (get_object_vars($obj) as $key => $value) {
				$row[$key] = $value;
			}
			$rows[] = $row;
			$count++;
			if ($limit > 0 && $count >= $limit) {
				break;
			}
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * Sort near-tier rows.
	 *
	 * @param DashboardRow $a First row
	 * @param DashboardRow $b Second row
	 * @return int
	 */
	private function sortNearTierRows(array $a, array $b)
	{
		return ((float) $a['remaining']) <=> ((float) $b['remaining']);
	}

	/**
	 * Sort objective rows by lowest rate.
	 *
	 * @param DashboardRow $a First row
	 * @param DashboardRow $b Second row
	 * @return int
	 */
	private function sortObjectiveRows(array $a, array $b)
	{
		return ((float) $a['rate']) <=> ((float) $b['rate']);
	}

	/**
	 * Append anomalies from source rows.
	 *
	 * @param array<int, DashboardRow> $rows           Existing rows
	 * @param string                   $severity       Severity
	 * @param string                   $typeKey        Type translation key
	 * @param string                   $descriptionKey Description translation key
	 * @param string                   $path           Correction path
	 * @param array<int, DashboardRow> $sourceRows     Source rows
	 * @return void
	 */
	private function appendAnomalyRows(array &$rows, $severity, $typeKey, $descriptionKey, $path, array $sourceRows)
	{
		foreach ($sourceRows as $sourceRow) {
			$rows[] = array(
				'severity' => $severity,
				'type' => $typeKey,
				'element' => isset($sourceRow['ref']) && (string) $sourceRow['ref'] !== '' ? (string) $sourceRow['ref'] : (isset($sourceRow['rowid']) ? '#'.((int) $sourceRow['rowid']) : ''),
				'description' => $descriptionKey,
				'action' => 'LmdbSalesCommissionsAnomalyActionReviewConfiguration',
				'url' => dol_buildpath('/lmdbsalescommissions/'.$path, 1),
			);
		}
	}

	/**
	 * @param int $limit Max rows
	 * @return array<int, DashboardRow>
	 */
	private function getIncompleteRuleRows($limit)
	{
		$sql = 'SELECT rowid, ref';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule';
		$sql .= ' WHERE entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_rule')).')';
		$sql .= ' AND active = 1';
		$sql .= " AND ((rule_type = 'margin' AND (rate IS NULL OR rate <= 0)) OR (rule_type = 'tier' AND (fk_tier_grid IS NULL OR fk_tier_grid <= 0)))";
		$sql .= ' ORDER BY rowid DESC'.$this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * @param int $limit Max rows
	 * @return array<int, DashboardRow>
	 */
	private function getInvalidPaymentTermRows($limit)
	{
		$sql = 'SELECT pt.rowid, pt.ref';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term AS pt';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term_line AS ptl ON ptl.fk_payment_term = pt.rowid AND ptl.entity = pt.entity';
		$sql .= ' WHERE pt.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_payment_term')).')';
		$sql .= ' AND pt.active = 1';
		$sql .= ' GROUP BY pt.rowid, pt.ref';
		$sql .= ' HAVING SUM(CASE WHEN ptl.active = 1 THEN ptl.percentage ELSE 0 END) <> 100 OR SUM(CASE WHEN ptl.active = 1 THEN ptl.percentage ELSE 0 END) IS NULL';
		$sql .= ' ORDER BY pt.rowid DESC'.$this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * @param int $limit Max rows
	 * @return array<int, DashboardRow>
	 */
	private function getInvalidTierGridRows($limit)
	{
		$sql = 'SELECT tg.rowid, tg.ref';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_tier_grid AS tg';
		$sql .= ' WHERE tg.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_tier_grid')).')';
		$sql .= ' AND tg.active = 1';
		$sql .= " AND (tg.calculation_mode NOT IN ('fixed_bonus','progressive_rate')";
		$sql .= ' OR NOT EXISTS (SELECT t.rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_tier AS t WHERE t.fk_tier_grid = tg.rowid AND t.entity = tg.entity AND t.active = 1)';
		$sql .= ' OR EXISTS (SELECT t.rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_tier AS t WHERE t.fk_tier_grid = tg.rowid AND t.entity = tg.entity AND t.active = 1 AND (t.threshold_amount <= 0';
		$sql .= " OR (tg.calculation_mode = 'fixed_bonus' AND t.bonus_amount < 0)";
		$sql .= " OR (tg.calculation_mode = 'progressive_rate' AND (t.commission_rate IS NULL OR t.commission_rate < 0 OR t.commission_rate > 100))))";
		$sql .= ' OR EXISTS (SELECT t1.rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_tier AS t1 INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_tier AS t2 ON t2.entity = t1.entity AND t2.fk_tier_grid = t1.fk_tier_grid AND t2.active = 1 AND t2.rang > t1.rang AND t2.threshold_amount <= t1.threshold_amount WHERE t1.fk_tier_grid = tg.rowid AND t1.entity = tg.entity AND t1.active = 1)';
		$sql .= " OR (tg.calculation_mode = 'progressive_rate' AND NOT EXISTS (SELECT t.rowid FROM ".MAIN_DB_PREFIX.'lmdbsalescommissions_tier AS t WHERE t.fk_tier_grid = tg.rowid AND t.entity = tg.entity AND t.active = 1 AND t.commission_rate > 0 AND t.commission_rate <= 100))';
		$sql .= ')';
		$sql .= ' ORDER BY tg.rowid DESC'.$this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * @param int $limit Max rows
	 * @return array<int, DashboardRow>
	 */
	private function getInvalidDispatchRows($limit)
	{
		$sql = 'SELECT d.rowid, COALESCE(p.ref, CONCAT("#", d.fk_propal)) AS ref';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_proposal_dispatch AS d';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'propal AS p ON p.rowid = d.fk_propal AND p.entity = d.entity';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = d.fk_user';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term AS pt ON pt.rowid = d.fk_payment_term AND pt.entity = d.entity';
		$sql .= ' WHERE d.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_proposal_dispatch')).')';
		$sql .= " AND (p.rowid IS NULL OR u.rowid IS NULL OR u.statut <> 1 OR u.entity NOT IN (0, d.entity) OR d.base_type NOT IN ('margin','turnover') OR d.value_type NOT IN ('amount','percentage') OR d.value <= 0 OR (d.value_type = 'percentage' AND d.value > 100) OR d.payment_term_mode NOT IN ('automatic','explicit') OR (d.payment_term_mode = 'automatic' AND d.fk_payment_term IS NOT NULL) OR (d.payment_term_mode = 'explicit' AND (pt.rowid IS NULL OR pt.active <> 1 OR ABS((SELECT COALESCE(SUM(ptl.percentage), 0) FROM ".MAIN_DB_PREFIX."lmdbsalescommissions_payment_term_line AS ptl WHERE ptl.entity = d.entity AND ptl.fk_payment_term = d.fk_payment_term AND ptl.active = 1) - 100) > 0.0001)) OR (d.base_type = 'turnover' AND d.value_type = 'amount' AND d.value > GREATEST(p.total_ht, 0)) OR (d.base_type = 'margin' AND NOT EXISTS (SELECT pd.rowid FROM ".MAIN_DB_PREFIX."propaldet AS pd WHERE pd.fk_propal = d.fk_propal AND pd.pa_ht IS NOT NULL)) OR (d.base_type = 'margin' AND d.value_type = 'amount' AND d.value > GREATEST(COALESCE((SELECT SUM(pd.total_ht - (pd.pa_ht * pd.qty)) FROM ".MAIN_DB_PREFIX."propaldet AS pd WHERE pd.fk_propal = d.fk_propal AND pd.pa_ht IS NOT NULL), 0), 0)))";
		$sql .= ' ORDER BY d.rowid DESC'.$this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * @param int $limit Max rows
	 * @return array<int, DashboardRow>
	 */
	private function getInvalidTurnoverDispatchRows($limit)
	{
		$sql = 'SELECT MIN(d.rowid) AS rowid, COALESCE(p.ref, CONCAT("#", d.fk_propal)) AS ref, p.total_ht, p.date_signature,';
		$sql .= " SUM(CASE WHEN d.value_type = 'percentage' THEN GREATEST(COALESCE(p.total_ht, 0), 0) * d.value / 100 ELSE d.value END) AS allocated_total,";
		$sql .= " SUM(CASE WHEN p.rowid IS NULL OR u.rowid IS NULL OR u.statut <> 1 OR u.entity NOT IN (0, d.entity) OR d.value_type NOT IN ('amount','percentage') OR d.value <= 0 OR (d.value_type = 'percentage' AND d.value > 100) OR (d.value_type = 'amount' AND d.value > GREATEST(COALESCE(p.total_ht, 0), 0)) THEN 1 ELSE 0 END) AS invalid_rows";
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_proposal_turnover_dispatch AS d';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'propal AS p ON p.rowid = d.fk_propal AND p.entity = d.entity';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = d.fk_user';
		$sql .= ' WHERE d.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_proposal_turnover_dispatch')).')';
		$sql .= ' GROUP BY d.entity, d.fk_propal, p.ref, p.rowid, p.total_ht, p.date_signature';
		$sql .= ' ORDER BY MIN(d.rowid) DESC';
		$candidates = $this->fetchRows($sql);
		$rows = array();
		foreach ($candidates as $candidate) {
			$total = (float) price2num(max(0, (float) $candidate['total_ht']), 'MT');
			$allocated = (float) price2num($candidate['allocated_total'], 'MT');
			$isInvalid = (int) $candidate['invalid_rows'] > 0 || $allocated > $total;
			if (!empty($candidate['date_signature']) && $allocated !== $total) {
				$isInvalid = true;
			}
			if ($isInvalid) {
				$rows[] = array('rowid' => (int) $candidate['rowid'], 'ref' => (string) $candidate['ref']);
			}
			if (count($rows) >= $limit) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @param int              $limit   Max rows
	 * @return array<int, DashboardRow>
	 */
	private function getOrphanLineRows(array $filters, $user, $limit)
	{
		$where = $this->buildLineWhere('l', $filters, $user, 'date_acquired');
		$sql = 'SELECT l.rowid, l.source_ref AS ref';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."propal AS p ON p.rowid = l.fk_source AND l.source_type = 'proposal'";
		$sql .= " WHERE l.source_type = 'proposal' AND p.rowid IS NULL".$where;
		$sql .= ' ORDER BY l.rowid DESC'.$this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * @param DashboardFilters $filters Normalized filters
	 * @param User             $user    Current user
	 * @param int              $limit   Max rows
	 * @return array<int, DashboardRow>
	 */
	private function getDueMismatchRows(array $filters, $user, $limit)
	{
		$where = $this->buildLineWhere('l', $filters, $user, 'date_acquired');
		$sql = 'SELECT l.rowid, l.source_ref AS ref';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d ON d.fk_commission_line = l.rowid AND d.entity = l.entity AND d.status <> 3';
		$sql .= ' WHERE l.status = 1'.$where;
		$sql .= ' GROUP BY l.rowid, l.source_ref, l.commission_total';
		$sql .= ' HAVING ABS(l.commission_total - COALESCE(SUM(d.amount), 0)) > 0.01';
		$sql .= ' ORDER BY l.rowid DESC'.$this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * @param int $limit Max rows
	 * @return array<int, DashboardRow>
	 */
	private function getObjectivesWithoutUserRows($limit)
	{
		$sql = 'SELECT rowid, CONCAT(objective_type, " ", year, COALESCE(CONCAT("-", month), "")) AS ref';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective';
		$sql .= ' WHERE entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_objective')).')';
		$sql .= " AND active = 1 AND assignment_type = 'user' AND (fk_user IS NULL OR fk_user <= 0)";
		$sql .= ' ORDER BY rowid DESC'.$this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * @param int $limit Max rows
	 * @return array<int, DashboardRow>
	 */
	private function getInvalidObjectiveRows($limit)
	{
		$sql = 'SELECT rowid, CONCAT(objective_type, " ", year, COALESCE(CONCAT("-", month), "")) AS ref';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective';
		$sql .= ' WHERE entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_objective')).')';
		$sql .= " AND active = 1 AND (target_value < 0 OR year <= 0 OR (objective_type = 'monthly' AND (month IS NULL OR month < 1 OR month > 12)))";
		$sql .= ' ORDER BY rowid DESC'.$this->db->plimit($limit);

		return $this->fetchRows($sql);
	}

	/**
	 * @param int $limit Max rows
	 * @return array<int, DashboardRow>
	 */
	private function getMissingArchiveRows($limit)
	{
		$currentYear = (int) date('Y', dol_now());
		$sql = 'SELECT o.rowid, CONCAT(o.objective_type, " ", o.year, COALESCE(CONCAT("-", o.month), "")) AS ref';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective AS o';
		$sql .= ' WHERE o.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_objective')).')';
		$sql .= ' AND o.active = 1';
		$sql .= ' AND o.year < '.$currentYear;
		$sql .= ' AND NOT EXISTS (SELECT a.rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_objective_archive AS a WHERE a.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_objective_archive')).') AND a.fk_objective = o.rowid)';
		$sql .= ' ORDER BY o.rowid DESC'.$this->db->plimit($limit);

		return $this->fetchRows($sql);
	}
}
