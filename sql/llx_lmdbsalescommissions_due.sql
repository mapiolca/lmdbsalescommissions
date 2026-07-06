CREATE TABLE llx_lmdbsalescommissions_due
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_commission_line integer NOT NULL,
	event_type varchar(32) NOT NULL,
	percentage double(10,4) DEFAULT 0 NOT NULL,
	amount double(24,8) DEFAULT 0 NOT NULL,
	status smallint DEFAULT 0 NOT NULL,
	date_due datetime DEFAULT NULL,
	date_paid datetime DEFAULT NULL,
	fk_user_paid integer DEFAULT NULL,
	note_private text,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL,
	import_key varchar(14)
) ENGINE=innodb;
