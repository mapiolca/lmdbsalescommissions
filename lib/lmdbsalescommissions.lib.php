<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

/**
 * Prepare admin tabs.
 *
 * @return array<int, array{0:string, 1:string, 2:string}>
 */
function lmdbsalescommissionsAdminPrepareHead()
{
	global $langs;

	$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions'));

	$head = array();
	$h = 0;

	$head[$h][0] = dol_buildpath('/lmdbsalescommissions/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('LmdbSalesCommissionsGeneralSettings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdbsalescommissions/admin/rules.php', 1);
	$head[$h][1] = $langs->trans('LmdbSalesCommissionsRules');
	$head[$h][2] = 'rules';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdbsalescommissions/admin/paymentterms.php', 1);
	$head[$h][1] = $langs->trans('LmdbSalesCommissionsPaymentTerms');
	$head[$h][2] = 'paymentterms';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdbsalescommissions/admin/tiergrids.php', 1);
	$head[$h][1] = $langs->trans('LmdbSalesCommissionsTierGrids');
	$head[$h][2] = 'tiergrids';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdbsalescommissions/admin/assignments.php', 1);
	$head[$h][1] = $langs->trans('LmdbSalesCommissionsAssignments');
	$head[$h][2] = 'assignments';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdbsalescommissions/admin/objectives.php', 1);
	$head[$h][1] = $langs->trans('LmdbSalesCommissionsObjectives');
	$head[$h][2] = 'objectives';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdbsalescommissions/admin/maintenance.php', 1);
	$head[$h][1] = $langs->trans('LmdbSalesCommissionsMaintenance');
	$head[$h][2] = 'maintenance';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdbsalescommissions/admin/checks.php', 1);
	$head[$h][1] = $langs->trans('LmdbSalesCommissionsChecks');
	$head[$h][2] = 'checks';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdbsalescommissions/admin/compatibility.php', 1);
	$head[$h][1] = $langs->trans('LmdbSalesCommissionsCompatibility');
	$head[$h][2] = 'compatibility';
	$h++;

	$head[$h][0] = dol_buildpath('/lmdbsalescommissions/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	return $head;
}

/**
 * Return module CSS files for pages rendered by llxHeader().
 *
 * @return array<int, string>
 */
function lmdbsalescommissionsGetCssFiles()
{
	return array(dol_buildpath('/lmdbsalescommissions/css/lmdbsalescommissions.css', 1));
}

/**
 * Return body CSS classes for module pages.
 *
 * @param string $extraClass Extra body classes
 * @return string
 */
function lmdbsalescommissionsGetBodyClass($extraClass = '')
{
	$bodyClass = 'mod-lmdbsalescommissions';
	if ($extraClass !== '') {
		$bodyClass .= ' '.$extraClass;
	}

	return $bodyClass;
}

/**
 * Build link back to module list.
 *
 * @return string
 */
function lmdbsalescommissionsBuildModuleListLink()
{
	global $langs;

	return '<a href="'.DOL_URL_ROOT.'/admin/modules.php?search_keyword='.urlencode('lmdbsalescommissions').'">'.$langs->trans('BackToModuleList').'</a>';
}

/**
 * Check module administration access.
 *
 * Administrators are allowed even when granular permissions are not explicitly assigned.
 *
 * @param User $user Current user
 * @return bool
 */
function lmdbsalescommissionsCanConfigure($user)
{
	if (!is_object($user)) {
		return false;
	}

	if (!empty($user->admin)) {
		return true;
	}

	return $user->hasRight('lmdbsalescommissions', 'admin', 'configure');
}

/**
 * Check permission with administrator elevation.
 *
 * @param User   $user   Current user
 * @param string $object Permission object
 * @param string $action Permission action
 * @return bool
 */
function lmdbsalescommissionsCanDo($user, $object, $action)
{
	if (!is_object($user)) {
		return false;
	}

	if (!empty($user->admin)) {
		return true;
	}

	return $user->hasRight('lmdbsalescommissions', $object, $action);
}

/**
 * Check whether the user may manage proposal commission dispatches.
 *
 * @param User $user Current user
 * @return bool
 */
function lmdbsalescommissionsCanManageDispatch($user)
{
	return lmdbsalescommissionsCanDo($user, 'commission', 'dispatch');
}

/**
 * Check if user can read at least one commission scope.
 *
 * @param User $user Current user
 * @return bool
 */
function lmdbsalescommissionsCanReadCommissions($user)
{
	if (!is_object($user)) {
		return false;
	}

	if (!empty($user->admin)) {
		return true;
	}

	return $user->hasRight('lmdbsalescommissions', 'commission', 'readown')
		|| $user->hasRight('lmdbsalescommissions', 'commission', 'readall')
		|| $user->hasRight('lmdbsalescommissions', 'commission', 'readgroup');
}

/**
 * Check if two users share at least one user group.
 *
 * @param int $userId       Current user id
 * @param int $targetUserId Target user id
 * @return bool
 */
function lmdbsalescommissionsUsersShareGroup($userId, $targetUserId)
{
	global $db;

	if ($userId <= 0 || $targetUserId <= 0) {
		return false;
	}

	$sql = 'SELECT ug1.fk_usergroup';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'usergroup_user AS ug1';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'usergroup_user AS ug2 ON ug2.fk_usergroup = ug1.fk_usergroup';
	$sql .= ' WHERE ug1.fk_user = '.((int) $userId);
	$sql .= ' AND ug2.fk_user = '.((int) $targetUserId);
	if (function_exists('getEntity')) {
		$entities = $db->sanitize(getEntity('usergroup'));
		$sql .= ' AND ug1.entity IN ('.$entities.')';
		$sql .= ' AND ug2.entity IN ('.$entities.')';
	}
	$sql .= ' LIMIT 1';

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return false;
	}

	$hasgroup = $db->num_rows($resql) > 0;
	$db->free($resql);

	return $hasgroup;
}

/**
 * Check if current user may read commissions for a target user.
 *
 * @param User $user         Current user
 * @param int  $targetUserId Target user id, 0 means no user filter yet
 * @return bool
 */
function lmdbsalescommissionsCanReadUserScope($user, $targetUserId)
{
	if (!is_object($user)) {
		return false;
	}

	if (!empty($user->admin)) {
		return true;
	}

	if ($targetUserId <= 0) {
		return lmdbsalescommissionsCanReadCommissions($user);
	}

	if ($user->hasRight('lmdbsalescommissions', 'commission', 'readall')) {
		return true;
	}

	if ((int) $user->id === (int) $targetUserId && $user->hasRight('lmdbsalescommissions', 'commission', 'readown')) {
		return true;
	}

	if ($user->hasRight('lmdbsalescommissions', 'commission', 'readgroup')) {
		return lmdbsalescommissionsUsersShareGroup((int) $user->id, (int) $targetUserId);
	}

	return false;
}

/**
 * Check if user can export at least one commission scope.
 *
 * @param User $user Current user
 * @return bool
 */
function lmdbsalescommissionsCanExport($user)
{
	if (!is_object($user)) {
		return false;
	}

	if (!empty($user->admin)) {
		return true;
	}

	return $user->hasRight('lmdbsalescommissions', 'export', 'own')
		|| $user->hasRight('lmdbsalescommissions', 'export', 'all');
}

/**
 * Check if current user may export commissions for a target user.
 *
 * @param User $user         Current user
 * @param int  $targetUserId Target user id, 0 means no user filter yet
 * @return bool
 */
function lmdbsalescommissionsCanExportUserScope($user, $targetUserId)
{
	if (!is_object($user)) {
		return false;
	}

	if (!empty($user->admin)) {
		return true;
	}

	if ($targetUserId <= 0) {
		return lmdbsalescommissionsCanExport($user);
	}

	if ($user->hasRight('lmdbsalescommissions', 'export', 'all')) {
		return true;
	}

	return (int) $user->id === (int) $targetUserId && $user->hasRight('lmdbsalescommissions', 'export', 'own');
}

/**
 * Return SQL restriction for commission exports visible by current user.
 *
 * @param User   $user      Current user
 * @param string $lineAlias Commission line SQL alias
 * @return string
 */
function lmdbsalescommissionsBuildExportScopeSql($user, $lineAlias = 'l')
{
	if (!is_object($user)) {
		return ' AND 1 = 0';
	}

	if (!empty($user->admin) || $user->hasRight('lmdbsalescommissions', 'export', 'all')) {
		return '';
	}

	if ($user->hasRight('lmdbsalescommissions', 'export', 'own')) {
		return ' AND '.$lineAlias.'.fk_user = '.((int) $user->id);
	}

	return ' AND 1 = 0';
}

/**
 * Check if user can read at least one objective scope.
 *
 * @param User $user Current user
 * @return bool
 */
function lmdbsalescommissionsCanReadObjectives($user)
{
	if (!is_object($user)) {
		return false;
	}

	if (!empty($user->admin)) {
		return true;
	}

	return $user->hasRight('lmdbsalescommissions', 'objective', 'readown')
		|| $user->hasRight('lmdbsalescommissions', 'objective', 'readall');
}

/**
 * Check if user may read objective data for target user.
 *
 * @param User $user         Current user
 * @param int  $targetUserId Target user id
 * @return bool
 */
function lmdbsalescommissionsCanReadObjectiveUserScope($user, $targetUserId)
{
	if (!is_object($user)) {
		return false;
	}

	if (!empty($user->admin) || $user->hasRight('lmdbsalescommissions', 'objective', 'readall')) {
		return true;
	}

	return (int) $user->id === (int) $targetUserId && $user->hasRight('lmdbsalescommissions', 'objective', 'readown');
}

/**
 * Return SQL restriction for commission rows visible by current user.
 *
 * @param DoliDB $db        Database handler
 * @param User   $user      Current user
 * @param string $lineAlias Commission line SQL alias
 * @return string
 */
function lmdbsalescommissionsBuildCommissionScopeSql($db, $user, $lineAlias = 'l')
{
	if (!is_object($user)) {
		return ' AND 1 = 0';
	}

	if (!empty($user->admin) || $user->hasRight('lmdbsalescommissions', 'commission', 'readall')) {
		return '';
	}

	$conditions = array();
	if ($user->hasRight('lmdbsalescommissions', 'commission', 'readown')) {
		$conditions[] = $lineAlias.'.fk_user = '.((int) $user->id);
	}

	if ($user->hasRight('lmdbsalescommissions', 'commission', 'readgroup')) {
		$entities = $db->sanitize(getEntity('usergroup'));
		$conditions[] = 'EXISTS (SELECT ug2.rowid FROM '.MAIN_DB_PREFIX.'usergroup_user AS ug1 INNER JOIN '.MAIN_DB_PREFIX.'usergroup_user AS ug2 ON ug2.fk_usergroup = ug1.fk_usergroup AND ug2.entity = ug1.entity WHERE ug1.fk_user = '.((int) $user->id).' AND ug2.fk_user = '.$lineAlias.'.fk_user AND ug1.entity IN ('.$entities.'))';
	}

	if (empty($conditions)) {
		return ' AND 1 = 0';
	}

	return ' AND ('.implode(' OR ', $conditions).')';
}

/**
 * Return translated due event label.
 *
 * @param Translate $langs     Language object
 * @param string    $eventType Event type
 * @return string
 */
function lmdbsalescommissionsGetDueEventLabel($langs, $eventType)
{
	$labels = array(
		'proposal_signed' => 'LmdbSalesCommissionsEventProposalSigned',
		'deposit_paid' => 'LmdbSalesCommissionsEventDepositPaid',
		'final_invoice_paid' => 'LmdbSalesCommissionsEventFinalInvoicePaid',
	);

	return $langs->trans($labels[$eventType] ?? $eventType);
}

/**
 * Return translated due status label.
 *
 * @param Translate $langs  Language object
 * @param int       $status Status code
 * @return string
 */
function lmdbsalescommissionsGetDueStatusLabel($langs, $status)
{
	$labels = array(
		0 => 'LmdbSalesCommissionsDueStatusWaiting',
		1 => 'LmdbSalesCommissionsDueStatusDue',
		2 => 'LmdbSalesCommissionsDueStatusPaid',
		3 => 'LmdbSalesCommissionsDueStatusCancelled',
		4 => 'LmdbSalesCommissionsDueStatusBlocked',
	);

	return $langs->trans($labels[$status] ?? 'StatusUnknown');
}

/**
 * Return translated commission line status label.
 *
 * @param Translate $langs  Language object
 * @param int       $status Status code
 * @return string
 */
function lmdbsalescommissionsGetLineStatusLabel($langs, $status)
{
	$labels = array(
		0 => 'LmdbSalesCommissionsLineStatusEstimated',
		1 => 'LmdbSalesCommissionsLineStatusAcquired',
		6 => 'LmdbSalesCommissionsLineStatusCancelled',
		7 => 'LmdbSalesCommissionsLineStatusBlocked',
	);

	return $langs->trans($labels[$status] ?? 'StatusUnknown');
}

/**
 * Return translated commission mode label.
 *
 * @param Translate $langs Language object
 * @param string    $mode  Mode code
 * @return string
 */
function lmdbsalescommissionsGetModeLabel($langs, $mode)
{
	$labels = array(
		'margin' => 'LmdbSalesCommissionsRuleTypeMargin',
		'tier' => 'LmdbSalesCommissionsRuleTypeTier',
		'tracking' => 'LmdbSalesCommissionsModeTracking',
		'dispatch' => 'LmdbSalesCommissionsModeDispatch',
	);

	return $langs->trans($labels[$mode] ?? $mode);
}

/**
 * Return translated rule source label.
 *
 * @param Translate $langs      Language object
 * @param string    $ruleSource Rule source code
 * @return string
 */
function lmdbsalescommissionsGetRuleSourceLabel($langs, $ruleSource)
{
	$labels = array(
		'user' => 'User',
		'group' => 'Group',
		'default' => 'Default',
		'none' => 'None',
		'dispatch' => 'LmdbSalesCommissionsManualDispatch',
	);

	return $langs->trans($labels[$ruleSource] ?? $ruleSource);
}

/**
 * Return translated source type label.
 *
 * @param Translate $langs      Language object
 * @param string    $sourceType Source type
 * @return string
 */
function lmdbsalescommissionsGetSourceTypeLabel($langs, $sourceType)
{
	$labels = array(
		'proposal' => 'Propal',
		'order' => 'Order',
		'contract' => 'Contract',
	);

	return $langs->trans($labels[$sourceType] ?? $sourceType);
}

/**
 * Return translated objective archive status label.
 *
 * @param Translate $langs  Language object
 * @param int       $status Status code
 * @return string
 */
function lmdbsalescommissionsGetObjectiveArchiveStatusLabel($langs, $status)
{
	$labels = array(
		1 => 'LmdbSalesCommissionsObjectiveStatusAchieved',
		2 => 'LmdbSalesCommissionsObjectiveStatusNotAchieved',
		3 => 'LmdbSalesCommissionsObjectiveStatusNoObjective',
		4 => 'LmdbSalesCommissionsObjectiveStatusBlocked',
	);

	return $langs->trans($labels[$status] ?? 'StatusUnknown');
}

/**
 * Return native badge for a status value.
 *
 * @param string     $label Label
 * @param int|string $type  Dolibarr status type
 * @return string
 */
function lmdbsalescommissionsStatusBadge($label, $type)
{
	if (is_numeric($type)) {
		$type = (int) $type >= 0 ? 'status'.((int) $type) : 'status8';
	}
	if (!is_string($type) || $type === '') {
		$type = 'status0';
	}

	return dolGetStatus($label, $label, '', $type, 2);
}

/**
 * Format a numeric total amount with Dolibarr total amount precision.
 *
 * @param int|float|string|null $amount Numeric amount
 * @return string
 */
function lmdbsalescommissionsFormatTotalAmount($amount)
{
	$value = is_numeric($amount) ? $amount : 0;

	return price(price2num($value, 'MT'));
}

/**
 * Return native Dolibarr user link.
 *
 * @param DoliDB $db        Database handler
 * @param int    $userId    User id
 * @param string $lastname  Last name
 * @param string $firstname First name
 * @param string $login     Login
 * @param int    $status    User status
 * @param string $photo     User photo filename
 * @param string $email     User email
 * @return string
 */
function lmdbsalescommissionsBuildUserNomUrl($db, $userId, $lastname, $firstname, $login, $status = 1, $photo = '', $email = '')
{
	$label = trim((string) $firstname.' '.(string) $lastname);
	if ($label === '') {
		$label = (string) $login;
	}
	if ($userId <= 0) {
		return dol_escape_htmltag($label);
	}

	require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

	$userstatic = new User($db);
	$userstatic->id = (int) $userId;
	$userstatic->lastname = (string) $lastname;
	$userstatic->firstname = (string) $firstname;
	$userstatic->login = (string) $login;
	$userstatic->status = (int) $status;
	$userstatic->statut = (int) $status;
	$userstatic->photo = (string) $photo;
	$userstatic->email = (string) $email;

	return $userstatic->getNomUrl(-1, '', 0, 0, 0);
}

/**
 * Return native Dolibarr thirdparty link.
 *
 * @param DoliDB $db           Database handler
 * @param int    $thirdpartyId Thirdparty id
 * @param string $name         Thirdparty name
 * @return string
 */
function lmdbsalescommissionsBuildThirdpartyNomUrl($db, $thirdpartyId, $name)
{
	if ($thirdpartyId <= 0) {
		return dol_escape_htmltag((string) $name);
	}

	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

	$thirdpartystatic = new Societe($db);
	$thirdpartystatic->id = (int) $thirdpartyId;
	$thirdpartystatic->rowid = (int) $thirdpartyId;
	$thirdpartystatic->name = (string) $name;
	$thirdpartystatic->nom = (string) $name;

	return $thirdpartystatic->getNomUrl(1, '', 0, 0);
}

/**
 * Return native Dolibarr source link when available.
 *
 * @param DoliDB $db         Database handler
 * @param string $sourceType Source type
 * @param int    $sourceId   Source id
 * @param string $sourceRef  Source reference
 * @return string
 */
function lmdbsalescommissionsBuildSourceNomUrl($db, $sourceType, $sourceId, $sourceRef)
{
	if ($sourceId <= 0) {
		return dol_escape_htmltag((string) $sourceRef);
	}

	if ($sourceType === 'proposal') {
		require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';

		$proposalstatic = new Propal($db);
		$proposalstatic->id = (int) $sourceId;
		$proposalstatic->ref = (string) $sourceRef;

		return $proposalstatic->getNomUrl(1, '', '', 0);
	}

	$sourceUrl = lmdbsalescommissionsBuildSourceUrl($sourceType, $sourceId);
	if ($sourceUrl !== '') {
		return '<a href="'.dol_escape_htmltag($sourceUrl).'">'.dol_escape_htmltag((string) $sourceRef).'</a>';
	}

	return dol_escape_htmltag((string) $sourceRef);
}

/**
 * Attach an external form id to generated filter controls.
 *
 * @param string $html   HTML controls
 * @param string $formId Form id
 * @return string
 */
function lmdbsalescommissionsAttachFormToControls($html, $formId)
{
	$result = preg_replace('/<(input|select|button)\b(?![^>]*\bform=)/i', '<$1 form="'.dol_escape_htmltag($formId).'"', $html);

	return is_string($result) ? $result : $html;
}

/**
 * Return timestamp from native Dolibarr date filter fields.
 *
 * @param string $prefix   Date field prefix
 * @param bool   $endOfDay Use 23:59:59 instead of 00:00:00
 * @return int|string
 */
function lmdbsalescommissionsGetDateFilterValue($prefix, $endOfDay = false)
{
	$day = GETPOSTINT($prefix.'day');
	$month = GETPOSTINT($prefix.'month');
	$year = GETPOSTINT($prefix.'year');

	if ($day <= 0 || $month <= 0 || $year <= 0) {
		return '';
	}

	return dol_mktime($endOfDay ? 23 : 0, $endOfDay ? 59 : 0, $endOfDay ? 59 : 0, $month, $day, $year);
}

/**
 * Return GET parameters for a native Dolibarr date filter.
 *
 * @param string $prefix Date field prefix
 * @return string
 */
function lmdbsalescommissionsBuildDateFilterParams($prefix)
{
	$param = '';
	foreach (array('day', 'month', 'year') as $suffix) {
		$value = GETPOSTINT($prefix.$suffix);
		if ($value > 0) {
			$param .= '&'.$prefix.$suffix.'='.((int) $value);
		}
	}

	return $param;
}

/**
 * Return native Dolibarr date range filter controls.
 *
 * @param Form       $form        Form helper
 * @param int|string $dateStart   Start date timestamp or empty value
 * @param int|string $dateEnd     End date timestamp or empty value
 * @param string     $startPrefix Start date field prefix
 * @param string     $endPrefix   End date field prefix
 * @param string     $formId      Optional external form id
 * @return string
 */
function lmdbsalescommissionsRenderDateRangeFilter($form, $dateStart, $dateEnd, $startPrefix, $endPrefix, $formId = '')
{
	global $langs;

	$html = '<div class="nowrapfordate">';
	$html .= $form->selectDate($dateStart ? $dateStart : -1, $startPrefix, 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'));
	$html .= '</div>';
	$html .= '<br>';
	$html .= '<div class="nowrapfordate">';
	$html .= $form->selectDate($dateEnd ? $dateEnd : -1, $endPrefix, 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to'));
	$html .= '</div>';

	if ($formId !== '') {
		$html = lmdbsalescommissionsAttachFormToControls($html, $formId);
	}

	return $html;
}

/**
 * Return source URL when Dolibarr core object route is known.
 *
 * @param string $sourceType Source type
 * @param int    $sourceId   Source id
 * @return string
 */
function lmdbsalescommissionsBuildSourceUrl($sourceType, $sourceId)
{
	if ($sourceId <= 0) {
		return '';
	}

	if ($sourceType === 'proposal') {
		return DOL_URL_ROOT.'/comm/propal/card.php?id='.((int) $sourceId);
	}
	if ($sourceType === 'order') {
		return DOL_URL_ROOT.'/commande/card.php?id='.((int) $sourceId);
	}
	if ($sourceType === 'contract') {
		return DOL_URL_ROOT.'/contrat/card.php?id='.((int) $sourceId);
	}

	return '';
}

/**
 * Return payment term options for select controls.
 *
 * @param DoliDB $db        Database handler
 * @param bool   $showEmpty Add empty option
 * @return array<int, string>
 */
function lmdbsalescommissionsGetPaymentTermOptions($db, $showEmpty = true)
{
	$options = array();
	if ($showEmpty) {
		$options[0] = '';
	}

	$sql = 'SELECT rowid, ref, label';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term';
	$sql .= ' WHERE entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_payment_term')).')';
	$sql .= ' AND active = 1';
	$sql .= ' ORDER BY label ASC, ref ASC';

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return $options;
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		$options[(int) $obj->rowid] = trim((string) $obj->ref.' - '.(string) $obj->label);
	}
	$db->free($resql);

	return $options;
}

