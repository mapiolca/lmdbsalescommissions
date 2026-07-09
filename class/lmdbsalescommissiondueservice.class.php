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
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

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
	public const FINAL_INVOICE_MODE_FIRST_PAID = 'first_paid';
	public const FINAL_INVOICE_MODE_ALL_LINKED_PAID = 'all_linked_paid';
	public const FINAL_INVOICE_MODE_ORDER_BILLED_AND_ALL_PAID = 'order_billed_and_all_paid';

	private const EVENT_PROPOSAL_SIGNED = 'proposal_signed';
	private const EVENT_DEPOSIT_PAID = 'deposit_paid';
	private const EVENT_FINAL_INVOICE_PAID = 'final_invoice_paid';

	/** @var DoliDB Database handler */
	private $db;

	/** @var string Error message */
	public $error = '';

	/** @var array<int, string> Error list */
	public $errors = array();

	/**
	 * Linked invoice cache by entity and proposal id.
	 *
	 * @var array<string, array<int, array{invoice_id:int,order_id:int,type:int,status:int,paid:int,situation_final:int,date_due:int,order_billed:int}>|null>
	 */
	private $linkedInvoiceCache = array();

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
			$amount = $index === $count ? (float) price2num((float) $line->commission_total - $sum, 'MT') : (float) price2num(((float) $line->commission_total * $percentage / 100), 'MT');
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
	 * Detect waiting due dates that became payable from native Dolibarr links.
	 *
	 * @param User       $user        User used to update due dates
	 * @param array<int> $proposalIds Optional proposal ids to restrict detection
	 * @return int Number of due dates marked as payable, -1 on error
	 */
	public function detectPayableDueDates($user, array $proposalIds = array())
	{
		$this->error = '';
		$this->errors = array();
		$this->linkedInvoiceCache = array();

		if (!is_object($user)) {
			$this->error = 'ErrorNoUser';
			return -1;
		}

		$proposalIds = $this->normalizePositiveIds($proposalIds);

		$sql = 'SELECT d.rowid AS due_id, d.entity, d.event_type, l.rowid AS line_id, l.fk_source AS proposal_id, l.date_acquired';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due AS d';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_line AS l ON l.rowid = d.fk_commission_line AND l.entity = d.entity';
		$sql .= ' WHERE d.entity IN ('.$this->db->sanitize(getEntity('lmdbsalescommissions_due')).')';
		$sql .= ' AND d.status = '.self::STATUS_WAITING;
		$sql .= ' AND l.status = 1';
		$sql .= " AND l.source_type = 'proposal'";
		if (!empty($proposalIds)) {
			$sql .= ' AND l.fk_source IN ('.implode(',', $proposalIds).')';
		}
		$sql .= " AND d.event_type IN ('".self::EVENT_PROPOSAL_SIGNED."', '".self::EVENT_DEPOSIT_PAID."', '".self::EVENT_FINAL_INVOICE_PAID."')";
		$sql .= ' ORDER BY d.rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$rows = array();
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$rows[] = $obj;
		}
		$this->db->free($resql);

		$marked = 0;
		foreach ($rows as $obj) {
			$event = $this->resolvePayableEvent((int) $obj->proposal_id, (int) $obj->entity, (string) $obj->event_type, $obj);
			if (!empty($event['error'])) {
				return -1;
			}
			if (empty($event['available'])) {
				continue;
			}

			$result = $this->markDueAsPayable((int) $obj->due_id, (int) $obj->line_id, (int) $obj->entity, (string) $obj->event_type, $event, $user);
			if ($result < 0) {
				return -1;
			}
			$marked += $result;
		}

		dol_syslog(__METHOD__.': marked '.$marked.' due dates as payable from native invoice links', LOG_INFO);

		return $marked;
	}

	/**
	 * Normalize a list of positive technical ids.
	 *
	 * @param array<int> $ids Raw ids
	 * @return array<int>
	 */
	private function normalizePositiveIds(array $ids)
	{
		$normalized = array();
		foreach ($ids as $id) {
			$id = (int) $id;
			if ($id > 0) {
				$normalized[$id] = $id;
			}
		}

		return array_values($normalized);
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
	 * Resolve whether one waiting due date event became payable.
	 *
	 * @param int    $proposalId Proposal id
	 * @param int    $entity     Entity id
	 * @param string $eventType  Due event type
	 * @param object $row        Current SQL row
	 * @return array{available:bool,date_due:int,invoice_id?:int,order_id?:int,mode?:string,error?:bool}
	 */
	private function resolvePayableEvent($proposalId, $entity, $eventType, $row)
	{
		if ($eventType === self::EVENT_PROPOSAL_SIGNED) {
			return array(
				'available' => true,
				'date_due' => !empty($row->date_acquired) ? (int) $this->db->jdate($row->date_acquired) : dol_now(),
			);
		}

		if ($proposalId <= 0 || $entity <= 0) {
			return array('available' => false, 'date_due' => 0);
		}

		$invoices = $this->fetchLinkedInvoicesForProposal($proposalId, $entity);
		if ($invoices === null) {
			return array('available' => false, 'date_due' => 0, 'error' => true);
		}
		if ($eventType === self::EVENT_DEPOSIT_PAID) {
			return $this->resolvePaidDepositEvent($invoices);
		}
		if ($eventType === self::EVENT_FINAL_INVOICE_PAID) {
			return $this->resolvePaidFinalInvoiceEvent($invoices);
		}

		return array('available' => false, 'date_due' => 0);
	}

	/**
	 * Fetch invoices linked directly to a proposal or indirectly through its orders.
	 *
	 * @param int $proposalId Proposal id
	 * @param int $entity     Entity id
	 * @return array<int, array{invoice_id:int,order_id:int,type:int,status:int,paid:int,situation_final:int,date_due:int,order_billed:int}>|null
	 */
	private function fetchLinkedInvoicesForProposal($proposalId, $entity)
	{
		$cacheKey = ((int) $entity).':'.((int) $proposalId);
		if (array_key_exists($cacheKey, $this->linkedInvoiceCache)) {
			return $this->linkedInvoiceCache[$cacheKey];
		}

		$linksSql = $this->buildLinkedInvoiceSubquery($proposalId, $entity);

		$sql = 'SELECT f.rowid AS invoice_id, MAX(src.order_id) AS order_id, f.type, f.fk_statut, f.paye, f.situation_final, f.date_closing, f.datef, f.date_valid, MAX(COALESCE(c.facture, 0)) AS order_billed';
		$sql .= ' FROM ('.$linksSql.') AS src';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facture AS f ON f.rowid = src.invoice_id';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'commande AS c ON c.rowid = src.order_id AND c.entity = '.((int) $entity);
		$sql .= ' WHERE f.entity = '.((int) $entity);
		$sql .= ' GROUP BY f.rowid, f.type, f.fk_statut, f.paye, f.situation_final, f.date_closing, f.datef, f.date_valid';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.': '.$this->error, LOG_ERR);
			$this->linkedInvoiceCache[$cacheKey] = null;
			return null;
		}

		$invoices = array();
		while (is_object($obj = $this->db->fetch_object($resql))) {
			$invoices[] = array(
				'invoice_id' => (int) $obj->invoice_id,
				'order_id' => (int) $obj->order_id,
				'type' => (int) $obj->type,
				'status' => (int) $obj->fk_statut,
				'paid' => (int) $obj->paye,
				'situation_final' => (int) $obj->situation_final,
				'date_due' => $this->getInvoicePayableDate($obj),
				'order_billed' => (int) $obj->order_billed,
			);
		}
		$this->db->free($resql);
		$this->linkedInvoiceCache[$cacheKey] = $invoices;

		return $invoices;
	}

	/**
	 * Build the native element-link subquery that resolves proposal invoices.
	 *
	 * @param int $proposalId Proposal id
	 * @param int $entity     Entity id
	 * @return string SQL subquery
	 */
	private function buildLinkedInvoiceSubquery($proposalId, $entity)
	{
		$orderLinks = array(
			'SELECT ee.fk_target AS order_id FROM '.MAIN_DB_PREFIX.'element_element AS ee INNER JOIN '.MAIN_DB_PREFIX.'commande AS c ON c.rowid = ee.fk_target AND c.entity = '.((int) $entity).' WHERE ee.fk_source = '.((int) $proposalId)." AND ee.sourcetype = 'propal' AND ee.targettype = 'commande'",
			'SELECT ee.fk_source AS order_id FROM '.MAIN_DB_PREFIX.'element_element AS ee INNER JOIN '.MAIN_DB_PREFIX.'commande AS c ON c.rowid = ee.fk_source AND c.entity = '.((int) $entity).' WHERE ee.fk_target = '.((int) $proposalId)." AND ee.targettype = 'propal' AND ee.sourcetype = 'commande'",
		);
		$ordersSql = implode(' UNION ', $orderLinks);

		$invoiceLinks = array(
			'SELECT ee.fk_target AS invoice_id, 0 AS order_id FROM '.MAIN_DB_PREFIX.'element_element AS ee WHERE ee.fk_source = '.((int) $proposalId)." AND ee.sourcetype = 'propal' AND ee.targettype = 'facture'",
			'SELECT ee.fk_source AS invoice_id, 0 AS order_id FROM '.MAIN_DB_PREFIX.'element_element AS ee WHERE ee.fk_target = '.((int) $proposalId)." AND ee.targettype = 'propal' AND ee.sourcetype = 'facture'",
			'SELECT ee.fk_target AS invoice_id, orders.order_id FROM ('.$ordersSql.') AS orders INNER JOIN '.MAIN_DB_PREFIX."element_element AS ee ON ee.fk_source = orders.order_id AND ee.sourcetype = 'commande' AND ee.targettype = 'facture'",
			'SELECT ee.fk_source AS invoice_id, orders.order_id FROM ('.$ordersSql.') AS orders INNER JOIN '.MAIN_DB_PREFIX."element_element AS ee ON ee.fk_target = orders.order_id AND ee.targettype = 'commande' AND ee.sourcetype = 'facture'",
		);

		return implode(' UNION ', $invoiceLinks);
	}

	/**
	 * Resolve the deposit due event from linked invoices.
	 *
	 * @param array<int, array{invoice_id:int,order_id:int,type:int,status:int,paid:int,situation_final:int,date_due:int,order_billed:int}> $invoices Linked invoices
	 * @return array{available:bool,date_due:int,invoice_id?:int,order_id?:int,mode?:string}
	 */
	private function resolvePaidDepositEvent(array $invoices)
	{
		foreach ($invoices as $invoice) {
			if ($this->isPaidInvoice($invoice) && $invoice['type'] === Facture::TYPE_DEPOSIT) {
				return array(
					'available' => true,
					'date_due' => $invoice['date_due'],
					'invoice_id' => $invoice['invoice_id'],
					'order_id' => $invoice['order_id'],
					'mode' => self::EVENT_DEPOSIT_PAID,
				);
			}
		}

		return array('available' => false, 'date_due' => 0);
	}

	/**
	 * Resolve the final invoice due event from linked invoices.
	 *
	 * @param array<int, array{invoice_id:int,order_id:int,type:int,status:int,paid:int,situation_final:int,date_due:int,order_billed:int}> $invoices Linked invoices
	 * @return array{available:bool,date_due:int,invoice_id?:int,order_id?:int,mode?:string}
	 */
	private function resolvePaidFinalInvoiceEvent(array $invoices)
	{
		$mode = $this->getFinalInvoiceDueMode();
		$finalInvoices = array();
		foreach ($invoices as $invoice) {
			if ($this->isFinalInvoiceCandidate($invoice)) {
				$finalInvoices[] = $invoice;
			}
		}
		if (empty($finalInvoices)) {
			return array('available' => false, 'date_due' => 0);
		}

		if ($mode === self::FINAL_INVOICE_MODE_ALL_LINKED_PAID) {
			return $this->resolveAllLinkedFinalInvoicesPaid($finalInvoices, $mode);
		}
		if ($mode === self::FINAL_INVOICE_MODE_ORDER_BILLED_AND_ALL_PAID) {
			return $this->resolveOrderBilledFinalInvoicesPaid($finalInvoices, $mode);
		}

		foreach ($finalInvoices as $invoice) {
			if ($this->isPaidInvoice($invoice)) {
				return array(
					'available' => true,
					'date_due' => $invoice['date_due'],
					'invoice_id' => $invoice['invoice_id'],
					'order_id' => $invoice['order_id'],
					'mode' => self::FINAL_INVOICE_MODE_FIRST_PAID,
				);
			}
		}

		return array('available' => false, 'date_due' => 0);
	}

	/**
	 * Resolve final event when every linked final invoice must be paid.
	 *
	 * @param array<int, array{invoice_id:int,order_id:int,type:int,status:int,paid:int,situation_final:int,date_due:int,order_billed:int}> $finalInvoices Final invoices
	 * @param string $mode Resolution mode
	 * @return array{available:bool,date_due:int,invoice_id?:int,order_id?:int,mode?:string}
	 */
	private function resolveAllLinkedFinalInvoicesPaid(array $finalInvoices, $mode)
	{
		$latestInvoice = null;
		foreach ($finalInvoices as $invoice) {
			if (!$this->isPaidInvoice($invoice)) {
				return array('available' => false, 'date_due' => 0);
			}
			if ($latestInvoice === null || $invoice['date_due'] > $latestInvoice['date_due']) {
				$latestInvoice = $invoice;
			}
		}

		if (!is_array($latestInvoice)) {
			return array('available' => false, 'date_due' => 0);
		}

		return array(
			'available' => true,
			'date_due' => $latestInvoice['date_due'],
			'invoice_id' => $latestInvoice['invoice_id'],
			'order_id' => $latestInvoice['order_id'],
			'mode' => $mode,
		);
	}

	/**
	 * Resolve final event when an order must be billed and all its final invoices paid.
	 *
	 * @param array<int, array{invoice_id:int,order_id:int,type:int,status:int,paid:int,situation_final:int,date_due:int,order_billed:int}> $finalInvoices Final invoices
	 * @param string $mode Resolution mode
	 * @return array{available:bool,date_due:int,invoice_id?:int,order_id?:int,mode?:string}
	 */
	private function resolveOrderBilledFinalInvoicesPaid(array $finalInvoices, $mode)
	{
		$byOrder = array();
		foreach ($finalInvoices as $invoice) {
			if ($invoice['order_id'] <= 0) {
				continue;
			}
			if (!isset($byOrder[$invoice['order_id']])) {
				$byOrder[$invoice['order_id']] = array();
			}
			$byOrder[$invoice['order_id']][] = $invoice;
		}

		foreach ($byOrder as $orderInvoices) {
			$hasBilledOrder = false;
			$latestInvoice = null;
			foreach ($orderInvoices as $invoice) {
				$hasBilledOrder = $hasBilledOrder || $invoice['order_billed'] > 0;
				if (!$this->isPaidInvoice($invoice)) {
					continue 2;
				}
				if ($latestInvoice === null || $invoice['date_due'] > $latestInvoice['date_due']) {
					$latestInvoice = $invoice;
				}
			}
			if ($hasBilledOrder && is_array($latestInvoice)) {
				return array(
					'available' => true,
					'date_due' => $latestInvoice['date_due'],
					'invoice_id' => $latestInvoice['invoice_id'],
					'order_id' => $latestInvoice['order_id'],
					'mode' => $mode,
				);
			}
		}

		return array('available' => false, 'date_due' => 0);
	}

	/**
	 * Check whether an invoice is paid according to native customer invoice state.
	 *
	 * @param array{invoice_id:int,order_id:int,type:int,status:int,paid:int,situation_final:int,date_due:int,order_billed:int} $invoice Invoice row
	 * @return bool
	 */
	private function isPaidInvoice(array $invoice)
	{
		return $invoice['paid'] === 1 && $invoice['status'] === Facture::STATUS_CLOSED;
	}

	/**
	 * Check whether an invoice can release the final invoice event.
	 *
	 * @param array{invoice_id:int,order_id:int,type:int,status:int,paid:int,situation_final:int,date_due:int,order_billed:int} $invoice Invoice row
	 * @return bool
	 */
	private function isFinalInvoiceCandidate(array $invoice)
	{
		if ($invoice['status'] === Facture::STATUS_ABANDONED) {
			return false;
		}

		if (in_array($invoice['type'], array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT), true)) {
			return true;
		}

		return $invoice['type'] === Facture::TYPE_SITUATION && $invoice['situation_final'] === 1;
	}

	/**
	 * Return configured final invoice due mode.
	 *
	 * @return string
	 */
	private function getFinalInvoiceDueMode()
	{
		$mode = getDolGlobalString('LMDBSALESCOMMISSIONS_FINAL_INVOICE_DUE_MODE', self::FINAL_INVOICE_MODE_FIRST_PAID);
		if (!in_array($mode, array(self::FINAL_INVOICE_MODE_FIRST_PAID, self::FINAL_INVOICE_MODE_ALL_LINKED_PAID, self::FINAL_INVOICE_MODE_ORDER_BILLED_AND_ALL_PAID), true)) {
			return self::FINAL_INVOICE_MODE_FIRST_PAID;
		}

		return $mode;
	}

	/**
	 * Return the best payable date available on an invoice row.
	 *
	 * @param object $invoice Invoice SQL row
	 * @return int
	 */
	private function getInvoicePayableDate($invoice)
	{
		foreach (array('date_closing', 'datef', 'date_valid') as $field) {
			if (!empty($invoice->{$field})) {
				return (int) $this->db->jdate($invoice->{$field});
			}
		}

		return dol_now();
	}

	/**
	 * Mark one due date as payable through the business object.
	 *
	 * @param int                  $dueId     Due date id
	 * @param int                  $lineId    Commission line id
	 * @param int                  $entity    Entity id
	 * @param string               $eventType Event type
	 * @param array<string, mixed> $event     Resolved native event
	 * @param User                 $user      User
	 * @return int
	 */
	private function markDueAsPayable($dueId, $lineId, $entity, $eventType, array $event, $user)
	{
		$due = new LmdbSalesCommissionDue($this->db);
		if ($due->fetch($dueId) <= 0 || (int) $due->entity !== (int) $entity) {
			$this->error = 'ErrorRecordNotFound';
			return -1;
		}
		if ((int) $due->status !== self::STATUS_WAITING) {
			return 0;
		}

		$due->oldcopy = clone $due;
		$due->status = self::STATUS_DUE;
		$due->date_due = !empty($event['date_due']) ? (int) $event['date_due'] : dol_now();
		if (!isset($due->context) || !is_array($due->context)) {
			$due->context = array();
		}
		$due->context['trigger_reason'] = 'native_invoice_payment_detected';
		$due->context['changed_fields'] = array('status', 'date_due');
		$due->context['event_type'] = $eventType;
		$due->context['old_status'] = self::STATUS_WAITING;
		$due->context['new_status'] = self::STATUS_DUE;
		if (!empty($event['invoice_id'])) {
			$due->context['fk_facture'] = (int) $event['invoice_id'];
		}
		if (!empty($event['order_id'])) {
			$due->context['fk_commande'] = (int) $event['order_id'];
		}
		if (!empty($event['mode']) && is_string($event['mode'])) {
			$due->context['final_invoice_due_mode'] = $event['mode'];
		}

		$result = $due->update($user);
		if ($result <= 0) {
			$this->error = $due->error;
			$this->errors = $due->errors;
			return -1;
		}

		$this->refreshLineTotals($lineId, $entity, $user);

		return 1;
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
		$due->amount = (float) price2num($amount, 'MT');
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
	 * @param int  $entity Entity id
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

		$line->payable_total = (float) price2num(is_numeric($obj->payable) ? $obj->payable : 0, 'MT');
		$line->paid_total = (float) price2num(is_numeric($obj->paid) ? $obj->paid : 0, 'MT');
		$line->update($user, 1);
	}
}
