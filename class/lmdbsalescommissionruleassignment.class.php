<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbsalescommissioncommon.class.php';

/**
 * Rule assignment.
 */
class LmdbSalesCommissionRuleAssignment extends LmdbSalesCommissionCommon
{
	public $element = 'lmdbsalescommissions_rule_assignment';
	public $table_element = 'lmdbsalescommissions_rule_assignment';

	public $assignment_type;
	public $fk_user;
	public $fk_usergroup;
	public $fk_rule;
	public $date_start;
	public $date_end;
	public $active;
	public $cumulative;
	public $priority;
	public $fk_payment_term;
	public $note_private;
	public $date_creation;
	public $tms;

	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => '1', 'visible' => 0, 'notnull' => 1, 'default' => '1', 'position' => 5),
		'assignment_type' => array('type' => 'varchar(32)', 'label' => 'Type', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => 'default', 'position' => 10),
		'fk_user' => array('type' => 'integer', 'label' => 'User', 'enabled' => '1', 'visible' => 1, 'position' => 20),
		'fk_usergroup' => array('type' => 'integer', 'label' => 'Group', 'enabled' => '1', 'visible' => 1, 'position' => 30),
		'fk_rule' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsRule', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 40),
		'date_start' => array('type' => 'date', 'label' => 'DateStart', 'enabled' => '1', 'visible' => 1, 'position' => 50),
		'date_end' => array('type' => 'date', 'label' => 'DateEnd', 'enabled' => '1', 'visible' => 1, 'position' => 60),
		'active' => array('type' => 'integer', 'label' => 'Active', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '1', 'position' => 70),
		'cumulative' => array('type' => 'integer', 'label' => 'Cumulative', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '1', 'position' => 80),
		'priority' => array('type' => 'integer', 'label' => 'Priority', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 90),
		'fk_payment_term' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsPaymentTerms', 'enabled' => '1', 'visible' => 1, 'position' => 100),
		'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => '1', 'visible' => 0, 'position' => 110),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 500),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'visible' => -2, 'noteditable' => 1, 'position' => 501),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => '1', 'visible' => -2, 'position' => 510),
		'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => '1', 'visible' => -2, 'position' => 520),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => '1', 'visible' => -2, 'position' => 530),
	);
}
