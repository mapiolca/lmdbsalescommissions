<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbsalescommissioncommon.class.php';

/**
 * Commission line.
 */
class LmdbSalesCommissionLine extends LmdbSalesCommissionCommon
{
	public $element = 'lmdbsalescommissions_line';
	public $table_element = 'lmdbsalescommissions_line';

	public $fk_user;
	public $fk_soc;
	public $source_type;
	public $fk_source;
	public $source_ref;
	public $mode;
	public $amount_base;
	public $margin_base;
	public $rate;
	public $fk_tier;
	public $commission_total;
	public $payable_total;
	public $paid_total;
	public $status;
	public $date_acquired;
	public $fk_rule;
	public $fk_payment_term;
	public $fk_proposal_dispatch;
	public $rule_source;
	public $snapshot_rule_label;
	public $snapshot_rule_rate;
	public $snapshot_base_type;
	public $snapshot_value_type;
	public $snapshot_value;
	public $note_private;
	public $date_creation;
	public $tms;

	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => '1', 'visible' => 0, 'notnull' => 1, 'default' => '1', 'position' => 5),
		'fk_user' => array('type' => 'integer', 'label' => 'SalesRepresentative', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 10),
		'fk_soc' => array('type' => 'integer', 'label' => 'ThirdParty', 'enabled' => '1', 'visible' => 1, 'position' => 20),
		'source_type' => array('type' => 'varchar(32)', 'label' => 'Source', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => 'proposal', 'position' => 30),
		'fk_source' => array('type' => 'integer', 'label' => 'SourceId', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 40),
		'source_ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => '1', 'visible' => 1, 'position' => 50),
		'mode' => array('type' => 'varchar(32)', 'label' => 'Mode', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 60),
		'amount_base' => array('type' => 'double(24,8)', 'label' => 'AmountHT', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 70),
		'margin_base' => array('type' => 'double(24,8)', 'label' => 'Margin', 'enabled' => '1', 'visible' => 1, 'position' => 80),
		'rate' => array('type' => 'double(10,4)', 'label' => 'Rate', 'enabled' => '1', 'visible' => 1, 'position' => 90),
		'fk_tier' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsTier', 'enabled' => '1', 'visible' => 1, 'position' => 100),
		'commission_total' => array('type' => 'double(24,8)', 'label' => 'LmdbSalesCommissionsCommissionTotal', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 110),
		'payable_total' => array('type' => 'double(24,8)', 'label' => 'LmdbSalesCommissionsPayableTotal', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 120),
		'paid_total' => array('type' => 'double(24,8)', 'label' => 'LmdbSalesCommissionsPaidTotal', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 130),
		'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 140),
		'date_acquired' => array('type' => 'datetime', 'label' => 'LmdbSalesCommissionsDateAcquired', 'enabled' => '1', 'visible' => 1, 'position' => 150),
		'fk_rule' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsRule', 'enabled' => '1', 'visible' => 1, 'position' => 160),
		'fk_payment_term' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsPaymentTerms', 'enabled' => '1', 'visible' => 1, 'position' => 165),
		'fk_proposal_dispatch' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsProposalDispatch', 'enabled' => '1', 'visible' => 0, 'position' => 166),
		'rule_source' => array('type' => 'varchar(32)', 'label' => 'LmdbSalesCommissionsRuleSource', 'enabled' => '1', 'visible' => 1, 'position' => 170),
		'snapshot_rule_label' => array('type' => 'varchar(255)', 'label' => 'LmdbSalesCommissionsSnapshotRuleLabel', 'enabled' => '1', 'visible' => 0, 'position' => 180),
		'snapshot_rule_rate' => array('type' => 'double(10,4)', 'label' => 'LmdbSalesCommissionsSnapshotRuleRate', 'enabled' => '1', 'visible' => 0, 'position' => 190),
		'snapshot_base_type' => array('type' => 'varchar(16)', 'label' => 'LmdbSalesCommissionsDispatchBase', 'enabled' => '1', 'visible' => 0, 'position' => 191),
		'snapshot_value_type' => array('type' => 'varchar(16)', 'label' => 'LmdbSalesCommissionsDispatchValueType', 'enabled' => '1', 'visible' => 0, 'position' => 192),
		'snapshot_value' => array('type' => 'double(24,8)', 'label' => 'LmdbSalesCommissionsDispatchValue', 'enabled' => '1', 'visible' => 0, 'position' => 193),
		'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => '1', 'visible' => 0, 'position' => 200),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 500),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'visible' => -2, 'noteditable' => 1, 'position' => 501),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => '1', 'visible' => -2, 'position' => 510),
		'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => '1', 'visible' => -2, 'position' => 520),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => '1', 'visible' => -2, 'position' => 530),
	);
}
