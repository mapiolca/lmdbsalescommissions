<?php
/** Persistence double only: lifecycle tests exercise module services, not CommonObject SQL. */
class CommonObject
{
	public $db; public $id = 0; public $error = ''; public $errors = array();
	public function createCommon($user, $notrigger = 0) { return $this->db->saveObject($this); }
	public function updateCommon($user, $notrigger = 0) { return $this->db->saveObject($this); }
	public function fetchCommon($id, $ref = null) {
		$row = $this->db->objects[$this->table_element][$id] ?? null;
		if (!$row) { return 0; }
		foreach ($row as $key => $value) { $this->$key = $value; }
		return 1;
	}
}
