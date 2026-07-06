<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/box_lmdbsalescommissions_dashboard_base.php';

class box_lmdbsalescommissions_my_monthly_objective extends box_lmdbsalescommissions_dashboard_base
{
	public $boxcode = 'lmdbsalescommissions_my_monthly_objective';
	public $boxlabel = 'LmdbSalesCommissionsHomeWidgetMyMonthlyObjective';
	protected $dashboardWidgetCode = 'kpi_monthly_objective_rate';
	protected $forceOwnScope = true;
}
