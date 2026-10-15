CREATE UNIQUE INDEX uk_lsc_mp_rule_product ON llx_lmdbsalescommissions_margin_product (entity, fk_rule, fk_product);
CREATE INDEX idx_lsc_mp_product ON llx_lmdbsalescommissions_margin_product (fk_product);
