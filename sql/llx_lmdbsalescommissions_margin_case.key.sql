ALTER TABLE llx_lmdbsalescommissions_margin_case ADD UNIQUE INDEX uk_lsc_mcase_active (entity, active_proposal);
ALTER TABLE llx_lmdbsalescommissions_margin_case ADD INDEX idx_lsc_mcase_propal (entity, fk_propal, status);
ALTER TABLE llx_lmdbsalescommissions_margin_case ADD INDEX idx_lsc_mcase_author (fk_author);
ALTER TABLE llx_lmdbsalescommissions_margin_case ADD INDEX idx_lsc_mcase_creator (fk_user_creat);
ALTER TABLE llx_lmdbsalescommissions_margin_case ADD INDEX idx_lsc_mcase_modifier (fk_user_modif);
ALTER TABLE llx_lmdbsalescommissions_margin_case ADD INDEX idx_lsc_mcase_decider (fk_user_decider);
