<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
require_once __DIR__.'/lmdbsalescommissionmargincase.class.php';
require_once __DIR__.'/lmdbsalescommissionmarginservice.class.php';

/** Authoritative request/decision transitions. No dependency on the web session. */
class LmdbSalesCommissionMarginWorkflow
{
	/** @var DoliDB */
	private $db;
	/** @var array<string,bool> Server-only transition scope, never accepted from object context or API data. */
	private static $deciding = array();
	public static function inDecision($proposal): bool { return isset(self::$deciding[(int) $proposal->entity.':'.(int) $proposal->id]); }
	public function __construct($db) { $this->db = $db; }

	/** Installation readiness is independent from the switch: existing locks survive deactivation of margin checks. */
	public static function available(): bool
	{
		return isModEnabled('lmdbsalescommissions') && getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_WORKFLOW_READY') === 1;
	}

	/** @return list<array<string,mixed>> */
	private function rows(string $sql): array
	{
		$q = $this->db->query($sql);
		if (!$q) { dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR); throw new RuntimeException('LscPolicyUnavailable'); }
		$rows = array();
		while (is_object($row = $this->db->fetch_object($q))) { $rows[] = (array) $row; }
		$this->db->free($q);
		return $rows;
	}

	/** @return array<string,mixed>|null */
	public function pending($proposal, bool $lock = false): ?array
	{
		if (!self::available()) { return null; }
		$rows = $this->rows('SELECT * FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_case WHERE entity = '.(int) $proposal->entity.' AND active_proposal = '.(int) $proposal->id.' AND status = 0'.($lock ? ' FOR UPDATE' : ''));
		return $rows[0] ?? null;
	}

	/** @return list<array<string,mixed>> */
	public function history($proposal, int $limit = 21, int $offset = 0): array
	{
		return $this->rows('SELECT c.*, u.firstname AS approver_firstname, u.lastname AS approver_lastname FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_case c LEFT JOIN '.MAIN_DB_PREFIX.'user u ON u.rowid = c.fk_user_decider WHERE c.entity = '.(int) $proposal->entity.' AND c.fk_propal = '.(int) $proposal->id.' ORDER BY c.rowid DESC LIMIT '.max(1, $limit).' OFFSET '.max(0, $offset));
	}

	/** Mandatory invariant used by every mutation channel, including when policies have been disabled. */
	public function assertUnlocked($proposal, bool $lock = false): void
	{
		if (!self::available()) { return; }
		// Triggers run within the native mutation transaction. A current locking read
		// serializes with requests even under InnoDB REPEATABLE READ.
		if ($lock && !$this->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'propal WHERE entity = '.(int) $proposal->entity.' AND rowid = '.(int) $proposal->id.' FOR UPDATE')) { throw new RuntimeException('ErrorRecordNotFound'); }
		if ($this->pending($proposal, $lock) !== null) { throw new RuntimeException('LscWorkflowLocked'); }
	}

	/** Full operation authorization: native object access, entity, scope and optional validation right. */
	public function assertDecisionAccess($proposal, $actor, bool $validate): void
	{
		global $conf;
		if (!self::available() || !empty($actor->socid) || !$actor->hasRight('propal', 'lire')
			|| (int) $proposal->entity !== (int) $conf->entity
			|| !restrictedArea($actor, 'propal', $proposal->id, 'propal', '', 'fk_soc', 'rowid', 0, 1, 'read')) { throw new RuntimeException('LscApprovalDenied'); }
		$author = LmdbSalesCommissionProposalService::resolveProposalAuthorId($this->db, $proposal);
		if (!$actor->hasRight('lmdbsalescommissions', 'marginpolicy', 'approvesale')) {
			if (!$actor->hasRight('lmdbsalescommissions', 'marginpolicy', 'approvesubordinates') || $author === (int) $actor->id) { throw new RuntimeException('LscApprovalDenied'); }
			$children = $actor->getAllChildIds(0);
			if (!empty($actor->error) || !in_array($author, array_map('intval', $children), true)) { throw new RuntimeException('LscApprovalDenied'); }
		}
		if ($validate && ((!getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && !$actor->hasRight('propal', 'creer'))
			|| (getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && !$actor->hasRight('propal', 'propal_advance', 'validate')))) { throw new RuntimeException('LscApprovalDenied'); }
	}

	/** Serialize all transitions on the native parent, then reload its authoritative data. */
	private function lockProposal($proposal, $actor, bool $request): void
	{
		global $conf;
		if (!self::available() || !empty($actor->socid) || (int) $proposal->id <= 0 || (int) $proposal->entity !== (int) $conf->entity
			|| !$actor->hasRight('propal', 'lire') || ($request && !$actor->hasRight('propal', 'creer'))
			|| !restrictedArea($actor, 'propal', $proposal->id, 'propal', '', 'fk_soc', 'rowid', 0, 1, $request ? 'write' : 'read')) { throw new RuntimeException('LscApprovalDenied'); }
		$rows = $this->rows('SELECT rowid, fk_statut FROM '.MAIN_DB_PREFIX.'propal WHERE entity = '.(int) $proposal->entity.' AND rowid = '.(int) $proposal->id.' FOR UPDATE');
		if (!$rows || (int) $rows[0]['fk_statut'] !== 0) { throw new RuntimeException('LscWorkflowChanged'); }
		if ($proposal->fetch((int) $proposal->id) <= 0) { throw new RuntimeException('ErrorRecordNotFound'); }
	}

	/** @return array<int,array<string,mixed>> Current denied sale checks, or a refusal for unknown data. */
	public function requestable($proposal, $actor): array
	{
		$decisions = (new LmdbSalesCommissionMarginService($this->db))->assess($proposal, false, $actor);
		$denied = false;
		foreach ($decisions as $decision) {
			if ($decision['sale'] === 'unknown') { throw new RuntimeException('LscWorkflowUnknown'); }
			$denied = $denied || $decision['sale'] === 'deny';
		}
		if (!$denied) { throw new RuntimeException('LscRequestNoDeniedSale'); }
		return $decisions;
	}

	/** @return int Dossier id; a repeated identical request returns the existing id. */
	public function request($proposal, $actor, string $reason, string $fingerprint): int
	{
		if (trim($reason) === '') { throw new RuntimeException('LscRequestReasonRequired'); }
		if (!$this->db->begin()) { throw new RuntimeException('LscPolicyUnavailable'); }
		try {
			$this->lockProposal($proposal, $actor, true);
			$current = $this->pending($proposal, true);
			if ($current !== null) {
				if (!hash_equals((string) $current['fingerprint'], $fingerprint)) { throw new RuntimeException('LscApprovalStale'); }
				if (!$this->db->commit()) { throw new RuntimeException('LscPolicyUnavailable'); }
				return (int) $current['rowid'];
			}
			$decisions = $this->requestable($proposal, $actor);
			foreach ($decisions as $decision) { if (!hash_equals($decision['fingerprint'], $fingerprint)) { throw new RuntimeException('LscApprovalStale'); } }
			$case = new LmdbSalesCommissionMarginCase($this->db);
			$case->entity = (int) $proposal->entity;
			$case->fk_propal = $case->active_proposal = (int) $proposal->id;
			$case->fk_author = LmdbSalesCommissionProposalService::resolveProposalAuthorId($this->db, $proposal);
			if ($case->fk_author <= 0) { throw new RuntimeException('LmdbSalesCommissionsProposalWithoutSalesUser'); }
			$case->status = LmdbSalesCommissionMarginCase::PENDING;
			$case->fingerprint = $fingerprint;
			$case->reason = trim($reason);
			$case->snapshot_payload = json_encode($decisions, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
			$case->date_creation = dol_now();
			$case->context['trigger_reason'] = 'validation_requested';
			// CommonObject::createCommon still derives a class-name trigger in older cores.
			if ($case->create($actor, 1) <= 0) { throw new RuntimeException($case->error ?: 'LscPolicyUnavailable'); }
			$case->ref = (string) $proposal->ref;
			$case->socid = (int) $proposal->socid;
			$case->total_ht = (float) $proposal->total_ht;
			$case->fk_project = (int) ($proposal->fk_project ?? 0);
			if ($case->call_trigger('LMDBSALESCOMMISSIONS_MARGIN_CASE_CREATE', $actor) < 0) { throw new RuntimeException($case->error ?: 'LscPolicyUnavailable'); }
			if (!$this->db->commit()) { throw new RuntimeException('LscPolicyUnavailable'); }
			return (int) $case->id;
		} catch (Throwable $e) { $this->db->rollback(); throw $e; }
	}

	/** @return float The author's commission before margin eligibility, using the usual formulas. */
	public function theoreticalCommission($proposal, int $author): float
	{
		require_once __DIR__.'/lmdbsalescommissionproposaldispatchservice.class.php';
		$service = new LmdbSalesCommissionProposalDispatchService($this->db);
		$dispatches = $service->fetchForProposal((int) $proposal->id, (int) $proposal->entity);
		if ($service->error !== '') { throw new RuntimeException('LscPolicyUnavailable'); }
		if ($dispatches) {
			$total = 0.0;
			foreach ($dispatches as $dispatch) {
				if ((int) $dispatch->fk_user !== $author) { continue; }
				$calculation = $service->calculate($dispatch, $proposal, dol_now(), true);
				if ($calculation === null) { throw new RuntimeException('LscPolicyUnavailable'); }
				$total += $calculation['commission'];
			}
			return (float) price2num($total, 'MT');
		}
		$resolver = new LmdbSalesCommissionRuleResolver($this->db);
		$profile = $resolver->resolveForUser($author, dol_now(), (int) $proposal->entity, 'proposal');
		if ($profile['errors']) { throw new RuntimeException('LscPolicyUnavailable'); }
		$rule = $profile['selected']['margin'] ?? null;
		if (!$rule) { return 0.0; }
		$margin = LmdbSalesCommissionProposalService::getEstimatedMargin($proposal);
		if ($margin === null) { throw new RuntimeException('LscWorkflowUnknown'); }
		$turnover = new LmdbSalesCommissionProposalTurnoverDispatchService($this->db);
		$base = $turnover->calculateCommissionableMarginForUser($proposal, $author, $margin);
		if ($base === null) { throw new RuntimeException('LscPolicyUnavailable'); }
		return (float) price2num(max(0, $base) * (float) $rule['rate'] / 100, 'MT');
	}

	/** @return int Resolved dossier id. Validation and approval are one transaction. */
	public function decide($proposal, $actor, int $id, bool $approve, string $reason, ?float $percent): int
	{
		if (trim($reason) === '') { throw new RuntimeException('LscRequestReasonRequired'); }
		$this->assertDecisionAccess($proposal, $actor, $approve);
		if (!$this->db->begin()) { throw new RuntimeException('LscPolicyUnavailable'); }
		$original = clone $proposal;
		try {
			// Idempotence after a completed decision must not require the parent to still be draft.
			$locked = $this->rows('SELECT rowid FROM '.MAIN_DB_PREFIX.'propal WHERE entity = '.(int) $proposal->entity.' AND rowid = '.(int) $proposal->id.' FOR UPDATE');
			if (!$locked) { throw new RuntimeException('ErrorRecordNotFound'); }
			$case = new LmdbSalesCommissionMarginCase($this->db);
			if ($case->fetch($id) <= 0 || (int) $case->entity !== (int) $proposal->entity || (int) $case->fk_propal !== (int) $proposal->id) { throw new RuntimeException('ErrorRecordNotFound'); }
			if ((int) $case->status !== LmdbSalesCommissionMarginCase::PENDING) {
				if ((int) $case->fk_user_decider !== (int) $actor->id || (int) $case->status !== ($approve ? 1 : 2)
					|| (string) $case->decision_reason !== trim($reason) || ($approve && ($percent === null || !is_finite($percent) || abs((float) $case->retained_percent - $percent) > 0.00000001))) { throw new RuntimeException('LscWorkflowChanged'); }
				if (!$this->db->commit()) { throw new RuntimeException('LscPolicyUnavailable'); }
				return $id;
			}
			$this->lockProposal($proposal, $actor, false);
			$this->assertDecisionAccess($proposal, $actor, $approve);
			if ($approve && (int) $case->fk_author !== LmdbSalesCommissionProposalService::resolveProposalAuthorId($this->db, $proposal)) { throw new RuntimeException('LscApprovalStale'); }
			$case->oldcopy = clone $case;
			$service = new LmdbSalesCommissionMarginService($this->db);
			$decisions = $approve ? $service->assess($proposal, false, $actor) : array();
			if ($approve) {
				foreach ($decisions as $decision) {
					if (!hash_equals((string) $case->fingerprint, $decision['fingerprint'])) { throw new RuntimeException('LscApprovalStale'); }
					if ($decision['sale'] === 'unknown') { throw new RuntimeException('LscWorkflowUnknown'); }
				}
				if (!$decisions || $percent === null || !is_finite($percent) || $percent < 0 || $percent > 100) { throw new RuntimeException('LscWorkflowInvalidPercent'); }
				$authorDecision = $decisions[(int) $case->fk_author] ?? null;
				$usualPercent = $authorDecision && $authorDecision['commission'] === 'allow' ? 100.0 : 0.0;
				if ($authorDecision && $authorDecision['commission'] === 'unknown' && $percent > 0) { throw new RuntimeException('LscWorkflowUnknown'); }
				if ($percent > $usualPercent && !$actor->hasRight('lmdbsalescommissions', 'marginpolicy', 'approvecommission')) { throw new RuntimeException('LscApprovalDenied'); }
				$case->retained_percent = $percent;
				$case->snapshot_commission_base = $this->theoreticalCommission($proposal, (int) $case->fk_author);
			}
			$case->status = $approve ? LmdbSalesCommissionMarginCase::APPROVED : LmdbSalesCommissionMarginCase::RETURNED;
			$case->active_proposal = null;
			$case->decision_reason = trim($reason);
			$case->fk_user_decider = (int) $actor->id;
			$case->date_decision = dol_now();
			if ($case->update($actor, 1) <= 0) { throw new RuntimeException($case->error ?: 'LscPolicyUnavailable'); }
			if ($approve) {
				self::$deciding[(int) $proposal->entity.':'.(int) $proposal->id] = true;
				foreach ($decisions as $beneficiary => $decision) {
					foreach ($decision['checks'] as $check) {
						if ($check['state'] !== 'deny') { continue; }
						if ($check['effect'] === 'sale' || ((int) $beneficiary === (int) $case->fk_author && $percent > 0 && $check['effect'] === 'commission')) {
							$service->approve($proposal, $actor, $beneficiary, $check['rule_id'], $check['effect'], $reason, $case->fingerprint);
						}
					}
				}
				if ($proposal->valid($actor) <= 0) { throw new RuntimeException($proposal->error ?: 'LscWorkflowValidationFailed'); }
			}
			$case->context['trigger_reason'] = $approve ? 'validation_approved' : 'modification_authorized';
			$case->ref = (string) $proposal->ref;
			$case->socid = (int) $proposal->socid;
			$case->total_ht = (float) $proposal->total_ht;
			$case->fk_project = (int) ($proposal->fk_project ?? 0);
			if ($case->call_trigger('LMDBSALESCOMMISSIONS_MARGIN_CASE_UPDATE', $actor) < 0) { throw new RuntimeException($case->error ?: 'LscPolicyUnavailable'); }
			if (!$this->db->commit()) { throw new RuntimeException('LscPolicyUnavailable'); }
			return $id;
		} catch (Throwable $e) {
			$this->db->rollback();
			foreach (get_object_vars($original) as $key => $value) { $proposal->{$key} = $value; }
			throw $e;
		} finally { unset(self::$deciding[(int) $proposal->entity.':'.(int) $proposal->id]); }
	}

	/** @return float|null Current approved percentage, bound to the exact economic fingerprint. */
	public function retainedPercent($proposal, int $beneficiary, string $fingerprint): ?float
	{
		if (!self::available()) { return null; }
		$rows = $this->rows('SELECT retained_percent FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_case WHERE entity = '.(int) $proposal->entity.' AND fk_propal = '.(int) $proposal->id.' AND fk_author = '.$beneficiary." AND status = 1 AND fingerprint = '".$this->db->escape($fingerprint)."' ORDER BY rowid DESC LIMIT 1");
		return isset($rows[0]['retained_percent']) ? (float) $rows[0]['retained_percent'] : null;
	}
}
