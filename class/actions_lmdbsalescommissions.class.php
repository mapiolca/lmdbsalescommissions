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
		$commissionData = $this->buildProposalEstimatedCommissionData($object, $marginInfo);
		if (empty($commissionData)) {
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
		$this->resprints .= '<td colspan="'.$columnCount.'">';
		$this->resprints .= '<table class="noborder liste centpercent lmdbsalescommissions-estimated-commission-table">';
		if (isset($commissionData['message'])) {
			$this->resprints .= '<tr class="liste_titre"><td>'.$langs->trans('LmdbSalesCommissionsEstimatedCommission').'</td></tr>';
			$this->resprints .= '<tr class="oddeven"><td>'.$commissionData['message'].'</td></tr>';
		} else {
			$headers = array(
				(string) $commissionData['title'],
				$langs->trans('LmdbSalesCommissionsMarginBase'),
				$langs->trans('Rate'),
				$langs->trans('LmdbSalesCommissionsRule'),
				$langs->trans('LmdbSalesCommissionsRuleSource'),
				$langs->trans('Status'),
			);
			$values = array(
				(string) $commissionData['amount'],
				(string) $commissionData['margin'],
				(string) $commissionData['rate'],
				(string) $commissionData['rule'],
				(string) $commissionData['source'],
				(string) $commissionData['status'],
			);

			$this->resprints .= '<tr class="liste_titre">';
			foreach ($headers as $headerIndex => $header) {
				$this->resprints .= '<td class="liste_titre'.($headerIndex < 3 ? ' right' : '').'">'.dol_escape_htmltag($header).'</td>';
			}
			$this->resprints .= '</tr>';
			$this->resprints .= '<tr class="oddeven">';
			foreach ($values as $valueIndex => $value) {
				$this->resprints .= '<td'.($valueIndex < 3 ? ' class="right"' : '').'>'.$value.'</td>';
			}
			$this->resprints .= '</tr>';
		}
		$this->resprints .= '</table>';
		$this->resprints .= '</td>';
		$this->resprints .= '</tr>';

		return 0;
	}

	/**
	 * Build estimated commission data for a proposal.
	 *
	 * @param object               $object     Current proposal
	 * @param array<string, mixed> $marginInfo Native margin information
	 * @return array<string, string>
	 */
	private function buildProposalEstimatedCommissionData($object, array $marginInfo)
	{
		global $langs, $user;

		require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionproposalservice.class.php', 0);
		require_once dol_buildpath('/lmdbsalescommissions/class/lmdbsalescommissionruleresolver.class.php', 0);
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

		$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions'));

		$salesUserId = LmdbSalesCommissionProposalService::getSalesUserId($object);
		if ($salesUserId <= 0) {
			return array();
		}
		if (!lmdbsalescommissionsCanReadUserScope($user, $salesUserId)) {
			return array();
		}

		$margin = isset($marginInfo['total_margin']) && is_numeric($marginInfo['total_margin'])
			? (float) $marginInfo['total_margin']
			: LmdbSalesCommissionProposalService::getEstimatedMargin($object);
		$resolver = new LmdbSalesCommissionRuleResolver($this->db);
		$profile = $resolver->resolveForUser($salesUserId, dol_now(), !empty($object->entity) ? (int) $object->entity : 0, 'proposal');
		$marginRule = $profile['selected']['margin'] ?? null;

		if (!empty($profile['errors'])) {
			return array('message' => '<span class="warning">'.$langs->trans('LmdbSalesCommissionsEstimateBlockedByRuleConflict').'</span>');
		} elseif (!is_array($marginRule)) {
			return array('message' => '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsNoMarginRuleAvailable').'</span>');
		} elseif ($margin === null) {
			return array('message' => '<span class="opacitymedium">'.$langs->trans('LmdbSalesCommissionsMarginNotComputable').'</span>');
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

		return array(
			'title' => $title,
			'amount' => price($amount),
			'margin' => price($margin),
			'rate' => price($rate).' %',
			'rule' => dol_escape_htmltag((string) $marginRule['rule_label']),
			'source' => dol_escape_htmltag(lmdbsalescommissionsGetRuleSourceLabel($langs, (string) $marginRule['source'])),
			'status' => $langs->trans('LmdbSalesCommissionsEstimateNotAcquired'),
		);
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
