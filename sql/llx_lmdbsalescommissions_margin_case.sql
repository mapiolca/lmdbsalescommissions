CREATE TABLE llx_lmdbsalescommissions_margin_case
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_propal integer NOT NULL,
	fk_author integer NOT NULL,
	active_proposal integer DEFAULT NULL,
	status integer DEFAULT 0 NOT NULL,
	fingerprint varchar(64) NOT NULL,
	reason text NOT NULL,
	decision_reason text,
	retained_percent double(24,8) DEFAULT NULL,
	snapshot_commission_base double(24,8) DEFAULT NULL,
	snapshot_payload longtext NOT NULL,
	fk_user_creat integer NOT NULL,
	fk_user_modif integer DEFAULT NULL,
	fk_user_decider integer DEFAULT NULL,
	date_creation datetime NOT NULL,
	date_decision datetime DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
