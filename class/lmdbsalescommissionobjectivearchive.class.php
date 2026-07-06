<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/lmdbsalescommissioncommon.class.php';

/**
 * Sales objective archive.
 */
class LmdbSalesCommissionObjectiveArchive extends LmdbSalesCommissionCommon
{
	public $element = 'lmdbsalescommissions_objective_archive';
	public $table_element = 'lmdbsalescommissions_objective_archive';

	public $fk_user;
	public $fk_objective;
	public $objective_type;
	public $year;
	public $month;
	public $target_value;
	public $realized_value;
	public $achievement_rate;
	public $status;
	public $objective_source;
	public $date_calculation;
	public $date_archive;
	public $fk_user_archive;
	public $note_private;
	public $date_creation;
	public $tms;

	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => '1', 'visible' => 0, 'notnull' => 1, 'default' => '1', 'position' => 5),
		'fk_user' => array('type' => 'integer', 'label' => 'User', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 10),
		'fk_objective' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsObjective', 'enabled' => '1', 'visible' => 1, 'position' => 20),
		'objective_type' => array('type' => 'varchar(32)', 'label' => 'LmdbSalesCommissionsObjectiveType', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => 'monthly', 'position' => 30),
		'year' => array('type' => 'integer', 'label' => 'Year', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 40),
		'month' => array('type' => 'integer', 'label' => 'Month', 'enabled' => '1', 'visible' => 1, 'position' => 50),
		'target_value' => array('type' => 'double(24,8)', 'label' => 'LmdbSalesCommissionsTargetValue', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 60),
		'realized_value' => array('type' => 'double(24,8)', 'label' => 'LmdbSalesCommissionsRealizedValue', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 70),
		'achievement_rate' => array('type' => 'double(10,4)', 'label' => 'LmdbSalesCommissionsAchievementRate', 'enabled' => '1', 'visible' => 1, 'position' => 80),
		'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 90),
		'objective_source' => array('type' => 'varchar(32)', 'label' => 'LmdbSalesCommissionsObjectiveSource', 'enabled' => '1', 'visible' => 1, 'position' => 100),
		'date_calculation' => array('type' => 'datetime', 'label' => 'LmdbSalesCommissionsDateCalculation', 'enabled' => '1', 'visible' => 1, 'position' => 110),
		'date_archive' => array('type' => 'datetime', 'label' => 'LmdbSalesCommissionsDateArchive', 'enabled' => '1', 'visible' => 1, 'notnull' => 1, 'position' => 120),
		'fk_user_archive' => array('type' => 'integer', 'label' => 'LmdbSalesCommissionsArchiveUser', 'enabled' => '1', 'visible' => 1, 'position' => 130),
		'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => '1', 'visible' => 0, 'position' => 140),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => '1', 'visible' => -2, 'notnull' => 1, 'position' => 500),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'visible' => -2, 'noteditable' => 1, 'position' => 501),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => '1', 'visible' => -2, 'position' => 510),
		'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => '1', 'visible' => -2, 'position' => 520),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => '1', 'visible' => -2, 'position' => 530),
	);
}
