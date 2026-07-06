CREATE TABLE llx_lmdbsalescommissions_objective
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	assignment_type varchar(32) DEFAULT 'default' NOT NULL,
	fk_user integer DEFAULT NULL,
	fk_usergroup integer DEFAULT NULL,
	objective_type varchar(32) DEFAULT 'monthly' NOT NULL,
	year integer NOT NULL,
	month integer DEFAULT NULL,
	base_type varchar(32) DEFAULT 'signed_turnover' NOT NULL,
	target_value double(24,8) DEFAULT 0 NOT NULL,
	active tinyint DEFAULT 1 NOT NULL,
	date_start date DEFAULT NULL,
	date_end date DEFAULT NULL,
	priority integer DEFAULT 0 NOT NULL,
	note_private text,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL,
	import_key varchar(14)
) ENGINE=innodb;
