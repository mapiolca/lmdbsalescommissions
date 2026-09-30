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
 */
class ActionsLmdbSalesCommissions
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Error message */
	public $error = '';

	/** @var array<int, string> Error messages */
	public $errors = array();

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
				if (!$service->saleAllowed($proposal)) { throw new RuntimeException('LscSaleBlocked'); }
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
			foreach ((new LmdbSalesCommissionMarginService($this->db))->assess($object) as $decision) {
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
		if (empty($commissionData)) { $commissionData = array('message' => ''); }

		$columnCount = 4;
		if (getDolGlobalString('DISPLAY_MARGIN_RATES')) {
			$columnCount++;
		}
		if (getDolGlobalString('DISPLAY_MARK_RATES')) {
			$columnCount++;
		}

		global $langs;

		$this->resprints = '<tr class="oddeven lmdbsalescommissions-estimated-commission">';
		$this->resprints .= '<td colspan="'.$columnCount.'">';
		$this->resprints .= '<table class="noborder liste centpercent lmdbsalescommissions-estimated-commission-table">';
		if (isset($commissionData['message'])) {
			$this->resprints .= '<tr class="liste_titre"><td>'.$langs->trans('LmdbSalesCommissionsEstimatedCommission').'</td></tr>';
			$this->resprints .= '<tr class="oddeven"><td>'.$commissionData['message'].'</td></tr>';
		} elseif (isset($commissionData['rows']) && is_array($commissionData['rows'])) {
			$headers = array(
				$langs->trans('SalesRepresentative'),
				$langs->trans('LmdbSalesCommissionsDispatchFormula'),
				$langs->trans('LmdbSalesCommissionsPaymentTerms'),
				$langs->trans('LmdbSalesCommissionsProposalEstimateTableCommission'),
				$langs->trans('Status'),
			);
			$this->resprints .= '<tr class="liste_titre">';
			foreach ($headers as $headerIndex => $header) {
				$this->resprints .= '<td class="liste_titre'.($headerIndex === 3 ? ' right' : '').'">'.dol_escape_htmltag($header).'</td>';
			}
			$this->resprints .= '</tr>';
			foreach ($commissionData['rows'] as $row) {
				if (!is_array($row)) {
					continue;
				}
				$this->resprints .= '<tr class="oddeven">';
				$this->resprints .= '<td>'.($row['beneficiary'] ?? '').'</td>';
				$this->resprints .= '<td>'.dol_escape_htmltag((string) ($row['formula'] ?? '')).'</td>';
				$this->resprints .= '<td>'.dol_escape_htmltag((string) ($row['payment_term'] ?? '')).'</td>';
				$this->resprints .= '<td class="right">'.($row['amount'] ?? '').'</td>';
				$this->resprints .= '<td>'.($row['status'] ?? '').'</td>';
				$this->resprints .= '</tr>';
			}
			if (isset($commissionData['total'])) {
				$this->resprints .= '<tr class="liste_total"><td colspan="3" class="right">'.$langs->trans('Total').'</td><td class="right">'.$commissionData['total'].'</td><td></td></tr>';
			}
		} else {
			$headers = array(
				$langs->trans('LmdbSalesCommissionsProposalEstimateTableCommission'),
				$langs->trans('LmdbSalesCommissionsMarginBase'),
				$langs->trans('Rate'),
				$langs->trans('LmdbSalesCommissionsProposalEstimateTableRule'),
				$langs->trans('LmdbSalesCommissionsProposalEstimateTableRuleSource'),
				$langs->trans('Status'),
			);
			$values = array(
				(string) $commissionData['amount'],
				(string) $commissionData['margin'],
				(string) $commissionData['rate'],
				(string) $commissionData['rule'],
				(string) $commissionData['source'],
				(string) $commissionData['status'],
			);

			$this->resprints .= '<tr class="liste_titre">';
			foreach ($headers as $headerIndex => $header) {
				$this->resprints .= '<td class="liste_titre'.($headerIndex < 3 ? ' right' : '').'">'.dol_escape_htmltag($header).'</td>';
			}
			$this->resprints .= '</tr>';
			$this->resprints .= '<tr class="oddeven">';
			foreach ($values as $valueIndex => $value) {
				$this->resprints .= '<td'.($valueIndex < 3 ? ' class="right"' : '').'>'.$value.'</td>';
			}
			$this->resprints .= '</tr>';
		}
		$this->resprints .= '</table>';
		$this->resprints .= '</td>';
		$this->resprints .= '</tr>';

		global $user;
		require_once __DIR__.'/lmdbsalescommissionmarginview.class.php';
		$policyHtml = LmdbSalesCommissionMarginView::render($this->db, $object, $user);
		if ($policyHtml !== '') { $this->resprints .= '<tr><td colspan="'.$columnCount.'">'.$policyHtml.'</td></tr>'; }

		return 0;
	}

	/**
	 * Build estimated commission data for a proposal.
	 *
	 * @param object               $object     Current proposal
	 * @param array<string, mixed> $marginInfo Native margin information
	 * @return array<string, mixed>
	 */
	private function buildProposalEstimatedCommissionData($object, array $marginInfo)
	{
		global $langs, $user;

		require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposalservice.class.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionruleresolver.class.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposaldispatchservice.class.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposalturnoverdispatchservice.class.php', 0);
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

		$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions'));

		$entity = !empty($object->entity) ? (int) $object->entity : 0;
		$dispatchService = new LmdbSalesCommissionProposalDispatchService($this->db);
		$dispatches = $dispatchService->fetchForProposal((int) ($object->id ?? 0), $entity);
		if (!empty($dispatches)) {
			$rows = array();
			$total = 0.0;
			$canSeeAll = lmdbsalescommissionsCanManageDispatch($user) || !empty($user->admin) || $user->hasRight('lmdbsalescommissions', 'commission', 'readall');
			foreach ($dispatches as $dispatch) {
				if (!$canSeeAll && !lmdbsalescommissionsCanReadUserScope($user, (int) $dispatch->fk_user)) {
					continue;
				}
				$calculation = $dispatchService->getCalculationForDisplay($dispatch, $object, dol_now());
				if (!is_array($calculation)) {
					$rows[] = array(
						'beneficiary' => dol_escape_htmltag((string) $dispatch->fk_user),
						'formula' => lmdbsalescommissionsFormatDispatchFormula($langs, (string) $dispatch->base_type, (string) $dispatch->value_type, $dispatch->value),
						'payment_term' => '',
						'amount' => img_warning($langs->trans($dispatchService->error)),
						'status' => lmdbsalescommissionsStatusBadge($langs->trans('LmdbSalesCommissionsLineStatusBlocked'), -1),
					);
					continue;
				}
				$beneficiary = new User($this->db);
				$beneficiaryLabel = dol_escape_htmltag((string) $dispatch->fk_user);
				if ($beneficiary->fetch((int) $dispatch->fk_user) > 0) {
					$beneficiaryLabel = $beneficiary->getNomUrl(1);
				}
				$paymentLabel = $calculation['payment_term_label'] === 'LmdbSalesCommissionsPaymentImmediateAtSignature' ? $langs->trans($calculation['payment_term_label']) : $calculation['payment_term_label'];
				$total += (float) $calculation['commission'];
				$rows[] = array(
					'beneficiary' => $beneficiaryLabel,
					'formula' => lmdbsalescommissionsFormatDispatchFormula($langs, (string) $dispatch->base_type, (string) $dispatch->value_type, $dispatch->value),
					'payment_term' => $paymentLabel,
					'amount' => lmdbsalescommissionsFormatTotalAmount($calculation['commission']),
					'status' => $langs->trans('LmdbSalesCommissionsEstimateNotAcquired'),
				);
			}
			if (empty($rows)) {
				return array();
			}

			$result = array('rows' => $rows);
			if ($canSeeAll) {
				$result['total'] = lmdbsalescommissionsFormatTotalAmount($total);
			}
			return $result;
		}

		$salesUserId = LmdbSalesCommissionProposalService::resolveSalesUserId($this->db, $object);
		if ($salesUserId <= 0) {
			return array();
		}
		if (!lmdbsalescommissionsCanReadUserScope($user, $salesUserId)) {
			return array();
		}

		$margin = isset($marginInfo['total_margin']) && is_numeric($marginInfo['total_margin'])
			? (float) $marginInfo['total_margin']
			: LmdbSalesCommissionProposalService::getEstimatedMargin($object);
		$resolver = new LmdbSalesCommissionRuleResolver($this->db);
		$profile = $resolver->resolveForUser($salesUserId, dol_now(), $entity, 'proposal');
		$marginRule = $profile['selected']['margin'] ?? null;

		if (!empty($profile['errors'])) {
			return array('message' => '<span class="warning">'.$langs->trans('LmdbSalesCommissionsEstimateBlockedByRuleConflict').'</span>');
		} elseif (!is_array($marginRule)) {
			return array('message' => '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsNoMarginRuleAvailable').'</span>');
		} elseif ($margin === null) {
			return array('message' => '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsMarginNotComputable').'</span>');
		}

		$turnoverDispatchService = new LmdbSalesCommissionProposalTurnoverDispatchService($this->db);
		$commissionableMargin = $turnoverDispatchService->calculateCommissionableMarginForUser($object, $salesUserId, $margin);
		if ($commissionableMargin === null) {
			return array('message' => '<span class="warning">'.$langs->trans($turnoverDispatchService->error).'</span>');
		}
		$base = max(0, $commissionableMargin);
		$rate = (float) ($marginRule['rate'] ?? 0);
		$amount = price2num($base * $rate / 100, 'MT');
		try {
			if ((new LmdbSalesCommissionMarginService($this->db))->commissionState($object, $salesUserId) !== 'allow') { $amount = 0.0; }
		} catch (Exception $e) { return array('message' => $langs->trans('LscPolicyUnavailable')); }


		return array(
			'amount' => lmdbsalescommissionsFormatTotalAmount($amount),
			'margin' => lmdbsalescommissionsFormatTotalAmount($commissionableMargin),
			'rate' => lmdbsalescommissionsFormatTotalAmount($rate).' %',
			'rule' => dol_escape_htmltag((string) $marginRule['rule_label']),
			'source' => dol_escape_htmltag(lmdbsalescommissionsGetRuleSourceLabel($langs, (string) $marginRule['source'])),
			'status' => $langs->trans('LmdbSalesCommissionsEstimateNotAcquired'),
		);
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
		unset($object, $action, $hookmanager);

		$contexts = explode(':', (string) ($parameters['context'] ?? ''));
		if (!in_array('notification', $contexts, true)) {
			return 0;
		}
		if (!isModEnabled('lmdbsalescommissions')) {
			return 0;
		}

		$supported = array(
			'LMDBSALESCOMMISSIONS_LINE_CREATE',
			'LMDBSALESCOMMISSIONS_LINE_UPDATE',
			'LMDBSALESCOMMISSIONS_DUE_CREATE',
			'LMDBSALESCOMMISSIONS_DUE_UPDATE',
			'LMDBSALESCOMMISSIONS_OBJECTIVE_ARCHIVE_CREATE',
		);
		if (isset($this->results['arrayofnotifsupported']) && is_array($this->results['arrayofnotifsupported'])) {
			$supported = array_merge($this->results['arrayofnotifsupported'], $supported);
		}
		$this->results['arrayofnotifsupported'] = array_values(array_unique($supported));

		return 0;
	}
}
