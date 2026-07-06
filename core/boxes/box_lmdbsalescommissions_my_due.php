<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/box_lmdbsalescommissions_dashboard_base.php';

class box_lmdbsalescommissions_my_due extends box_lmdbsalescommissions_dashboard_base
{
	public $boxcode = 'lmdbsalescommissions_my_due';
	public $boxlabel = 'LmdbSalesCommissionsHomeWidgetMyDue';
	protected $dashboardWidgetCode = 'kpi_commission_due';
	protected $forceOwnScope = true;
}
