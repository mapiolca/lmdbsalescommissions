<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */

/** Bridge native administration's element type to Notify's element_send type.
 * Only generated transport copies are updated; source templates and selections are untouched.
 */
class LmdbSalesCommissionWorkflowNotification
{
	/** @param DoliDB $db @param User $actor @return void */
	public static function prepare($db, $actor, string $code, int $entity): void
	{
		global $conf;
		if ($entity !== (int) $conf->entity) { throw new RuntimeException('LscOwnerContext'); }
		$label = getDolGlobalString($code.'_TEMPLATE');
		if ($label === '') { throw new RuntimeException('LscWorkflowTemplateMissing'); }
		$type = 'lscmargincase';
		// Lock source rows to serialize mirror creation for different dossiers too.
		$sql = 'SELECT * FROM '.MAIN_DB_PREFIX."c_email_templates WHERE type_template = '".$type."' AND label = '".$db->escape($label)."' AND active = 1 AND entity IN (".$db->sanitize(getEntity('c_email_templates')).') AND (private = 0 OR fk_user = '.(int) $actor->id.') ORDER BY rowid FOR UPDATE';
		$q = $db->query($sql);
		if (!$q) { throw new RuntimeException('LscPolicyUnavailable'); }
		$templates = array();
		while (is_object($row = $db->fetch_object($q))) { $templates[] = $row; }
		$db->free($q);
		if (!$templates) { throw new RuntimeException('LscWorkflowTemplateMissing'); }
		// Since v24 Notify reads the same element type as the administration.
		if (version_compare(DOL_VERSION, '24.0.0', '>=')) { return; }
		// Refresh only generated transport copies in the visible scope. Removed languages
		// must not survive as stale models. The selected source rows stay locked until commit.
		if (!$db->query('DELETE FROM '.MAIN_DB_PREFIX."c_email_templates WHERE module = 'lmdbsalescommissions' AND type_template = '".$type."_send' AND label = '".$db->escape($label)."' AND entity IN (".$db->sanitize(getEntity('c_email_templates')).') AND (private = 0 OR fk_user = '.(int) $actor->id.')')) { throw new RuntimeException('LscPolicyUnavailable'); }
		foreach ($templates as $template) {
			$values = array();
			foreach (array('entity', 'module', 'lang', 'private', 'fk_user', 'label', 'position', 'active', 'enabled', 'joinfiles', 'topic', 'content', 'content_lines', 'email_from', 'email_to', 'email_tocc', 'email_tobcc') as $field) {
				$value = $template->{$field};
				$values[$field] = $value === null ? 'NULL' : "'".$db->escape((string) $value)."'";
			}
			$values['module'] = "'lmdbsalescommissions'";
			$values['type_template'] = "'".$type."_send'";
			$values['datec'] = "'".$db->idate(dol_now())."'";
			$write = 'INSERT INTO '.MAIN_DB_PREFIX.'c_email_templates ('.implode(',', array_keys($values)).') VALUES ('.implode(',', $values).')';
			if (!$db->query($write)) { throw new RuntimeException('LscPolicyUnavailable'); }
		}
	}
}
