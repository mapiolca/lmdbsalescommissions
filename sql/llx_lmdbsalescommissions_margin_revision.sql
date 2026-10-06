CREATE TABLE llx_lmdbsalescommissions_margin_revision
(
 rowid integer AUTO_INCREMENT PRIMARY KEY,
 entity integer DEFAULT 1 NOT NULL,
 object_id integer DEFAULT 0 NOT NULL,
 revision bigint DEFAULT 1 NOT NULL
) ENGINE=innodb;
