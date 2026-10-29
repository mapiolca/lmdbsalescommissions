<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr> */
require_once __DIR__.'/lmdbsalescommissionmarginworkflow.class.php';

/** Presentation only. Mutations are protected independently by the workflow service. */
class LmdbSalesCommissionMarginWorkflowView
{
	/** @param array<int,array<string,mixed>> $decisions @return string */
	public static function checks(array $decisions): string
	{
		global $langs;
		$html = '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('LscAppliedRules').'</th><th>'.$langs->trans('LscActualRate').'</th><th>'.$langs->trans('LscThreshold').'</th></tr>';
		foreach ($decisions as $decision) {
			foreach ($decision['checks'] as $check) {
				if ($check['effect'] !== 'sale' || $check['state'] !== 'deny') { continue; }
				$html .= '<tr class="oddeven"><td>'.dol_escape_htmltag($check['origin']).'</td><td>'.price($check['rate'] ?? $decision['inputs']['rate']).' %</td><td>'.price($check['threshold']).' %</td></tr>';
			}
		}
		return $html.'</table></div>';
	}

	/**
	 * Native actions; the request page checks permissions and fingerprints again.
	 * @param bool $withStatus Show the pending badge when no native status banner is available
	 * @return string
	 */
	public static function actions($db, $proposal, $actor, bool $withStatus = true): string
	{
		global $langs;
		if (!LmdbSalesCommissionMarginWorkflow::available()) { return ''; }
		$workflow = new LmdbSalesCommissionMarginWorkflow($db);
		$url = dol_buildpath('/lmdbsalescommissions/proposal_margin_workflow.php', 1).'?id='.(int) $proposal->id;
		$pending = $workflow->pending($proposal);
		$html = '';
		if ($pending) {
			$html = $withStatus ? '<span class="lsc-workflow-pending">'.dolGetStatus($langs->trans('LscWorkflowPending'), '', '', 'status1', 5).'</span> ' : '';
			try {
				$workflow->assertDecisionAccess($proposal, $actor, false);
				$html .= '<a class="butAction lsc-workflow-action" href="'.$url.'&mode=return">'.$langs->trans('LscWorkflowReturn').'</a>';
				$workflow->assertDecisionAccess($proposal, $actor, true);
				$html .= '<a class="butAction lsc-workflow-action" href="'.$url.'&mode=approve">'.$langs->trans('LscWorkflowApprove').'</a>';
			} catch (RuntimeException $e) { /* Read-only actors see the state, never an approval control. */ }
		} elseif ((int) ($proposal->status ?? $proposal->statut ?? -1) === 0 && $actor->hasRight('propal', 'creer')) {
			try {
				$workflow->requestable($proposal, $actor);
				$html = '<a class="butAction lsc-workflow-action lsc-workflow-request" href="'.$url.'&mode=request">'.$langs->trans('LscWorkflowRequest').'</a>';
			} catch (RuntimeException $e) { if ($e->getMessage() !== 'LscRequestNoDeniedSale') { $html = '<span class="warning">'.dol_escape_htmltag($langs->trans($e->getMessage())).'</span>'; } }
		}
		return $html;
	}

	/** @return string Dossier history, including an explicit record of the commission decision. */
	public static function history($db, $proposal): string
	{
		global $langs, $user;
		$page = max(0, GETPOSTINT('page'));
		$limit = GETPOSTINT('limit') > 0 ? min(100, GETPOSTINT('limit')) : 20;
		$rows = (new LmdbSalesCommissionMarginWorkflow($db))->history($proposal, $limit + 1, $page * $limit);
		ob_start();
		print '<form method="GET" action="'.dol_buildpath('/lmdbsalescommissions/proposal_margin_workflow.php', 1).'"><input type="hidden" name="id" value="'.(int) $proposal->id.'">';
		print_barre_liste($langs->trans('LscWorkflowHistory'), $page, dol_buildpath('/lmdbsalescommissions/proposal_margin_workflow.php', 1), 'id='.(int) $proposal->id, '', '', '', count($rows), -1, 'propal', 0, '', '', $limit);
		$html = ob_get_clean().'<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre">';
		foreach (array('Status', 'DateCreation', 'Reason', 'LscWorkflowDecision', 'LscWorkflowPercent', 'LscWorkflowApprover') as $key) { $html .= '<th>'.$langs->trans($key).'</th>'; }
		$canApprove = false;
		try { (new LmdbSalesCommissionMarginWorkflow($db))->assertDecisionAccess($proposal, $user, false); $canApprove = true; }
		catch (RuntimeException $e) { /* Native quote readers may still read the request history. */ }
		foreach (array_slice($rows, 0, $limit) as $row) {
			$case = new LmdbSalesCommissionMarginCase($db);
			$case->status = (int) $row['status'];
			$commissionVisible = $canApprove || $user->hasRight('lmdbsalescommissions', 'commission', 'readall') || $user->hasRight('lmdbsalescommissions', 'commission', 'dispatch') || ($user->hasRight('lmdbsalescommissions', 'commission', 'readown') && (int) $user->id === (int) $row['fk_author']);
			$html .= '<tr class="oddeven"><td>'.$case->getLibStatut().'</td><td>'.dol_print_date($db->jdate($row['date_creation']), 'dayhour').'</td><td>'.dol_nl2br(dol_escape_htmltag($row['reason'])).'</td><td>'.dol_nl2br(dol_escape_htmltag($row['decision_reason'] ?? '')).'</td><td>'.($commissionVisible && $row['retained_percent'] !== null ? price($row['retained_percent']).' %' : '').'</td><td>'.dol_escape_htmltag(trim(($row['approver_firstname'] ?? '').' '.($row['approver_lastname'] ?? ''))).'</td></tr>';
		}
		if (!$rows) { $html .= '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>'; }
		return $html.'</table></div></form>';
	}
}
