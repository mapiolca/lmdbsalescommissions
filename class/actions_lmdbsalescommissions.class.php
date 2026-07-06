<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * Hook action class for lmdbsalescommissions.
 */
class ActionsLmdbSalesCommissions
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Error message */
	public $error = '';

	/** @var array<int, string> Error messages */
	public $errors = array();

	/** @var array<string, mixed> Hook results */
	public $results = array();

	/** @var string|null Printed hook result */
	public $resprints;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Add estimated commission block on proposal card.
	 *
	 * @param array<string, mixed> $parameters Hook parameters
	 * @param object               $object     Current object
	 * @param string               $action     Current action
	 * @param HookManager          $hookmanager Hook manager
	 * @return int
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		unset($action, $hookmanager);

		$contexts = explode(':', (string) ($parameters['context'] ?? ''));
		if (!in_array('propalcard', $contexts, true)) {
			return 0;
		}
		if (!isModEnabled('lmdbsalescommissions')) {
			return 0;
		}

		require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposalservice.class.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionruleresolver.class.php', 0);
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

		$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions'));

		$salesUserId = LmdbSalesCommissionProposalService::getSalesUserId($object);
		if ($salesUserId <= 0) {
			return 0;
		}
		if (!lmdbsalescommissionsCanReadUserScope($user, $salesUserId)) {
			return 0;
		}

		$margin = LmdbSalesCommissionProposalService::getEstimatedMargin($object);
		$resolver = new LmdbSalesCommissionRuleResolver($this->db);
		$profile = $resolver->resolveForUser($salesUserId, dol_now(), !empty($object->entity) ? (int) $object->entity : 0, 'proposal');
		$marginRule = $profile['selected']['margin'] ?? null;

		print '<tr class="oddeven">';
		print '<td>'.$langs->trans('LmdbSalesCommissionsEstimatedCommission').'</td>';
		print '<td>';

		if (!empty($profile['errors'])) {
			print '<span class="warning">'.$langs->trans('LmdbSalesCommissionsEstimateBlockedByRuleConflict').'</span>';
		} elseif (!is_array($marginRule)) {
			print '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsNoMarginRuleAvailable').'</span>';
		} elseif ($margin === null) {
			print '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsMarginNotComputable').'</span>';
		} else {
			$base = max(0, $margin);
			$rate = (float) ($marginRule['rate'] ?? 0);
			$amount = $base * $rate / 100;
			$salesUserLabel = (string) $salesUserId;
			if ((int) $user->id !== $salesUserId) {
				$salesUser = new User($this->db);
				if ($salesUser->fetch($salesUserId) > 0) {
					$salesUserLabel = $salesUser->getFullName($langs);
				}
			}
			$title = ((int) $user->id === $salesUserId)
				? $langs->trans('LmdbSalesCommissionsYourEstimatedCommission')
				: $langs->trans('LmdbSalesCommissionsUserEstimatedCommission', $salesUserLabel);

			print '<strong>'.dol_escape_htmltag($title).' : '.price($amount).'</strong><br>';
			print $langs->trans('LmdbSalesCommissionsMarginBase').' : '.price($margin).'<br>';
			print $langs->trans('Rate').' : '.price($rate).' %<br>';
			print $langs->trans('LmdbSalesCommissionsRule').' : '.dol_escape_htmltag((string) $marginRule['rule_label']).'<br>';
			print $langs->trans('LmdbSalesCommissionsRuleSource').' : '.dol_escape_htmltag((string) $marginRule['source']).'<br>';
			print $langs->trans('Status').' : '.$langs->trans('LmdbSalesCommissionsEstimateNotAcquired');
		}

		print '</td>';
		print '</tr>';

		return 0;
	}

	/**
	 * Expose supported notification trigger codes to native Notifications module.
	 *
	 * @param array<string, mixed> $parameters  Hook parameters
	 * @param object               $object      Current object
	 * @param string               $action      Current action
	 * @param HookManager          $hookmanager Hook manager
	 * @return int
	 */
	public function notifsupported($parameters, &$object, &$action, $hookmanager)
	{
		unset($object, $action, $hookmanager);

		$contexts = explode(':', (string) ($parameters['context'] ?? ''));
		if (!in_array('notification', $contexts, true)) {
			return 0;
		}
		if (!isModEnabled('lmdbsalescommissions')) {
			return 0;
		}

		$supported = array(
			'LMDBSALESCOMMISSIONS_LINE_CREATE',
			'LMDBSALESCOMMISSIONS_LINE_UPDATE',
			'LMDBSALESCOMMISSIONS_DUE_CREATE',
			'LMDBSALESCOMMISSIONS_DUE_UPDATE',
			'LMDBSALESCOMMISSIONS_OBJECTIVE_ARCHIVE_CREATE',
		);
		if (isset($this->results['arrayofnotifsupported']) && is_array($this->results['arrayofnotifsupported'])) {
			$supported = array_merge($this->results['arrayofnotifsupported'], $supported);
		}
		$this->results['arrayofnotifsupported'] = array_values(array_unique($supported));

		return 0;
	}
}
