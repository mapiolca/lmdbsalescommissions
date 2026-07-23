<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbsalescommissioncommon.class.php';

/**
 * Tier row.
 */
class LmdbSalesCommissionTier extends LmdbSalesCommissionCommon
{
	public $element = 'lmdbsalescommissions_tier';
	public $table_element = 'lmdbsalescommissions_tier';

	public $fk_tier_grid;
	public $threshold_amount;
	public $bonus_amount;
	public $commission_rate;
	public $rang;
	public $active;
	public $date_creation;
	public $tms;

	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => '1', 'visible' => 0, 'notnull' => 1, 'default' => '1', 'position' => 5),
		'fk_tier_grid' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsTierGrid', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 10),
		'threshold_amount' => array('type' => 'double(24,8)', 'label' => 'LmdbSalesCommissionsThresholdAmount', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 20),
		'bonus_amount' => array('type' => 'double(24,8)', 'label' => 'LmdbSalesCommissionsBonusAmount', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 30),
		'commission_rate' => array('type' => 'double(10,4)', 'label' => 'LmdbSalesCommissionsCommissionRate', 'enabled' => '1', 'visible' => 1, 'position' => 40),
		'rang' => array('type' => 'integer', 'label' => 'Position', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 50),
		'active' => array('type' => 'integer', 'label' => 'Active', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '1', 'position' => 60),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 500),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'visible' => -2, 'noteditable' => 1, 'position' => 501),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => '1', 'visible' => -2, 'position' => 510),
		'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => '1', 'visible' => -2, 'position' => 520),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => '1', 'visible' => -2, 'position' => 530),
	);
}
