<?php
/** Proposal loading fixture; native element resolution is executed by the test. */
class Propal
{
	public $db;
	public $module = '';
	public $element = 'propal';
	public $table_element = 'propal';
	public $id = 0;
	public $entity = 1;
	public $status = 2;
	public $date_signature = 100;
	public function __construct($db) { $this->db = $db; }
	public function fetch($id, $ref = '') { $this->id = (int) $id; return $this->id === 41 ? 1 : -1; }
	public function getTooltipContent($params) { return ''; }
}
