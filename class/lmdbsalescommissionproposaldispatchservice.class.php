<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbsalescommissionproposaldispatch.class.php';
require_once __DIR__.'/lmdbsalescommissionproposalservice.class.php';
require_once __DIR__.'/lmdbsalescommissionruleresolver.class.php';

/**
 * @phpstan-type DispatchCalculation array{
 *     turnover:float,
 *     margin:float|null,
 *     base:float,
 *     commission:float,
 *     rate:float|null,
 *     payment_term_id:int,
 *     payment_term_label:string
 * }
 */

/**
 * Manage and calculate manual proposal commission dispatches.
 */
class LmdbSalesCommissionProposalDispatchService
{
	public const BASE_MARGIN = 'margin';
	public const BASE_TURNOVER = 'turnover';
	public const VALUE_AMOUNT = 'amount';
	public const VALUE_PERCENTAGE = 'percentage';
	public const PAYMENT_AUTOMATIC = 'automatic';
	public const PAYMENT_EXPLICIT = 'explicit';

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
	 * Fetch all dispatches for a proposal in its owning entity.
	 *
	 * @param int $proposalId Proposal id
	 * @param int $entity     Proposal entity
	 * @return array<int, LmdbSalesCommissionProposalDispatch>
	 */
	public function fetchForProposal($proposalId, $entity)
	{
		$this->resetErrors();
		$rows = array();
		if ($proposalId <= 0 || $entity <= 0) {
			return $rows;
		}

		$sql = 'SELECT rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_proposal_dispatch';
		$sql .= ' WHERE entity = '.((int) $entity);
		$sql .= ' AND fk_propal = '.((int) $proposalId);
		$sql .= ' ORDER BY rowid ASC';
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
			$dispatch = new LmdbSalesCommissionProposalDispatch($this->db);
			if ($dispatch->fetch($id) > 0 && (int) $dispatch->entity === $entity && (int) $dispatch->fk_propal === $proposalId) {
				$rows[] = $dispatch;
			}
		}

