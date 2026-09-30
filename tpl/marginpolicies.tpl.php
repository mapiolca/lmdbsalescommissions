<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
/** Margin policy settings view. All mutations are handled by the secured admin page.
 * @var Form $form
 * @var LmdbSalesCommissionRule $rule
 * @var array<string,string> $formValues
 * @var list<object> $policies
 */
if (!defined('DOL_DOCUMENT_ROOT')) { exit; }
print '<p>'.$langs->trans('LscPolicyHelp').'</p>';
$enabled = getDolGlobalInt('LMDBSALESCOMMISSIONS_MARGIN_ENABLED');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="oddeven">';
print '<td>'.$form->textwithpicto($langs->trans('LscEnableMarginControl'), $langs->trans('LscActivationHelp')).'</td>';
// Keep the activation date and compatibility guard in the module action, not constantonoff.php.
print '<td class="right"><a href="'.$pageUrl.'?action=activate&amp;enabled='.($enabled ? 0 : 1).'&amp;token='.newToken().'" role="switch" aria-checked="'.($enabled ? 'true' : 'false').'" aria-label="'.dol_escape_htmltag($langs->trans('LscEnableMarginControl')).'">'.img_picto($langs->trans($enabled ? 'Enabled' : 'Disabled'), $enabled ? 'switch_on' : 'switch_off').'</a></td></tr></table></div>';
print load_fiche_titre('', dolGetButtonTitle($langs->trans('New'), '', 'fa fa-plus-circle', $pageUrl.'?mode=create', 'lsc-new-policy'), '');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" id="lsc-policies"><tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Label').'</td><td>'.$langs->trans('LscPolicyContext').'</td><td>'.$langs->trans('LscPolicyEffect').'</td><td>'.$langs->trans('Active').'</td><td class="right">'.$langs->trans('Actions').'</td></tr>';
foreach ($policies as $row) {
	$rowId = (int) $row->rowid;
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($row->ref).'</td><td>'.dol_escape_htmltag($row->label).'</td><td>'.($contexts[$row->policy_context] ?? '').'</td><td>'.($effects[$row->policy_effect] ?? '').'</td><td>'.yn($row->active).'</td>';
	print '<td class="right nowraponall"><a class="editfielda" href="'.$pageUrl.'?mode=edit&amp;id='.$rowId.'" aria-label="'.dol_escape_htmltag($langs->trans('Modify').' '.$row->ref).'">'.img_edit().'</a> ';
	print '<a href="'.$pageUrl.'?mode=delete&amp;id='.$rowId.'" aria-label="'.dol_escape_htmltag($langs->trans('Delete').' '.$row->ref).'">'.img_delete().'</a></td></tr>';
}
if (!$policies) { print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>'; }
print '</table></div>';
if ($mode === 'delete') {
	print $form->formconfirm($pageUrl.'?id='.$id, $langs->trans('Delete'), $langs->trans('LscConfirmDeletePolicy', dol_escape_htmltag($rule->ref)), 'confirm_delete', '', 'no', 1);
}
if ($mode === 'create' || $mode === 'edit') {
	print '<div id="lsc-policy-editor" data-return-url="'.$pageUrl.'" title="'.dol_escape_htmltag($langs->trans($id ? 'Modify' : 'New')).'">';
	print '<form method="POST" action="'.$pageUrl.'" id="lsc-policy-form"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savepolicy"><input type="hidden" name="id" value="'.$id.'">';
	print '<table class="border centpercent">';
	foreach (array('ref' => 'Ref', 'label' => 'Label', 'rate' => 'LscThreshold') as $key => $label) {
		$required = !empty($rule->fields[$key]['notnull']);
		print '<tr'.($key === 'rate' ? ' id="lsc-policy-rate-row"' : '').'><td class="titlefield'.($required ? ' fieldrequired' : '').'"><label for="lsc-'.$key.'">'.$langs->trans($label).'</label></td><td><input class="flat maxwidth200" id="lsc-'.$key.'" name="'.$key.'" value="'.dol_escape_htmltag($formValues[$key]).'"'.($required ? ' required' : '').'></td></tr>';
	}
	print '<tr><td><label for="policy_context">'.$langs->trans('LscPolicyContext').'</label></td><td>'.$form->selectarray('policy_context', $contexts, $formValues['policy_context'] ?: 'general').'</td></tr>';
	print '<tr><td><label for="policy_effect">'.$langs->trans('LscPolicyEffect').'</label></td><td>'.$form->selectarray('policy_effect', $effects, $formValues['policy_effect'] ?: 'commission').'</td></tr>';
	if ($id) {
		print '<tr><td>'.$langs->trans('Active').'</td><td><a href="'.$pageUrl.'?action=togglepolicy&amp;id='.$id.'&amp;token='.newToken().'" role="switch" aria-checked="'.($rule->active ? 'true' : 'false').'" aria-label="'.dol_escape_htmltag($langs->trans('Active')).'">'.img_picto($langs->trans($rule->active ? 'Enabled' : 'Disabled'), $rule->active ? 'switch_on' : 'switch_off').'</a></td></tr>';
	}
	print '</table><div class="center"><button class="button button-save" type="submit">'.$langs->trans('Save').'</button> <a class="button button-cancel" href="'.$pageUrl.'">'.$langs->trans('Cancel').'</a></div></form>';
	if ($id && $rule->policy_context !== 'general') {
		print '<p>'.$langs->trans('LscBandHelp').'</p><div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>kWc</td><td>kWh</td><td>'.$langs->trans('LscThreshold').'</td><td></td></tr>';
		foreach ($bands as $band) {
			print '<tr class="oddeven">';
			foreach (array('kwc', 'kwh') as $axis) { print '<td>'.($band[$axis.'_inclusive'] ? '[' : ']').dol_escape_htmltag((string) ($band[$axis.'_min'] ?? '−∞')).' ; '.dol_escape_htmltag((string) ($band[$axis.'_max'] ?? '+∞')).']</td>'; }
			print '<td>'.dol_escape_htmltag((string) $band['threshold']).' %</td><td><form method="POST" action="'.$pageUrl.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="deleteband"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="band" value="'.$band['rowid'].'"><button class="button" type="submit">'.$langs->trans('Delete').'</button></form></td></tr>';
		}
		if (!$bands) { print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>'; }
		print '</table></div><form method="POST" action="'.$pageUrl.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="addband"><input type="hidden" name="id" value="'.$id.'">';
		foreach (array('kwc', 'kwh') as $axis) {
			print '<p>'.($axis === 'kwc' ? 'kWc' : 'kWh').' : <input class="width75" name="'.$axis.'_min" aria-label="'.$langs->trans('LscLower').'"> '.$form->selectarray($axis.'_inclusive', array(0 => ']', 1 => '['), 0).' — <input class="width75" name="'.$axis.'_max" aria-label="'.$langs->trans('LscUpper').'"> ]</p>';
		}
		print '<p>'.$langs->trans('LscThreshold').' <input class="width75" name="threshold"> %</p><button class="button" type="submit">'.$langs->trans('Add').'</button></form>';
	}
	print '</div>';
}