/**
 * Return active payment terms owned by one entity.
 *
 * @param DoliDB $db        Database handler
 * @param int    $entity    Owning entity
 * @param bool   $showEmpty Add empty option
 * @return array<int, string>
 */
function lmdbsalescommissionsGetPaymentTermOptionsForEntity($db, $entity, $showEmpty = true)
{
	$options = array();
	if ($showEmpty) {
		$options[0] = '';
	}
	$sql = 'SELECT pt.rowid, pt.ref, pt.label';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term AS pt';
	$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_payment_term_line AS ptl ON ptl.fk_payment_term = pt.rowid AND ptl.entity = pt.entity';
	$sql .= ' WHERE pt.entity = '.((int) $entity);
	$sql .= ' AND pt.active = 1';
	$sql .= ' GROUP BY pt.rowid, pt.ref, pt.label';
	$sql .= ' HAVING ABS(SUM(CASE WHEN ptl.active = 1 THEN ptl.percentage ELSE 0 END) - 100) <= 0.0001';
	$sql .= ' ORDER BY pt.label ASC, pt.ref ASC';
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return $options;
	}
	while (is_object($obj = $db->fetch_object($resql))) {
		$options[(int) $obj->rowid] = trim((string) $obj->ref.' - '.(string) $obj->label);
	}
	$db->free($resql);

	return $options;
}

