<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbsalescommissioncommon.class.php';

/**
 * Commission rule.
 */
class LmdbSalesCommissionRule extends LmdbSalesCommissionCommon
{
	public $element = 'lmdbsalescommissions_rule';
	public $table_element = 'lmdbsalescommissions_rule';

	/** @var string|null Reference */
	public $ref;
	/** @var string|null Label */
	public $label;
	/** @var string|null Rule type: margin or tier */
	public $rule_type;
	/** @var float|string|null Commission rate */
	public $rate;
	/** @var int|string|null Tier grid id */
	public $fk_tier_grid;
	/** @var int|string|null Payment term id */
	public $fk_payment_term;
	/** @var string|null Source type */
	public $source_type;
	/** @var string|null Period type */
	public $period_type;
	/** @var int|string|null Cumulative flag */
	public $cumulative;
	/** @var int|string|null Priority */
	public $priority;
	/** @var int|string|null Active flag */
	public $active;
	/** @var int|string|null Start date timestamp/string */
	public $date_start;
	/** @var int|string|null End date timestamp/string */
	public $date_end;
	/** @var string|null Negative margin behavior */
	public $negative_margin_mode;
	/** @var string|null Description */
	public $description;
	/** @var string|null Private note */
	public $note_private;
	/** @var int|string|null Creation date */
	public $date_creation;
	/** @var int|string|null Timestamp */
	public $tms;

	/** @var array<string, array<string, mixed>> Field definitions */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => '1', 'visible' => 0, 'notnull' => 1, 'default' => '1', 'position' => 5),
		'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 10),
		'label' => array('type' => 'varchar(255)', 'label' => 'Label', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 20),
		'rule_type' => array('type' => 'varchar(32)', 'label' => 'Type', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 30),
		'rate' => array('type' => 'double(10,4)', 'label' => 'Rate', 'enabled' => '1', 'visible' => 1, 'position' => 40),
		'fk_tier_grid' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsTierGrid', 'enabled' => '1', 'visible' => 1, 'position' => 50),
		'fk_payment_term' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsPaymentTerms', 'enabled' => '1', 'visible' => 1, 'position' => 55),
		'source_type' => array('type' => 'varchar(32)', 'label' => 'Source', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => 'proposal', 'position' => 60),
		'period_type' => array('type' => 'varchar(32)', 'label' => 'Period', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => 'monthly', 'position' => 70),
		'cumulative' => array('type' => 'integer', 'label' => 'Cumulative', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '1', 'position' => 80),
		'priority' => array('type' => 'integer', 'label' => 'Priority', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 90),
		'active' => array('type' => 'integer', 'label' => 'Active', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '1', 'position' => 100),
		'date_start' => array('type' => 'date', 'label' => 'DateStart', 'enabled' => '1', 'visible' => 1, 'position' => 110),
		'date_end' => array('type' => 'date', 'label' => 'DateEnd', 'enabled' => '1', 'visible' => 1, 'position' => 120),
		'negative_margin_mode' => array('type' => 'varchar(32)', 'label' => 'LmdbSalesCommissionsNegativeMarginMode', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => 'zero', 'position' => 130),
		'description' => array('type' => 'text', 'label' => 'Description', 'enabled' => '1', 'visible' => 1, 'position' => 140),
		'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => '1', 'visible' => 0, 'position' => 150),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 500),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'visible' => -2, 'noteditable' => 1, 'position' => 501),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => '1', 'visible' => -2, 'position' => 510),
		'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => '1', 'visible' => -2, 'position' => 520),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => '1', 'visible' => -2, 'position' => 530),
	);
}
