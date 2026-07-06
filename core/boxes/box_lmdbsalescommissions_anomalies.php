<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/box_lmdbsalescommissions_dashboard_base.php';

class box_lmdbsalescommissions_anomalies extends box_lmdbsalescommissions_dashboard_base
{
	public $boxcode = 'lmdbsalescommissions_anomalies';
	public $boxlabel = 'LmdbSalesCommissionsHomeWidgetAnomalies';
	protected $dashboardWidgetCode = 'table_anomalies';
}
