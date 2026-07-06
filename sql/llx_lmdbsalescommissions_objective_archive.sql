CREATE TABLE llx_lmdbsalescommissions_objective_archive
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	fk_objective integer DEFAULT NULL,
	objective_type varchar(32) DEFAULT 'monthly' NOT NULL,
	year integer NOT NULL,
	month integer DEFAULT NULL,
	target_value double(24,8) DEFAULT 0 NOT NULL,
	realized_value double(24,8) DEFAULT 0 NOT NULL,
	achievement_rate double(10,4) DEFAULT NULL,
	status smallint DEFAULT 0 NOT NULL,
	objective_source varchar(32) DEFAULT NULL,
	date_calculation datetime DEFAULT NULL,
	date_archive datetime NOT NULL,
	fk_user_archive integer DEFAULT NULL,
	note_private text,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL,
	import_key varchar(14)
) ENGINE=innodb;
