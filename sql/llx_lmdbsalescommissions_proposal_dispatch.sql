CREATE TABLE llx_lmdbsalescommissions_proposal_dispatch
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_propal integer NOT NULL,
	fk_user integer NOT NULL,
	base_type varchar(16) NOT NULL,
	value_type varchar(16) NOT NULL,
	value double(24,8) DEFAULT 0 NOT NULL,
	payment_term_mode varchar(16) DEFAULT 'automatic' NOT NULL,
	fk_payment_term integer DEFAULT NULL,
	note_private text,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL,
	import_key varchar(14)
) ENGINE=innodb;
