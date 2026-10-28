CREATE TABLE llx_lmdbsalescommissions_margin_product
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_rule integer NOT NULL,
	fk_product integer NOT NULL
) ENGINE=innodb;
