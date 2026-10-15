<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once __DIR__.'/lmdbsalescommissionmarginservice.class.php';

/** Read-only proposal projection for the native core/ajax/ajaxtooltip.php endpoint.
 * The inherited proposal identity keeps native proposal access checks in place.
 * option = beneficiary:rule:effect; never expose an arbitrary rule by its ID.
 */
class LmdbSalesCommissionPolicyTooltip extends Propal
{
	public $module = 'propal';

	/** @param array<string,mixed> $params Native tooltip parameters
	 * @return string Escaped rule details, or a neutral unavailable message.
	 */
	public function getTooltipContent($params)
	{
		global $user, $langs;
		$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions', 'commercial'));
		$unavailable = dol_escape_htmltag($langs->trans('LscPolicyUnavailable'));
		if (!isModEnabled('lmdbsalescommissions') || !empty($user->socid) || !$user->hasRight('propal', 'lire')
			|| (int) $this->id <= 0 || !in_array((int) $this->entity, array_map('intval', explode(',', getEntity('propal'))), true)) { return $unavailable; }
		restrictedArea($user, 'propal', $this->id, 'propal', '', 'fk_soc', 'rowid', 0, 0, 'read');
		$option = $params['option'] ?? '';
		if (!is_string($option) || !preg_match('/^([1-9][0-9]*):([1-9][0-9]*):(sale|commission)$/D', $option, $matches)) { return $unavailable; }
		$beneficiary = (int) $matches[1]; $ruleId = (int) $matches[2]; $effect = $matches[3];
		$all = $user->hasRight('lmdbsalescommissions', 'commission', 'readall') || $user->hasRight('lmdbsalescommissions', 'commission', 'dispatch');
		$approve = $user->hasRight('lmdbsalescommissions', 'marginpolicy', 'approvesale') || $user->hasRight('lmdbsalescommissions', 'marginpolicy', 'approvecommission');
		$own = $user->hasRight('lmdbsalescommissions', 'commission', 'readown');
		$group = $user->hasRight('lmdbsalescommissions', 'commission', 'readgroup');
		$allowed = $all || $approve || (($own || $group) && $beneficiary === (int) $user->id);
		if (!$allowed && $group) {
			$q = $this->db->query('SELECT b.fk_user FROM '.MAIN_DB_PREFIX.'usergroup_user a INNER JOIN '.MAIN_DB_PREFIX.'usergroup_user b ON b.fk_usergroup = a.fk_usergroup AND b.entity = a.entity WHERE a.entity = '.((int) $this->entity).' AND a.fk_user = '.((int) $user->id).' AND b.fk_user = '.$beneficiary);
			if (!$q) { return $unavailable; }
			$allowed = is_object($this->db->fetch_object($q)); $this->db->free($q);
		}
		if (!$allowed) { return $unavailable; }
		try { $decisions = (new LmdbSalesCommissionMarginService($this->db))->assess($this, true, $user); }
		catch (Exception $e) { return $unavailable; }
		$decision = $decisions[$beneficiary] ?? array(); $check = null; $policy = null;
		foreach ($decision['checks'] ?? array() as $candidate) {
			if ((int) ($candidate['rule_id'] ?? 0) === $ruleId && $candidate['effect'] === $effect) { $check = $candidate; break; }
		}
		if ($check === null) { return $unavailable; }
		foreach ($decision['rules'] ?? array() as $candidate) {
			if ((int) $candidate['rule_id'] === $ruleId && $candidate['effect'] === $effect) { $policy = $candidate; break; }
		}
		$q = $this->db->query('SELECT ref, label, active, description FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule WHERE entity = '.((int) $this->entity)." AND rule_type = 'margin_policy' AND rowid = ".$ruleId);
		if (!$q) { return $unavailable; }
		$record = $this->db->fetch_object($q); $this->db->free($q);
		$html = '<div class="centpercent"><strong>'.dol_escape_htmltag(is_object($record) ? $record->label : $check['origin']).'</strong>';
		if (is_object($record)) {
			$html .= '<p>'.dol_escape_htmltag($langs->trans('LscRuleCurrentMetadata')).'</p>';
			foreach (array('Ref' => $record->ref, 'Active' => $langs->trans($record->active ? 'Yes' : 'No'), 'Description' => $record->description ?? '') as $key => $value) {
				$html .= '<div><strong>'.dol_escape_htmltag($langs->trans($key)).' :</strong> '.nl2br(dol_escape_htmltag((string) $value)).'</div>';
			}
		}
		$html .= '<p><strong>'.dol_escape_htmltag($langs->trans(!empty($decision['frozen']) ? 'LscFrozen' : 'LscAppliedRules')).'</strong></p>';
		$source = array('user' => 'User', 'group' => 'Group', 'default' => 'Default')[$check['origin_type'] ?? ''] ?? 'Unknown';
		$fields = array(
			'LmdbSalesCommissionsProposalEstimateTableRuleSource' => $langs->trans($source),
			'LscPolicyContext' => $langs->trans('LscContext_'.$check['context']),
			'LscPolicyEffect' => $langs->trans('LscEffect_'.$effect),
			'LscRuleBaseThreshold' => isset($check['base_threshold']) ? price($check['base_threshold']).' %' : '—',
			'LscThreshold' => isset($check['threshold']) ? price($check['threshold']).' %' : '—',
			'LscActualRate' => isset($decision['inputs']['rate']) ? price($decision['inputs']['rate']).' %' : $langs->trans('Unknown'),
			'Result' => $langs->trans('LscReason_'.$check['reason']),
		);
		foreach (array('kwc' => 'kWc', 'kwh' => 'kWh') as $key => $unit) {
			$fields[$unit] = isset($decision['inputs'][$key]) ? price($decision['inputs'][$key]).' '.$unit : '—';
		}
		if (($check['travel_metric'] ?? '') !== '') {
			$fields['LscTravelMetric'] = $langs->trans($check['travel_metric'] === 'minutes' ? 'LscTravelMinutes' : 'LscTravelKilometres');
			$fields['LscRuleTravelValue'] = isset($check['travel_value']) ? price($check['travel_value']) : $langs->trans('Unknown');
			$fields['LscTravelUplift'] = isset($check['travel_uplift']) ? price($check['travel_uplift']).' '.$langs->trans('LscPercentagePoints') : '—';
		}
		if (!empty($check['complex_site_configured'])) {
			$fields['LscComplexSiteState'] = ($check['complex_site_state'] ?? null) === null ? $langs->trans('Unknown') : $langs->trans($check['complex_site_state'] ? 'Enabled' : 'Disabled');
			$fields['LscComplexSiteMargin'] = price($check['complex_site_uplift']).' '.$langs->trans('LscPercentagePoints');
		}
		if (isset($check['approval_id'])) { $fields['LscApproval'] = '#'.((int) $check['approval_id']); }
		foreach ($fields as $key => $value) {
			$html .= '<div><strong>'.dol_escape_htmltag($langs->trans($key)).' :</strong> '.dol_escape_htmltag($value).'</div>';
		}
		// Use the evaluated policy, including frozen bands. Never substitute today's configuration.
		if ($policy === null) { $html .= '<p>'.dol_escape_htmltag($langs->trans('LscRuleHistoricalDetailsUnavailable')).'</p>'; }
		else {
			$html .= '<p><strong>'.dol_escape_htmltag($langs->trans('LscRuleTechnicalBands')).'</strong></p>';
			foreach ($policy['bands'] ?? array() as $band) {
				$html .= '<div>';
				foreach (array('kwc' => 'kWc', 'kwh' => 'kWh') as $axis => $unit) {
					$html .= $unit.' '.(!empty($band[$axis.'_inclusive']) ? '[' : ']').dol_escape_htmltag(isset($band[$axis.'_min']) ? price($band[$axis.'_min']) : '−∞').' ; '.dol_escape_htmltag(isset($band[$axis.'_max']) ? price($band[$axis.'_max']) : '+∞').'] · ';
				}
				$html .= dol_escape_htmltag(price($band['threshold'])).' %</div>';
			}
			if (empty($policy['bands'])) { $html .= dol_escape_htmltag($langs->trans('NoRecordFound')); }
			$html .= '<p><strong>'.dol_escape_htmltag($langs->trans('LscTravelMargin')).'</strong></p>';
			foreach ($policy['travel_bands'] ?? array() as $band) {
				$html .= '<div>&gt; '.dol_escape_htmltag(price($band['min_value']).' '.$langs->trans($band['metric'] === 'minutes' ? 'LscTravelMinutes' : 'LscTravelKilometres')).' : +'.dol_escape_htmltag(price($band['uplift']).' '.$langs->trans('LscPercentagePoints')).'</div>';
			}
			if (empty($policy['travel_bands'])) { $html .= dol_escape_htmltag($langs->trans('NoRecordFound')); }
			if (isset($policy['complex_site']) && is_array($policy['complex_site'])) {
				$html .= '<p><strong>'.dol_escape_htmltag($langs->trans('LscComplexSiteMargin')).'</strong></p>';
				$html .= '<div>'.dol_escape_htmltag($langs->trans('LscComplexSiteWithoutTravel')).' : +'.dol_escape_htmltag(price($policy['complex_site']['uplift_without_travel'])).' '.$langs->trans('LscPercentagePoints').'</div>';
				$html .= '<div>'.dol_escape_htmltag($langs->trans('LscComplexSiteWithTravel')).' : +'.dol_escape_htmltag(price($policy['complex_site']['uplift_with_travel'] ?? $policy['complex_site']['uplift_without_travel'])).' '.$langs->trans('LscPercentagePoints').'</div>';
			}
		}
		return $html.'</div>';
	}
}
