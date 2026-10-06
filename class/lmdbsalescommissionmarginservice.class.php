<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
require_once __DIR__.'/lmdbsalescommissionmarginengine.class.php';
require_once __DIR__.'/lmdbsalescommissionproposalservice.class.php';
require_once __DIR__.'/lmdbsalescommissionscompatibility.class.php';

/** Policies belong strictly to the proposal's entity. Historical payloads are explicit
 * commercial snapshots, never a second source of live product or user data.
 * @phpstan-import-type Policy from LmdbSalesCommissionMarginEngine
 */
class LmdbSalesCommissionMarginService
{
	/** @var DoliDB */
	private $db;
	public function __construct($db) { $this->db = $db; }

	/** In the caller's transaction: 0 means entity policy configuration; >0 a proposal.
	 * @return void */
	public function invalidate($entity, $proposalId = 0)
	{
		if ((int) $entity <= 0 || (int) $proposalId < 0) { throw new RuntimeException('LscPolicyUnavailable'); }
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_revision (entity,object_id,revision) VALUES ('.((int) $entity).','.((int) $proposalId).',1) ON DUPLICATE KEY UPDATE revision = revision + 1';
		if (!$this->db->query($sql)) { throw new RuntimeException('LscPolicyUnavailable'); }
	}

	/** Read owner configuration without switching global entity.
	 * @return int */
	public function activation($entity)
	{
		global $conf;
		if (!isModEnabled('lmdbsalescommissions')) { return 0; }
		if ((int) $entity <= 0) { throw new RuntimeException('LscPolicyUnavailable'); }
		if ((int) $conf->entity === (int) $entity) {
			if (!getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_ENABLED')) { return 0; }
			$date = getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_ACTIVATED_AT');
			if (!LmdbSalesCommissionsCompatibility::nativeMarginGuardCoverage()) { throw new RuntimeException('LscPolicyUnavailable'); }
			if ($date <= 0) { throw new RuntimeException('LscPolicyUnavailable'); }
			return $date;
		}
		$rows = $this->rows("SELECT name, value FROM ".MAIN_DB_PREFIX."const WHERE entity = ".((int) $entity)." AND name IN ('LMDBSALESCOMMISSIONS_MARGIN_ENABLED','LMDBSALESCOMMISSIONS_MARGIN_ACTIVATED_AT')");
		$settings = array();
		foreach ($rows as $row) { $settings[$row['name']] = (int) $row['value']; }
		if (empty($settings['LMDBSALESCOMMISSIONS_MARGIN_ENABLED'])) { return 0; }
		if (empty($settings['LMDBSALESCOMMISSIONS_MARGIN_ACTIVATED_AT'])) { throw new RuntimeException('LscPolicyUnavailable'); }
		if (!LmdbSalesCommissionsCompatibility::nativeMarginGuardCoverage()) { throw new RuntimeException('LscPolicyUnavailable'); }
		return $settings['LMDBSALESCOMMISSIONS_MARGIN_ACTIVATED_AT'];
	}

	/** @param string $sql Internal validated SQL
	 * @return list<array<string,string|null>> */
	private function rows($sql)
	{
		$res = $this->db->query($sql);
		if (!$res) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			throw new RuntimeException('LscPolicyUnavailable');
		}
		$rows = array();
		while (is_object($row = $this->db->fetch_object($res))) { $rows[] = (array) $row; }
		$this->db->free($res);
		return $rows;
	}

