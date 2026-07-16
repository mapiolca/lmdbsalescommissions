<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbsalescommissionproposalturnoverdispatch.class.php';
require_once __DIR__.'/lmdbsalescommissionproposalservice.class.php';

/**
 * Manage manual proposal turnover allocations.
 *
 * @phpstan-type TurnoverAllocation array{
 *     dispatch_id:int,
 *     user_id:int,
 *     amount:float,
 *     value_type:string,
 *     value:float,
 *     is_default:bool
 * }
 */
class LmdbSalesCommissionProposalTurnoverDispatchService
{
	public const VALUE_AMOUNT = 'amount';
	public const VALUE_PERCENTAGE = 'percentage';

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
	 * Fetch allocations for one proposal.
	 *
	 * @param int $proposalId Proposal id
	 * @param int $entity     Proposal entity
	 * @return array<int, LmdbSalesCommissionProposalTurnoverDispatch>
	 */
	public function fetchForProposal($proposalId, $entity)
	{
		$this->resetErrors();
		$rows = array();
		if ($proposalId <= 0 || $entity <= 0) {
			return $rows;
		}

		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_proposal_turnover_dispatch';
		$sql .= ' WHERE entity = '.((int) $entity).' AND fk_propal = '.((int) $proposalId).' ORDER BY rowid ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $rows;
		}
		$ids = array();
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$ids[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		foreach ($ids as $id) {
			$dispatch = new LmdbSalesCommissionProposalTurnoverDispatch($this->db);
			if ($dispatch->fetch($id) > 0 && (int) $dispatch->entity === $entity && (int) $dispatch->fk_propal === $proposalId) {
				$rows[] = $dispatch;
			}
		}

		return $rows;
	}

