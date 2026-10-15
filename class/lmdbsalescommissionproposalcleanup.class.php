<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** Transactional removal and single-use confirmation of proposal commissions. */
class LmdbSalesCommissionProposalCleanup
{
	/** @var DoliDB */
	private $db;
	/** @var string */
	public $error = '';
	/** @var list<string> */
	public $errors = array();

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Read the authoritative proposal row, not the stale object passed by setDraft().
	 * @return array<string, mixed>|null Null means absent; SQL errors set error.
	 */
	public function proposalState(int $id, int $entity, bool $lock = false): ?array
	{
		$rows = $this->rows('SELECT rowid, entity, fk_statut FROM '.MAIN_DB_PREFIX.'propal WHERE rowid = '.$id.' AND entity = '.$entity.($lock ? ' FOR UPDATE' : ''));
		return $rows === null || !$rows ? null : $rows[0];
	}

	/**
	 * Stable snapshot includes all line/dues values, including paid history.
	 * @return array{lines:list<array<string,mixed>>,dues:list<array<string,mixed>>,paid:bool,fingerprint:string}|null
	 */
	public function inspect(int $id, int $entity, bool $lock = false): ?array
	{
		if ($id <= 0 || $entity <= 0) {
			$this->error = 'LmdbSalesCommissionsInvalidProposal';
			return null;
		}
		$lines = $this->rows('SELECT * FROM '.MAIN_DB_PREFIX."lmdbsalescommissions_line WHERE source_type = 'proposal' AND fk_source = ".$id.' AND entity = '.$entity.' ORDER BY rowid'.($lock ? ' FOR UPDATE' : ''));
		if ($lines === null) { return null; }
		$ids = array_map(static function (array $line): int { return (int) $line['rowid']; }, $lines);
		$dues = $ids ? $this->rows('SELECT * FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due WHERE entity = '.$entity.' AND fk_commission_line IN ('.implode(',', $ids).') ORDER BY rowid'.($lock ? ' FOR UPDATE' : '')) : array();
		if ($dues === null) { return null; }
		$paid = false;
		foreach ($lines as $line) { $paid = $paid || (float) $line['paid_total'] > 0; }
		foreach ($dues as $due) { $paid = $paid || (int) $due['status'] === 2; }
		return array('lines' => $lines, 'dues' => $dues, 'paid' => $paid, 'fingerprint' => hash('sha256', serialize(array($lines, $dues))));
	}

	/**
	 * Only the authenticated card or maintenance page may issue this challenge.
	 * @param User $user Actor
	 * @param array{fingerprint:string} $snapshot Snapshot to approve
	 * @return string Opaque session challenge, never written to logs or the database
	 */
	public function prepareConfirmation(int $id, int $entity, string $operation, $user, array $snapshot): string
	{
		global $conf;
		$nonce = bin2hex(random_bytes(32));
		// One outstanding challenge per session prevents unbounded session growth.
		$_SESSION['lmdbsalescommissions_cleanup'] = array(
			'nonce' => $nonce, 'proposal' => $id, 'entity' => $entity, 'actor_entity' => (int) $conf->entity,
			'user' => (int) $user->id, 'operation' => $operation, 'expires' => dol_now() + 600,
			'fingerprint' => $snapshot['fingerprint'],
			'approved' => false,
		);
		return $nonce;
	}

	/**
	 * Record the affirmative native form submission before the trigger may consume it.
	 * @param User $user Actor
	 * @param array{fingerprint:string} $snapshot Current snapshot
	 */
	public function approveConfirmation(int $id, int $entity, string $operation, $user, array $snapshot, string $nonce): bool
	{
		global $conf;
		$grant = $_SESSION['lmdbsalescommissions_cleanup'] ?? null;
		if (!is_array($grant) || $nonce === '' || !isset($grant['nonce'], $grant['fingerprint'], $grant['user'], $grant['entity'], $grant['actor_entity'], $grant['proposal'], $grant['operation'], $grant['expires'])
			|| !hash_equals((string) $grant['nonce'], $nonce) || !hash_equals((string) $grant['fingerprint'], $snapshot['fingerprint'])
			|| (int) $grant['user'] !== (int) $user->id || (int) $grant['entity'] !== $entity || (int) $grant['actor_entity'] !== (int) $conf->entity
			|| (int) $grant['proposal'] !== $id || $grant['operation'] !== $operation || (int) $grant['expires'] <= dol_now()) {
			unset($_SESSION['lmdbsalescommissions_cleanup']);
			$this->error = 'LscCleanupConfirmationRequired';
			return false;
		}
		$_SESSION['lmdbsalescommissions_cleanup']['approved'] = true;
		return true;
	}