/**
 * Format a manual proposal dispatch formula.
 *
 * @param Translate      $langs     Translation service
 * @param string         $baseType  margin or turnover
 * @param string         $valueType amount or percentage
 * @param float|int|string|null $value Formula value
 * @return string
 */
function lmdbsalescommissionsFormatDispatchFormula($langs, $baseType, $valueType, $value)
{
	$baseLabel = $baseType === 'margin' ? $langs->trans('Margin') : $langs->trans('AmountHT');
	if ($valueType === 'percentage') {
		return lmdbsalescommissionsFormatTotalAmount($value).' % '.$baseLabel;
	}

	return lmdbsalescommissionsFormatTotalAmount($value).' — '.$baseLabel;
}

/**
 * Return tier grid options for select controls.
 *
 * @param DoliDB $db        Database handler
 * @param bool   $showEmpty Add empty option
 * @return array<int, string>
 */
function lmdbsalescommissionsGetTierGridOptions($db, $showEmpty = true)
{
	$options = array();
	if ($showEmpty) {
		$options[0] = '';
	}

	$sql = 'SELECT rowid, ref, label';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_tier_grid';
	$sql .= ' WHERE entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_tier_grid')).')';
	$sql .= ' AND active = 1';
	$sql .= ' ORDER BY label ASC, ref ASC';

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return $options;
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		$options[(int) $obj->rowid] = trim((string) $obj->ref.' - '.(string) $obj->label);
	}
	$db->free($resql);

	return $options;
}