	/** @return list<Policy> */
	public function policies($beneficiary, $entity)
	{
		// Validity columns are DATE: include the entire last day selected in the native form.
		$now = "DATE('".$this->db->idate(dol_now())."')";
		$sql = 'SELECT r.*, a.assignment_type, a.rowid AS assignment_id FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule r';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule_assignment a ON a.fk_rule = r.rowid AND a.entity = r.entity';
		$sql .= " WHERE r.entity = ".((int) $entity)." AND r.rule_type = 'margin_policy' AND r.active = 1 AND a.active = 1";
		foreach (array('r', 'a') as $alias) {
			$sql .= ' AND ('.$alias.'.date_start IS NULL OR '.$alias.'.date_start <= '.$now.') AND ('.$alias.'.date_end IS NULL OR '.$alias.'.date_end >= '.$now.')';
		}
		$sql .= " AND (a.assignment_type = 'default' OR (a.assignment_type = 'user' AND a.fk_user = ".((int) $beneficiary).") OR (a.assignment_type = 'group' AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX.'usergroup_user gu WHERE gu.fk_usergroup = a.fk_usergroup AND gu.fk_user = '.((int) $beneficiary).' AND gu.entity = '.((int) $entity).'))) ORDER BY r.rowid, a.rowid';
		$policies = array();
		foreach ($this->rows($sql) as $row) {
			$bands = array();
			foreach ($this->rows('SELECT * FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_band WHERE entity = '.((int) $entity).' AND fk_rule = '.((int) $row['rowid']).' ORDER BY rowid') as $band) {
				$typed = array('threshold' => (float) $band['threshold']);
				foreach (array('kwc', 'kwh') as $axis) {
					$typed[$axis.'_min'] = $band[$axis.'_min'] === null ? null : (float) $band[$axis.'_min'];
					$typed[$axis.'_max'] = $band[$axis.'_max'] === null ? null : (float) $band[$axis.'_max'];
					$typed[$axis.'_inclusive'] = (int) $band[$axis.'_inclusive'];
				}
				$bands[] = $typed;
			}
			$travelBands = array();
			foreach ($this->rows('SELECT metric, min_value, uplift FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_travel_band WHERE entity = '.((int) $entity).' AND fk_rule = '.((int) $row['rowid']).' ORDER BY min_value') as $band) {
				$travelBands[] = array('metric' => (string) $band['metric'], 'min_value' => (float) $band['min_value'], 'uplift' => (float) $band['uplift']);
			}
			$complexSite = null;
			$complexRows = $this->rows('SELECT uplift_without_travel, uplift_with_travel FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_complex_site WHERE entity = '.((int) $entity).' AND fk_rule = '.((int) $row['rowid']));
			if (count($complexRows) > 1) { throw new RuntimeException('LscPolicyUnavailable'); }
			if ($complexRows) {
				$complexSite = array('uplift_without_travel' => (float) $complexRows[0]['uplift_without_travel'], 'uplift_with_travel' => $complexRows[0]['uplift_with_travel'] === null ? null : (float) $complexRows[0]['uplift_with_travel']);
			}
			$effects = $row['policy_effect'] === 'both' ? array('sale', 'commission') : array($row['policy_effect']);
			foreach ($effects as $effect) {
				if (!in_array($effect, array('sale', 'commission'), true) || !in_array($row['policy_context'], array('general', 'pv', 'storage', 'mixed'), true) || ($row['policy_context'] !== 'general' && $effect !== 'commission')) {
					throw new RuntimeException('LscPolicyUnavailable');
				}
				$policies[] = array('rule_id' => (int) $row['rowid'], 'context' => $row['policy_context'], 'effect' => $effect, 'rank' => array('user' => 1, 'group' => 2, 'default' => 3)[$row['assignment_type']], 'origin' => $row['ref'], 'origin_type' => $row['assignment_type'], 'assignment_id' => (int) $row['assignment_id'], 'threshold' => $row['rate'] === null ? null : (float) $row['rate'], 'bands' => $bands, 'travel_bands' => $travelBands, 'complex_site' => $complexSite);
			}
		}
		return $policies;
	}

