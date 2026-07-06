<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/box_lmdbsalescommissions_dashboard_base.php';

class box_lmdbsalescommissions_my_acquired_month extends box_lmdbsalescommissions_dashboard_base
{
	public $boxcode = 'lmdbsalescommissions_my_acquired_month';
	public $boxlabel = 'LmdbSalesCommissionsHomeWidgetMyAcquiredMonth';
	protected $dashboardWidgetCode = 'kpi_commission_acquired';
	protected $forceOwnScope = true;
}