/**
 * Return active rule options.
 *
 * @param DoliDB $db        Database handler
 * @param bool   $showEmpty Add empty option
 * @return array<int, string>
 */
function lmdbsalescommissionsGetRuleOptions($db, $showEmpty = true)
{
	$options = array();
	if ($showEmpty) {
		$options[0] = '';
	}

	$sql = 'SELECT rowid, ref, label';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule';
	$sql .= ' WHERE entity IN ('.$db->sanitize(getEntity('lmdbsalescommissions_rule')).')';
	$sql .= ' AND active = 1';
	$sql .= ' ORDER BY label ASC, ref ASC';

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return $options;
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		$options[(int) $obj->rowid] = trim((string) $obj->ref.' - '.(string) $obj->label);
	}
	$db->free($resql);

	return $options;
}

/**
 * Return internal user options.
 *
 * @param DoliDB $db        Database handler
 * @param bool   $showEmpty Add empty option
 * @return array<int, string>
 */
function lmdbsalescommissionsGetUserOptions($db, $showEmpty = true)
{
	$options = array();
	if ($showEmpty) {
		$options[0] = '';
	}

	$sql = 'SELECT rowid, login, lastname, firstname';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'user';
	$sql .= ' WHERE entity IN ('.$db->sanitize(getEntity('user')).')';
	$sql .= ' AND statut = 1';
	$sql .= ' ORDER BY lastname ASC, firstname ASC, login ASC';

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return $options;
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		$name = trim((string) $obj->firstname.' '.(string) $obj->lastname);
		$options[(int) $obj->rowid] = ($name !== '' ? $name : (string) $obj->login);
	}
	$db->free($resql);

	return $options;
}

