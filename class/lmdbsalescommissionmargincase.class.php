<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
require_once __DIR__.'/lmdbsalescommissioncommon.class.php';

/** One auditable workflow dossier; previous per-rule requests remain historical. */
class LmdbSalesCommissionMarginCase extends LmdbSalesCommissionCommon
{
	public const PENDING = 0;
	public const APPROVED = 1;
	public const RETURNED = 2;
	public $TRIGGER_PREFIX = 'LMDBSALESCOMMISSIONS_MARGIN_CASE';
	/** Native email types are varchar(32), including Notify's legacy _send suffix.
	 * The elementproperties hook resolves this short identity to this class. */
	public $element = 'lscmargincase';
	public $table_element = 'lmdbsalescommissions_margin_case';
	public $fk_propal;
	public $fk_author;
	public $active_proposal;
	public $status;
	public $fingerprint;
	public $reason;
	public $decision_reason;
	public $retained_percent;
	public $snapshot_commission_base;
	public $snapshot_payload;
	public $fk_user_decider;
	public $date_creation;
	public $date_decision;
	public $tms;
	/** @var int Transient native notification/Agenda link, read from the parent quote. */
	public $socid = 0;
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => '1', 'visible' => -2, 'notnull' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => '1', 'visible' => 0, 'notnull' => 1),
		'fk_propal' => array('type' => 'integer', 'label' => 'Proposal', 'enabled' => '1', 'visible' => 1, 'notnull' => 1),
		'fk_author' => array('type' => 'integer', 'label' => 'Author', 'enabled' => '1', 'visible' => 1, 'notnull' => 1),
		'active_proposal' => array('type' => 'integer', 'label' => 'Proposal', 'enabled' => '1', 'visible' => 0),
		'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => '1', 'visible' => 1, 'notnull' => 1),
		'fingerprint' => array('type' => 'varchar(64)', 'label' => 'LscApproval', 'enabled' => '1', 'visible' => 0, 'notnull' => 1),
		'reason' => array('type' => 'text', 'label' => 'Reason', 'enabled' => '1', 'visible' => 1, 'notnull' => 1),
		'decision_reason' => array('type' => 'text', 'label' => 'LscWorkflowDecision', 'enabled' => '1', 'visible' => 1),
		'retained_percent' => array('type' => 'double(24,8)', 'label' => 'LscWorkflowPercent', 'enabled' => '1', 'visible' => 1),
		'snapshot_commission_base' => array('type' => 'double(24,8)', 'label' => 'LscWorkflowBase', 'enabled' => '1', 'visible' => 1),
		'snapshot_payload' => array('type' => 'text', 'label' => 'LscAppliedRules', 'enabled' => '1', 'visible' => 0, 'notnull' => 1),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'Author', 'enabled' => '1', 'visible' => 0, 'notnull' => 1),
		'fk_user_modif' => array('type' => 'integer', 'label' => 'User', 'enabled' => '1', 'visible' => 0),
		'fk_user_decider' => array('type' => 'integer', 'label' => 'LscWorkflowApprover', 'enabled' => '1', 'visible' => 1),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => '1', 'visible' => 1),
		'date_decision' => array('type' => 'datetime', 'label' => 'Date', 'enabled' => '1', 'visible' => 1),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'visible' => 0),
	);
	/** Dossier transitions must go through the workflow, never a generic object switch.
	 * @return int */
	public function setValueFrom($field, $value, $table = '', $id = null, $format = '', $id_field = '', $fuser = null, $trigkey = '', $fk_user_field = 'fk_user_modif')
	{
		$this->error = 'LscWorkflowUseDecision';
		return -1;
	}

	/** @return string */
	public function getLibStatut($mode = 5)
	{
		global $langs;
		$key = array(self::PENDING => 'LscWorkflowPending', self::APPROVED => 'LscWorkflowApproved', self::RETURNED => 'LscWorkflowReturned');
		return dolGetStatus($langs->trans($key[(int) $this->status] ?? 'Unknown'), '', '', array(0 => 'status1', 1 => 'status4', 2 => 'status0')[(int) $this->status] ?? 'status0', $mode);
	}

	/** Native notification/document helpers resolve this dossier to its authenticated page. */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '')
	{
		global $langs;
		return '<a href="'.dol_buildpath('/lmdbsalescommissions/proposal_margin_workflow.php', 1).'?id='.(int) $this->fk_propal.'">'.($withpicto ? img_picto('', $this->picto).' ' : '').dol_escape_htmltag($langs->trans('LscWorkflowTitle')).' #'.(int) $this->id.'</a>';
	}
}
