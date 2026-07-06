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
