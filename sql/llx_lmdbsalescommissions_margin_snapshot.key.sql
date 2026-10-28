ALTER TABLE llx_lmdbsalescommissions_margin_snapshot ADD UNIQUE INDEX uk_lsc_ms_user (entity, fk_propal, fk_user);
