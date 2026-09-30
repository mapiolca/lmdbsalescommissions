<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
require_once __DIR__.'/lmdbsalescommissionmarginservice.class.php';
require_once __DIR__.'/lmdbsalescommissionruleresolver.class.php';

/** Reward calculation and its historical contract; native margin remains authoritative.
 * @phpstan-type Reward array{amount:float,reason:string,threshold:?float,cost:?float,margin:?float,surplus:float,share:float,mode:string,value:float,rule_id:int,rule_label:string,payment_term_id:int,policy_fingerprint:string}
 */
class LmdbSalesCommissionRewardService
{
	/** @var DoliDB */
	private $db;
	public function __construct($db) { $this->db = $db; }

	/** @param string $mode fixed or percentage
	 * @param float $value Amount or percentage
	 * @return bool */
	public static function validValue($mode, $value)
	{
		return in_array($mode, array('fixed', 'percentage'), true) && is_finite($value) && $value > 0 && ($mode !== 'percentage' || $value <= 100);
	}

	/** One beneficiary's surplus, using the strictest commission target, never an approved lower target.
	 * @param array<string,mixed> $decision Evaluated native margin policy
	 * @param string $mode fixed or percentage
	 * @param float $value Amount or rate
	 * @param float $share Turnover share in [0,1]
	 * @return Reward */
	public static function calculate(array $decision, $mode, $value, $share)
	{
		$r = array('amount' => 0.0, 'reason' => 'unavailable', 'threshold' => null, 'cost' => null, 'margin' => null, 'surplus' => 0.0, 'share' => $share, 'mode' => $mode, 'value' => $value, 'rule_id' => 0, 'rule_label' => '', 'payment_term_id' => 0, 'policy_fingerprint' => (string) ($decision['fingerprint'] ?? ''));
		if (!self::validValue($mode, $value) || !is_finite($share) || $share < 0 || $share > 1) { return $r; }
		if (($decision['commission'] ?? 'unknown') !== 'allow') { $r['reason'] = 'commission_blocked'; return $r; }
		foreach ($decision['checks'] ?? array() as $check) {
			if ($check['effect'] !== 'commission') { continue; }
			if ($check['threshold'] === null || !is_numeric($check['threshold']) || !is_finite((float) $check['threshold']) || $check['state'] !== 'allow') { return $r; }
			$r['threshold'] = $r['threshold'] === null ? (float) $check['threshold'] : max($r['threshold'], (float) $check['threshold']);
		}
		if ($r['threshold'] === null) { $r['reason'] = 'no_target'; return $r; }
		$cost = $decision['inputs']['cost'] ?? null;
		$sale = $decision['inputs']['sale'] ?? null;
		if (!is_numeric($cost) || !is_numeric($sale) || !is_finite((float) $cost) || !is_finite((float) $sale) || $cost <= 0) { return $r; }
		$r['cost'] = (float) $cost;
		$r['margin'] = (float) $sale - $r['cost'];
		$target = $r['cost'] * $r['threshold'] / 100;
		$delta = $r['margin'] - $target;
		if (!is_finite($delta) || !is_finite($target)) { throw new RuntimeException('LscRewardInvalid'); }
		// Equality accepts only machine precision, never a monetary tolerance.
		$r['surplus'] = $delta <= 4 * PHP_FLOAT_EPSILON * max(1.0, abs($r['margin']), abs($target)) ? 0.0 : $delta;
		if ($share <= 0) { $r['reason'] = 'no_share'; return $r; }
		if ($r['surplus'] <= 0) { $r['reason'] = 'not_exceeded'; return $r; }
		$amount = ($mode === 'fixed' ? $value : $r['surplus'] * $value / 100) * $share;
		if (!is_finite($amount)) { throw new RuntimeException('LscRewardInvalid'); }
		$r['amount'] = (float) price2num($amount, 'MT');
		$r['reason'] = 'earned';
		return $r;
	}

