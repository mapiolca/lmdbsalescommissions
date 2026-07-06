CREATE TABLE llx_lmdbsalescommissions_tier_grid
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	ref varchar(128) NOT NULL,
	label varchar(255) NOT NULL,
	period_type varchar(32) DEFAULT 'monthly' NOT NULL,
	active tinyint DEFAULT 1 NOT NULL,
	note_private text,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL,
	import_key varchar(14)
) ENGINE=innodb;
