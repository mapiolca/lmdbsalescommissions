<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * Trigger class for lmdbsalescommissions.
 */
class InterfaceLmdbSalesCommissionsTriggers
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Family */
	public $family = 'lmdbsalescommissions';

	/** @var string Description */
	public $description = 'LmdbSalesCommissionsTriggers';

	/** @var string Version */
	public $version = '1.3.0';

	/** @var string Picto */
	public $picto = 'fa-percent';

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
	 * Run trigger.
	 *
	 * @param string    $action Action code
	 * @param object    $object Object
	 * @param User      $user   User
	 * @param Translate $langs  Langs
	 * @param Conf      $conf   Conf
	 * @return int
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		$langs->load('lmdbsalescommissions@lmdbsalescommissions');

		if (!isModEnabled('lmdbsalescommissions')) {
			return 0;
		}

		require_once __DIR__.'/../../class/lmdbsalescommissionmarginservice.class.php';
		// MODIFY is listened to for legacy CommonObject callers; new approval events use CRUD.
		if (preg_match('/^LMDBSALESCOMMISSIONS_(RULE|RULE_ASSIGNMENT|PROPOSAL_DISPATCH|PROPOSAL_TURNOVER_DISPATCH)_(CREATE|UPDATE|MODIFY|DELETE)$/', $action, $match)) {
			try { (new LmdbSalesCommissionMarginService($this->db))->invalidate((int) $object->entity, strpos($match[1], 'PROPOSAL_') === 0 ? (int) $object->fk_propal : 0); }
			catch (Exception $e) { $this->error = $langs->trans($e->getMessage()); return -1; }
			return 0;
		}
		$proposalUpdateActions = array('PROPAL_MODIFY', 'LINEPROPAL_INSERT', 'LINEPROPAL_UPDATE', 'LINEPROPAL_DELETE');
		if ($action !== 'PROPAL_VALIDATE' && $action !== 'PROPAL_CLOSE_SIGNED' && $action !== 'PROPAL_CLOSE_REFUSED' && $action !== 'PROPAL_DELETE' && !in_array($action, $proposalUpdateActions, true)) {
			return 0;
		}

		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionlineservice.class.php', 0);
		if (in_array($action, $proposalUpdateActions, true)) {
			$proposal = $object;
			if ($action !== 'PROPAL_MODIFY') {
				$proposalId = property_exists($object, 'fk_propal') ? (int) $object->fk_propal : 0;
				if ($proposalId <= 0) {
					return 0;
				}
				require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
				$proposal = new Propal($this->db);
				if ($proposal->fetch($proposalId) <= 0) {
					$this->error = $proposal->error ?: 'ErrorRecordNotFound';
					return -1;
				}
			}
			try { (new LmdbSalesCommissionMarginService($this->db))->invalidate((int) $proposal->entity, (int) $proposal->id); }
			catch (Exception $e) { $this->error = $langs->trans($e->getMessage()); return -1; }
			$status = property_exists($proposal, 'statut') ? (int) $proposal->statut : (property_exists($proposal, 'status') ? (int) $proposal->status : -1);
			$signatureDate = property_exists($proposal, 'date_signature') ? (int) $proposal->date_signature : 0;
			if ($status !== 1 || $signatureDate > 0) {
				return 0;
			}
			$service = new LmdbSalesCommissionLineService($this->db);
			$result = $service->estimateFromProposal($proposal, $user);
			if ($result < 0) {
				$this->error = $service->error;
				$this->errors = $service->errors;
				return -1;
			}

			return 0;
		}

		if (in_array($action, array('PROPAL_VALIDATE', 'PROPAL_CLOSE_SIGNED'), true)) {
			require_once __DIR__.'/../../class/lmdbsalescommissionmarginservice.class.php';
			try {
				// Reload: public signature writes the row without updating its in-memory object.
				require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
				$current = new Propal($this->db);
				if ($current->fetch((int) $object->id) <= 0) { throw new RuntimeException('LscPolicyUnavailable'); }
				$current->context['lmdb_margin_signing'] = $action === 'PROPAL_CLOSE_SIGNED';
				$policy = new LmdbSalesCommissionMarginService($this->db);
				if (!$policy->saleAllowed($current, $user)) { throw new RuntimeException('LscSaleBlocked'); }
				if ($action === 'PROPAL_CLOSE_SIGNED') { $policy->freeze($current, $user); $object = $current; }
			} catch (Exception $e) { $this->error = $langs->trans($e->getMessage()); return -1; }
		}
		$service = new LmdbSalesCommissionLineService($this->db);
		if ($action === 'PROPAL_VALIDATE') {
			$result = $service->estimateFromProposal($object, $user);
			if ($result < 0) {
				$this->error = $service->error;
				$this->errors = $service->errors;
				return -1;
			}
		} elseif ($action === 'PROPAL_CLOSE_SIGNED') {
			$result = $service->acquireFromProposal($object, $user);
			if ($result < 0) {
				$this->error = $service->error;
				$this->errors = $service->errors;
				return -1;
			}
		} else {
			$result = $service->cancelProposalLines($object, $user);
			if ($result < 0) {
				$this->error = $service->error;
				return -1;
			}
			if ($action === 'PROPAL_DELETE') {
				require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposaldispatchservice.class.php', 0);
				require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposalturnoverdispatchservice.class.php', 0);
				$dispatchService = new LmdbSalesCommissionProposalDispatchService($this->db);
				if ($dispatchService->deleteForProposal($object, $user) < 0) {
					$this->error = $dispatchService->error;
					$this->errors = $dispatchService->errors;
					return -1;
				}
				$turnoverDispatchService = new LmdbSalesCommissionProposalTurnoverDispatchService($this->db);
				if ($turnoverDispatchService->deleteForProposal($object, $user) < 0) {
					$this->error = $turnoverDispatchService->error;
					$this->errors = $turnoverDispatchService->errors;
					return -1;
				}
			}
		}

		return 0;
	}
}
