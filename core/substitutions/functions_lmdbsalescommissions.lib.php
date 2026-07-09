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
