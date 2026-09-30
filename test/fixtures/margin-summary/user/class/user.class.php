<?php
/** User/rights fixture only; no ERP authentication is performed by these tests. */
class User
{
	public $id = 7;
	public $socid = 0;
	public $admin = 1;
	public $permissions = array('readall');
	public function __construct($db) {}
	public function hasRight($module, $object, $action) { return in_array($action, $this->permissions, true); }
	public function fetch($id) { $this->id = (int) $id; return $id > 0 ? 1 : 0; }
	public function getFullName($langs) { return 'Commercial '.$this->id; }
}
