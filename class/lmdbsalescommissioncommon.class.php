<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Common base for lmdbsalescommissions business objects.
 */
abstract class LmdbSalesCommissionCommon extends CommonObject
{
	/** @var string Module key */
	public $module = 'lmdbsalescommissions';

	/** @var string Object element */
	public $element = '';

	/** @var string Database table without prefix */
	public $table_element = '';

	/** @var string Picto */
	public $picto = 'fa-percent';

	/** @var int Multicompany management mode */
	public $ismultientitymanaged = 1;

	/** @var int Extrafields management mode (no extrafields tables are provided) */
	public $isextrafieldmanaged = 0;

	/** @var array<string, array<string, mixed>> Field definitions */
	public $fields = array();

	/** @var int|string|null Technical row id */
	public $rowid;

	/** @var int|string|null Entity id */
	public $entity;

	/** @var int|string|null Activation state for configuration objects */
	public $active;

	/** @var int|string|null Creator user id */
	public $fk_user_creat;

	/** @var int|string|null Last modifier user id */
	public $fk_user_modif;

	/** @var string|null Import key */
	public $import_key;

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
	 * Create record.
	 *
	 * @param User $user      User making creation
	 * @param int  $notrigger 1 disables triggers
	 * @return int
	 */
	public function create($user, $notrigger = 0)
	{
		global $conf;

		if (empty($this->entity)) {
			$this->entity = (int) $conf->entity;
		}

		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Fetch record.
	 *
	 * @param int         $id  Record id
	 * @param string|null $ref Record reference
	 * @return int
	 */
	public function fetch($id, $ref = null)
	{
		return $this->fetchCommon($id, $ref);
	}

	/**
	 * Update record.
	 *
	 * @param User $user      User making update
	 * @param int  $notrigger 1 disables triggers
	 * @return int
	 */
	public function update($user, $notrigger = 0)
	{
		$this->fk_user_modif = (int) $user->id;

		return $this->updateCommon($user, $notrigger);
	}

	/** Change an administrative switch without overwriting other fields from a stale page.
	 * @param string $field active or is_default
	 * @param int $value Requested state (idempotent)
	 * @param User $user Current actor
	 * @return int
	 */
	public function setConfigurationFlag(string $field, int $value, $user): int
	{
		if (!$user->hasRight('lmdbsalescommissions', 'admin', 'configure')
			|| !in_array($field, array('active', 'is_default'), true) || !isset($this->fields[$field])
			|| !in_array($value, array(0, 1), true) || (int) $this->id <= 0 || (int) $this->entity <= 0) {
			$this->error = 'ErrorForbidden'; return -1;
		}
		$owner = (int) $this->entity;
		if (!in_array($owner, array_map('intval', explode(',', getEntity($this->table_element))), true)) {
			$this->error = 'ErrorForbidden'; return -1;
		}
		if (!$this->db->begin()) { $this->error = $this->db->lasterror(); return -1; }
		// Use the same lock order as payment-term default changes.
		$locked = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.$this->table_element.' WHERE entity = '.$owner.' ORDER BY rowid FOR UPDATE');
		if (!$locked) { $this->error = $this->db->lasterror(); $this->db->rollback(); return -1; }
		$this->db->free($locked);
		if ($this->fetch((int) $this->id) <= 0 || (int) $this->entity !== $owner) {
			$this->error = 'ErrorRecordNotFound'; $this->db->rollback(); return -1;
		}
		$this->{$field} = $value;
		if ($field === 'is_default' && $value === 1) { $this->active = 1; }
		if ($this->update($user) <= 0) { $this->db->rollback(); return -1; }
		if (!$this->db->commit()) { $this->error = $this->db->lasterror(); $this->db->rollback(); return -1; }
		return 1;
	}

	/**
	 * Delete record.
	 *
	 * @param User $user      User making deletion
	 * @param int  $notrigger 1 disables triggers
	 * @return int
	 */
	public function delete($user, $notrigger = 0)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/**
	 * Return a display label. Card pages are added in later PRs.
	 *
	 * @param int    $withpicto Add picto
	 * @param string $option    Option
	 * @param int    $notooltip Disable tooltip
	 * @param string $morecss   Additional CSS
	 * @return string
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '')
	{
		unset($option, $notooltip, $morecss);

		$label = property_exists($this, 'ref') && !empty($this->ref) ? (string) $this->ref : (string) $this->id;
		$result = dol_escape_htmltag($label);
		if ($withpicto) {
			$result = img_picto('', $this->picto, 'class="paddingright"').$result;
		}

		return $result;
	}
}
