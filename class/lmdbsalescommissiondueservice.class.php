<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

require_once __DIR__.'/lmdbsalescommissiondue.class.php';
require_once __DIR__.'/lmdbsalescommissionline.class.php';

/**
 * Commission due service.
 */
class LmdbSalesCommissionDueService
{
	public const STATUS_WAITING = 0;
	public const STATUS_DUE = 1;
	public const STATUS_PAID = 2;
	public const STATUS_CANCELLED = 3;
	public const STATUS_BLOCKED = 4;

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
	 * Generate due dates for a commission line.
	 *
	 * @param LmdbSalesCommissionLine $line Commission line
	 * @param User                    $user User
	 * @return int Number of created due dates, -1 on error
	 */
	public function generateForLine($line, $user)
	{
		if (!is_object($line) || empty($line->id) || (float) $line->commission_total <= 0) {
			return 0;
		}

		$distribution = $this->fetchDistribution((int) $line->fk_payment_term, (int) $line->entity);
		if (empty($distribution)) {
			$distribution = array('proposal_signed' => 100.0);
		}

		$created = 0;
		$sum = 0.0;
		$index = 0;
		$count = count($distribution);
		foreach ($distribution as $eventType => $percentage) {
			$index++;
			if ($percentage <= 0) {
				continue;
			}
			$amount = $index === $count ? (float) $line->commission_total - $sum : price2num(((float) $line->commission_total * $percentage / 100), 'MT');
			$sum += $amount;
			$status = $eventType === 'proposal_signed' ? self::STATUS_DUE : self::STATUS_WAITING;
			$result = $this->createDueIfMissing($line, $user, $eventType, $percentage, $amount, $status);
			if ($result < 0) {
				return -1;
			}
			$created += $result;
		}

		$this->refreshLineTotals((int) $line->id, (int) $line->entity, $user);

		return $created;
	}

	/**
	 * Rebuild unpaid due dates for a commission line.
	 *
	 * Paid due dates are preserved as historical payment records.
	 *
	 * @param int  $lineId Commission line id
	 * @param User $user   User
	 * @return int Number of created due dates, -1 on error
	 */
	public function rebuildForLine($lineId, $user)
	{
		$line = new LmdbSalesCommissionLine($this->db);
		if ($line->fetch($lineId) <= 0) {
			$this->error = 'ErrorRecordNotFound';
			return -1;
		}

		$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due';
		$sql .= ' WHERE entity = '.((int) $line->entity);
		$sql .= ' AND fk_commission_line = '.((int) $line->id);
		$sql .= ' AND status <> '.self::STATUS_PAID;
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$result = $this->generateForLine($line, $user);
		if ($result < 0) {
			return -1;
		}

		dol_syslog(__METHOD__.': rebuilt unpaid due dates for line '.$lineId.' by user '.$user->id, LOG_INFO);

		return $result;
	}

	/**
	 * Mark due date as paid.
	 *
	 * @param int    $dueId    Due id
	 * @param int    $datePaid Payment date timestamp
	 * @param string $note     Private note
	 * @param User   $user     User
	 * @return int
	 */
	public function markAsPaid($dueId, $datePaid, $note, $user)
	{
		$due = new LmdbSalesCommissionDue($this->db);
		if ($due->fetch($dueId) <= 0) {
			$this->error = 'ErrorRecordNotFound';
			return -1;
		}
		$line = new LmdbSalesCommissionLine($this->db);
		if ($line->fetch((int) $due->fk_commission_line) <= 0 || (int) $line->entity !== (int) $due->entity) {
			$this->error = 'LmdbSalesCommissionsDueLineInvalid';
			return -1;
		}
		if ((int) $due->status !== self::STATUS_DUE) {
			$this->error = 'LmdbSalesCommissionsDueMustBeDueBeforePaid';
			return -1;
		}

		$due->status = self::STATUS_PAID;
		$due->date_paid = $datePaid > 0 ? $datePaid : dol_now();
		$due->fk_user_paid = (int) $user->id;
		$due->note_private = $note;

		$result = $due->update($user);
		if ($result <= 0) {
			$this->error = $due->error;
			$this->errors = $due->errors;
			return -1;
		}

		$this->refreshLineTotals((int) $due->fk_commission_line, (int) $due->entity, $user);
		dol_syslog(__METHOD__.': due '.$dueId.' marked as paid by user '.$user->id, LOG_INFO);

		return 1;
	}

	/**
	 * Fetch distribution for a payment term.
	 *
	 * @param int $paymentTermId Payment term id
	 * @param int $entity        Entity id
	 * @return array<string, float>
	 */
	private function fetchDistribution($paymentTermId, $entity)
	{
		$distribution = array();
		if ($paymentTermId <= 0) {
			return $distribution;
		}

		$sql = 'SELECT event_type, percentage';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term_line';
		$sql .= ' WHERE entity = '.((int) $entity);
		$sql .= ' AND fk_payment_term = '.((int) $paymentTermId);
		$sql .= ' AND active = 1';
		$sql .= ' ORDER BY rang ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $distribution;
		}
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$distribution[(string) $obj->event_type] = (float) $obj->percentage;
		}
		$this->db->free($resql);

		return $distribution;
	}

	/**
	 * Create a due date if missing.
	 *
	 * @param LmdbSalesCommissionLine $line       Commission line
	 * @param User                    $user       User
	 * @param string                  $eventType  Event type
	 * @param float                   $percentage Percentage
	 * @param float                   $amount     Amount
	 * @param int                     $status     Status
	 * @return int
	 */
	private function createDueIfMissing($line, $user, $eventType, $percentage, $amount, $status)
	{
		$sql = 'SELECT rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due';
		$sql .= ' WHERE entity = '.((int) $line->entity);
		$sql .= ' AND fk_commission_line = '.((int) $line->id);
		$sql .= " AND event_type = '".$this->db->escape($eventType)."'";
		$sql .= ' LIMIT 1';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$exists = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);
		if ($exists) {
			return 0;
		}

		$due = new LmdbSalesCommissionDue($this->db);
		$due->entity = (int) $line->entity;
		$due->fk_commission_line = (int) $line->id;
		$due->event_type = $eventType;
		$due->percentage = $percentage;
		$due->amount = $amount;
		$due->status = $status;
		$due->date_due = $status === self::STATUS_DUE ? (!empty($line->date_acquired) ? (int) $line->date_acquired : dol_now()) : null;

		$result = $due->create($user);
		if ($result <= 0) {
			$this->error = $due->error;
			$this->errors = $due->errors;
			return -1;
		}

		return 1;
	}

	/**
	 * Refresh paid and payable totals on parent line.
	 *
	 * @param int  $lineId Line id
	 * @param User $user   User
	 * @return void
	 */
	private function refreshLineTotals($lineId, $entity, $user)
	{
		$sql = 'SELECT SUM(CASE WHEN status IN ('.self::STATUS_DUE.','.self::STATUS_PAID.') THEN amount ELSE 0 END) AS payable,';
		$sql .= ' SUM(CASE WHEN status = '.self::STATUS_PAID.' THEN amount ELSE 0 END) AS paid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due';
		$sql .= ' WHERE fk_commission_line = '.((int) $lineId);
		$sql .= ' AND entity = '.((int) $entity);

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!is_object($obj)) {
			return;
		}

		$line = new LmdbSalesCommissionLine($this->db);
		if ($line->fetch($lineId) <= 0 || (int) $line->entity !== (int) $entity) {
			return;
		}

		$line->payable_total = (float) $obj->payable;
		$line->paid_total = (float) $obj->paid;
		$line->update($user, 1);
	}
}