	/** Read only the stored, fresh lmdbzoning journey displayed on the proposal.
	 * No route calculation or external request belongs in a proposal validation transaction.
	 * @param Propal $proposal Loaded proposal
	 * @param User|null $actor User whose zoning and third-party read rights are checked
	 * @return array{state:string,minutes:?float,kilometres:?float,profile_ref:string,date_calculation:?string,provider:string,optimization:string}
	 */
	protected function travelData($proposal, $actor)
	{
		$data = array('state' => 'unavailable', 'minutes' => null, 'kilometres' => null, 'profile_ref' => '', 'date_calculation' => null, 'provider' => '', 'optimization' => '');
		if (!LmdbSalesCommissionsCompatibility::isFeatureAvailable('travel_margin_uplift') || !is_object($actor) || (int) ($proposal->id ?? 0) <= 0) { return $data; }
		// Let zoning resolve the owner's standalone reference point, or its default profile.
		// Proposal routes have their own jobs and may target linked sites instead of the client address.
		$profileRef = '';
		try {
			$source = (new LmdbZoningTravelService($this->db))->read('propal', (int) $proposal->id, $profileRef, $actor);
		} catch (RuntimeException $error) {
			if ($error->getMessage() === 'TravelDatabaseError') { throw new RuntimeException('LscPolicyUnavailable', 0, $error); }
			return $data;
		}
		$data['state'] = is_string($source['state'] ?? null) ? $source['state'] : 'invalid';
		$data['profile_ref'] = is_string($source['profile_ref'] ?? null) ? $source['profile_ref'] : $profileRef;
		$data['date_calculation'] = is_string($source['date_calculation'] ?? null) ? $source['date_calculation'] : null;
		$data['provider'] = is_string($source['provider'] ?? null) ? $source['provider'] : '';
		$data['optimization'] = is_string($source['optimization'] ?? null) ? $source['optimization'] : '';
		$roundTrip = $source['total']['round_trip'] ?? null;
		if ($data['state'] !== 'ready' || !is_array($roundTrip)) { return $data; }
		$seconds = $roundTrip['duration_s'] ?? null;
		$metres = $roundTrip['distance_m'] ?? null;
		if (!is_numeric($seconds) || !is_numeric($metres) || !is_finite((float) $seconds) || !is_finite((float) $metres) || (float) $seconds < 0 || (float) $metres < 0) {
			$data['state'] = 'invalid';
			return $data;
		}
		$data['minutes'] = (float) $seconds / 60;
		$data['kilometres'] = (float) $metres / 1000;
		return $data;
	}

	/** Fetch current input once for all beneficiaries. Native margin works on cloned lines:
	 * FormMargin may fill buying prices for its computation; a preview must not mutate a proposal.
	 * @return array<string,mixed> Validated commercial snapshot */
	private function inputs($proposal, $technicalNeeded)
	{
		global $conf;
		// FormMargin reads the active entity's cost options. Never apply another entity's options silently.
		if ((int) $proposal->entity !== (int) $conf->entity) { throw new RuntimeException('LscOwnerContext'); }
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmargin.class.php';
		$copy = clone $proposal;
		if ($copy->fetch_lines() < 0 || ($technicalNeeded && $copy->fetch_optionals() < 0)) { throw new RuntimeException('LscPolicyUnavailable'); }
		$copy->lines = array_map(static function ($line) { return clone $line; }, $copy->lines);
		$lineData = array();
		foreach ($copy->lines as $line) {
			$values = array();
			foreach (array('id', 'fk_product', 'qty', 'subprice', 'total_ht', 'pa_ht', 'fk_fournprice', 'remise_percent', 'product_type', 'special_code', 'multicurrency_subprice', 'multicurrency_total_ht') as $key) {
				$values[$key] = $line->$key ?? null;
			}
			$lineData[] = $values;
		}
		$form = new FormMargin($this->db);
		$margin = $form->getMarginInfosArray($copy);
		$cost = isset($margin['pa_total']) && is_numeric($margin['pa_total']) ? (float) $margin['pa_total'] : null;
		$sale = isset($margin['pv_total']) && is_numeric($margin['pv_total']) ? (float) $margin['pv_total'] : null;
		$rate = $cost !== null && $cost > 0 && $sale !== null ? ($sale - $cost) / $cost * 100 : null;
		foreach ($copy->lines as $line) {
			if ((int) ($line->product_type ?? 0) !== 9 && ($line->qty ?? 0) != 0 && (!isset($line->pa_ht) || !is_numeric($line->pa_ht))) { $rate = null; }
		}

		$resolvedCosts = array();
		foreach ($copy->lines as $line) { $resolvedCosts[] = $line->pa_ht ?? null; }
		$costSettings = array();
		foreach (array('ForceBuyingPriceIfNull', 'MARGIN_METHODE_FOR_DISCOUNT', 'MAIN_MAX_DECIMALS_UNIT', 'MAIN_MAX_DECIMALS_TOT', 'MAIN_ROUNDING_RULE_TOT') as $key) { $costSettings[$key] = getDolGlobalString($key); }
		$tech = array();
		foreach (array('kwc' => 'options_powerplantpv_peak_power', 'kwh' => 'options_powerplantpv_storage_capacity') as $key => $field) {
			$value = $technicalNeeded ? ($copy->array_options[$field] ?? null) : null;
			$tech[$key] = is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
		}
		return array('cost' => $cost, 'sale' => $sale, 'rate' => $rate, 'kwc' => $tech['kwc'], 'kwh' => $tech['kwh'], 'lines' => $lineData, 'resolved_costs' => $resolvedCosts, 'cost_settings' => $costSettings, 'total_ht' => $copy->total_ht, 'discount' => $copy->remise_percent ?? null, 'currency' => $copy->multicurrency_code ?? null, 'currency_rate' => $copy->multicurrency_tx ?? null);
	}

