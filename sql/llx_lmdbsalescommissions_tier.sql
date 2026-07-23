CREATE TABLE llx_lmdbsalescommissions_tier
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_tier_grid integer NOT NULL,
	threshold_amount double(24,8) DEFAULT 0 NOT NULL,
	bonus_amount double(24,8) DEFAULT 0 NOT NULL,
	commission_rate double(10,4) DEFAULT NULL,
	rang integer DEFAULT 0 NOT NULL,
	active tinyint DEFAULT 1 NOT NULL,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL,
	import_key varchar(14)
) ENGINE=innodb;
