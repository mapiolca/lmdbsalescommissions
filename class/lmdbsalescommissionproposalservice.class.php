<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * Proposal helper service for estimated commissions.
 */
class LmdbSalesCommissionProposalService
{
	/**
	 * Try to read the estimated margin from native Dolibarr proposal data.
	 *
	 * @param object $proposal Proposal object
	 * @return float|null
	 */
	public static function getEstimatedMargin($proposal)
	{
		if (!is_object($proposal)) {
			return null;
		}

		if (method_exists($proposal, 'getMarginInfosArray')) {
			$marginInfos = $proposal->getMarginInfosArray();
			if (is_array($marginInfos)) {
				foreach (array('total_margin', 'margin', 'marge', 'total_marge') as $key) {
					if (isset($marginInfos[$key]) && is_numeric($marginInfos[$key])) {
						return (float) $marginInfos[$key];
					}
				}
			}
		}

		foreach (array('total_margin', 'marge_marge', 'margin', 'marge') as $property) {
			if (property_exists($proposal, $property) && is_numeric($proposal->{$property})) {
				return (float) $proposal->{$property};
			}
		}

		return null;
	}

	/**
	 * Best effort sales user id for a proposal.
	 *
	 * @param object $proposal Proposal object
	 * @return int
	 */
	public static function getSalesUserId($proposal)
	{
		if (!is_object($proposal)) {
			return 0;
		}

		foreach (array('fk_user_comm', 'fk_user_commercial', 'commercial_id', 'fk_user_author', 'user_author_id') as $property) {
			if (property_exists($proposal, $property) && (int) $proposal->{$property} > 0) {
				return (int) $proposal->{$property};
			}
		}

		return 0;
	}
}