	/** Read the optional qualification only after a selected rule requires it.
	 * @param Propal $proposal Loaded proposal
	 * @return bool */
	private function complexSiteState($proposal)
	{
		$copy = clone $proposal;
		if ($copy->fetch_optionals() < 0) { throw new RuntimeException('LscPolicyUnavailable'); }
		return !empty($copy->array_options['options_lmdbpropalpv_complex_site']);
	}

	/** @param Propal $proposal Loaded proposal
	 * @param bool $frozen Return existing signed snapshot when available
	 * @param User|null $actor Authenticated actor, or the current Dolibarr user
	 * @return array<int,array<string,mixed>> Beneficiary => evaluated/frozen decision */
	public function assess($proposal, $frozen = true, $actor = null)
	{
		global $user, $conf;
		if ($actor === null) { $actor = $user ?? null; }
		$entity = (int) $proposal->entity;
		$activation = $this->activation($entity);
		$signature = LmdbSalesCommissionProposalService::getSignatureDate($proposal);
		$where = 'entity = '.$entity.' AND fk_propal = '.((int) $proposal->id);
		// Existing snapshots remain authoritative even after controls are disabled.
		if ($frozen && ($signature > 0 || (int) ($proposal->status ?? $proposal->statut ?? 0) >= 2)) {
			$snapshots = $this->rows('SELECT * FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_snapshot WHERE '.$where.' ORDER BY fk_user');
			if ($snapshots) {
				$result = array();
				foreach ($snapshots as $snapshot) {
					$decision = json_decode($snapshot['snapshot_payload'], true);
					if (!is_array($decision) || !isset($decision['checks'], $decision['commission'], $decision['sale'])) { throw new RuntimeException('LscPolicyUnavailable'); }
					$decision['frozen'] = true;
					$result[(int) $snapshot['fk_user']] = $decision;
				}
				return $result;
			}
		}
		if (!$activation) { return array(); }
		if (($signature > 0 && $signature < $activation) || (!$signature && (int) ($proposal->status ?? $proposal->statut ?? 0) >= 2 && empty($proposal->context['lmdb_margin_signing']))) { return array(); }
		// Never manufacture a historical decision during a backfill/recalculation.
		if ($signature >= $activation && empty($proposal->context['lmdb_margin_signing'])) { throw new RuntimeException('LscSnapshotMissing'); }

		$dispatch = $this->rows('SELECT fk_user, base_type, value_type, value FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_proposal_dispatch WHERE '.$where.' ORDER BY fk_user');
		$beneficiaries = array_map('intval', array_column($dispatch, 'fk_user'));
		if (!$beneficiaries) { $beneficiaries[] = LmdbSalesCommissionProposalService::resolveProposalAuthorId($this->db, $proposal); }
		$policies = array();
		foreach ($beneficiaries as $id) { $policies[$id] = $this->policies($id, $entity); }
		$technicalNeeded = false; $travelNeeded = false; $hasRules = false;
		foreach ($policies as $rules) {
			foreach ($rules as $rule) { $hasRules = true; $technicalNeeded = $technicalNeeded || $rule['context'] !== 'general'; $travelNeeded = $travelNeeded || !empty($rule['travel_bands']); }
		}
		$inputs = $hasRules ? $this->inputs($proposal, $technicalNeeded) : array('cost' => null, 'sale' => null, 'rate' => null, 'kwc' => null, 'kwh' => null);
		$travel = $travelNeeded ? $this->travelData($proposal, $actor) : null;
		if ($travelNeeded) { $inputs['travel'] = $travel; }
		$complexNeeded = false;
		foreach ($policies as $rules) {
			foreach (LmdbSalesCommissionMarginEngine::evaluate($rules, $inputs['rate'], $inputs['kwc'], $inputs['kwh'], $travel)['checks'] as $check) {
				if ($check['reason'] === 'complex_site_missing') { $complexNeeded = true; break 2; }
			}
		}
		if ($complexNeeded && $entity !== (int) $conf->entity) { throw new RuntimeException('LscOwnerContext'); }
		$complexAvailable = $complexNeeded && LmdbSalesCommissionsCompatibility::isFeatureAvailable('complex_site_margin_uplift');
		$inputs['complex_site'] = $complexAvailable ? $this->complexSiteState($proposal) : null;
		$inputs['complex_site_available'] = $complexAvailable;

		// All selected profiles and all commercial data participate: a distribution change also expires approvals.
		$revisions = $this->rows('SELECT object_id, revision FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_revision WHERE entity = '.$entity.' AND object_id IN (0,'.((int) $proposal->id).') ORDER BY object_id');
		$payload = array('entity' => $entity, 'proposal' => (int) $proposal->id, 'inputs' => $inputs, 'dispatch' => $dispatch, 'policies' => $policies, 'revisions' => $revisions);
		$fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
		$approvals = $this->rows('SELECT rowid, fk_user, fk_rule, effect, reason, fk_user_creat, date_creation FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_approval WHERE '.$where." AND fingerprint = '".$this->db->escape($fingerprint)."'");
		$result = array();
		foreach ($policies as $id => $rules) {
			$decision = LmdbSalesCommissionMarginEngine::evaluate($rules, $inputs['rate'], $inputs['kwc'], $inputs['kwh'], $travel, $inputs['complex_site']);
			foreach ($decision['checks'] as &$check) {
				if ($id <= 0) { $check['state'] = 'unknown'; $check['reason'] = 'beneficiary_missing'; }
				foreach ($approvals as $approval) {
					if ((int) $approval['fk_user'] === $id && (int) $approval['fk_rule'] === $check['rule_id'] && $approval['effect'] === $check['effect'] && $check['state'] === 'deny') {
						$check['state'] = 'allow'; $check['reason'] = 'approved'; $check['approval_id'] = (int) $approval['rowid'];
						$check['approval'] = array('reason' => $approval['reason'], 'approver' => (int) $approval['fk_user_creat'], 'date' => $approval['date_creation']);
					}
				}
			}
			unset($check);
			$decision = LmdbSalesCommissionMarginEngine::aggregate($decision);
			$decision['inputs'] = $inputs;
			$decision['rules'] = $rules;
			$decision['fingerprint'] = $fingerprint;
			$decision['frozen'] = false;
			$result[$id] = $decision;
		}
		return $result;
	}

