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
	public $version = '1.2.0';

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
		unset($langs, $conf);

		if (!isModEnabled('lmdbsalescommissions')) {
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
