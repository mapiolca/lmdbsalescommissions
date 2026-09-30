CREATE TABLE llx_lmdbsalescommissions_margin_travel_band
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_rule integer NOT NULL,
	metric varchar(16) NOT NULL,
	min_value double(24,8) NOT NULL,
	uplift double(24,8) NOT NULL
) ENGINE=innodb;
