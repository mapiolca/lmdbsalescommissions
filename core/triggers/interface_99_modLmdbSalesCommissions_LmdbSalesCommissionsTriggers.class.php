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
	public $version = '1.0';

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

		if ($action !== 'PROPAL_VALIDATE' && $action !== 'PROPAL_CLOSE_SIGNED' && $action !== 'PROPAL_CLOSE_REFUSED' && $action !== 'PROPAL_DELETE') {
			return 0;
		}

		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionlineservice.class.php', 0);

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
		}

		return 0;
	}
}