/**
 * Return active users usable by an object owned by one entity.
 *
 * @param DoliDB $db        Database handler
 * @param int    $entity    Owning entity
 * @param bool   $showEmpty Add empty option
 * @return array<int, string>
 */
function lmdbsalescommissionsGetUserOptionsForEntity($db, $entity, $showEmpty = true)
{
	$options = array();
	if ($showEmpty) {
		$options[0] = '';
	}

	$sql = 'SELECT rowid, login, lastname, firstname';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'user';
	$sql .= ' WHERE entity IN (0,'.((int) $entity).')';
	$sql .= ' AND statut = 1';
	$sql .= ' ORDER BY lastname ASC, firstname ASC, login ASC';
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return $options;
	}
	while (is_object($obj = $db->fetch_object($resql))) {
		$name = trim((string) $obj->firstname.' '.(string) $obj->lastname);
		$options[(int) $obj->rowid] = $name !== '' ? $name : (string) $obj->login;
	}
	$db->free($resql);

	return $options;
}

/**
 * Return user options visible from the commission scope of current user.
 *
 * @param DoliDB $db        Database handler
 * @param User   $user      Current user
 * @param bool   $showEmpty Add empty option
 * @return array<int, string>
 */
function lmdbsalescommissionsGetAccessibleUserOptions($db, $user, $showEmpty = true)
{
	$options = array();
	if ($showEmpty) {
		$options[0] = '';
	}
	if (!is_object($user)) {
		return $options;
	}

	$sql = 'SELECT DISTINCT u.rowid, u.login, u.lastname, u.firstname';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'user AS u';
	if (empty($user->admin) && !$user->hasRight('lmdbsalescommissions', 'commission', 'readall') && $user->hasRight('lmdbsalescommissions', 'commission', 'readgroup')) {
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'usergroup_user AS targetgroup ON targetgroup.fk_user = u.rowid';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'usergroup_user AS currentgroup ON currentgroup.fk_usergroup = targetgroup.fk_usergroup AND currentgroup.entity = targetgroup.entity';
	}
	$sql .= ' WHERE u.entity IN ('.$db->sanitize(getEntity('user')).')';
	$sql .= ' AND u.statut = 1';
	if (empty($user->admin) && !$user->hasRight('lmdbsalescommissions', 'commission', 'readall')) {
		if ($user->hasRight('lmdbsalescommissions', 'commission', 'readgroup')) {
			$sql .= ' AND currentgroup.fk_user = '.((int) $user->id);
			$sql .= ' AND currentgroup.entity IN ('.$db->sanitize(getEntity('usergroup')).')';
		} else {
			$sql .= ' AND u.rowid = '.((int) $user->id);
		}
	}
	$sql .= ' ORDER BY u.lastname ASC, u.firstname ASC, u.login ASC';

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return $options;
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		$name = trim((string) $obj->firstname.' '.(string) $obj->lastname);
		$options[(int) $obj->rowid] = ($name !== '' ? $name : (string) $obj->login);
	}
	$db->free($resql);

	return $options;
}

