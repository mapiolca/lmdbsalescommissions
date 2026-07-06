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
	 * Add estimated commission block under native margin table on proposal card.
	 *
	 * @param array<string, mixed> $parameters  Hook parameters
	 * @param object               $object      Current object
	 * @param string               $action      Current action
	 * @param HookManager          $hookmanager Hook manager
	 * @return int
	 */
	public function displayMarginInfos($parameters, &$object, &$action, $hookmanager)
	{
		unset($action, $hookmanager);

		$contexts = explode(':', (string) ($parameters['context'] ?? ''));
		if (!in_array('propalcard', $contexts, true)) {
			return 0;
		}
		if (!isModEnabled('lmdbsalescommissions')) {
			return 0;
		}

		$marginInfo = isset($parameters['marginInfo']) && is_array($parameters['marginInfo']) ? $parameters['marginInfo'] : array();
		$content = $this->buildProposalEstimatedCommissionContent($object, $marginInfo);
		if ($content === '') {
			return 0;
		}

		$columnCount = 4;
		if (getDolGlobalString('DISPLAY_MARGIN_RATES')) {
			$columnCount++;
		}
		if (getDolGlobalString('DISPLAY_MARK_RATES')) {
			$columnCount++;
		}

		global $langs;

		$this->resprints = '<tr class="oddeven lmdbsalescommissions-estimated-commission">';
		$this->resprints .= '<td>'.$langs->trans('LmdbSalesCommissionsEstimatedCommission').'</td>';
		$this->resprints .= '<td colspan="'.max(1, $columnCount - 1).'">'.$content.'</td>';
		$this->resprints .= '</tr>';

		return 0;
	}

	/**
	 * Build estimated commission content for a proposal.
	 *
	 * @param object               $object     Current proposal
	 * @param array<string, mixed> $marginInfo Native margin information
	 * @return string
	 */
	private function buildProposalEstimatedCommissionContent($object, array $marginInfo)
	{
		global $langs, $user;

		require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposalservice.class.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionruleresolver.class.php', 0);
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

		$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions'));

		$salesUserId = LmdbSalesCommissionProposalService::getSalesUserId($object);
		if ($salesUserId <= 0) {
			return '';
		}
		if (!lmdbsalescommissionsCanReadUserScope($user, $salesUserId)) {
			return '';
		}

		$margin = isset($marginInfo['total_margin']) && is_numeric($marginInfo['total_margin'])
			? (float) $marginInfo['total_margin']
			: LmdbSalesCommissionProposalService::getEstimatedMargin($object);
		$resolver = new LmdbSalesCommissionRuleResolver($this->db);
		$profile = $resolver->resolveForUser($salesUserId, dol_now(), !empty($object->entity) ? (int) $object->entity : 0, 'proposal');
		$marginRule = $profile['selected']['margin'] ?? null;

		if (!empty($profile['errors'])) {
			return '<span class="warning">'.$langs->trans('LmdbSalesCommissionsEstimateBlockedByRuleConflict').'</span>';
		} elseif (!is_array($marginRule)) {
			return '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsNoMarginRuleAvailable').'</span>';
		} elseif ($margin === null) {
			return '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsMarginNotComputable').'</span>';
		}

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

		$html = '<strong>'.dol_escape_htmltag($title).' : '.price($amount).'</strong><br>';
		$html .= $langs->trans('LmdbSalesCommissionsMarginBase').' : '.price($margin).'<br>';
		$html .= $langs->trans('Rate').' : '.price($rate).' %<br>';
		$html .= $langs->trans('LmdbSalesCommissionsRule').' : '.dol_escape_htmltag((string) $marginRule['rule_label']).'<br>';
		$html .= $langs->trans('LmdbSalesCommissionsRuleSource').' : '.dol_escape_htmltag((string) $marginRule['source']).'<br>';
		$html .= $langs->trans('Status').' : '.$langs->trans('LmdbSalesCommissionsEstimateNotAcquired');

		return $html;
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
