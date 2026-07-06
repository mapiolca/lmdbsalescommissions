CREATE TABLE llx_lmdbsalescommissions_payment_term_line
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_payment_term integer NOT NULL,
	event_type varchar(32) NOT NULL,
	percentage double(10,4) DEFAULT 0 NOT NULL,
	rang integer DEFAULT 0 NOT NULL,
	active tinyint DEFAULT 1 NOT NULL,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL,
	import_key varchar(14)
) ENGINE=innodb;