/**
 * Return user group options.
 *
 * @param DoliDB $db        Database handler
 * @param bool   $showEmpty Add empty option
 * @return array<int, string>
 */
function lmdbsalescommissionsGetUserGroupOptions($db, $showEmpty = true)
{
	$options = array();
	if ($showEmpty) {
		$options[0] = '';
	}

	$sql = 'SELECT rowid, nom';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'usergroup';
	$sql .= ' WHERE entity IN ('.$db->sanitize(getEntity('usergroup')).')';
	$sql .= ' ORDER BY nom ASC';

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return $options;
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		$options[(int) $obj->rowid] = (string) $obj->nom;
	}
	$db->free($resql);

	return $options;
}

/**
 * Return user group options visible from the commission scope of current user.
 *
 * @param DoliDB $db        Database handler
 * @param User   $user      Current user
 * @param bool   $showEmpty Add empty option
 * @return array<int, string>
 */
function lmdbsalescommissionsGetAccessibleUserGroupOptions($db, $user, $showEmpty = true)
{
	$options = array();
	if ($showEmpty) {
		$options[0] = '';
	}
	if (!is_object($user)) {
		return $options;
	}

	$sql = 'SELECT DISTINCT g.rowid, g.nom';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'usergroup AS g';
	if (empty($user->admin) && !$user->hasRight('lmdbsalescommissions', 'commission', 'readall') && $user->hasRight('lmdbsalescommissions', 'commission', 'readgroup')) {
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'usergroup_user AS ugu ON ugu.fk_usergroup = g.rowid AND ugu.entity = g.entity';
	}
	$sql .= ' WHERE g.entity IN ('.$db->sanitize(getEntity('usergroup')).')';
	if (empty($user->admin) && !$user->hasRight('lmdbsalescommissions', 'commission', 'readall')) {
		if ($user->hasRight('lmdbsalescommissions', 'commission', 'readgroup')) {
			$sql .= ' AND ugu.fk_user = '.((int) $user->id);
		} else {
			$sql .= ' AND 1 = 0';
		}
	}
	$sql .= ' ORDER BY g.nom ASC';

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__METHOD__.': '.$db->lasterror(), LOG_ERR);
		return $options;
	}

	while (is_object($obj = $db->fetch_object($resql))) {
		$options[(int) $obj->rowid] = (string) $obj->nom;
	}
	$db->free($resql);

	return $options;
}

