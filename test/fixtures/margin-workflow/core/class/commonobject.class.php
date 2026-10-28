<?php
/** Isolated persistence double. The workflow test separately executes native Propal::valid(). */
class CommonObject
{
	public $db;
	public $id = 0;
	public $error = '';
	public $errors = array();
	public $context = array();
	public $oldcopy;
	public $ref;
	public $fk_project;
	public function createCommon($user, $notrigger = 0)
	{
		$this->fk_user_creat = $user->id;
		$result = $this->db->saveObject($this);
		if ($result > 0 && !$notrigger && $this->call_trigger($this->TRIGGER_PREFIX.'_CREATE', $user) < 0) { return -1; }
		return $result;
	}
	public function updateCommon($user, $notrigger = 0)
	{
		$result = $this->db->saveObject($this);
		if ($result > 0 && !$notrigger && $this->call_trigger($this->TRIGGER_PREFIX.'_UPDATE', $user) < 0) { return -1; }
		return $result;
	}
	public function fetchCommon($id, $ref = null)
	{
		$row = $this->db->objects[$this->table_element][$id] ?? null;
		if (!$row) { return 0; }
		foreach ($row as $key => $value) { $this->{$key} = $value; }
		return 1;
	}
	public function call_trigger($action, $user)
	{
		if ($this->db->event($action, $this, $user) < 0) { $this->error = 'TestNotificationFailure'; return -1; }
		return 1;
	}
}
