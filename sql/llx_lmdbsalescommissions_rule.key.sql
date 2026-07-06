ALTER TABLE llx_lmdbsalescommissions_rule ADD UNIQUE INDEX uk_lmdbsalescommissions_rule_ref (entity, ref);
ALTER TABLE llx_lmdbsalescommissions_rule ADD INDEX idx_lmdbsalescommissions_rule_entity (entity);
ALTER TABLE llx_lmdbsalescommissions_rule ADD INDEX idx_lmdbsalescommissions_rule_type (rule_type);
ALTER TABLE llx_lmdbsalescommissions_rule ADD INDEX idx_lmdbsalescommissions_rule_grid (fk_tier_grid);
ALTER TABLE llx_lmdbsalescommissions_rule ADD INDEX idx_lmdbsalescommissions_rule_payment (fk_payment_term);
ALTER TABLE llx_lmdbsalescommissions_rule ADD INDEX idx_lmdbsalescommissions_rule_source (source_type);
ALTER TABLE llx_lmdbsalescommissions_rule ADD INDEX idx_lmdbsalescommissions_rule_period (period_type);
ALTER TABLE llx_lmdbsalescommissions_rule ADD INDEX idx_lmdbsalescommissions_rule_active (active);
ALTER TABLE llx_lmdbsalescommissions_rule ADD INDEX idx_lmdbsalescommissions_rule_dates (date_start, date_end);
