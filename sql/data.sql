INSERT IGNORE INTO llx_c_action_trigger (elementtype, code, label, description, rang)
VALUES ('lmdbsalescommissions_line@lmdbsalescommissions', 'LMDBSALESCOMMISSIONS_LINE_CREATE', 'Create commission line', 'Commission line created; business details are carried by the object context when available.', 500700);

INSERT IGNORE INTO llx_c_action_trigger (elementtype, code, label, description, rang)
VALUES ('lmdbsalescommissions_line@lmdbsalescommissions', 'LMDBSALESCOMMISSIONS_LINE_UPDATE', 'Update commission line', 'Commission line updated; business details are carried by the object context when available.', 500701);

INSERT IGNORE INTO llx_c_action_trigger (elementtype, code, label, description, rang)
VALUES ('lmdbsalescommissions_due@lmdbsalescommissions', 'LMDBSALESCOMMISSIONS_DUE_CREATE', 'Create commission due date', 'Commission due date created; payment event details are carried by the object context when available.', 500702);

INSERT IGNORE INTO llx_c_action_trigger (elementtype, code, label, description, rang)
VALUES ('lmdbsalescommissions_due@lmdbsalescommissions', 'LMDBSALESCOMMISSIONS_DUE_UPDATE', 'Update commission due date', 'Commission due date updated; payment status details are carried by the object context when available.', 500703);

INSERT IGNORE INTO llx_c_action_trigger (elementtype, code, label, description, rang)
VALUES ('lmdbsalescommissions_objective_archive@lmdbsalescommissions', 'LMDBSALESCOMMISSIONS_OBJECTIVE_ARCHIVE_CREATE', 'Create objective archive', 'Objective archive created at period closing.', 500704);

INSERT IGNORE INTO llx_c_action_trigger (elementtype, code, label, description, rang)
VALUES ('lscmargincase', 'LMDBSALESCOMMISSIONS_MARGIN_CASE_CREATE', 'New margin validation request', 'Margin validation request created.', 500705);

INSERT IGNORE INTO llx_c_action_trigger (elementtype, code, label, description, rang)
VALUES ('lscmargincase', 'LMDBSALESCOMMISSIONS_MARGIN_CASE_UPDATE', 'Margin validation decision', 'Margin validation decision recorded; outcome is carried by status and context.', 500706);

INSERT INTO llx_c_email_templates (entity,module,type_template,lang,private,fk_user,datec,label,position,active,enabled,joinfiles,topic,content)
SELECT 0,'lmdbsalescommissions','lscmargincase','fr_FR',0,NULL,NOW(),'LscWorkflowDefaultMail',100,1,'isModEnabled("lmdbsalescommissions")',0,'__LSC_WORKFLOW_DECISION__ : __LSC_WORKFLOW_PROPOSAL_REF__','Devis : __LSC_WORKFLOW_PROPOSAL_REF__<br>Auteur : __LSC_WORKFLOW_AUTHOR__<br>Demandeur : __LSC_WORKFLOW_REQUESTER__<br>Marge : __LSC_WORKFLOW_MARGIN__<br>Règles : __LSC_WORKFLOW_RULES__<br>Motif : __LSC_WORKFLOW_REASON__<br>Décision : __LSC_WORKFLOW_DECISION__<br>Motif de décision : __LSC_WORKFLOW_DECISION_REASON__<br>Approbateur : __LSC_WORKFLOW_APPROVER__<br>Commission retenue : __LSC_WORKFLOW_COMMISSION__<br>__LSC_WORKFLOW_URL__' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM llx_c_email_templates WHERE entity = 0 AND module = 'lmdbsalescommissions' AND type_template = 'lscmargincase' AND label = 'LscWorkflowDefaultMail' AND lang = 'fr_FR');

INSERT INTO llx_c_email_templates (entity,module,type_template,lang,private,fk_user,datec,label,position,active,enabled,joinfiles,topic,content)
SELECT 0,'lmdbsalescommissions','lscmargincase','en_US',0,NULL,NOW(),'LscWorkflowDefaultMail',100,1,'isModEnabled("lmdbsalescommissions")',0,'__LSC_WORKFLOW_DECISION__: __LSC_WORKFLOW_PROPOSAL_REF__','Proposal: __LSC_WORKFLOW_PROPOSAL_REF__<br>Author: __LSC_WORKFLOW_AUTHOR__<br>Requester: __LSC_WORKFLOW_REQUESTER__<br>Margin: __LSC_WORKFLOW_MARGIN__<br>Rules: __LSC_WORKFLOW_RULES__<br>Reason: __LSC_WORKFLOW_REASON__<br>Decision: __LSC_WORKFLOW_DECISION__<br>Decision reason: __LSC_WORKFLOW_DECISION_REASON__<br>Approver: __LSC_WORKFLOW_APPROVER__<br>Retained commission: __LSC_WORKFLOW_COMMISSION__<br>__LSC_WORKFLOW_URL__' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM llx_c_email_templates WHERE entity = 0 AND module = 'lmdbsalescommissions' AND type_template = 'lscmargincase' AND label = 'LscWorkflowDefaultMail' AND lang = 'en_US');
