<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/box_lmdbsalescommissions_dashboard_base.php';

class box_lmdbsalescommissions_my_tier_progress extends box_lmdbsalescommissions_dashboard_base
{
	public $boxcode = 'lmdbsalescommissions_my_tier_progress';
	public $boxlabel = 'LmdbSalesCommissionsHomeWidgetMyTierProgress';
	protected $dashboardWidgetCode = 'chart_tier_progress';
	protected $forceOwnScope = true;
}