	/**
	 * @param User $user Actor
	 * @param array{fingerprint:string} $snapshot Locked snapshot
	 */
	private function consumeConfirmation(int $id, int $entity, string $operation, $user, array $snapshot, string $nonce): bool
	{
		global $conf;
		$grant = $_SESSION['lmdbsalescommissions_cleanup'] ?? null;
		unset($_SESSION['lmdbsalescommissions_cleanup']);
		return is_array($grant) && $nonce !== '' && isset($grant['nonce'], $grant['fingerprint'], $grant['expires'], $grant['proposal'], $grant['entity'], $grant['actor_entity'], $grant['user'], $grant['operation'], $grant['approved']) && $grant['approved'] === true
			&& hash_equals((string) $grant['nonce'], $nonce) && hash_equals((string) $grant['fingerprint'], $snapshot['fingerprint'])
			&& (int) $grant['proposal'] === $id && (int) $grant['entity'] === $entity
			&& (int) $grant['actor_entity'] === (int) $conf->entity && (int) $grant['user'] === (int) $user->id
			&& $grant['operation'] === $operation && (int) $grant['expires'] > dol_now();
	}

	/**
	 * Native callers retain responsibility for proposal access, CSRF and final commit.
	 * Maintenance requires an administrator and the explicit maintenance right.
	 * @param object $proposal Loaded proposal (or verified historical source identity)
	 * @param User $user Actor
	 * @return int Number of deleted commission lines, -1 on error
	 */
	public function deleteForProposal($proposal, $user, string $operation, string $nonce = ''): int
	{
		global $conf;
		$this->error = '';
		$this->errors = array();
		$id = is_object($proposal) && isset($proposal->id) ? (int) $proposal->id : 0;
		$entity = is_object($proposal) && isset($proposal->entity) ? (int) $proposal->entity : 0;
		$maintenance = $operation === 'cleanup';
		if ($id <= 0 || $entity <= 0 || !in_array($operation, array('delete', 'draft', 'cleanup'), true)) {
			$this->error = 'LmdbSalesCommissionsInvalidProposal';
			return -1;
		}
		if ($maintenance) {
			if (!$user->admin || !$user->hasRight('lmdbsalescommissions', 'maintenance', 'recalculate') || $entity !== (int) $conf->entity) {
				$this->error = 'NotEnoughPermissions'; return -1;
			}
		} elseif (!$user->hasRight('propal', $operation === 'delete' ? 'supprimer' : 'creer')) {
			$this->error = 'NotEnoughPermissions'; return -1;
		}
		if (!$this->db->begin()) { $this->error = $this->db->lasterror(); return -1; }
		try {
			$state = $this->proposalState($id, $entity, true);
			if ($this->error !== '') { throw new RuntimeException($this->error); }
			if (($operation === 'draft' && ($state === null || (int) $state['fk_statut'] !== 0))
				|| ($operation === 'delete' && $state === null)
				|| ($maintenance && $state !== null && (int) $state['fk_statut'] !== 0)) {
				throw new RuntimeException('LscCleanupChanged');
			}
			if ($maintenance) {
				$candidates = $this->candidates($entity, $id - 1);
				if ($candidates === null) { throw new RuntimeException($this->error); }
				$eligible = false;
				foreach ($candidates as $candidate) { if ((int) $candidate['fk_source'] === $id) { $eligible = true; break; } }
				if (!$eligible) { throw new RuntimeException('LscCleanupChanged'); }
			}
			$snapshot = $this->inspect($id, $entity, true);
			if ($snapshot === null) { throw new RuntimeException($this->error); }
			if ($snapshot['paid'] && !$user->hasRight('lmdbsalescommissions', 'due', 'pay')) {
				throw new RuntimeException('NotEnoughPermissions');
			}
			if (($snapshot['paid'] || $maintenance || $nonce !== '') && !$this->consumeConfirmation($id, $entity, $operation, $user, $snapshot, $nonce)) {
				throw new RuntimeException('LscCleanupConfirmationRequired');
			}
			$periods = array();
			foreach ($snapshot['lines'] as $line) {
				if (in_array($line['mode'], array('turnover', 'tier'), true) && $line['date_acquired'] !== null && $line['date_acquired'] !== '') {
					$date = (int) $this->db->jdate($line['date_acquired']);
					if ($date <= 0) { throw new RuntimeException('LscCleanupMissingAcquisitionDate'); }
					$periods[(int) $line['fk_user'].':'.$date] = array((int) $line['fk_user'], $date);
				} elseif (in_array($line['mode'], array('turnover', 'tier'), true) && (int) $line['status'] === 1) {
					throw new RuntimeException('LscCleanupMissingAcquisitionDate');
				}
			}
			foreach ($snapshot['dues'] as $due) {
				$this->execute('DELETE FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_due WHERE entity = '.$entity.' AND rowid = '.((int) $due['rowid']));
			}
			foreach ($snapshot['lines'] as $line) {
				$this->execute('DELETE FROM '.MAIN_DB_PREFIX."lmdbsalescommissions_line WHERE entity = ".$entity." AND source_type = 'proposal' AND fk_source = ".$id.' AND rowid = '.((int) $line['rowid']));
			}
			require_once __DIR__.'/lmdbsalescommissiontierservice.class.php';
			$tierService = new LmdbSalesCommissionTierService($this->db);
			foreach ($periods as $period) {
				$result = $tierService->calculateForUser($period[0], $user, $period[1], $entity);
				if (!isset($result['status']) || in_array($result['status'], array('error', 'blocked'), true)) {
					$this->errors = array_merge($this->errors, $tierService->errors);
					throw new RuntimeException($tierService->error ?: 'LscCleanupTierFailed');
				}
				$this->errors = array_merge($this->errors, $tierService->errors);
				if ($result['status'] === 'no_rule') { $this->errors[] = 'LscCleanupTierRuleMissing'; }
			}
			if (!$this->db->commit()) { throw new RuntimeException($this->db->lasterror() ?: 'LscCleanupTransactionFailed'); }
			dol_syslog(__METHOD__.': proposal '.$id.' entity '.$entity.' lines '.count($snapshot['lines']).' dues '.count($snapshot['dues']).' actor '.((int) $user->id), LOG_INFO);
			return count($snapshot['lines']);
		} catch (Throwable $e) {
			$this->error = $e->getMessage();
			if (!$this->db->rollback()) { $this->errors[] = 'LscCleanupTransactionFailed'; }
			return -1;
		}
	}

