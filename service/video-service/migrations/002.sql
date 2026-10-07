CREATE TABLE IF NOT EXISTS operation_result (
 tenant VARCHAR(64) NOT NULL, video CHAR(36) NOT NULL, operation VARCHAR(32) NOT NULL,
 operation_key CHAR(64) NOT NULL, result LONGTEXT NOT NULL, created BIGINT NOT NULL,
 PRIMARY KEY(tenant,video,operation,operation_key)
);
INSERT IGNORE INTO schema_version(version) VALUES (2);
