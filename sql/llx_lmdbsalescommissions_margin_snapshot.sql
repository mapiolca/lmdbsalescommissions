CREATE TABLE llx_lmdbsalescommissions_margin_snapshot
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_propal integer NOT NULL,
	fk_user integer NOT NULL,
	fingerprint varchar(64) NOT NULL,
	sale_state varchar(16) NOT NULL,
	commission_state varchar(16) NOT NULL,
	snapshot_payload longtext NOT NULL,
	fk_user_creat integer DEFAULT NULL,
	date_creation datetime NOT NULL
) ENGINE=innodb;
