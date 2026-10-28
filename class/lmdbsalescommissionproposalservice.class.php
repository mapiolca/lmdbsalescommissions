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
		global $db;
		if (!is_object($proposal) || !isset($proposal->lines) || !is_array($proposal->lines)) { return null; }
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmargin.class.php';
		$copy = clone $proposal;
		$copy->lines = array_map(static function ($line) { return clone $line; }, $proposal->lines);
		$form = new FormMargin($db);
		$info = $form->getMarginInfosArray($copy);
		return isset($info['total_margin']) && is_numeric($info['total_margin']) ? (float) $info['total_margin'] : null;
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

		foreach (array('user_author_id', 'user_creation_id', 'fk_user_author', 'fk_user_comm', 'fk_user_commercial', 'commercial_id') as $property) {
			if (property_exists($proposal, $property) && (int) $proposal->{$property} > 0) {
				return (int) $proposal->{$property};
			}
		}

		return 0;
	}

	/**
	 * Resolve the effective sales user for a proposal.
	 *
	 * The native proposal author is the source of truth. A unique thirdparty sales
	 * representative remains a compatibility fallback for legacy proposals without an author.
	 *
	 * @param DoliDB|null $db       Database handler
	 * @param object      $proposal Proposal object
	 * @return int
	 */
	public static function resolveSalesUserId($db, $proposal)
	{
		if (!is_object($proposal)) {
			return 0;
		}

		$authorId = self::resolveProposalAuthorId($db, $proposal);
		if ($authorId > 0) {
			return $authorId;
		}

		$socid = 0;
		foreach (array('socid', 'fk_soc') as $property) {
			if (property_exists($proposal, $property) && (int) $proposal->{$property} > 0) {
				$socid = (int) $proposal->{$property};
				break;
			}
		}

		if (is_object($db) && $socid > 0) {
			$salesUsers = self::fetchThirdpartySalesUsers($db, $socid);
			$countSalesUsers = count($salesUsers);
			if ($countSalesUsers === 1) {
				return (int) $salesUsers[0];
			}
		}

		foreach (array('fk_user_comm', 'fk_user_commercial', 'commercial_id') as $property) {
			if (property_exists($proposal, $property) && (int) $proposal->{$property} > 0 && (!is_object($db) || self::isUsableUserId($db, (int) $proposal->{$property}))) {
				return (int) $proposal->{$property};
			}
		}

		return 0;
	}

	/**
	 * Return proposal signature date.
	 *
	 * @param object $proposal Proposal object
	 * @return int Timestamp, 0 if unavailable
	 */
	public static function getSignatureDate($proposal)
	{
		if (!is_object($proposal)) {
			return 0;
		}

		if (property_exists($proposal, 'date_signature') && (int) $proposal->date_signature > 0) {
			return (int) $proposal->date_signature;
		}

		return 0;
	}

	/**
	 * Resolve the active author of a proposal from the object or its native database row.
	 *
	 * The database fallback is required because some core trigger paths provide a proposal
	 * object without its author property populated. The proposal entity is always included
	 * when it is known so another entity's object cannot be used as a fallback.
	 *
	 * @param DoliDB|null $db       Database handler
	 * @param object      $proposal Proposal object
	 * @return int
	 */
	public static function resolveProposalAuthorId($db, $proposal)
	{
		if (!is_object($proposal)) {
			return 0;
		}

		$authorId = self::getProposalAuthorId($proposal);
		if ($authorId > 0 && (!is_object($db) || self::isUsableUserId($db, $authorId))) {
			return $authorId;
		}
		if (!is_object($db)) {
			return 0;
		}

		$proposalId = 0;
		foreach (array('id', 'rowid') as $property) {
			if (property_exists($proposal, $property) && (int) $proposal->{$property} > 0) {
				$proposalId = (int) $proposal->{$property};
				break;
			}
		}
		if ($proposalId <= 0) {
			return 0;
		}

		$entity = property_exists($proposal, 'entity') ? (int) $proposal->entity : 0;
		$sql = 'SELECT p.fk_user_author';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'propal AS p';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = p.fk_user_author AND u.statut = 1';
		$sql .= ' WHERE p.rowid = '.$proposalId;
		if ($entity > 0) {
			$sql .= ' AND p.entity = '.$entity;
		}
		$sql .= ' LIMIT 1';

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
			return 0;
		}

		$obj = $db->fetch_object($resql);
		$authorId = is_object($obj) ? (int) $obj->fk_user_author : 0;
		$db->free($resql);

		return $authorId;
	}

	/**
	 * Return proposal validation date.
	 *
	 * @param object $proposal Proposal object
	 * @return int Timestamp, 0 if unavailable
	 */
	public static function getValidationDate($proposal)
	{
		if (!is_object($proposal)) {
			return 0;
		}

		foreach (array('date_validation', 'date_valid', 'datev') as $property) {
			if (property_exists($proposal, $property) && (int) $proposal->{$property} > 0) {
				return (int) $proposal->{$property};
			}
		}

		return 0;
	}

	/**
	 * Return proposal author id.
	 *
	 * @param object $proposal Proposal object
	 * @return int
	 */
	private static function getProposalAuthorId($proposal)
	{
		foreach (array('user_author_id', 'user_creation_id', 'fk_user_author') as $property) {
			if (property_exists($proposal, $property) && (int) $proposal->{$property} > 0) {
				return (int) $proposal->{$property};
			}
		}

		return 0;
	}

	/**
	 * Fetch active thirdparty sales users.
	 *
	 * @param DoliDB $db    Database handler
	 * @param int    $socid Thirdparty id
	 * @return array<int, int>
	 */
	private static function fetchThirdpartySalesUsers($db, $socid)
	{
		$salesUsers = array();
		if ($socid <= 0) {
			return $salesUsers;
		}

		$sql = 'SELECT DISTINCT sc.fk_user';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'societe_commerciaux AS sc';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = sc.fk_user';
		$sql .= ' WHERE sc.fk_soc = '.((int) $socid);
		$sql .= ' AND u.statut = 1';
		$sql .= ' ORDER BY sc.fk_user ASC';

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
			return $salesUsers;
		}

		while (is_object($obj = $db->fetch_object($resql))) {
			$salesUsers[] = (int) $obj->fk_user;
		}
		$db->free($resql);

		return $salesUsers;
	}

	/**
	 * Check that a user exists and is active.
	 *
	 * @param DoliDB $db     Database handler
	 * @param int    $userId User id
	 * @return bool
	 */
	private static function isUsableUserId($db, $userId)
	{
		if ($userId <= 0) {
			return false;
		}

		$sql = 'SELECT rowid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'user';
		$sql .= ' WHERE rowid = '.((int) $userId);
		$sql .= ' AND statut = 1';
		$sql .= ' LIMIT 1';

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
			return false;
		}

		$isUsable = $db->num_rows($resql) > 0;
		$db->free($resql);

		return $isUsable;
	}
}