	/**
	 * Save one allocation and refresh a persisted estimate.
	 *
	 * @param LmdbSalesCommissionProposalTurnoverDispatch $dispatch Allocation
	 * @param object                                      $proposal Proposal
	 * @param User                                        $user     User
	 * @return int
	 */
	public function save($dispatch, $proposal, $user)
	{
		$this->resetErrors();
		if (!$this->isProposalEditable($proposal)) {
			$this->error = 'LmdbSalesCommissionsDispatchLocked';
			return -1;
		}
		if (!$this->validate($dispatch, $proposal)) {
			return -1;
		}
		if (!$this->validateAggregateWithCandidate($dispatch, $proposal)) {
			return -1;
		}

		$this->db->begin();
		$result = !empty($dispatch->id) ? $dispatch->update($user) : $dispatch->create($user);
		if ($result <= 0) {
			$this->error = $dispatch->error;
			$this->errors = $dispatch->errors;
			$this->db->rollback();
			return -1;
		}
		if ($this->refreshEstimateIfValidated($proposal, $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();

		return $result;
	}

	/**
	 * Delete one allocation.
	 *
	 * @param LmdbSalesCommissionProposalTurnoverDispatch $dispatch Allocation
	 * @param object                                      $proposal Proposal
	 * @param User                                        $user     User
	 * @return int
	 */
	public function delete($dispatch, $proposal, $user)
	{
		$this->resetErrors();
		if (!$this->isProposalEditable($proposal)) {
			$this->error = 'LmdbSalesCommissionsDispatchLocked';
			return -1;
		}
		if (!is_object($dispatch) || (int) $dispatch->entity !== (int) $proposal->entity || (int) $dispatch->fk_propal !== (int) $proposal->id) {
			$this->error = 'ErrorRecordNotFound';
			return -1;
		}

		$this->db->begin();
		if ($dispatch->delete($user) <= 0) {
			$this->error = $dispatch->error;
			$this->errors = $dispatch->errors;
			$this->db->rollback();
			return -1;
		}
		if ($this->refreshEstimateIfValidated($proposal, $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();

		return 1;
	}

	/**
	 * Delete configuration rows with their proposal.
	 *
	 * @param object $proposal Proposal
	 * @param User   $user     User
	 * @return int
	 */
	public function deleteForProposal($proposal, $user)
	{
		$rows = $this->fetchForProposal((int) ($proposal->id ?? 0), (int) ($proposal->entity ?? 0));
		if ($this->error !== '') {
			return -1;
		}
		$deleted = 0;
		foreach ($rows as $dispatch) {
			if ($dispatch->delete($user) <= 0) {
				$this->error = $dispatch->error;
				$this->errors = $dispatch->errors;
				return -1;
			}
			$deleted++;
		}

		return $deleted;
	}

	/**
	 * Resolve explicit allocations, or the automatic 100 percent fallback.
	 *
	 * @param object $proposal       Proposal
	 * @param bool   $requireComplete Require an exact total for explicit rows
	 * @return array<int, TurnoverAllocation>|null
	 */
	public function resolveForProposal($proposal, $requireComplete)
	{
		$this->resetErrors();
		$total = $this->getProposalTurnover($proposal);
		$rows = $this->fetchForProposal((int) ($proposal->id ?? 0), (int) ($proposal->entity ?? 0));
		if ($this->error !== '') {
			return null;
		}
		if (empty($rows)) {
			$userId = LmdbSalesCommissionProposalService::resolveSalesUserId($this->db, $proposal);
			if ($userId <= 0 || !$this->isUserUsable($userId, (int) ($proposal->entity ?? 0))) {
				$this->error = 'LmdbSalesCommissionsProposalWithoutSalesUser';
				return null;
			}

			return array(array(
				'dispatch_id' => 0,
				'user_id' => $userId,
				'amount' => $total,
				'value_type' => self::VALUE_PERCENTAGE,
				'value' => 100.0,
				'is_default' => true,
			));
		}

		$allocations = array();
		$sum = 0.0;
		foreach ($rows as $dispatch) {
			if (!$this->validate($dispatch, $proposal)) {
				return null;
			}
			$amount = $this->calculateAmount($dispatch, $total);
			$sum = (float) price2num($sum + $amount, 'MT');
			$allocations[] = array(
				'dispatch_id' => !empty($dispatch->id) ? (int) $dispatch->id : (int) $dispatch->rowid,
				'user_id' => (int) $dispatch->fk_user,
				'amount' => $amount,
				'value_type' => (string) $dispatch->value_type,
				'value' => (float) $dispatch->value,
				'is_default' => false,
			);
		}

		if ((float) price2num($sum, 'MT') > (float) price2num($total, 'MT')) {
			$this->error = 'LmdbSalesCommissionsTurnoverDispatchExceedsProposal';
			return null;
		}
		if ($requireComplete && (float) price2num($sum, 'MT') !== (float) price2num($total, 'MT')) {
			$this->error = 'LmdbSalesCommissionsTurnoverDispatchMustEqualProposal';
			return null;
		}

		return $allocations;
	}

	/**
	 * Calculate allocation amount.
	 *
	 * @param LmdbSalesCommissionProposalTurnoverDispatch $dispatch Allocation
	 * @param float                                       $total    Proposal turnover
	 * @return float
	 */
	public function calculateAmount($dispatch, $total)
	{
		$value = is_numeric($dispatch->value) ? (float) $dispatch->value : 0.0;
		if ((string) $dispatch->value_type === self::VALUE_PERCENTAGE) {
			return (float) price2num($total * $value / 100, 'MT');
		}

		return (float) price2num($value, 'MT');
	}

	/**
	 * Validate one allocation.
	 *
	 * @param LmdbSalesCommissionProposalTurnoverDispatch $dispatch Allocation
	 * @param object                                      $proposal Proposal
	 * @return bool
	 */
	public function validate($dispatch, $proposal)
	{
		if (!is_object($dispatch) || !is_object($proposal) || empty($proposal->id) || empty($proposal->entity)) {
			$this->errors[] = 'ErrorBadParameter';
		} elseif ((int) $dispatch->entity !== (int) $proposal->entity || (int) $dispatch->fk_propal !== (int) $proposal->id) {
			$this->errors[] = 'LmdbSalesCommissionsDispatchEntityMismatch';
		}
		if (!in_array((string) ($dispatch->value_type ?? ''), array(self::VALUE_AMOUNT, self::VALUE_PERCENTAGE), true)) {
			$this->errors[] = 'LmdbSalesCommissionsDispatchInvalidValueType';
		}
		if ((int) ($dispatch->fk_user ?? 0) <= 0 || !$this->isUserUsable((int) $dispatch->fk_user, (int) ($proposal->entity ?? 0))) {
			$this->errors[] = 'LmdbSalesCommissionsDispatchInvalidUser';
		} elseif ($this->hasDuplicateUser($dispatch)) {
			$this->errors[] = 'LmdbSalesCommissionsDispatchDuplicateUser';
		}

		$value = is_numeric($dispatch->value) ? (float) $dispatch->value : 0.0;
		$dispatch->value = (string) $dispatch->value_type === self::VALUE_AMOUNT ? price2num($value, 'MT') : price2num($value);
		$value = (float) $dispatch->value;
		if ($value <= 0) {
			$this->errors[] = 'LmdbSalesCommissionsDispatchValueMustBePositive';
		} elseif ((string) $dispatch->value_type === self::VALUE_PERCENTAGE && $value > 100) {
			$this->errors[] = 'LmdbSalesCommissionsDispatchPercentageTooHigh';
		} elseif ((string) $dispatch->value_type === self::VALUE_AMOUNT && $value > $this->getProposalTurnover($proposal)) {
			$this->errors[] = 'LmdbSalesCommissionsDispatchAmountExceedsBase';
		}

		if (!empty($this->errors)) {
			$this->error = $this->errors[0];
			return false;
		}

		return true;
	}

	/** @return bool */
	public function isProposalEditable($proposal)
	{
		if (!is_object($proposal) || empty($proposal->id)) {
			return false;
		}
		$status = property_exists($proposal, 'statut') ? (int) $proposal->statut : (property_exists($proposal, 'status') ? (int) $proposal->status : -1);
		$signatureDate = property_exists($proposal, 'date_signature') ? (int) $proposal->date_signature : 0;

		return $signatureDate <= 0 && in_array($status, array(0, 1), true);
	}

	/** @return bool */
	private function validateAggregateWithCandidate($candidate, $proposal)
	{
		$total = $this->getProposalTurnover($proposal);
		$rows = $this->fetchForProposal((int) $proposal->id, (int) $proposal->entity);
		if ($this->error !== '') {
			return false;
		}
		$currentId = !empty($candidate->id) ? (int) $candidate->id : (int) $candidate->rowid;
		$sum = $this->calculateAmount($candidate, $total);
		foreach ($rows as $row) {
			$rowId = !empty($row->id) ? (int) $row->id : (int) $row->rowid;
			if ($currentId > 0 && $rowId === $currentId) {
				continue;
			}
			$sum = (float) price2num($sum + $this->calculateAmount($row, $total), 'MT');
		}
		if ((float) price2num($sum, 'MT') > (float) price2num($total, 'MT')) {
			$this->error = 'LmdbSalesCommissionsTurnoverDispatchExceedsProposal';
			return false;
		}

		return true;
	}

	/** @return float */
	private function getProposalTurnover($proposal)
	{
		return property_exists($proposal, 'total_ht') && is_numeric($proposal->total_ht) ? (float) price2num(max(0, (float) $proposal->total_ht), 'MT') : 0.0;
	}

	/** @return bool */
	private function isUserUsable($userId, $entity)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.((int) $userId).' AND statut = 1 AND entity IN (0,'.((int) $entity).') LIMIT 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$result = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);

		return $result;
	}

	/** @return bool */
	private function hasDuplicateUser($dispatch)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_proposal_turnover_dispatch';
		$sql .= ' WHERE entity = '.((int) $dispatch->entity).' AND fk_propal = '.((int) $dispatch->fk_propal).' AND fk_user = '.((int) $dispatch->fk_user);
		$currentId = !empty($dispatch->id) ? (int) $dispatch->id : (int) $dispatch->rowid;
		if ($currentId > 0) {
			$sql .= ' AND rowid <> '.$currentId;
		}
		$sql .= ' LIMIT 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return true;
		}
		$result = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);

		return $result;
	}

	/** @return int */
	private function refreshEstimateIfValidated($proposal, $user)
	{
		$status = property_exists($proposal, 'statut') ? (int) $proposal->statut : (property_exists($proposal, 'status') ? (int) $proposal->status : -1);
		if ($status !== 1) {
			return 0;
		}
		require_once __DIR__.'/lmdbsalescommissionlineservice.class.php';
		$service = new LmdbSalesCommissionLineService($this->db);
		$result = $service->estimateFromProposal($proposal, $user);
		if ($result < 0) {
			$this->error = $service->error;
			$this->errors = $service->errors;
			return -1;
		}

		return $result;
	}

	/** @return void */
	private function resetErrors()
	{
		$this->error = '';
		$this->errors = array();
	}
}
