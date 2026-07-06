<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once __DIR__.'/box_lmdbsalescommissions_dashboard_base.php';

class box_lmdbsalescommissions_agents_near_tier extends box_lmdbsalescommissions_dashboard_base
{
	public $boxcode = 'lmdbsalescommissions_agents_near_tier';
	public $boxlabel = 'LmdbSalesCommissionsHomeWidgetAgentsNearTier';
	protected $dashboardWidgetCode = 'table_agents_near_tier';
}
