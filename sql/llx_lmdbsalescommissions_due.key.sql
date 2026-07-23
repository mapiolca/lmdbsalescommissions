ALTER TABLE llx_lmdbsalescommissions_due ADD UNIQUE INDEX uk_lmdbsalescommissions_due_line_event (entity, fk_commission_line, event_type, revision);
ALTER TABLE llx_lmdbsalescommissions_due ADD INDEX idx_lmdbsalescommissions_due_entity (entity);
ALTER TABLE llx_lmdbsalescommissions_due ADD INDEX idx_lmdbsalescommissions_due_line (fk_commission_line);
ALTER TABLE llx_lmdbsalescommissions_due ADD INDEX idx_lmdbsalescommissions_due_event (event_type);
ALTER TABLE llx_lmdbsalescommissions_due ADD INDEX idx_lmdbsalescommissions_due_status (status);
ALTER TABLE llx_lmdbsalescommissions_due ADD INDEX idx_lmdbsalescommissions_due_date_due (date_due);
ALTER TABLE llx_lmdbsalescommissions_due ADD INDEX idx_lmdbsalescommissions_due_date_paid (date_paid);
ALTER TABLE llx_lmdbsalescommissions_due ADD INDEX idx_lmdbsalescommissions_due_user_paid (fk_user_paid);
