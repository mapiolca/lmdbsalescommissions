<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbsalescommissioncommon.class.php';

/**
 * Commission payment term.
 */
class LmdbSalesCommissionPaymentTerm extends LmdbSalesCommissionCommon
{
	public $element = 'lmdbsalescommissions_payment_term';
	public $table_element = 'lmdbsalescommissions_payment_term';
	public $TRIGGER_PREFIX = 'LMDBSALESCOMMISSIONS_PAYMENT_TERM';

	/** @param User $user @param int $notrigger @return int */
	public function create($user, $notrigger = 0)
	{
		global $conf;
		$this->entity = (int) $conf->entity;
		return $this->saveFlags($user, $notrigger, true);
	}

	/** @param User $user @param int $notrigger @return int */
	public function update($user, $notrigger = 0)
	{
		return $this->saveFlags($user, $notrigger, false);
	}

	/** Persist a term and its exclusive default in the owner's transaction.
	 * @param User $user @param int $notrigger @param bool $create @return int
	 */
	protected function saveFlags($user, $notrigger, bool $create)
	{
		if (!$user->hasRight('lmdbsalescommissions', 'admin', 'configure') || (int) $this->entity <= 0
			|| !in_array((int) $this->entity, array_map('intval', explode(',', getEntity($this->table_element))), true)) {
			$this->error = 'ErrorForbidden'; return -1;
		}
		if (!$this->db->begin()) { $this->error = $this->db->lasterror(); return -1; }
		// Serialize default changes, including concurrent form submissions, within this entity.
		$table = MAIN_DB_PREFIX.$this->table_element;
		$locked = $this->db->query('SELECT rowid FROM '.$table.' WHERE entity = '.((int) $this->entity).' ORDER BY rowid FOR UPDATE');
		if (!$locked) { $this->error = $this->db->lasterror(); $this->db->rollback(); return -1; }
		$this->db->free($locked);
		if (!(int) $this->active) { $this->is_default = 0; }
		$result = 1;
		if ((int) $this->is_default) {
			$peers = $this->db->query('SELECT rowid FROM '.$table.' WHERE entity = '.((int) $this->entity).' AND is_default = 1 AND rowid <> '.((int) $this->id));
			if (!$peers) { $this->error = $this->db->lasterror(); $result = -1; }
			else {
				$ids = array();
				while (is_object($peer = $this->db->fetch_object($peers))) { $ids[] = (int) $peer->rowid; }
				$this->db->free($peers);
				foreach ($ids as $peerId) {
					$term = new self($this->db);
					if ($term->fetch($peerId) <= 0 || (int) $term->entity !== (int) $this->entity) { $this->error = 'ErrorRecordNotFound'; $result = -1; break; }
					$term->is_default = 0;
					if ($term->update($user, $notrigger) <= 0) { $this->error = $term->error; $result = -1; break; }
				}
			}
		}
		if ($result > 0) { $result = $create ? parent::create($user, $notrigger) : parent::update($user, $notrigger); }
		if ($result <= 0) { $this->db->rollback(); return -1; }
		if (!$this->db->commit()) { $this->error = $this->db->lasterror(); $this->db->rollback(); return -1; }
		return $result;
	}

	public $ref;
	public $label;
	public $active;
	public $is_default;
	public $note_private;
	public $date_creation;
	public $tms;

	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => '1', 'visible' => 0, 'notnull' => 1, 'default' => '1', 'position' => 5),
		'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 10),
		'label' => array('type' => 'varchar(255)', 'label' => 'Label', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 20),
		'active' => array('type' => 'integer', 'label' => 'Active', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '1', 'position' => 30),
		'is_default' => array('type' => 'integer', 'label' => 'Default', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 40),
		'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => '1', 'visible' => 0, 'position' => 50),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 500),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'visible' => -2, 'noteditable' => 1, 'position' => 501),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => '1', 'visible' => -2, 'position' => 510),
		'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => '1', 'visible' => -2, 'position' => 520),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => '1', 'visible' => -2, 'position' => 530),
	);
}