	/**
	 * One bounded page of historical sources; numeric id is a pagination cursor.
	 * @return list<array<string,mixed>>|null
	 */
	public function candidates(int $entity, int $after = 0): ?array
	{
		return $this->rows('SELECT l.fk_source, COUNT(*) AS line_count, MIN(l.source_ref) AS source_ref, SUM(l.commission_total) AS amount'
			.' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_line l LEFT JOIN '.MAIN_DB_PREFIX.'propal p ON p.rowid = l.fk_source'
			." WHERE l.entity = ".$entity." AND l.source_type = 'proposal' AND l.fk_source > ".$after
			.' AND (p.rowid IS NULL OR (p.entity = l.entity AND p.fk_statut = 0)) GROUP BY l.fk_source ORDER BY l.fk_source LIMIT 50');
	}

	/** @return list<array<string,mixed>>|null */
	private function rows(string $sql): ?array
	{
		$resql = $this->db->query($sql);
		if (!$resql) { $this->error = $this->db->lasterror(); return null; }
		$rows = array();
		while (is_object($row = $this->db->fetch_object($resql))) { $rows[] = (array) $row; }
		$this->db->free($resql);
		return $rows;
	}

	/** Execute a step of the cleanup transaction. */
	private function execute(string $sql): void
	{
		if (!$this->db->query($sql)) { throw new RuntimeException($this->db->lasterror()); }
	}
}
