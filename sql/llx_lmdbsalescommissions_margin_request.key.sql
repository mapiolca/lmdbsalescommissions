ALTER TABLE llx_lmdbsalescommissions_margin_request ADD UNIQUE INDEX uk_lsc_mrequest (entity, fk_propal, fk_user, fk_rule, fingerprint, fk_user_creat);
ALTER TABLE llx_lmdbsalescommissions_margin_request ADD INDEX idx_lsc_mrequest_user (fk_user);
ALTER TABLE llx_lmdbsalescommissions_margin_request ADD INDEX idx_lsc_mrequest_rule (fk_rule);
ALTER TABLE llx_lmdbsalescommissions_margin_request ADD INDEX idx_lsc_mrequest_author (fk_user_creat);
