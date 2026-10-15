CREATE TABLE llx_lmdbsalescommissions_margin_complex_site
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_rule integer NOT NULL,
	uplift_without_travel double(24,8) NOT NULL,
	uplift_with_travel double(24,8) NULL
) ENGINE=innodb;
