<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/box_lmdbsalescommissions_dashboard_base.php';

class box_lmdbsalescommissions_due_global extends box_lmdbsalescommissions_dashboard_base
{
	public $boxcode = 'lmdbsalescommissions_due_global';
	public $boxlabel = 'LmdbSalesCommissionsHomeWidgetDueGlobal';
	protected $dashboardWidgetCode = 'table_due_commissions';
}
