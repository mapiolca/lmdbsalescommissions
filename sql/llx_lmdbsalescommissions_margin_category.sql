CREATE TABLE llx_lmdbsalescommissions_margin_category
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_rule integer NOT NULL,
	fk_category integer NOT NULL
) ENGINE=innodb;
