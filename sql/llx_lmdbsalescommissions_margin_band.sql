CREATE TABLE llx_lmdbsalescommissions_margin_band
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_rule integer NOT NULL,
	kwc_min double(24,8) DEFAULT NULL,
	kwc_max double(24,8) DEFAULT NULL,
	kwc_inclusive tinyint DEFAULT 0 NOT NULL,
	kwh_min double(24,8) DEFAULT NULL,
	kwh_max double(24,8) DEFAULT NULL,
	kwh_inclusive tinyint DEFAULT 0 NOT NULL,
	threshold double(24,8) NOT NULL
) ENGINE=innodb;
