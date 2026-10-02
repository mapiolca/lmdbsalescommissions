<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
/** Margin policy settings view. All mutations are handled by the secured admin page.
 * @var Form $form
 * @var LmdbSalesCommissionRule $rule
 * @var array<string,string> $formValues
 * @var list<object> $policies
 * @var array<string,string> $bandValues Pending band fields, preserved after a failed POST
 * @var list<array{rowid:int,metric:string,min_value:float,uplift:float}> $travelBands
 * @var array{metric:string,min_value:string,uplift:string} $travelValues
 * @var bool $travelAvailable
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
	print '</table>';
	// Render at creation too: JavaScript follows the selected context without a preliminary save.
	print '<div id="lsc-policy-bands">';
	print '<p>'.$langs->trans('LscBandHelp').'</p><div class="div-table-responsive-no-min"><table class="noborder centpercent" id="lsc-band-table"><tr class="liste_titre"><td>kWc</td><td>kWh</td><td>'.$langs->trans('LscThreshold').'</td><td></td></tr>';
	print '<tr class="oddeven">';
	foreach (array('kwc', 'kwh') as $axis) {
		$unit = $axis === 'kwc' ? 'kWc' : 'kWh';
		print '<td class="nowraponall">'.$form->selectarray($axis.'_inclusive', array(0 => ']', 1 => '['), $bandValues[$axis.'_inclusive'] ?: 0).' <input class="width50" name="'.$axis.'_min" value="'.dol_escape_htmltag($bandValues[$axis.'_min']).'" aria-label="'.dol_escape_htmltag($langs->trans('LscLower').' ('.$unit.')').'"> ; <input class="width50" name="'.$axis.'_max" value="'.dol_escape_htmltag($bandValues[$axis.'_max']).'" aria-label="'.dol_escape_htmltag($langs->trans('LscUpper').' ('.$unit.')').'"> ]</td>';
	}
	print '<td class="nowraponall"><input class="width75" name="threshold" value="'.dol_escape_htmltag($bandValues['threshold']).'" aria-label="'.dol_escape_htmltag($langs->trans('LscThreshold')).'"> %</td><td class="right"><button class="button" type="submit" name="add_band_continue" value="1">'.$langs->trans('Add').'</button></td></tr>';
	foreach ($bands as $band) {
		print '<tr class="oddeven">';
		foreach (array('kwc', 'kwh') as $axis) { print '<td>'.($band[$axis.'_inclusive'] ? '[' : ']').dol_escape_htmltag((string) ($band[$axis.'_min'] ?? '−∞')).' ; '.dol_escape_htmltag((string) ($band[$axis.'_max'] ?? '+∞')).']</td>'; }
		print '<td>'.dol_escape_htmltag((string) $band['threshold']).' %</td><td class="right"><button class="bordertransp cursorpointer" type="submit" form="lsc-delete-band-'.((int) $band['rowid']).'" aria-label="'.dol_escape_htmltag($langs->trans('Delete')).'">'.img_delete().'</button></td></tr>';
	}
	if (!$bands) { print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>'; }
	print '</table></div>';
	print '</div>';
	// These fallback actions are replaced by the native dialog footer when JavaScript is available.
	print '<div id="lsc-policy-form-actions" class="center"><button class="button button-save" type="submit">'.$langs->trans('Save').'</button> <a class="button button-cancel" href="'.$pageUrl.'">'.$langs->trans('Cancel').'</a></div></form>';
	if ($id && $travelAvailable) {
		print '<h3>'.$langs->trans('LscTravelMargin').'</h3><p>'.$langs->trans('LscTravelHelp').'</p>';
		print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" id="lsc-travel-bands"><tr class="liste_titre"><td>'.$langs->trans('LscTravelMetric').'</td><td>'.$langs->trans('LscTravelMinimum').'</td><td>'.$langs->trans('LscTravelUplift').'</td><td></td></tr>';
		print '<tr class="oddeven"><td>'.$form->selectarray('metric', array('minutes' => $langs->trans('LscTravelMinutes'), 'kilometres' => $langs->trans('LscTravelKilometres')), $travelValues['metric'], 0, 0, 0, 'form="lsc-add-travel-band" aria-label="'.dol_escape_htmltag($langs->trans('LscTravelMetric')).'"').'</td>';
		print '<td class="nowraponall">&gt; <input class="width75" name="min_value" value="'.dol_escape_htmltag($travelValues['min_value']).'" form="lsc-add-travel-band" aria-label="'.dol_escape_htmltag($langs->trans('LscTravelMinimum')).'" required></td>';
		print '<td class="nowraponall">+ <input class="width75" name="uplift" value="'.dol_escape_htmltag($travelValues['uplift']).'" form="lsc-add-travel-band" aria-label="'.dol_escape_htmltag($langs->trans('LscTravelUplift')).'" required> '.$langs->trans('LscPercentagePoints').'</td>';
		print '<td class="right"><button class="button" type="submit" form="lsc-add-travel-band">'.$langs->trans('Add').'</button></td></tr>';
		foreach ($travelBands as $travelBand) {
			$metricLabel = $travelBand['metric'] === 'minutes' ? 'LscTravelMinutes' : 'LscTravelKilometres';
			print '<tr class="oddeven"><td>'.$langs->trans($metricLabel).'</td><td>&gt; '.dol_escape_htmltag((string) $travelBand['min_value']).'</td><td>+'.dol_escape_htmltag((string) $travelBand['uplift']).' '.$langs->trans('LscPercentagePoints').'</td><td class="right"><button class="bordertransp cursorpointer" type="submit" form="lsc-delete-travel-band-'.((int) $travelBand['rowid']).'" aria-label="'.dol_escape_htmltag($langs->trans('Delete')).'">'.img_delete().'</button></td></tr>';
		}
		if (!$travelBands) { print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>'; }
		print '</table></div>';
		print '<form method="POST" action="'.$pageUrl.'" id="lsc-add-travel-band"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="addtravelband"><input type="hidden" name="id" value="'.$id.'">';
		print '</form>';
		print ajax_combobox('metric');
		foreach ($travelBands as $travelBand) {
			print '<form id="lsc-delete-travel-band-'.((int) $travelBand['rowid']).'" method="POST" action="'.$pageUrl.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="deletetravelband"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="band" value="'.((int) $travelBand['rowid']).'"></form>';
		}
	}
	foreach ($bands as $band) {
		print '<form id="lsc-delete-band-'.((int) $band['rowid']).'" method="POST" action="'.$pageUrl.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="deleteband"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="band" value="'.((int) $band['rowid']).'"></form>';
	}
	print '</div>';
}
