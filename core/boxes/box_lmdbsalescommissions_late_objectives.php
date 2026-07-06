<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/box_lmdbsalescommissions_dashboard_base.php';

class box_lmdbsalescommissions_late_objectives extends box_lmdbsalescommissions_dashboard_base
{
	public $boxcode = 'lmdbsalescommissions_late_objectives';
	public $boxlabel = 'LmdbSalesCommissionsHomeWidgetLateObjectives';
	protected $dashboardWidgetCode = 'table_late_objectives';
}
