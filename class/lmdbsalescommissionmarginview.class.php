<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
require_once __DIR__.'/lmdbsalescommissionmarginservice.class.php';

/** Internal policy explanation, shared by proposal card and commission dispatch.
 * @phpstan-type EstimateRow array{beneficiary_id:int,beneficiary:string,formula?:string,payment_term?:string,amount?:string,amount_value?:float,base_amount?:string,reward_amount?:string,margin?:string,rate?:string,rule?:string,source?:string,status?:string,message?:string}
 * @phpstan-type EstimateData array{rows?:list<EstimateRow>,total?:string}|EstimateRow
 */
class LmdbSalesCommissionMarginView
{
	/** @var int Distinguish multiple blocks rendered on the same page. */
	private static $renderSequence = 0;

	/** Native semantic colors for evaluated margin rules. */
	private const POLICY_BADGE_TYPES = array('met' => 'success', 'below' => 'warning', 'approved' => 'info', 'conflict' => 'danger', 'overlap' => 'danger', 'travel_invalid' => 'danger');

	/**
	 * @param EstimateData|null $estimates Filtered estimate data from the card hook;
	 *        null keeps the detailed policy/approval view on the dispatch page.
	 * @return string Escaped HTML; approval forms only on the secured dispatch page.
	 */
	public static function render($db, $proposal, $user, $forms = false, ?array $estimates = null)
	{
		global $langs, $conf;
		if (!empty($user->socid)) { return ''; }
		$all = $user->hasRight('lmdbsalescommissions', 'commission', 'readall') || $user->hasRight('lmdbsalescommissions', 'commission', 'dispatch');
		$saleApproval = $user->hasRight('lmdbsalescommissions', 'marginpolicy', 'approvesale');
		$commissionApproval = $user->hasRight('lmdbsalescommissions', 'marginpolicy', 'approvecommission');
		$own = $user->hasRight('lmdbsalescommissions', 'commission', 'readown');
		$group = $user->hasRight('lmdbsalescommissions', 'commission', 'readgroup');
		if (!$all && !$own && !$group && !$saleApproval && !$commissionApproval) { return ''; }
		$langs->loadLangs(array('lmdbsalescommissions@lmdbsalescommissions', 'commercial'));
		$summary = $estimates !== null;
		$estimateRows = array();
		foreach ($estimates['rows'] ?? (isset($estimates['beneficiary_id']) ? array($estimates) : array()) as $row) {
			$estimateRows[(int) $row['beneficiary_id']] = $row;
		}
		$allowedUsers = array((int) $user->id);
		if ($group) {
			$sql = 'SELECT DISTINCT b.fk_user FROM '.MAIN_DB_PREFIX.'usergroup_user a INNER JOIN '.MAIN_DB_PREFIX.'usergroup_user b ON b.fk_usergroup = a.fk_usergroup AND b.entity = a.entity WHERE a.entity = '.((int) $proposal->entity).' AND a.fk_user = '.((int) $user->id);
			$q = $db->query($sql);
			if (!$q) { return '<span class="warning">'.$langs->trans('LscPolicyUnavailable').'</span>'; }
			while (is_object($row = $db->fetch_object($q))) { $allowedUsers[] = (int) $row->fk_user; }
			$db->free($q);
		}
		$policyError = '';
		try { $decisions = (new LmdbSalesCommissionMarginService($db))->assess($proposal, true, $user); }
		catch (Exception $e) {
			$policyError = '<span class="warning">'.$langs->trans($e->getMessage() === 'LscOwnerContext' ? 'LscOwnerContext' : 'LscPolicyUnavailable').'</span>';
			if (!$summary) { return $policyError; }
			$decisions = array();
		}
		$rewards = array();
		$rewardError = '';
		if ($all || $own || $group) {
			require_once __DIR__.'/lmdbsalescommissionrewardservice.class.php';
			try {
				$visibleDecisions = $all ? $decisions : array_intersect_key($decisions, array_flip($allowedUsers));
				$rewards = (new LmdbSalesCommissionRewardService($db))->forProposal($proposal, dol_now(), $visibleDecisions);
			}
			catch (Exception $e) { $rewardError = '<span class="warning">'.$langs->trans($e->getMessage()).'</span>'; }
		}
		$sum = 0.0;
		$complete = $rewardError === '';
		foreach ($estimateRows as $beneficiary => &$estimate) {
			if (!isset($estimate['amount_value'])) { $complete = false; continue; }
			if (isset($rewards[$beneficiary])) {
				$estimate['base_amount'] = $estimate['amount'];
				$estimate['reward_amount'] = price($rewards[$beneficiary]['amount']);
				$estimate['amount'] = price(price2num($estimate['amount_value'] + $rewards[$beneficiary]['amount'], 'MT'));
			}
			$sum += $estimate['amount_value'] + ($rewards[$beneficiary]['amount'] ?? 0.0);
			if ($rewardError !== '') { $estimate['amount'] = $rewardError; }
		}
		unset($estimate);
		if ($summary && $all && $complete && $estimateRows) { $estimates['total'] = price(price2num($sum, 'MT')); }
		elseif ($rewardError !== '') { unset($estimates['total']); }
		if (!$decisions && !$estimateRows) { return $policyError; }
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
		$form = new Form($db);
		$renderSequence = ++self::$renderSequence;
		$policyHeaders = array('SalesRepresentative', 'LscSale', 'LscCommission', 'LscActualRate', 'LscAppliedRules');
		$headers = $summary ? array('SalesRepresentative', 'LmdbSalesCommissionsProposalEstimateTableCommission', 'Status', 'LscAppliedRules') : $policyHeaders;
		$html = '<div class="div-table-responsive-no-min"><table class="noborder centpercent'.($summary ? ' lmdbsalescommissions-estimated-commission-table' : '').'"><tr class="liste_titre">';
		foreach ($headers as $key) { $html .= '<th scope="col"'.($key === 'LmdbSalesCommissionsProposalEstimateTableCommission' ? ' class="right"' : '').'>'.$langs->trans($key).'</th>'; }
		$html .= '</tr>';
		$count = 0;
		$beneficiaries = array_unique(array_merge(array_keys($estimateRows), array_keys($decisions)));
		// One owner-scoped lookup for the labels of visible applied rules, including old snapshots.
		$ruleIds = array(); $ruleLabels = array(); $ruleLabelError = false;
		if ($summary) {
			foreach ($decisions as $beneficiary => $decision) {
				if (!$all && !$saleApproval && !$commissionApproval && !(($own || $group) && in_array($beneficiary, $allowedUsers, true))) { continue; }
				foreach ($decision['checks'] as $check) {
					if ((int) ($check['rule_id'] ?? 0) > 0) { $ruleIds[(int) $check['rule_id']] = (int) $check['rule_id']; }
				}
			}
			if ($ruleIds) {
				$q = $db->query('SELECT rowid, label FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule WHERE entity = '.((int) $proposal->entity)." AND rule_type = 'margin_policy' AND rowid IN (".implode(',', $ruleIds).')');
				if (!$q) { $ruleLabelError = true; }
				else {
					while (is_object($row = $db->fetch_object($q))) { $ruleLabels[(int) $row->rowid] = (string) $row->label; }
					$db->free($q);
				}
			}
		}
		foreach ($beneficiaries as $beneficiary) {
			if (!$all && !$saleApproval && !$commissionApproval && !(($own || $group) && in_array($beneficiary, $allowedUsers, true))) { continue; }
			$count++;
			$decision = $decisions[$beneficiary] ?? null;
			$estimate = $estimateRows[$beneficiary] ?? array();
			$person = new User($db);
			$personLoaded = $person->fetch($beneficiary) > 0;
			$label = $personLoaded ? $person->getFullName($langs) : $langs->trans('Unknown');
			$beneficiaryHtml = $estimate['beneficiary'] ?? dol_escape_htmltag($label);
			$status = $estimate['status'] ?? $langs->trans('Unknown');
			if ($policyError !== '' || (isset($decision['commission']) && $decision['commission'] !== 'allow')) {
				$status = $langs->trans('LscState_commission_'.($policyError !== '' ? 'unknown' : $decision['commission']));
			}
			$rate = $decision['inputs']['rate'] ?? null;
			$policyValues = array(dol_escape_htmltag($label));
			foreach (array('sale', 'commission') as $effect) { $policyValues[] = $decision !== null ? $langs->trans('LscState_'.$effect.'_'.$decision[$effect]) : '—'; }
			$policyValues[] = $rate === null ? $langs->trans('Unknown') : dol_escape_htmltag((string) $rate).' %';
			$html .= '<tr class="oddeven"><td>'.$beneficiaryHtml.'</td>';
			$html .= $summary ? '<td class="right">'.($estimate['amount'] ?? '—').'</td><td>'.$status.'</td><td>' : '<td>'.implode('</td><td>', array_slice($policyValues, 1)).'</td><td>';
			$rulesHtml = !empty($decision['frozen']) ? '<p>'.$langs->trans('LscFrozen').'</p>' : '';
			$rulesHtml .= '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tbody><tr class="liste_titre">';
			$ruleHeaders = $summary ? array('LscRules', 'Status') : array('LmdbSalesCommissionsProposalEstimateTableRuleSource', 'LmdbSalesCommissionsProposalEstimateTableRule', 'LscPolicyContext', 'LscPolicyEffect', 'LscThreshold', 'Result');
			foreach ($ruleHeaders as $key) { $rulesHtml .= '<th scope="col">'.$langs->trans($key).'</th>'; }
			$rulesHtml .= '</tr>';
			foreach ($decision['checks'] ?? array() as $check) {
				$originLabel = array('user' => 'User', 'group' => 'Group', 'default' => 'Default')[$check['origin_type'] ?? ''] ?? '';
				$thresholdLabel = $check['threshold'] === null ? '—' : dol_escape_htmltag((string) $check['threshold']).' %';
				if (($check['travel_metric'] ?? '') !== '' && ($check['travel_value'] ?? null) !== null) {
					$unit = $langs->trans($check['travel_metric'] === 'minutes' ? 'LscTravelMinutes' : 'LscTravelKilometres');
					$thresholdLabel .= '<br><span class="opacitymedium">'.dol_escape_htmltag($langs->trans('LscTravelApplied', (string) $check['base_threshold'], (string) $check['travel_uplift'], price($check['travel_value'], 0, $langs, 0, 2, 2), $unit)).'</span>';
				}
				$cells = array($originLabel !== '' ? $langs->trans($originLabel) : '—', dol_escape_htmltag($check['origin']), $langs->trans('LscContext_'.$check['context']), $langs->trans('LscEffect_'.$check['effect']), $thresholdLabel, $langs->trans('LscReason_'.$check['reason']));
				if ($summary) {
					$ruleId = (int) ($check['rule_id'] ?? 0);
					$ruleLabel = dol_escape_htmltag($ruleLabelError ? $langs->trans('LscPolicyUnavailable') : ($ruleLabels[$ruleId] ?? $check['origin']));
					if (!$ruleLabelError && $ruleId > 0) {
						$params = array('id' => (int) $proposal->id, 'objecttype' => 'lmdbsalescommissionpolicytooltip@lmdbsalescommissions', 'option' => $beneficiary.':'.$ruleId.':'.$check['effect']);
						$ruleLabel = '<span class="classforajaxtooltip cursorpointer" title="'.$ruleLabel.'" data-params="'.dol_escape_htmltag(json_encode($params, JSON_THROW_ON_ERROR)).'">'.$ruleLabel.'</span>';
					}
					$badge = dolGetBadge(dol_escape_htmltag($langs->trans('LscReason_'.$check['reason'])), '', self::POLICY_BADGE_TYPES[$check['reason']] ?? 'secondary', '', '', array('css' => 'badge-status'));
					$cells = array($ruleLabel, $badge);
				}
				$rulesHtml .= '<tr class="oddeven"><td>'.implode('</td><td>', $cells);
				if (!$summary && isset($check['approval_id'])) { $rulesHtml .= '<br>'.$langs->trans('LscApproval').' #'.((int) $check['approval_id']); }
				if ($forms && !$summary && !$decision['frozen'] && $check['state'] === 'deny' && (($check['effect'] === 'sale' && $saleApproval) || ($check['effect'] === 'commission' && $commissionApproval))) {
					$rulesHtml .= '<form method="POST" action="'.dol_buildpath('/lmdbsalescommissions/proposal_dispatch.php', 1).'"><input type="hidden" name="token" value="'.newToken().'">';
					foreach (array('action' => 'approvemargin', 'id' => (int) $proposal->id, 'beneficiary' => $beneficiary, 'rule' => $check['rule_id'], 'effect' => $check['effect'], 'fingerprint' => $decision['fingerprint']) as $key => $value) { $rulesHtml .= '<input type="hidden" name="'.$key.'" value="'.dol_escape_htmltag((string) $value).'">'; }
					$rulesHtml .= '<label>'.$langs->trans('Reason').' <input name="reason" required></label> <button class="button">'.$langs->trans('LscApprove').'</button></form>';
				}
				$rulesHtml .= '</td></tr>';
			}
			if (empty($decision['checks'])) {
				$notice = $policyError !== '' ? $policyError : '<span class="opacitymedium">'.$langs->trans($decision === null ? 'LscNoMarginControl' : 'LscNoRule').'</span>';
				$rulesHtml .= '<tr class="oddeven"><td colspan="'.count($ruleHeaders).'">'.$notice.'</td></tr>';
			}
			$rulesHtml .= '</tbody></table></div>';
			if ($summary) {
				// Native dialogs focus the first link; do not open a nested user tooltip on focus.
				$dialogBeneficiaryHtml = $personLoaded ? $person->getNomUrl(1, '', 0, 1) : dol_escape_htmltag($label);
				$details = self::renderEstimateDetails($estimate, $dialogBeneficiaryHtml, $status);
				$details .= '<h3>'.$langs->trans('LscMarginDetails').'</h3><div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre">';
				foreach (array_slice($policyHeaders, 0, 4) as $key) { $details .= '<th scope="col">'.$langs->trans($key).'</th>'; }
				$details .= '</tr><tr class="oddeven"><td>'.implode('</td><td>', $policyValues).'</td></tr></table></div>';
				$details .= '<h3>'.$langs->trans('LscAppliedRules').'</h3>'.$rulesHtml;
			} else {
				$details = '<p><strong>'.$langs->trans('LscAppliedRules').' — '.dol_escape_htmltag($label).'</strong></p>'.$rulesHtml;
			}
			$canReadReward = $all || (($own || $group) && in_array($beneficiary, $allowedUsers, true));
			if ($canReadReward && isset($rewards[$beneficiary])) { $details .= self::renderRewardDetails($rewards[$beneficiary]); }
			elseif ($canReadReward && $rewardError !== '') { $details .= $rewardError; }
			$linkLabel = img_picto('', 'search').' '.$langs->trans('LscConsult');
			if (!empty($conf->use_javascript_ajax)) {
				$dialogKey = 'lscmargin'.((int) $proposal->id).'user'.((int) $beneficiary).'view'.$renderSequence;
				$link = '<a class="nowrap" href="#idfortooltiponclick_'.$dialogKey.'" aria-haspopup="dialog" aria-controls="idfortooltiponclick_'.$dialogKey.'" aria-label="'.dol_escape_htmltag($langs->trans('LscConsult').' — '.$langs->trans('LscAppliedRules').' — '.$label).'">'.$linkLabel.'</a>';
				// Native click-to-open dialog: no module JS, endpoint or duplicate event handlers.
				$html .= $form->textwithpicto($link, $details, 1, 'none', '', 1, 3, $dialogKey);
			} else {
				$html .= '<details><summary class="cursorpointer">'.$linkLabel.'</summary>'.$details.'</details>';
			}
			$html .= '</td></tr>';
		}
		if (!$count) { $html .= '<tr><td colspan="'.count($headers).'">'.$langs->trans('NoRecordFound').'</td></tr>'; }
		if ($summary && $all && isset($estimates['total'])) {
			$html .= '<tr class="liste_total"><td class="right">'.$langs->trans('Total').'</td><td class="right">'.$estimates['total'].'</td><td colspan="2"></td></tr>';
		}
		$html .= '</table></div>';
		if ($forms && ($all || $saleApproval || $commissionApproval)) {
			$q = $db->query('SELECT a.*, u.firstname, u.lastname, b.firstname AS beneficiary_firstname, b.lastname AS beneficiary_lastname, r.ref AS rule_ref FROM '.MAIN_DB_PREFIX.'lmdbsalescommissions_margin_approval a LEFT JOIN '.MAIN_DB_PREFIX.'user u ON u.rowid = a.fk_user_creat LEFT JOIN '.MAIN_DB_PREFIX.'user b ON b.rowid = a.fk_user LEFT JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule r ON r.rowid = a.fk_rule AND r.entity = a.entity WHERE a.entity = '.((int) $proposal->entity).' AND a.fk_propal = '.((int) $proposal->id).' ORDER BY a.rowid DESC');
			if ($q) {
				$html .= '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('LscApproval').'</td><td>'.$langs->trans('SalesRepresentative').'</td><td>'.$langs->trans('LscAppliedRules').'</td><td>'.$langs->trans('User').'</td><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Reason').'</td></tr>';
				$n = 0;
				while (is_object($row = $db->fetch_object($q))) {
					$n++;
					$current = isset($decisions[(int) $row->fk_user]) && hash_equals($decisions[(int) $row->fk_user]['fingerprint'], $row->fingerprint);
					$html .= '<tr class="oddeven"><td>#'.((int) $row->rowid).' · '.$langs->trans('LscEffect_'.$row->effect).' · '.$langs->trans($current ? 'LscApprovalCurrent' : 'LscApprovalExpired').'</td><td>'.dol_escape_htmltag(trim($row->beneficiary_firstname.' '.$row->beneficiary_lastname)).'</td><td>'.dol_escape_htmltag($row->rule_ref ?? ('#'.(int) $row->fk_rule)).'</td><td>'.dol_escape_htmltag(trim($row->firstname.' '.$row->lastname)).'</td><td>'.dol_print_date($db->jdate($row->date_creation), 'dayhour').'</td><td>'.dol_escape_htmltag($row->reason).'</td></tr>';
				}
				if (!$n) { $html .= '<tr><td colspan="6">'.$langs->trans('NoRecordFound').'</td></tr>'; }
				$html .= '</table></div>'; $db->free($q);
			}
			// Requests are immutable audit records. An actual sale approval alone grants the exception.
			if ($saleApproval) {
				require_once DOL_DOCUMENT_ROOT.'/core/lib/html.lib.php';
				$q = $db->query('SELECT r.*, u.firstname, u.lastname, b.firstname AS beneficiary_firstname, b.lastname AS beneficiary_lastname, p.ref AS rule_ref, EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX."lmdbsalescommissions_margin_approval a WHERE a.entity = r.entity AND a.fk_propal = r.fk_propal AND a.fk_user = r.fk_user AND a.fk_rule = r.fk_rule AND a.fingerprint = r.fingerprint AND a.effect = 'sale') AS approved FROM ".MAIN_DB_PREFIX.'lmdbsalescommissions_margin_request r LEFT JOIN '.MAIN_DB_PREFIX.'user u ON u.rowid = r.fk_user_creat LEFT JOIN '.MAIN_DB_PREFIX.'user b ON b.rowid = r.fk_user LEFT JOIN '.MAIN_DB_PREFIX.'lmdbsalescommissions_rule p ON p.rowid = r.fk_rule AND p.entity = r.entity WHERE r.entity = '.((int) $proposal->entity).' AND r.fk_propal = '.((int) $proposal->id).' ORDER BY r.rowid DESC');
				if (!$q) {
					$html .= '<div class="warning">'.$langs->trans('LscRequestsUnavailable').'</div>';
				} else {
					$html .= '<h3>'.$langs->trans('LscSaleRequests').'</h3><p>'.$langs->trans('LscSaleRequestsHelp').'</p><div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre">';
					foreach (array('Status', 'SalesRepresentative', 'LscAppliedRules', 'User', 'Date', 'Reason') as $key) { $html .= '<th scope="col">'.$langs->trans($key).'</th>'; }
					$html .= '</tr>'; $n = 0;
					while (is_object($row = $db->fetch_object($q))) {
						$n++;
						$current = isset($decisions[(int) $row->fk_user]) && hash_equals($decisions[(int) $row->fk_user]['fingerprint'], $row->fingerprint);
						$statusKey = !$current ? 'LscRequestExpired' : ((int) $row->approved > 0 ? 'LscRequestApproved' : 'LscRequestPending');
						$html .= '<tr class="oddeven"><td>'.dolGetStatus($langs->trans($statusKey), $langs->trans($statusKey), '', !$current ? 'status6' : ((int) $row->approved > 0 ? 'status4' : 'status1'), 5).'</td>';
						$html .= '<td>'.dol_escape_htmltag(trim($row->beneficiary_firstname.' '.$row->beneficiary_lastname)).'</td><td>'.dol_escape_htmltag($row->rule_ref ?? ('#'.(int) $row->fk_rule)).'</td><td>'.dol_escape_htmltag(trim($row->firstname.' '.$row->lastname)).'</td><td>'.dol_print_date($db->jdate($row->date_creation), 'dayhour').'</td><td>'.dol_nl2br(dol_escape_htmltag($row->reason)).'</td></tr>';
					}
					if (!$n) { $html .= '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>'; }
					$html .= '</table></div>'; $db->free($q);
				}
			}
		}
		return $html;
	}

	/**
	 * Restore the calculation detail table inside the beneficiary dialog.
	 * @param EstimateRow|array{} $estimate Formatted values from the estimate builder
	 * @param string $beneficiaryHtml Escaped name or native user link
	 * @param string $status Escaped commission status
	 * @return string
	 */
	private static function renderEstimateDetails(array $estimate, string $beneficiaryHtml, string $status): string
	{
		global $langs;
		$columns = array('beneficiary' => 'SalesRepresentative');
		if (isset($estimate['formula'])) {
			$columns += array('formula' => 'LmdbSalesCommissionsDispatchFormula', 'payment_term' => 'LmdbSalesCommissionsPaymentTerms', 'amount' => 'LmdbSalesCommissionsProposalEstimateTableCommission');
		} else {
			$columns += array('amount' => 'LmdbSalesCommissionsProposalEstimateTableCommission', 'margin' => 'LmdbSalesCommissionsMarginBase', 'rate' => 'Rate', 'rule' => 'LmdbSalesCommissionsProposalEstimateTableRule', 'source' => 'LmdbSalesCommissionsProposalEstimateTableRuleSource');
		}
		if (isset($estimate['reward_amount'])) { $columns += array('base_amount' => 'LscBaseCommission', 'reward_amount' => 'LscReward'); }
		$columns['status'] = 'Status';
		$estimate['beneficiary'] = $beneficiaryHtml;
		$estimate['status'] = $status;
		$html = '<h3>'.$langs->trans('LscCommissionDetails').'</h3><div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre">';
		foreach ($columns as $key => $title) { $html .= '<th scope="col">'.$langs->trans($title).'</th>'; }
		$html .= '</tr><tr class="oddeven">';
		foreach ($columns as $key => $title) {
			$value = (string) ($estimate[$key] ?? '—');
			$html .= '<td'.(in_array($key, array('amount', 'margin', 'rate'), true) ? ' class="right"' : '').'>'.(in_array($key, array('formula', 'payment_term'), true) ? dol_escape_htmltag($value) : $value).'</td>';
		}
		$html .= '</tr>';
		if (isset($estimate['message'])) { $html .= '<tr class="oddeven"><td colspan="'.count($columns).'">'.$estimate['message'].'</td></tr>'; }
		return $html.'</table></div>';
	}

	/** @param array<string,mixed> $reward Frozen or estimated reward, after access filtering.
	 * @return string */
	public static function renderRewardDetails(array $reward): string
	{
		global $langs, $conf;
		$currency = dol_escape_htmltag($conf->currency);
		$values = array(
			'LscRewardRule' => array(dol_escape_htmltag($reward['rule_label']), ''),
			'LscRewardMode' => array($langs->trans($reward['mode'] === 'fixed' ? 'LscRewardFixed' : 'LscRewardPercentage'), ''),
			'LscRewardValue' => array(price($reward['value']), $reward['mode'] === 'fixed' ? $currency : '%'),
			'LscThreshold' => array($reward['threshold'] === null ? '—' : price($reward['threshold']), '%'),
			'LscRewardSurplus' => array(price($reward['surplus']), $currency),
			'LscRewardShare' => array(price($reward['share'] * 100), '%'),
			'LscReward' => array(price($reward['amount']), $currency),
		);
		$badgeType = array('earned' => 'success', 'not_exceeded' => 'warning', 'commission_blocked' => 'danger')[$reward['reason']] ?? 'secondary';
		$values['Result'] = array(dolGetBadge(dol_escape_htmltag($langs->trans('LscRewardReason_'.$reward['reason'])), '', $badgeType), '');
		$html = '<h3>'.$langs->trans('LscReward').'</h3><div class="div-table-responsive-no-min"><table class="noborder centpercent"><tbody><tr class="liste_titre">';
		foreach (array('Label', 'Value', 'Unit') as $key) { $html .= '<th scope="col">'.$langs->trans($key).'</th>'; }
		$html .= '</tr>';
		foreach ($values as $key => $value) { $html .= '<tr class="oddeven"><td>'.$langs->trans($key).'</td><td>'.$value[0].'</td><td>'.$value[1].'</td></tr>'; }
		return $html.'</tbody></table></div>';
	}
}
