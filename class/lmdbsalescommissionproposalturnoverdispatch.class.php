<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbsalescommissioncommon.class.php';

/**
 * Manual turnover allocation attached to a customer proposal.
 */
class LmdbSalesCommissionProposalTurnoverDispatch extends LmdbSalesCommissionCommon
{
	public $element = 'lmdbsalescommissions_proposal_turnover_dispatch';
	public $table_element = 'lmdbsalescommissions_proposal_turnover_dispatch';

	/** @var int|string|null Proposal id */
	public $fk_propal;
	/** @var int|string|null Beneficiary user id */
	public $fk_user;
	/** @var string|null amount or percentage */
	public $value_type;
	/** @var float|string|null Fixed amount or percentage */
	public $value;
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
		'fk_propal' => array('type' => 'integer', 'label' => 'Proposal', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 10),
		'fk_user' => array('type' => 'integer', 'label' => 'SalesRepresentative', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 20),
		'value_type' => array('type' => 'varchar(16)', 'label' => 'LmdbSalesCommissionsTurnoverDispatchValueType', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 30),
		'value' => array('type' => 'double(24,8)', 'label' => 'Value', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 40),
		'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => '1', 'visible' => 0, 'position' => 50),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 500),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'visible' => -2, 'noteditable' => 1, 'position' => 501),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => '1', 'visible' => -2, 'position' => 510),
		'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => '1', 'visible' => -2, 'position' => 520),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => '1', 'visible' => -2, 'position' => 530),
	);
}
