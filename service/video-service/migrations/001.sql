CREATE TABLE IF NOT EXISTS schema_version (version INT PRIMARY KEY);
CREATE TABLE IF NOT EXISTS video (
 uuid CHAR(36) PRIMARY KEY, tenant VARCHAR(64) NOT NULL, owner VARCHAR(128) NOT NULL,
 create_key CHAR(64) NOT NULL, kind VARCHAR(8) NOT NULL, state VARCHAR(32) NOT NULL,
 revision INT NOT NULL DEFAULT 1, duration DOUBLE NOT NULL DEFAULT 0,
 attempt INT NOT NULL DEFAULT 0, error_code VARCHAR(64) NOT NULL DEFAULT '',
 refs LONGTEXT NOT NULL, refgeneration BIGINT NOT NULL DEFAULT 0,
 raw_key VARCHAR(255) NOT NULL DEFAULT '', raw_expires BIGINT NOT NULL DEFAULT 0,
 raw_state VARCHAR(20) NOT NULL DEFAULT 'absent', hold_flag INT NOT NULL DEFAULT 0,
 deletion_key CHAR(64) NOT NULL DEFAULT '', created BIGINT NOT NULL, modified BIGINT NOT NULL,
 UNIQUE KEY tenant_create(tenant,create_key), KEY owner_lookup(tenant,owner)
);
CREATE TABLE IF NOT EXISTS upload_session (
 id CHAR(36) PRIMARY KEY, video CHAR(36) NOT NULL, operation_key CHAR(64) NOT NULL,
 size BIGINT NOT NULL, sha256 CHAR(64) NOT NULL, object_key VARCHAR(255) NOT NULL,
 multipart_id TEXT NULL, state VARCHAR(32) NOT NULL, expires BIGINT NOT NULL,
 policy LONGTEXT NOT NULL, created BIGINT NOT NULL,
 UNIQUE KEY video_operation(video,operation_key), KEY active(video,state)
);
CREATE TABLE IF NOT EXISTS media_version (
 video CHAR(36) NOT NULL, revision INT NOT NULL, prefix VARCHAR(255) NOT NULL,
 duration DOUBLE NOT NULL, policy LONGTEXT NOT NULL, metadata LONGTEXT NOT NULL,
 created BIGINT NOT NULL, PRIMARY KEY(video,revision)
);
CREATE TABLE IF NOT EXISTS rendition (
 video CHAR(36) NOT NULL, revision INT NOT NULL, path VARCHAR(255) NOT NULL,
 size BIGINT NOT NULL, sha256 CHAR(64) NOT NULL, mime VARCHAR(80) NOT NULL,
 PRIMARY KEY(video,revision,path)
);
CREATE TABLE IF NOT EXISTS job (
 id CHAR(36) PRIMARY KEY, video CHAR(36) NOT NULL, upload_id CHAR(36) NOT NULL,
 state VARCHAR(20) NOT NULL, attempt INT NOT NULL DEFAULT 0, fence BIGINT NOT NULL DEFAULT 0,
 lease_until BIGINT NOT NULL DEFAULT 0, worker VARCHAR(64) NOT NULL DEFAULT '',
 error_code VARCHAR(64) NOT NULL DEFAULT '', available BIGINT NOT NULL,
 created BIGINT NOT NULL, modified BIGINT NOT NULL,
 UNIQUE KEY job_upload(upload_id), KEY due_jobs(state,available,lease_until)
);
CREATE TABLE IF NOT EXISTS outbox (
 job CHAR(36) PRIMARY KEY, published BIGINT NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS auth_nonce (
 key_id VARCHAR(64) NOT NULL, nonce CHAR(64) NOT NULL, expires BIGINT NOT NULL,
 PRIMARY KEY(key_id,nonce), KEY expiry(expires)
);
CREATE TABLE IF NOT EXISTS privacy_request (
 tenant VARCHAR(64) NOT NULL, operation_key CHAR(64) NOT NULL, owner_hash CHAR(64) NOT NULL,
 action VARCHAR(20) NOT NULL, completed BIGINT NOT NULL,
 PRIMARY KEY(tenant,operation_key)
);
INSERT IGNORE INTO schema_version(version) VALUES (1);