/**
 * Print a native empty table placeholder.
 *
 * @param Translate $langs   Language object
 * @param int       $colspan Number of columns
 * @return void
 */
function lmdbsalescommissionsPrintNoRecordRow($langs, $colspan = 1)
{
	print '<tr class="oddeven"><td colspan="'.((int) $colspan).'">';
	print '<span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span>';
	print '</td></tr>';
}

/**
 * Count visible list columns from a native Dolibarr arrayfields definition.
 *
 * @param array<string,array<string,mixed>> $arrayfields Array fields definition
 * @param int                              $extraColumns Extra non-hideable columns
 * @return int
 */
function lmdbsalescommissionsCountVisibleColumns($arrayfields, $extraColumns = 0)
{
	$count = (int) $extraColumns;
	foreach ($arrayfields as $field) {
		if (!empty($field['checked'])) {
			$count++;
		}
	}

	return $count;
}

/**
 * Print a simple placeholder table for an empty V1 scaffold page.
 *
 * @param Translate $langs          Language object
 * @param string    $descriptionKey Translation key
 * @return void
 */
function lmdbsalescommissionsPrintScaffoldTable($langs, $descriptionKey)
{
	print '<table class="noborder liste centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Description').'</td></tr>';
	print '<tr class="oddeven"><td><span class="opacitymedium">'.$langs->trans($descriptionKey).'</span></td></tr>';
	print '</table>';
}
