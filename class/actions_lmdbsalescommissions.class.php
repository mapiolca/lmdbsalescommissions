<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * Hook action class for lmdbsalescommissions.
 * @phpstan-import-type EstimateData from LmdbSalesCommissionMarginView
 */
class ActionsLmdbSalesCommissions
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var int Native hook priority: append the summary after other margin contributions. */
	public $priority = PHP_INT_MAX;

	/** @var string Error message */
	public $error = '';

	/** @var array<int, string> Error messages */
	public $errors = array();
	/** @var array<int,string>|null Native HookManager warnings, reset between calls. */
	public $warnings = array();

	/** @var array<string, mixed> Hook results */
	public $results = array();

	/** @var string|null Printed hook result */
	public $resprints;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		require_once __DIR__.'/lmdbsalescommissionmarginguard.class.php';
		LmdbSalesCommissionMarginGuard::initialize($db);
	}

	/** Native UI precheck; no commission permissions are required for this mandatory invariant.
	 * @param array<string,mixed> $parameters @return int */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs, $toselect, $massaction;
		if (getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_WORKFLOW_READY')) {
			require_once __DIR__.'/lmdbsalescommissionmarginworkflow.class.php';
			$contexts = explode(':', (string) ($parameters['context'] ?? ''));
			try {
				$workflow = new LmdbSalesCommissionMarginWorkflow($this->db);
				if (in_array('propalcard', $contexts, true) && $action !== '' && is_object($object) && (int) $object->id > 0) { $workflow->assertUnlocked($object); }
				if (in_array('propallist', $contexts, true) && in_array($massaction, array('delete', 'validate', 'sign', 'builddoc', 'presend', 'send'), true)) {
					if (!class_exists('Propal')) { require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php'; }
					foreach (is_array($toselect) ? $toselect : array() as $id) {
						$quote = new Propal($this->db);
						if ($quote->fetch((int) $id) <= 0 || !restrictedArea($user, 'propal', $quote->id, 'propal', '', 'fk_soc', 'rowid', 0, 1, 'read')) { throw new RuntimeException('ErrorForbidden'); }
						$workflow->assertUnlocked($quote);
					}
				}
			} catch (Throwable $e) {
				$langs->load('lmdbsalescommissions@lmdbsalescommissions');
				setEventMessages($langs->trans($e->getMessage()), null, 'errors');
				$action = ''; $massaction = ''; $toselect = array(); return 1;
			}
		}
		if (in_array($action, array('modif', 'confirm_delete', 'lscconfirmdelete', 'lscconfirmdraft'), true)) {
			return $this->handleProposalCleanup($parameters, $object, $action, $hookmanager);
		}
		$contexts = explode(':', (string) ($parameters['context'] ?? ''));
		$card = in_array('propalcard', $contexts, true) && ($action === 'confirm_validate' || ($action === 'confirm_closeas' && GETPOSTINT('statut') === 2));
		$list = in_array('propallist', $contexts, true) && (in_array($action, array('validate', 'sign'), true) || $massaction === 'validate');
		if (!$card && !$list) { return 0; }
		if (!class_exists('Propal')) { require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php'; }
		$ids = $card ? array((int) $object->id) : (is_array($toselect) ? array_map('intval', $toselect) : array());
		$service = new LmdbSalesCommissionMarginService($this->db);
		try {
			foreach ($ids as $id) {
				$proposal = new Propal($this->db);
				if ($proposal->fetch($id) <= 0 || !restrictedArea($user, 'propal', $id, 'propal', '', 'fk_soc', 'rowid', 0, 1, 'write')) { throw new RuntimeException('LscSaleBlocked'); }
				if (!$service->saleAllowed($proposal, $user)) { throw new RuntimeException('LscSaleBlocked'); }
			}
		} catch (Exception $e) {
			$langs->load('lmdbsalescommissions@lmdbsalescommissions');
			setEventMessages($langs->trans($e->getMessage()), null, 'errors');
			// Native list actions are outside the empty($reshook) block: clear both selectors.
			$action = ''; $massaction = ''; $toselect = array();
			return 1;
		}
		return 0;
	}

	/** Replace native validation confirmation only when the sale is blocked.
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param Propal $object Current proposal
	 * @param string $action Native action
	 * @param HookManager $hookmanager Native manager
	 * @return int */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;
		if (getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_WORKFLOW_READY') && $action === 'validate' && in_array('propalcard', explode(':', (string) ($parameters['context'] ?? '')), true)) {
			return $this->workflowConfirmation($object);
		}
		if (in_array($action, array('lscaskdelete', 'lscaskdraft'), true)) {
			return $this->confirmProposalCleanup($parameters, $object, $action, $hookmanager);
		}
		if (!in_array('propalcard', explode(':', (string) ($parameters['context'] ?? '')), true)
			|| $action !== 'validate' || !isModEnabled('lmdbsalescommissions')
			|| !empty($user->socid) || !is_object($object) || (int) $object->id <= 0
			|| (int) ($object->status ?? $object->statut ?? -1) !== 0) { return 0; }
		if (!$user->hasRight('propal', 'lire')
			|| (!getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && !$user->hasRight('propal', 'creer'))
			|| (getDolGlobalInt('MAIN_USE_ADVANCED_PERMS') && !$user->hasRight('propal', 'propal_advance', 'validate'))) { return 0; }
		if (!restrictedArea($user, 'propal', $object->id, 'propal', '', 'fk_soc', 'rowid', 0, 1, 'write')) { return 0; }
		$langs->load('lmdbsalescommissions@lmdbsalescommissions');
		$blocked = false; $requestable = false; $fingerprint = '';
		try {
			foreach ((new LmdbSalesCommissionMarginService($this->db))->assess($object, true, $user) as $decision) {
				$blocked = $blocked || $decision['sale'] !== 'allow';
				foreach ($decision['checks'] as $check) {
					if ($check['effect'] === 'sale' && $check['state'] === 'deny') {
						$requestable = true; $fingerprint = $decision['fingerprint'];
					}
				}
			}
		} catch (Exception $e) { $blocked = true; $requestable = false; }
		if (!$blocked) { return 0; }
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
		$form = new Form($this->db);
		$page = $requestable
			? dol_buildpath('/lmdbsalescommissions/proposal_margin_request.php', 1).'?id='.(int) $object->id.'&fingerprint='.urlencode($fingerprint)
			: DOL_URL_ROOT.'/comm/propal/card.php?id='.(int) $object->id;
		// Both choices are navigation only. The request itself uses a separate CSRF-protected POST.
		$this->resprints = $form->formconfirm($page, $langs->trans('LscSaleBlockedTitle'),
			$langs->trans($requestable ? 'LscSaleBlockedChoice' : 'LscSaleUnknownChoice'), '', '', 'no', 2, 260, 650, 0,
			$requestable ? 'LscRequestApproval' : 'LscModifyProposal', $requestable ? 'LscModifyProposal' : 'Cancel');
		return 1;
	}

	/**
	 * Intercept native actions only when paid commissions need a second approval.
	 * @param array<string,mixed> $parameters Hook context
	 * @param object $object Proposal
	 * @param string $action Native action, passed by reference
	 * @param HookManager $hookmanager Hook manager
	 * @return int 1 stops native actions; 0 resumes the native operation
	 */
	private function handleProposalCleanup($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs, $confirm;
		if (!isModEnabled('lmdbsalescommissions') || !in_array('propalcard', explode(':', (string) ($parameters['context'] ?? '')), true)) { return 0; }
		$final = in_array($action, array('lscconfirmdelete', 'lscconfirmdraft'), true);
		if (!$final && $action !== 'modif' && !($action === 'confirm_delete' && GETPOST('confirm', 'alpha') === 'yes')) { return 0; }
		$operation = in_array($action, array('confirm_delete', 'lscconfirmdelete'), true) ? 'delete' : 'draft';
		$langs->load('lmdbsalescommissions@lmdbsalescommissions');
		if ($final && GETPOST('confirm', 'alpha') !== 'yes') {
			unset($_SESSION['lmdbsalescommissions_cleanup']);
			$action = '';
			return 1;
		}
		if (!$user->hasRight('propal', $operation === 'delete' ? 'supprimer' : 'creer')) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors'); $action = ''; return 1;
		}
		require_once __DIR__.'/lmdbsalescommissionproposalcleanup.class.php';
		$cleanup = new LmdbSalesCommissionProposalCleanup($this->db);
		$snapshot = $cleanup->inspect((int) $object->id, (int) $object->entity);
		if ($snapshot === null) {
			setEventMessages($langs->trans($cleanup->error), null, 'errors'); $action = ''; return 1;
		}
		if (!$final && !$snapshot['paid']) { return 0; }
		if (!$user->hasRight('lmdbsalescommissions', 'due', 'pay')) {
			setEventMessages($langs->trans('LscCleanupPaidPermission'), null, 'errors'); $action = ''; return 1;
		}
		if (!$final) {
			$cleanup->prepareConfirmation((int) $object->id, (int) $object->entity, $operation, $user, $snapshot);
			$action = $operation === 'delete' ? 'lscaskdelete' : 'lscaskdraft';
			return 1;
		}
		// The trigger validates and consumes the challenge against locked data.
		$object->context['lmdb_cleanup_confirmation'] = GETPOST('lsc_confirmation', 'aZ09');
		if (!$cleanup->approveConfirmation((int) $object->id, (int) $object->entity, $operation, $user, $snapshot, $object->context['lmdb_cleanup_confirmation'])) {
			unset($object->context['lmdb_cleanup_confirmation']);
			setEventMessages($langs->trans($cleanup->error), null, 'errors'); $action = ''; return 1;
		}
		$confirm = 'yes';
		$action = $operation === 'delete' ? 'confirm_delete' : 'modif';
		return 0;
	}

	/**
	 * Render the extra native confirmation, keeping native deletion confirmation intact.
	 * @param array<string,mixed> $parameters Hook context
	 * @param object $object Proposal
	 * @param string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	private function confirmProposalCleanup($parameters, &$object, &$action, $hookmanager)
	{
		global $form, $langs, $user;
		if (!isModEnabled('lmdbsalescommissions') || !in_array('propalcard', explode(':', (string) ($parameters['context'] ?? '')), true)
			|| !in_array($action, array('lscaskdelete', 'lscaskdraft'), true)) { return 0; }
		$grant = $_SESSION['lmdbsalescommissions_cleanup'] ?? null;
		if (!is_array($grant) || !isset($grant['user'], $grant['proposal'], $grant['nonce']) || (int) $grant['user'] !== (int) $user->id || (int) $grant['proposal'] !== (int) $object->id) { return 0; }
		$langs->load('lmdbsalescommissions@lmdbsalescommissions');
		$fields = array(array('type' => 'hidden', 'name' => 'lsc_confirmation', 'value' => $grant['nonce']));
		$this->resprints = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.((int) $object->id), $langs->trans('LscCleanupPaidTitle'),
			$langs->trans('LscCleanupPaidWarning'), $action === 'lscaskdelete' ? 'lscconfirmdelete' : 'lscconfirmdraft', $fields, 'no', 0);
		return 1;
	}

	/**
	 * Add estimated commission block under native margin table on proposal card.
	 *
	 * @param array<string, mixed> $parameters  Hook parameters
	 * @param object               $object      Current object
	 * @param string               $action      Current action
	 * @param HookManager          $hookmanager Hook manager
	 * @return int
	 */
	public function displayMarginInfos($parameters, &$object, &$action, $hookmanager)
	{
		unset($action, $hookmanager);

		$contexts = explode(':', (string) ($parameters['context'] ?? ''));
		if (!in_array('propalcard', $contexts, true)) {
			return 0;
		}
		if (!isModEnabled('lmdbsalescommissions')) {
			return 0;
		}

		$marginInfo = isset($parameters['marginInfo']) && is_array($parameters['marginInfo']) ? $parameters['marginInfo'] : array();
		$commissionData = $this->buildProposalEstimatedCommissionData($object, $marginInfo);
		global $user;
		require_once __DIR__.'/lmdbsalescommissionmarginview.class.php';
		$summary = LmdbSalesCommissionMarginView::render($this->db, $object, $user, false, $commissionData);
		$this->resprints = '';
		if ($summary === '') { return 0; }

		$columnCount = 4;
		if (getDolGlobalString('DISPLAY_MARGIN_RATES')) {
			$columnCount++;
		}
		if (getDolGlobalString('DISPLAY_MARK_RATES')) {
			$columnCount++;
		}

		$this->resprints = '<tr class="oddeven lmdbsalescommissions-estimated-commission">';
		$this->resprints .= '<td colspan="'.$columnCount.'">';
		$this->resprints .= $summary;
		$this->resprints .= '</td>';
		$this->resprints .= '</tr>';

		return 0;
	}

	/**
	 * Build estimated commission data for a proposal.
	 *
	 * @param object               $object     Current proposal
	 * @param array<string, mixed> $marginInfo Native margin information
	 * @return EstimateData
	 */
	private function buildProposalEstimatedCommissionData($object, array $marginInfo)
	{
		global $langs, $user;
		if (!empty($user->socid)) { return array(); }
		$canSeeAll = $user->hasRight('lmdbsalescommissions', 'commission', 'dispatch') || $user->hasRight('lmdbsalescommissions', 'commission', 'readall');
		$canSeeOwn = $user->hasRight('lmdbsalescommissions', 'commission', 'readown');
		$canSeeGroup = $user->hasRight('lmdbsalescommissions', 'commission', 'readgroup');
		if (!$canSeeAll && !$canSeeOwn && !$canSeeGroup) { return array(); }

		require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposalservice.class.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionruleresolver.class.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposaldispatchservice.class.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposalturnoverdispatchservice.class.php', 0);
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

		$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions', 'commercial'));

		$entity = !empty($object->entity) ? (int) $object->entity : 0;
		$dispatchService = new LmdbSalesCommissionProposalDispatchService($this->db);
		$dispatches = $dispatchService->fetchForProposal((int) ($object->id ?? 0), $entity);
		if (!empty($dispatches)) {
			$rows = array();
			$total = 0.0;
			$complete = true;
			foreach ($dispatches as $dispatch) {
				if (!$canSeeAll && !($canSeeOwn && (int) $user->id === (int) $dispatch->fk_user) && !($canSeeGroup && lmdbsalescommissionsUsersShareGroup((int) $user->id, (int) $dispatch->fk_user))) {
					continue;
				}
				$beneficiary = new User($this->db);
				$beneficiaryLabel = $beneficiary->fetch((int) $dispatch->fk_user) > 0 ? $beneficiary->getNomUrl(1) : $langs->trans('Unknown');
				$calculation = $dispatchService->getCalculationForDisplay($dispatch, $object, dol_now());
				if (!is_array($calculation)) {
					$complete = false;
					$rows[] = array(
						'beneficiary_id' => (int) $dispatch->fk_user,
						'beneficiary' => $beneficiaryLabel,
						'formula' => lmdbsalescommissionsFormatDispatchFormula($langs, (string) $dispatch->base_type, (string) $dispatch->value_type, $dispatch->value),
						'payment_term' => '',
						'amount' => img_warning($langs->trans($dispatchService->error)),
						'status' => lmdbsalescommissionsStatusBadge($langs->trans('LmdbSalesCommissionsLineStatusBlocked'), -1),
					);
					continue;
				}
				$paymentLabel = $calculation['payment_term_label'] === 'LmdbSalesCommissionsPaymentImmediateAtSignature' ? $langs->trans($calculation['payment_term_label']) : $calculation['payment_term_label'];
				$total += (float) $calculation['commission'];
				$rows[] = array(
					'beneficiary_id' => (int) $dispatch->fk_user,
					'beneficiary' => $beneficiaryLabel,
					'formula' => lmdbsalescommissionsFormatDispatchFormula($langs, (string) $dispatch->base_type, (string) $dispatch->value_type, $dispatch->value),
					'payment_term' => $paymentLabel,
					'amount_value' => (float) $calculation['commission'],
					'amount' => lmdbsalescommissionsFormatTotalAmount($calculation['commission']),
					'status' => $langs->trans(!$dispatchService->isProposalEditable($object) ? 'LscFrozen' : 'LmdbSalesCommissionsEstimateNotAcquired'),
				);
			}
			if (empty($rows)) {
				return array();
			}

			$result = array('rows' => $rows);
			if ($canSeeAll && $complete) {
				$result['total'] = lmdbsalescommissionsFormatTotalAmount($total);
			}
			return $result;
		}

		$salesUserId = LmdbSalesCommissionProposalService::resolveSalesUserId($this->db, $object);
		if ($salesUserId <= 0) {
			return array();
		}
		if (!$canSeeAll && !($canSeeOwn && (int) $user->id === $salesUserId) && !($canSeeGroup && lmdbsalescommissionsUsersShareGroup((int) $user->id, $salesUserId))) {
			return array();
		}
		$beneficiary = new User($this->db);
		$identity = array('beneficiary_id' => $salesUserId, 'beneficiary' => $beneficiary->fetch($salesUserId) > 0 ? $beneficiary->getNomUrl(1) : $langs->trans('Unknown'));

		if ((int) ($object->status ?? $object->statut ?? 0) >= 2) {
			$q = $this->db->query('SELECT commission_total, snapshot_rule_label FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line WHERE entity = '.$entity.' AND fk_user = '.$salesUserId." AND source_type = 'proposal' AND fk_source = ".((int) $object->id)." AND mode = 'margin' AND status <> 0 ORDER BY rowid DESC LIMIT 1");
			if (!$q) { return $identity + array('message' => $langs->trans('LscPolicyUnavailable')); }
			$row = $this->db->fetch_object($q);
			$this->db->free($q);
			$amount = is_object($row) ? (float) $row->commission_total : 0.0;
			return $identity + array('amount_value' => $amount, 'amount' => price($amount), 'rule' => is_object($row) ? dol_escape_htmltag($row->snapshot_rule_label) : '', 'status' => $langs->trans('LscFrozen'));
		}

		$margin = isset($marginInfo['total_margin']) && is_numeric($marginInfo['total_margin'])
			? (float) $marginInfo['total_margin']
			: LmdbSalesCommissionProposalService::getEstimatedMargin($object);
		$resolver = new LmdbSalesCommissionRuleResolver($this->db);
		$profile = $resolver->resolveForUser($salesUserId, dol_now(), $entity, 'proposal');
		$marginRule = $profile['selected']['margin'] ?? null;

		if (!empty($profile['errors'])) {
			return $identity + array('message' => '<span class="warning">'.$langs->trans('LmdbSalesCommissionsEstimateBlockedByRuleConflict').'</span>');
		} elseif (!is_array($marginRule)) {
			return $identity + array('amount_value' => 0.0, 'amount' => price(0), 'message' => '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsNoMarginRuleAvailable').'</span>');
		} elseif ($margin === null) {
			return $identity + array('message' => '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsMarginNotComputable').'</span>');
		}

		$turnoverDispatchService = new LmdbSalesCommissionProposalTurnoverDispatchService($this->db);
		$commissionableMargin = $turnoverDispatchService->calculateCommissionableMarginForUser($object, $salesUserId, $margin);
		if ($commissionableMargin === null) {
			return $identity + array('message' => '<span class="warning">'.$langs->trans($turnoverDispatchService->error).'</span>');
		}
		$base = max(0, $commissionableMargin);
		$rate = (float) ($marginRule['rate'] ?? 0);
		$amount = price2num($base * $rate / 100, 'MT');
		try {
			$amount = (new LmdbSalesCommissionMarginService($this->db))->commissionAmount($object, $salesUserId, (float) $amount);
		} catch (Exception $e) { return $identity + array('message' => $langs->trans('LscPolicyUnavailable')); }


		return $identity + array(
			'amount_value' => (float) $amount,
			'amount' => lmdbsalescommissionsFormatTotalAmount($amount),
			'margin' => lmdbsalescommissionsFormatTotalAmount($commissionableMargin),
			'rate' => lmdbsalescommissionsFormatTotalAmount($rate).' %',
			'rule' => dol_escape_htmltag((string) $marginRule['rule_label']),
			'source' => dol_escape_htmltag(lmdbsalescommissionsGetRuleSourceLabel($langs, (string) $marginRule['source'])),
			'status' => $langs->trans('LmdbSalesCommissionsEstimateNotAcquired'),
		);
	}

	/** Resolve the short notification/Agenda identity without a duplicate object class.
	 * @param array<string,mixed> $parameters @return int */
	public function getElementProperties($parameters, &$object, &$action, $hookmanager)
	{
		if (($parameters['elementType'] ?? '') !== 'lscmargincase') { return 0; }
		$this->results = array('module' => 'lmdbsalescommissions', 'element' => 'lscmargincase',
			'table_element' => 'lmdbsalescommissions_margin_case', 'subelement' => 'lscmargincase',
			'classpath' => 'lmdbsalescommissions/class', 'classfile' => 'lmdbsalescommissionmargincase',
			'classname' => 'LmdbSalesCommissionMarginCase');
		return 0;
	}

	/**
	 * Expose supported notification trigger codes to native Notifications module.
	 *
	 * @param array<string, mixed> $parameters  Hook parameters
	 * @param object               $object      Current object
	 * @param string               $action      Current action
	 * @param HookManager          $hookmanager Hook manager
	 * @return int
	 */
	public function notifsupported($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;

		$contexts = explode(':', (string) ($parameters['context'] ?? ''));
		if (!in_array('notification', $contexts, true)) {
			return 0;
		}
		if (!isModEnabled('lmdbsalescommissions')) {
			return 0;
		}
		// The generic notification branch needs the module's documentary configuration.
		$element = 'lscmargincase';
		if (isset($conf->lmdbsalescommissions) && is_object($conf->lmdbsalescommissions)) {
			$conf->{$element} = clone $conf->lmdbsalescommissions;
			$conf->{$element}->enabled = 1;
		}
		if (in_array($parameters['notifcode'] ?? '', array('LMDBSALESCOMMISSIONS_MARGIN_CASE_CREATE', 'LMDBSALESCOMMISSIONS_MARGIN_CASE_UPDATE'), true)) {
			global $user;
			require_once __DIR__.'/lmdbsalescommissionworkflownotification.class.php';
			LmdbSalesCommissionWorkflowNotification::prepare($this->db, $user, $parameters['notifcode'], (int) $object->entity);
		}

		$supported = array(
			'LMDBSALESCOMMISSIONS_LINE_CREATE',
			'LMDBSALESCOMMISSIONS_LINE_UPDATE',
			'LMDBSALESCOMMISSIONS_DUE_CREATE',
			'LMDBSALESCOMMISSIONS_DUE_UPDATE',
			'LMDBSALESCOMMISSIONS_OBJECTIVE_ARCHIVE_CREATE',
			'LMDBSALESCOMMISSIONS_MARGIN_CASE_CREATE',
			'LMDBSALESCOMMISSIONS_MARGIN_CASE_UPDATE',
		);
		if (isset($this->results['arrayofnotifsupported']) && is_array($this->results['arrayofnotifsupported'])) {
			$supported = array_merge($this->results['arrayofnotifsupported'], $supported);
		}
		$this->results['arrayofnotifsupported'] = array_values(array_unique($supported));

		return 0;
	}

	/** Fallback for the native validation URL, including when JavaScript is unavailable. */
	private function workflowConfirmation($proposal): int
	{
		global $user, $langs;
		if (!isModEnabled('lmdbsalescommissions') || !empty($user->socid) || !$user->hasRight('propal', 'lire') || !$user->hasRight('propal', 'creer')
			|| !is_object($proposal) || (int) ($proposal->status ?? $proposal->statut ?? -1) !== 0
			|| !restrictedArea($user, 'propal', $proposal->id, 'propal', '', 'fk_soc', 'rowid', 0, 1, 'write')) { return 0; }
		require_once __DIR__.'/lmdbsalescommissionmarginworkflowview.class.php';
		$langs->load('lmdbsalescommissions@lmdbsalescommissions');
		try { $decisions = (new LmdbSalesCommissionMarginWorkflow($this->db))->requestable($proposal, $user); }
		catch (RuntimeException $e) {
			if ($e->getMessage() === 'LscRequestNoDeniedSale') { return 0; }
			$this->resprints = '<div class="warning">'.dol_escape_htmltag($langs->trans($e->getMessage())).'</div>';
			return 1;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
		$form = new Form($this->db);
		$first = reset($decisions);
		$fields = array(array('type' => 'hidden', 'name' => 'fingerprint', 'value' => $first['fingerprint']), array('type' => 'textarea', 'name' => 'reason', 'label' => $langs->trans('Reason'), 'value' => '', 'moreattr' => 'required'));
		$this->resprints = $form->formconfirm(dol_buildpath('/lmdbsalescommissions/proposal_margin_workflow.php', 1).'?id='.(int) $proposal->id, $langs->trans('LscWorkflowRequest'), LmdbSalesCommissionMarginWorkflowView::checks($decisions), 'submitrequest', $fields, 'no', 1, 0, 800);
		return 1;
	}

	/** Add native workflow controls without replacing the core action bar. */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $user;
		if (!getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_WORKFLOW_READY') || !in_array('propalcard', explode(':', (string) ($parameters['context'] ?? '')), true)
			|| !empty($user->socid) || !$user->hasRight('propal', 'lire') || !is_object($object) || (int) $object->id <= 0) { return 0; }
		require_once __DIR__.'/lmdbsalescommissionmarginworkflowview.class.php';
		try {
			$this->resprints = LmdbSalesCommissionMarginWorkflowView::actions($this->db, $object, $user);
			$pending = (new LmdbSalesCommissionMarginWorkflow($this->db))->pending($object) !== null;
			$replace = $pending || strpos($this->resprints, 'lsc-workflow-request') !== false;
			if ($replace) {
				// Ergonomics only: doActions, API checks and triggers independently enforce the lock.
				$this->resprints .= '<script nonce="'.getNonce().'">jQuery(function($){$(".tabsAction a").each(function(){if($(this).hasClass("lsc-workflow-action")){return;}var u=new URL(this.href,location.href);if('.($pending ? 'true' : 'false').'||u.searchParams.get("action")==="validate"){$(this).hide();}});});</script>';
			}
			// Native proposal cards consume this hook's direct output, not resPrint.
			print $this->resprints;
			$this->resprints = '';
		} catch (RuntimeException $e) { $this->error = $e->getMessage(); return -1; }
		return 0;
	}

	/** Select the pending flag with the native list, not with one query per row. */
	public function printFieldListSelect($parameters, &$object, &$action, $hookmanager)
	{
		if (getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_WORKFLOW_READY') && in_array('propallist', explode(':', (string) ($parameters['context'] ?? '')), true)) {
			$this->resprints = ', (SELECT c.rowid FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_case c WHERE c.entity = p.entity AND c.active_proposal = p.rowid AND c.status = 0) AS lsc_pending_case';
		}
		return 0;
	}

	/** Transfer only the selected workflow flag to the object used by the core status renderer. */
	public function printFieldListValue($parameters, &$object, &$action, $hookmanager)
	{
		global $objectstatic;
		if (getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_WORKFLOW_READY') && in_array('propallist', explode(':', (string) ($parameters['context'] ?? '')), true)
			&& isset($parameters['obj']) && is_object($parameters['obj']) && is_object($objectstatic)) {
			$objectstatic->context['lsc_pending_case'] = !empty($parameters['obj']->lsc_pending_case);
		}
		return 0;
	}

	/** Native quote status extension; the persisted native status remains draft. */
	public function LibStatut($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;
		if (!getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_WORKFLOW_READY') || !is_object($object) || ($object->element ?? '') !== 'propal' || (int) ($parameters['status'] ?? -1) !== 0) { return 0; }
		$contexts = explode(':', (string) ($parameters['context'] ?? ''));
		$pending = false;
		if (in_array('propallist', $contexts, true)) { $pending = !empty($object->context['lsc_pending_case']); }
		elseif (in_array('propalcard', $contexts, true) && (int) $object->id > 0) {
			require_once __DIR__.'/lmdbsalescommissionmarginworkflow.class.php';
			try { $pending = (new LmdbSalesCommissionMarginWorkflow($this->db))->pending($object) !== null; }
			catch (RuntimeException $e) { $this->error = $e->getMessage(); return -1; }
		}
		if (!$pending) { return 0; }
		$langs->load('lmdbsalescommissions@lmdbsalescommissions');
		$this->resprints = dolGetStatus($langs->trans('LscWorkflowPending'), $langs->trans('LscWorkflowPending'), '', 'status1', (int) ($parameters['mode'] ?? 5));
		return 1;
	}
}
