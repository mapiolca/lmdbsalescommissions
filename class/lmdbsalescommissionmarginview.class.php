<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
require_once __DIR__.'/lmdbsalescommissionmarginservice.class.php';

/** Internal policy explanation, shared by proposal card and commission dispatch. */
class LmdbSalesCommissionMarginView
{
	/** @var int Distinguish multiple blocks rendered on the same page. */
	private static $renderSequence = 0;

	/** @return string Escaped HTML; approval forms only on the secured dispatch page. */
	public static function render($db, $proposal, $user, $forms = false)
	{
		global $langs, $conf;
		if (!empty($user->socid)) { return ''; }
		$all = $user->hasRight('lmdbsalescommissions', 'commission', 'readall') || $user->hasRight('lmdbsalescommissions', 'commission', 'dispatch');
		$saleApproval = $user->hasRight('lmdbsalescommissions', 'marginpolicy', 'approvesale');
		$commissionApproval = $user->hasRight('lmdbsalescommissions', 'marginpolicy', 'approvecommission');
		$own = $user->hasRight('lmdbsalescommissions', 'commission', 'readown');
		$group = $user->hasRight('lmdbsalescommissions', 'commission', 'readgroup');
		if (!$all && !$own && !$group && !$saleApproval && !$commissionApproval) { return ''; }
		$langs->load('lmdbsalescommissions@lmdbsalescommissions');
		$allowedUsers = array((int) $user->id);
		if ($group) {
			$sql = 'SELECT DISTINCT b.fk_user FROM '.MAIN_DB_PREFIX.'usergroup_user a INNER JOIN '.MAIN_DB_PREFIX.'usergroup_user b ON b.fk_usergroup = a.fk_usergroup AND b.entity = a.entity WHERE a.entity = '.((int) $proposal->entity).' AND a.fk_user = '.((int) $user->id);
			$q = $db->query($sql);
			if (!$q) { return '<span class="warning">'.$langs->trans('LscPolicyUnavailable').'</span>'; }
			while (is_object($row = $db->fetch_object($q))) { $allowedUsers[] = (int) $row->fk_user; }
			$db->free($q);
		}
		try { $decisions = (new LmdbSalesCommissionMarginService($db))->assess($proposal); }
		catch (Exception $e) { return '<span class="warning">'.$langs->trans($e->getMessage() === 'LscOwnerContext' ? 'LscOwnerContext' : 'LscPolicyUnavailable').'</span>'; }
		if (!$decisions) { return ''; }
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
		$form = new Form($db);
		$renderSequence = ++self::$renderSequence;
		$html = '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre">';
		foreach (array('SalesRepresentative', 'LscSale', 'LscCommission', 'LscActualRate', 'LscAppliedRules') as $key) { $html .= '<td>'.$langs->trans($key).'</td>'; }
		$html .= '</tr>';
		$count = 0;
		foreach ($decisions as $beneficiary => $decision) {
			if (!$all && !$saleApproval && !$commissionApproval && !(($own || $group) && in_array($beneficiary, $allowedUsers, true))) { continue; }
			$count++;
			$person = new User($db);
			$label = $person->fetch($beneficiary) > 0 ? $person->getFullName($langs) : $langs->trans('Unknown');
			$html .= '<tr class="oddeven"><td>'.dol_escape_htmltag($label).'</td>';
			foreach (array('sale', 'commission') as $effect) { $html .= '<td>'.$langs->trans('LscState_'.$effect.'_'.$decision[$effect]).'</td>'; }
			$html .= '<td>'.($decision['inputs']['rate'] === null ? $langs->trans('Unknown') : dol_escape_htmltag((string) $decision['inputs']['rate']).' %').'</td><td>';
			$rulesHtml = '<p><strong>'.$langs->trans('LscAppliedRules').' — '.dol_escape_htmltag($label).'</strong></p>';
			$rulesHtml .= $decision['frozen'] ? '<p>'.$langs->trans('LscFrozen').'</p>' : '';
			$rulesHtml .= '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre">';
			foreach (array('LmdbSalesCommissionsProposalEstimateTableRuleSource', 'LmdbSalesCommissionsProposalEstimateTableRule', 'LscPolicyContext', 'LscPolicyEffect', 'LscThreshold', 'Result') as $key) { $rulesHtml .= '<th scope="col">'.$langs->trans($key).'</th>'; }
			$rulesHtml .= '</tr>';
			foreach ($decision['checks'] as $check) {
				$originLabel = array('user' => 'User', 'group' => 'Group', 'default' => 'Default')[$check['origin_type'] ?? ''] ?? '';
				$rulesHtml .= '<tr class="oddeven"><td>'.($originLabel !== '' ? $langs->trans($originLabel) : '—').'</td><td>'.dol_escape_htmltag($check['origin']).'</td><td>'.$langs->trans('LscContext_'.$check['context']).'</td><td>'.$langs->trans('LscEffect_'.$check['effect']).'</td><td class="right">'.($check['threshold'] === null ? '—' : dol_escape_htmltag((string) $check['threshold']).' %').'</td><td>'.$langs->trans('LscReason_'.$check['reason']);
				if (isset($check['approval_id'])) { $rulesHtml .= '<br>'.$langs->trans('LscApproval').' #'.((int) $check['approval_id']); }
				if ($forms && !$decision['frozen'] && $check['state'] === 'deny' && (($check['effect'] === 'sale' && $saleApproval) || ($check['effect'] === 'commission' && $commissionApproval))) {
					$rulesHtml .= '<form method="POST" action="'.dol_buildpath('/lmdbsalescommissions/proposal_dispatch.php', 1).'"><input type="hidden" name="token" value="'.newToken().'">';
					foreach (array('action' => 'approvemargin', 'id' => (int) $proposal->id, 'beneficiary' => $beneficiary, 'rule' => $check['rule_id'], 'effect' => $check['effect'], 'fingerprint' => $decision['fingerprint']) as $key => $value) { $rulesHtml .= '<input type="hidden" name="'.$key.'" value="'.dol_escape_htmltag((string) $value).'">'; }
					$rulesHtml .= '<label>'.$langs->trans('Reason').' <input name="reason" required></label> <button class="button">'.$langs->trans('LscApprove').'</button></form>';
				}
				$rulesHtml .= '</td></tr>';
			}
			if (!$decision['checks']) { $rulesHtml .= '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('LscNoRule').'</span></td></tr>'; }
			$rulesHtml .= '</table></div>';
			$linkLabel = img_picto('', 'search').' '.$langs->trans('LscConsult');
			if (!empty($conf->use_javascript_ajax)) {
				$dialogKey = 'lscmargin'.((int) $proposal->id).'user'.((int) $beneficiary).'view'.$renderSequence;
				$link = '<a class="nowrap" href="#idfortooltiponclick_'.$dialogKey.'" aria-haspopup="dialog" aria-controls="idfortooltiponclick_'.$dialogKey.'" aria-label="'.dol_escape_htmltag($langs->trans('LscConsult').' — '.$langs->trans('LscAppliedRules').' — '.$label).'">'.$linkLabel.'</a>';
				// Native click-to-open dialog: no module JS, endpoint or duplicate event handlers.
				$html .= $form->textwithpicto($link, $rulesHtml, 1, 'none', '', 1, 3, $dialogKey);
			} else {
				$html .= '<details><summary class="cursorpointer">'.$linkLabel.'</summary>'.$rulesHtml.'</details>';
			}
			$html .= '</td></tr>';
		}
		if (!$count) { $html .= '<tr><td colspan="5">'.$langs->trans('NoRecordFound').'</td></tr>'; }
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
		}
		return $html;
	}
}
