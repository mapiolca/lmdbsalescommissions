<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbsalescommissioncommon.class.php';

/**
 * Commission payment due date.
 */
class LmdbSalesCommissionDue extends LmdbSalesCommissionCommon
{
	public $element = 'lmdbsalescommissions_due';
	public $table_element = 'lmdbsalescommissions_due';

	public $fk_commission_line;
	public $event_type;
	public $percentage;
	public $amount;
	public $status;
	public $date_due;
	public $date_paid;
	public $fk_user_paid;
	public $note_private;
	public $date_creation;
	public $tms;

	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => '1', 'visible' => 0, 'notnull' => 1, 'default' => '1', 'position' => 5),
		'fk_commission_line' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsCommissionLine', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 10),
		'event_type' => array('type' => 'varchar(32)', 'label' => 'Event', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 20),
		'percentage' => array('type' => 'double(10,4)', 'label' => 'Percentage', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 30),
		'amount' => array('type' => 'double(24,8)', 'label' => 'Amount', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 40),
		'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 50),
		'date_due' => array('type' => 'datetime', 'label' => 'DateDue', 'enabled' => '1', 'visible' => 1, 'position' => 60),
		'date_paid' => array('type' => 'datetime', 'label' => 'DatePayment', 'enabled' => '1', 'visible' => 1, 'position' => 70),
		'fk_user_paid' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsPaidBy', 'enabled' => '1', 'visible' => 1, 'position' => 80),
		'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => '1', 'visible' => 0, 'position' => 90),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 500),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'visible' => -2, 'noteditable' => 1, 'position' => 501),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => '1', 'visible' => -2, 'position' => 510),
		'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => '1', 'visible' => -2, 'position' => 520),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => '1', 'visible' => -2, 'position' => 530),
	);
}
