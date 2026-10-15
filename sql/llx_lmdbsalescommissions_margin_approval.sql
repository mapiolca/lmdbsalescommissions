CREATE TABLE llx_lmdbsalescommissions_margin_approval
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_propal integer NOT NULL,
	fk_user integer NOT NULL,
	fk_rule integer NOT NULL,
	effect varchar(16) NOT NULL,
	fingerprint varchar(64) NOT NULL,
	reason text NOT NULL,
	fk_user_creat integer NOT NULL,
	date_creation datetime NOT NULL
) ENGINE=innodb;
