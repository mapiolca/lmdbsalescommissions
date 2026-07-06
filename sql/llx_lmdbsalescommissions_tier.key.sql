ALTER TABLE llx_lmdbsalescommissions_tier ADD UNIQUE INDEX uk_lmdbsalescommissions_tier_threshold (entity, fk_tier_grid, threshold_amount);
ALTER TABLE llx_lmdbsalescommissions_tier ADD INDEX idx_lmdbsalescommissions_tier_entity (entity);
ALTER TABLE llx_lmdbsalescommissions_tier ADD INDEX idx_lmdbsalescommissions_tier_grid (fk_tier_grid);
ALTER TABLE llx_lmdbsalescommissions_tier ADD INDEX idx_lmdbsalescommissions_tier_rang (rang);
ALTER TABLE llx_lmdbsalescommissions_tier ADD INDEX idx_lmdbsalescommissions_tier_active (active);
