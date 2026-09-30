<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
require_once __DIR__.'/lmdbsalescommissionmarginservice.class.php';

/** Pre-action guards. Trigger checks alone run after native PDF/signature writes.
 * Registering the native Restler onCall event at api context initialization also
 * covers v20/v21, which do not expose beforeApiCall. No authentication is replaced.
 */
class LmdbSalesCommissionMarginGuard
{
	/** @var bool */
	private static $apiRegistered = false;

	/** Called on native hook-context initialization, before side effects.
	 * @return void */
	public static function initialize($db)
	{
		global $hookmanager, $langs;
		if (!isModEnabled('lmdbsalescommissions')) { return; }
		$contexts = is_object($hookmanager) ? $hookmanager->contextarray : array();
		if (in_array('api', $contexts, true) && !self::$apiRegistered && class_exists('Luracast\\Restler\\Restler')) {
			self::$apiRegistered = true;
			\Luracast\Restler\Restler::onCall(static function () use ($db) {
				self::apiCall($db, \Luracast\Restler\Restler::$self);
			});
		}
		// This native context is initialized AFTER securekey verification, BEFORE the image/PDF is written.
		if (in_array('ajaxonlinesign', $contexts, true) && preg_match('~/core/ajax/onlineSign\.php$~i', str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '')) && GETPOST('action', 'aZ09') === 'importSignature' && in_array(GETPOST('mode', 'aZ09'), array('proposal', 'propale'), true)) {
			if (!class_exists('Propal')) { require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php'; }
			global $conf;
			$proposal = new Propal($db);
			$allowed = false;
			try {
				if ($proposal->fetch(0, GETPOST('ref', 'alpha')) > 0 && (int) $proposal->entity === (int) $conf->entity) {
					$allowed = (new LmdbSalesCommissionMarginService($db))->saleAllowed($proposal);
				}
			} catch (Exception $e) { $allowed = false; }
			if (!$allowed) { $langs->load('lmdbsalescommissions@lmdbsalescommissions'); httponly_accessforbidden($langs->trans('LscPublicBlocked'), 403); exit; }
		}
	}

	/** Runs AFTER native authentication, routing and argument validation.
	 * @return void */
	public static function apiCall($db, $restler)
	{
		if (!is_object($restler) || !is_object($restler->apiMethodInfo)) { return; }
		$info = $restler->apiMethodInfo;
		if (strtolower($info->className) !== 'proposals' || !in_array(strtoupper($restler->requestMethod), array('POST', 'PUT', 'PATCH'), true)) { return; }
		if (!in_array(strtolower($info->methodName), array('post', 'put', 'validate', 'close'), true)) { return; }
		$actor = DolibarrApiAccess::$user;
		if (!is_object($actor) || !$actor->hasRight('propal', 'creer')) { throw new \Luracast\Restler\RestException(403); }
		$args = array();
		foreach ($info->arguments as $name => $index) { $args[$name] = $info->parameters[$index] ?? null; }
		$service = new LmdbSalesCommissionMarginService($db);
		global $conf;
		$entity = (int) $conf->entity;
		$proposal = null;
		if (isset($args['id']) && (int) $args['id'] > 0) {
			if (!class_exists('Propal')) { require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php'; }
			$proposal = new Propal($db);
			if ($proposal->fetch((int) $args['id']) <= 0 || !restrictedArea($actor, 'propal', $proposal->id, 'propal', '', 'fk_soc', 'rowid', 0, 1, 'write')) { throw new \Luracast\Restler\RestException(403); }
			$entity = (int) $proposal->entity;
		}
		try {
			if (!$service->activation($entity)) { return; }
			$data = isset($args['request_data']) && is_array($args['request_data']) ? $args['request_data'] : array();
			// Lifecycle fields must go through their native methods, not generic assignment.
			if (in_array(strtolower($info->methodName), array('put', 'post'), true)) {
				foreach (array('status', 'statut', 'fk_statut', 'date_signature', 'date_valid', 'user_signature', 'user_signature_id', 'entity', 'context') as $field) {
					if (array_key_exists($field, $data) && !($field === 'entity' && (int) $data[$field] === $entity)) { throw new RuntimeException('LscApiLifecycle'); }
				}
			}
			if (!empty($args['notrigger']) || !empty($data['notrigger'])) { throw new RuntimeException('LscApiLifecycle'); }
			$method = strtolower($info->methodName);
			if ($proposal && ($method === 'validate' || ($method === 'close' && (int) ($args['status'] ?? 0) === 2)) && !$service->saleAllowed($proposal)) { throw new RuntimeException('LscSaleBlocked'); }
		} catch (Exception $e) {
			throw new \Luracast\Restler\RestException(409, $e->getMessage());
		}
	}
}