		return $rows;
	}

	/**
	 * Check whether a proposal has at least one manual dispatch.
	 *
	 * @param int $proposalId Proposal id
	 * @param int $entity     Proposal entity
	 * @return bool
	 */
	public function hasForProposal($proposalId, $entity)
	{
		$this->resetErrors();
		$sql = 'SELECT rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_proposal_dispatch';
		$sql .= ' WHERE entity = '.((int) $entity);
		$sql .= ' AND fk_propal = '.((int) $proposalId);
		$sql .= ' LIMIT 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$found = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);

		return $found;
	}

	/**
	 * Create or update a dispatch and refresh a stored estimate when applicable.
	 *
	 * @param LmdbSalesCommissionProposalDispatch $dispatch Dispatch to save
	 * @param object                              $proposal Current proposal
	 * @param User                                $user     Current user
	 * @return int Record id on creation, 1 on update, -1 on error
	 */
	public function save($dispatch, $proposal, $user)
	{
		$this->resetErrors();
		if (!$this->isProposalEditable($proposal)) {
			$this->error = 'LmdbSalesCommissionsDispatchLocked';
			return -1;
		}
		if (!is_array($this->calculate($dispatch, $proposal, dol_now()))) {
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
	 * Delete a dispatch and restore automatic estimation when the last row is removed.
	 *
	 * @param LmdbSalesCommissionProposalDispatch $dispatch Dispatch to delete
	 * @param object                              $proposal Current proposal
	 * @param User                                $user     Current user
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
		$result = $dispatch->delete($user);
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

		return 1;
	}

	/**
	 * Delete all dispatch configuration rows when their proposal is deleted.
	 *
	 * @param object $proposal Deleted proposal
	 * @param User   $user     Triggering user
	 * @return int Number of deleted rows, -1 on error
	 */
	public function deleteForProposal($proposal, $user)
	{
		$this->resetErrors();
		if (!is_object($proposal) || empty($proposal->id) || empty($proposal->entity)) {
			return 0;
		}
		$dispatches = $this->fetchForProposal((int) $proposal->id, (int) $proposal->entity);
		if ($this->error !== '') {
			return -1;
		}
		$deleted = 0;
		foreach ($dispatches as $dispatch) {
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
	 * Validate and calculate one dispatch.
	 *
	 * @param LmdbSalesCommissionProposalDispatch $dispatch Dispatch
	 * @param object                              $proposal Proposal
	 * @param int                                 $date     Business date
	 * @return DispatchCalculation|null
	 */
	public function calculate($dispatch, $proposal, $date)
	{
		$this->resetErrors();
		if (!$this->validate($dispatch, $proposal, $date)) {
			return null;
		}

		$turnover = property_exists($proposal, 'total_ht') && is_numeric($proposal->total_ht) ? (float) price2num($proposal->total_ht, 'MT') : 0.0;
		$marginValue = LmdbSalesCommissionProposalService::getEstimatedMargin($proposal);
		$margin = $marginValue !== null ? (float) price2num(max(0, $marginValue), 'MT') : null;
		$base = (string) $dispatch->base_type === self::BASE_MARGIN ? (float) $margin : max(0, $turnover);
		$value = (float) $dispatch->value;
		$commission = (string) $dispatch->value_type === self::VALUE_PERCENTAGE ? (float) price2num($base * $value / 100, 'MT') : (float) price2num($value, 'MT');
		$paymentTermId = $this->resolvePaymentTermId($dispatch, $date);
		if ($paymentTermId < 0) {
			return null;
		}

		return array(
			'turnover' => $turnover,
			'margin' => $margin,
			'base' => $base,
			'commission' => $commission,
			'rate' => (string) $dispatch->value_type === self::VALUE_PERCENTAGE ? $value : null,
			'payment_term_id' => $paymentTermId,
			'payment_term_label' => $this->getPaymentTermLabel($paymentTermId),
		);
	}

	/**
	 * Return the acquired snapshot after signature, or the current estimate before signature.
	 *
	 * @param LmdbSalesCommissionProposalDispatch $dispatch Dispatch
	 * @param object                              $proposal Proposal
	 * @param int                                 $date     Current business date
	 * @return DispatchCalculation|null
	 */
	public function getCalculationForDisplay($dispatch, $proposal, $date)
	{
		$signatureDate = is_object($proposal) && property_exists($proposal, 'date_signature') ? (int) $proposal->date_signature : 0;
		if ($signatureDate > 0) {
			$snapshot = $this->fetchAcquiredCalculation($dispatch);
			if (is_array($snapshot)) {
				return $snapshot;
			}
		}

		return $this->calculate($dispatch, $proposal, $date);
	}

	/**
	 * Validate a dispatch against the current proposal values.
	 *
	 * @param LmdbSalesCommissionProposalDispatch $dispatch Dispatch
	 * @param object                              $proposal Proposal
	 * @param int                                 $date     Business date
	 * @return bool
	 */
	public function validate($dispatch, $proposal, $date)
	{
		unset($date);
		if (!is_object($dispatch)) {
			$this->errors[] = 'ErrorBadParameter';
			$this->error = $this->errors[0];
			return false;
		}
		if (!is_object($proposal) || empty($proposal->id) || empty($proposal->entity)) {
			$this->errors[] = 'LmdbSalesCommissionsInvalidProposal';
			$this->error = $this->errors[0];
			return false;
		}
		if ((int) $dispatch->fk_propal !== (int) $proposal->id || (int) $dispatch->entity !== (int) $proposal->entity) {
			$this->errors[] = 'LmdbSalesCommissionsDispatchEntityMismatch';
		}
		if (!in_array((string) $dispatch->base_type, array(self::BASE_MARGIN, self::BASE_TURNOVER), true)) {
			$this->errors[] = 'LmdbSalesCommissionsDispatchInvalidBase';
		}
		if (!in_array((string) $dispatch->value_type, array(self::VALUE_AMOUNT, self::VALUE_PERCENTAGE), true)) {
			$this->errors[] = 'LmdbSalesCommissionsDispatchInvalidValueType';
		}
		if (!in_array((string) $dispatch->payment_term_mode, array(self::PAYMENT_AUTOMATIC, self::PAYMENT_EXPLICIT), true)) {
			$this->errors[] = 'LmdbSalesCommissionsDispatchInvalidPaymentMode';
		}
		if ((int) $dispatch->fk_user <= 0 || !$this->isUserUsable((int) $dispatch->fk_user, (int) $proposal->entity)) {
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
		}

		$turnover = property_exists($proposal, 'total_ht') && is_numeric($proposal->total_ht) ? (float) price2num($proposal->total_ht, 'MT') : 0.0;
		$marginValue = LmdbSalesCommissionProposalService::getEstimatedMargin($proposal);
		$margin = $marginValue !== null ? (float) price2num(max(0, $marginValue), 'MT') : null;
		if ((string) $dispatch->base_type === self::BASE_MARGIN && $margin === null) {
			$this->errors[] = 'LmdbSalesCommissionsMarginNotComputable';
		}
		$base = (string) $dispatch->base_type === self::BASE_MARGIN ? (float) $margin : max(0, $turnover);
		if ((string) $dispatch->value_type === self::VALUE_AMOUNT && $value > $base) {
			$this->errors[] = 'LmdbSalesCommissionsDispatchAmountExceedsBase';
		}

		if ((string) $dispatch->payment_term_mode === self::PAYMENT_EXPLICIT) {
			if ((int) $dispatch->fk_payment_term <= 0 || !$this->isPaymentTermUsable((int) $dispatch->fk_payment_term, (int) $proposal->entity)) {
				$this->errors[] = 'LmdbSalesCommissionsDispatchInvalidPaymentTerm';
			}
		} else {
			$dispatch->fk_payment_term = null;
		}

		if (!empty($this->errors)) {
			$this->error = $this->errors[0];
			return false;
		}

		return true;
	}

	/**
	 * Resolve explicit or inherited payment term.
	 *
	 * @param LmdbSalesCommissionProposalDispatch $dispatch Dispatch
	 * @param int                                 $date     Business date
	 * @return int Positive term id, 0 for immediate payment, -1 on error
	 */
	public function resolvePaymentTermId($dispatch, $date)
	{
		$entity = (int) $dispatch->entity;
		if ((string) $dispatch->payment_term_mode === self::PAYMENT_EXPLICIT) {
			$termId = (int) $dispatch->fk_payment_term;
			if (!$this->isPaymentTermUsable($termId, $entity)) {
				$this->errors[] = 'LmdbSalesCommissionsDispatchInvalidPaymentTerm';
				$this->error = 'LmdbSalesCommissionsDispatchInvalidPaymentTerm';
				return -1;
			}
			return $termId;
		}

		$resolver = new LmdbSalesCommissionRuleResolver($this->db);
		$profile = $resolver->resolveForUser((int) $dispatch->fk_user, $date, $entity, 'proposal');
		if (!empty($profile['errors'])) {
			$this->errors = array_merge($this->errors, $profile['errors']);
			$this->error = $profile['errors'][0];
			return -1;
		}

		foreach (array('margin', 'tier') as $ruleType) {
			$rule = $profile['selected'][$ruleType] ?? null;
			if (!is_array($rule)) {
				continue;
			}
			$termId = (int) ($rule['fk_payment_term'] ?? 0);
			if ($termId > 0 && $this->isPaymentTermUsable($termId, $entity)) {
				return $termId;
			}
		}

		return 0;
	}

	/**
	 * Get payment term label, including immediate fallback.
	 *
	 * @param int $paymentTermId Payment term id
	 * @return string Translation key or stored label
	 */
	public function getPaymentTermLabel($paymentTermId)
	{
		if ($paymentTermId <= 0) {
			return 'LmdbSalesCommissionsPaymentImmediateAtSignature';
		}

		$sql = 'SELECT ref, label FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term';
		$sql .= ' WHERE rowid = '.((int) $paymentTermId);
		$sql .= ' LIMIT 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return '';
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return is_object($obj) ? trim((string) $obj->ref.' - '.(string) $obj->label) : '#'.((int) $paymentTermId);
	}

	/**
	 * Fetch the frozen acquired line linked to a dispatch.
	 *
	 * @param LmdbSalesCommissionProposalDispatch $dispatch Dispatch
	 * @return DispatchCalculation|null
	 */
	private function fetchAcquiredCalculation($dispatch)
	{
		$dispatchId = !empty($dispatch->id) ? (int) $dispatch->id : (int) $dispatch->rowid;
		if ($dispatchId <= 0) {
			return null;
		}

		$sql = 'SELECT amount_base, margin_base, rate, commission_total, fk_payment_term, snapshot_base_type';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line';
		$sql .= ' WHERE entity = '.((int) $dispatch->entity);
		$sql .= " AND mode = 'dispatch'";
		$sql .= ' AND fk_proposal_dispatch = '.$dispatchId;
		$sql .= ' AND status = 1';
		$sql .= ' ORDER BY rowid DESC LIMIT 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!is_object($obj)) {
			return null;
		}

		$turnover = (float) price2num($obj->amount_base, 'MT');
		$margin = $obj->margin_base !== null ? (float) price2num($obj->margin_base, 'MT') : null;
		$base = (string) $obj->snapshot_base_type === self::BASE_MARGIN ? (float) $margin : $turnover;
		$paymentTermId = $obj->fk_payment_term !== null ? (int) $obj->fk_payment_term : 0;

		return array(
			'turnover' => $turnover,
			'margin' => $margin,
			'base' => $base,
			'commission' => (float) price2num($obj->commission_total, 'MT'),
			'rate' => $obj->rate !== null ? (float) $obj->rate : null,
			'payment_term_id' => $paymentTermId,
			'payment_term_label' => $this->getPaymentTermLabel($paymentTermId),
		);
	}

	/**
	 * Check whether the proposal may still be configured.
	 *
	 * @param object $proposal Proposal
	 * @return bool
	 */
	public function isProposalEditable($proposal)
	{
		if (!is_object($proposal) || empty($proposal->id)) {
			return false;
		}
		$status = property_exists($proposal, 'statut') ? (int) $proposal->statut : (property_exists($proposal, 'status') ? (int) $proposal->status : -1);
		$signatureDate = property_exists($proposal, 'date_signature') ? (int) $proposal->date_signature : 0;

		return $signatureDate <= 0 && in_array($status, array(0, 1), true);
	}

	/**
	 * Refresh persisted estimate for an unsigned validated proposal.
	 *
	 * @param object $proposal Proposal
	 * @param User   $user     User
	 * @return int
	 */
	private function refreshEstimateIfValidated($proposal, $user)
	{
		$status = property_exists($proposal, 'statut') ? (int) $proposal->statut : (property_exists($proposal, 'status') ? (int) $proposal->status : -1);
		if ($status !== 1) {
			return 0;
		}

		require_once __DIR__.'/lmdbsalescommissionlineservice.class.php';
		$lineService = new LmdbSalesCommissionLineService($this->db);
		$result = $lineService->estimateFromProposal($proposal, $user);
		if ($result < 0) {
			$this->error = $lineService->error;
			$this->errors = $lineService->errors;
			return -1;
		}

		return $result;
	}

	/**
	 * Check an active user in proposal entity scope.
	 *
	 * @param int $userId User id
	 * @param int $entity Entity id
	 * @return bool
	 */
	private function isUserUsable($userId, $entity)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'user';
		$sql .= ' WHERE rowid = '.((int) $userId);
		$sql .= ' AND statut = 1';
		$sql .= ' AND entity IN (0,'.((int) $entity).')';
		$sql .= ' LIMIT 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$usable = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);

		return $usable;
	}

	/**
	 * Check the unique beneficiary constraint before writing.
	 *
	 * @param LmdbSalesCommissionProposalDispatch $dispatch Dispatch
	 * @return bool
	 */
	private function hasDuplicateUser($dispatch)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_proposal_dispatch';
		$sql .= ' WHERE entity = '.((int) $dispatch->entity);
		$sql .= ' AND fk_propal = '.((int) $dispatch->fk_propal);
		$sql .= ' AND fk_user = '.((int) $dispatch->fk_user);
		$currentId = !empty($dispatch->id) ? (int) $dispatch->id : (int) $dispatch->rowid;
		if ($currentId > 0) {
			$sql .= ' AND rowid <> '.$currentId;
		}
		$sql .= ' LIMIT 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return true;
		}
		$duplicate = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);

		return $duplicate;
	}

	/**
	 * Check an active configured payment term in the proposal entity.
	 *
	 * @param int $paymentTermId Payment term id
	 * @param int $entity        Entity id
	 * @return bool
	 */
	private function isPaymentTermUsable($paymentTermId, $entity)
	{
		if ($paymentTermId <= 0) {
			return false;
		}
		$sql = 'SELECT pt.rowid, SUM(CASE WHEN ptl.active = 1 THEN ptl.percentage ELSE 0 END) AS total_percentage';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term AS pt';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term_line AS ptl ON ptl.fk_payment_term = pt.rowid AND ptl.entity = pt.entity';
		$sql .= ' WHERE pt.rowid = '.((int) $paymentTermId);
		$sql .= ' AND pt.entity = '.((int) $entity);
		$sql .= ' AND pt.active = 1';
		$sql .= ' GROUP BY pt.rowid';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$usable = is_object($obj) && abs((float) $obj->total_percentage - 100.0) <= 0.0001;
		$this->db->free($resql);

		return $usable;
	}

	/**
	 * Reset errors before a public operation.
	 *
	 * @return void
	 */
	private function resetErrors()
	{
		$this->error = '';
		$this->errors = array();
	}
}
