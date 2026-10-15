CREATE UNIQUE INDEX uk_lsc_mc_rule_category ON llx_lmdbsalescommissions_margin_category (entity, fk_rule, fk_category);
CREATE INDEX idx_lsc_mc_category ON llx_lmdbsalescommissions_margin_category (fk_category);
