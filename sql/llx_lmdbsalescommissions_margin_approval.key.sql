CREATE INDEX idx_lsc_ma_propal ON llx_lmdbsalescommissions_margin_approval (entity, fk_propal, fk_user);
CREATE INDEX idx_lsc_ma_rule ON llx_lmdbsalescommissions_margin_approval (fk_rule);
