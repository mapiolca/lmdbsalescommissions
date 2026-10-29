<?php
/* Copyright (C) 2026		Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

require_once dol_buildpath('/lmdbsalescommissions/lib/lmdbsalescommissions.lib.php', 0);

/**
 * Complete native substitution array for lmdbsalescommissions objects.
 *
 * @param array<string, string> $substitutionarray Existing substitution array
 * @param Translate            $langs              Language object
 * @param object               $object             Current object
 * @param array<string, mixed> $parameters         Parameters
 * @return void
 */
function lmdbsalescommissions_completesubstitutionarray(&$substitutionarray, $langs, $object, $parameters = array())
{
	unset($parameters);

	if (!is_object($object)) {
		return;
	}

	if (($object->element ?? '') === 'lscmargincase') {
		$langs->loadLangs(array('propal', 'users', 'lmdbsalescommissions@lmdbsalescommissions'));
		require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
		$proposal = new Propal($object->db);
		if ($proposal->fetch((int) $object->fk_propal) <= 0 || (int) $proposal->entity !== (int) $object->entity) { return; }
		$values = array('PROPOSAL_REF' => dol_escape_htmltag($proposal->ref), 'REASON' => dol_nl2br(dol_escape_htmltag($object->reason)), 'DECISION_REASON' => dol_nl2br(dol_escape_htmltag($object->decision_reason ?? '')), 'DECISION' => $langs->trans(array(0 => 'LscWorkflowPending', 1 => 'LscWorkflowApproved', 2 => 'LscWorkflowReturned')[(int) $object->status]), 'COMMISSION' => $object->retained_percent !== null ? price($object->retained_percent).' % ('.price(price2num((float) $object->snapshot_commission_base * (float) $object->retained_percent / 100, 'MT')).')' : '', 'MARGIN' => '', 'RULES' => '');
		foreach (array('AUTHOR' => $object->fk_author, 'REQUESTER' => $object->fk_user_creat, 'APPROVER' => $object->fk_user_decider) as $key => $userId) {
			$person = new User($object->db);
			$values[$key] = (int) $userId > 0 && $person->fetch((int) $userId) > 0 ? dol_escape_htmltag($person->getFullName($langs)) : '';
		}
		$decisions = json_decode((string) $object->snapshot_payload, true);
		$rules = array();
		foreach (is_array($decisions) ? $decisions : array() as $decision) {
			$values['MARGIN'] = isset($decision['inputs']['rate']) ? price($decision['inputs']['rate']).' %' : '';
			foreach ($decision['checks'] ?? array() as $check) {
				if ($check['effect'] === 'sale' && $check['state'] === 'deny') { $rules[] = dol_escape_htmltag($check['origin']).' : '.price($check['threshold']).' %'; }
			}
		}
		$values['RULES'] = implode('<br>', array_unique($rules));
		$values['URL'] = '<a href="'.dol_escape_htmltag(dol_buildpath('/lmdbsalescommissions/proposal_margin_workflow.php', 3).'?id='.(int) $object->fk_propal).'">'.dol_escape_htmltag($proposal->ref).'</a>';
		foreach ($values as $key => $value) { $substitutionarray['__LSC_WORKFLOW_'.$key.'__'] = $value; }
	}

	$ref = property_exists($object, 'ref') && !empty($object->ref) ? (string) $object->ref : (property_exists($object, 'source_ref') ? (string) $object->source_ref : '');
	$label = property_exists($object, 'label') && !empty($object->label) ? (string) $object->label : $ref;
	$status = property_exists($object, 'status') ? (string) $object->status : '';
	$amount = property_exists($object, 'commission_total') ? lmdbsalescommissionsFormatTotalAmount($object->commission_total) : (property_exists($object, 'amount') ? lmdbsalescommissionsFormatTotalAmount($object->amount) : '');

	$substitutionarray['__LMDBSALESCOMMISSIONS_REF__'] = $ref;
	$substitutionarray['__LMDBSALESCOMMISSIONS_LABEL__'] = $label;
	$substitutionarray['__LMDBSALESCOMMISSIONS_STATUS__'] = $status;
	$substitutionarray['__LMDBSALESCOMMISSIONS_AMOUNT__'] = $amount;
	$substitutionarray['__LMDBSALESCOMMISSIONS_URL__'] = '';
	if (method_exists($object, 'getNomUrl')) {
		$substitutionarray['__LMDBSALESCOMMISSIONS_URL__'] = $object->getNomUrl(0);
	}
	$substitutionarray['__LMDBSALESCOMMISSIONS_EVENT__'] = $langs->trans('LmdbSalesCommissions');
}
