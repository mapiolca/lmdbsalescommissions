CREATE TABLE llx_lmdbsalescommissions_dashboard_widget_user
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	widget_code varchar(128) NOT NULL,
	visible tinyint DEFAULT 1 NOT NULL,
	position integer DEFAULT 0 NOT NULL,
	column_index integer DEFAULT 0 NOT NULL,
	width integer DEFAULT NULL,
	height integer DEFAULT NULL,
	settings text,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer DEFAULT NULL,
	fk_user_modif integer DEFAULT NULL
) ENGINE=innodb;