	/** Mandatory invariant, independent of commission read permission.
	 * @param Propal $proposal Loaded proposal
	 * @param User|null $actor Authenticated actor
	 * @return bool */
	public function saleAllowed($proposal, $actor = null)
	{
		foreach ($this->assess($proposal, true, $actor) as $decision) {
			if ($decision['sale'] !== 'allow') { return false; }
		}
		return true;
	}

	/** Called inside the native signature transaction, before acquisition. Idempotent.
	 * @return void */
	public function freeze($proposal, $user)
	{
		$decisions = $this->assess($proposal, true, $user);
		$rewards = array();
		foreach ($decisions as $decision) {
			if (!$decision['frozen']) {
				require_once __DIR__.'/lmdbsalescommissionrewardservice.class.php';
				$rewards = (new LmdbSalesCommissionRewardService($this->db))->forProposal($proposal, LmdbSalesCommissionProposalService::getSignatureDate($proposal) ?: dol_now(), $decisions);
				break;
			}
		}
		foreach ($decisions as $beneficiary => $decision) {
			if ($decision['frozen']) { continue; }
			// Null is an explicit historical absence; adding a rule later must not create a bonus.
			$decision['reward'] = $rewards[$beneficiary] ?? null;
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_snapshot (entity,fk_propal,fk_user,fingerprint,sale_state,commission_state,snapshot_payload,fk_user_creat,date_creation) VALUES (';
			$sql .= ((int) $proposal->entity).','.((int) $proposal->id).','.$beneficiary.",'".$this->db->escape($decision['fingerprint'])."','".$decision['sale']."','".$decision['commission']."','".$this->db->escape(json_encode($decision, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))."',".((int) $user->id).",'".$this->db->idate(dol_now())."')";
			if (!$this->db->query($sql)) { throw new RuntimeException('LscPolicyUnavailable'); }
		}
	}

	/** @return string allow, deny, unknown */
	public function commissionState($proposal, $beneficiary)
	{
		$decisions = $this->assess($proposal);
		if (!$decisions) { return 'allow'; }
		return isset($decisions[$beneficiary]) ? $decisions[$beneficiary]['commission'] : 'unknown';
	}

	/** Record a request for every currently denied sale check, without granting any approval.
	 * One atomic INSERT and a unique key make double submissions harmless.
	 * @param Propal $proposal Loaded draft proposal
	 * @param User $user Authenticated requester
	 * @param string $reason Plain-text reason
	 * @param string $fingerprint Fingerprint shown before submission
	 * @return void */
	public function requestSaleApproval($proposal, $user, $reason, $fingerprint)
	{
		global $conf;
		if (!isModEnabled('lmdbsalescommissions') || !empty($user->socid) || (int) $user->id <= 0
			|| !$user->hasRight('propal', 'lire')
			|| (!getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && !$user->hasRight('propal', 'creer'))
			|| (getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && !$user->hasRight('propal', 'propal_advance', 'validate'))
			|| (int) $proposal->id <= 0 || (int) $proposal->entity !== (int) $conf->entity
			|| (int) ($proposal->status ?? $proposal->statut ?? -1) !== 0
			|| LmdbSalesCommissionProposalService::getSignatureDate($proposal) > 0
			|| !restrictedArea($user, 'propal', $proposal->id, 'propal', '', 'fk_soc', 'rowid', 0, 1, 'write')) {
			throw new RuntimeException('LscApprovalDenied');
		}
		if (trim($reason) === '') { throw new RuntimeException('LscRequestReasonRequired'); }
		$values = array();
		foreach ($this->assess($proposal, false, $user) as $beneficiary => $decision) {
			if (!hash_equals($decision['fingerprint'], $fingerprint)) { throw new RuntimeException('LscApprovalStale'); }
			foreach ($decision['checks'] as $check) {
				if ($check['effect'] !== 'sale' || $check['state'] !== 'deny') { continue; }
				$values[] = '('.((int) $proposal->entity).','.((int) $proposal->id).','.((int) $beneficiary).','.((int) $check['rule_id'])
					.",'".$this->db->escape($fingerprint)."','".$this->db->escape(trim($reason))."',".((int) $user->id).",'".$this->db->idate(dol_now())."')";
			}
		}
		if (!$values) { throw new RuntimeException('LscRequestNoDeniedSale'); }
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_request (entity,fk_propal,fk_user,fk_rule,fingerprint,reason,fk_user_creat,date_creation) VALUES '.implode(',', $values).' ON DUPLICATE KEY UPDATE rowid = rowid';
		if (!$this->db->query($sql)) { throw new RuntimeException('LscPolicyUnavailable'); }
	}

	/** Approve one exact rule and beneficiary; clients must supply the displayed fingerprint.
	 * Object access is additionally checked by the secured page before this method.
	 * @return void */
	public function approve($proposal, $user, $beneficiary, $rule, $effect, $reason, $fingerprint)
	{
		if (!empty($user->socid) || ($effect === 'sale' && !$user->hasRight('lmdbsalescommissions', 'marginpolicy', 'approvesale')) || ($effect === 'commission' && !$user->hasRight('lmdbsalescommissions', 'marginpolicy', 'approvecommission')) || !in_array($effect, array('sale', 'commission'), true) || trim($reason) === '' || (int) ($proposal->status ?? $proposal->statut) >= 2 || LmdbSalesCommissionProposalService::getSignatureDate($proposal) > 0) {
			throw new RuntimeException('LscApprovalDenied');
		}
		$decisions = $this->assess($proposal, false, $user);
		$decision = $decisions[$beneficiary] ?? null;
		if (!$decision || !hash_equals($decision['fingerprint'], $fingerprint)) { throw new RuntimeException('LscApprovalStale'); }
		$found = false;
		foreach ($decision['checks'] as $check) {
			if ($check['rule_id'] === $rule && $check['effect'] === $effect && $check['state'] === 'deny') { $found = true; }
		}
		if (!$found) { throw new RuntimeException('LscApprovalDenied'); }
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_approval (entity,fk_propal,fk_user,fk_rule,effect,fingerprint,reason,fk_user_creat,date_creation) VALUES (';
		$sql .= ((int) $proposal->entity).','.((int) $proposal->id).','.((int) $beneficiary).','.((int) $rule).",'".$effect."','".$this->db->escape($fingerprint)."','".$this->db->escape(trim($reason))."',".((int) $user->id).",'".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) { throw new RuntimeException('LscPolicyUnavailable'); }
	}
}