	/** Read frozen rewards for signed proposals; never manufacture a historical bonus.
	 * During the native signing transaction, compute once using the frozen policy decision.
	 * @param object $proposal Loaded proposal
	 * @param int $date Business date
	 * @param array<int,array<string,mixed>>|null $decisions Current decisions during the native freeze
	 * @return array<int,Reward> Beneficiary => calculation
	 * @throws RuntimeException On read/calculation failure */
	public function forProposal($proposal, $date, ?array $decisions = null)
	{
		if ((int) $proposal->entity <= 0 || (int) $proposal->id <= 0) { throw new RuntimeException('LscPolicyUnavailable'); }
		$signed = LmdbSalesCommissionProposalService::getSignatureDate($proposal) > 0 || (int) ($proposal->status ?? $proposal->statut ?? 0) >= 2;
		$decisions = $decisions ?? (new LmdbSalesCommissionMarginService($this->db))->assess($proposal);
		if (!$decisions) { return array(); }
		if ($signed) {
			$frozen = array();
			$allFrozen = true;
			foreach ($decisions as $beneficiary => $decision) {
				$allFrozen = $allFrozen && !empty($decision['frozen']);
				if (isset($decision['reward']) && is_array($decision['reward'])) { $frozen[$beneficiary] = $decision['reward']; }
			}
			if ($allFrozen || empty($proposal->context['lmdb_margin_signing'])) { return $frozen; }
		}
		require_once __DIR__.'/lmdbsalescommissionproposaldispatchservice.class.php';
		require_once __DIR__.'/lmdbsalescommissionproposalturnoverdispatchservice.class.php';
		$dispatchService = new LmdbSalesCommissionProposalDispatchService($this->db);
		$byUser = null;
		$allocations = null;
		$result = array();
		foreach ($decisions as $beneficiary => $decision) {
			$resolver = new LmdbSalesCommissionRuleResolver($this->db);
			$profile = $resolver->resolveForUser($beneficiary, $date, (int) $proposal->entity, 'proposal', 'margin_excess');
			if ($profile['errors']) { throw new RuntimeException(in_array('LscPolicyUnavailable', $profile['errors'], true) ? 'LscPolicyUnavailable' : 'LscRewardConflict'); }
			$rule = $profile['selected']['margin_excess'] ?? null;
			if ($rule === null) { continue; }
			if (!is_numeric($rule['reward_value']) || !self::validValue($rule['reward_mode'], (float) $rule['reward_value'])) { throw new RuntimeException('LscRewardInvalid'); }
			if ($allocations === null) {
				$turnover = new LmdbSalesCommissionProposalTurnoverDispatchService($this->db);
				$allocations = $turnover->resolveForProposal($proposal, $signed);
				if ($turnover->error !== '' || !is_array($allocations)) { throw new RuntimeException('LscPolicyUnavailable'); }
			}
			$share = 0.0;
			foreach ($allocations as $allocation) {
				if ((int) $allocation['user_id'] === $beneficiary && (float) $proposal->total_ht > 0) { $share = $allocation['value_type'] === 'percentage' ? (float) $allocation['value'] / 100 : (float) $allocation['amount'] / (float) $proposal->total_ht; }
			}
			// resolveForProposal already validates completeness and bounds, including native rounding.
			$share = min(1.0, max(0.0, $share));
			$reward = self::calculate($decision, $rule['reward_mode'], (float) $rule['reward_value'], $share);
			$reward['rule_id'] = (int) $rule['rule_id'];
			$reward['rule_label'] = $rule['rule_label'];
			if ($byUser === null) {
				$dispatches = $dispatchService->fetchForProposal((int) $proposal->id, (int) $proposal->entity);
				if ($dispatchService->error !== '') { throw new RuntimeException('LscPolicyUnavailable'); }
				$byUser = array();
				foreach ($dispatches as $row) { $byUser[(int) $row->fk_user] = $row; }
			}
			$dispatch = $byUser[$beneficiary] ?? new LmdbSalesCommissionProposalDispatch($this->db);
			if (!isset($byUser[$beneficiary])) {
				$dispatch->entity = (int) $proposal->entity;
				$dispatch->fk_user = $beneficiary;
				$dispatch->payment_term_mode = LmdbSalesCommissionProposalDispatchService::PAYMENT_AUTOMATIC;
			}
			$reward['payment_term_id'] = $dispatchService->resolvePaymentTermId($dispatch, $date);
			if ($reward['payment_term_id'] < 0) { throw new RuntimeException('LscPolicyUnavailable'); }
			$result[$beneficiary] = $reward;
		}
		return $result;
	}

}
