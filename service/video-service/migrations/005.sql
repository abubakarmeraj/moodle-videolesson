ALTER TABLE video ADD COLUMN IF NOT EXISTS recycle_at BIGINT NOT NULL DEFAULT 0;
ALTER TABLE video ADD COLUMN IF NOT EXISTS delete_after BIGINT NOT NULL DEFAULT 0;
ALTER TABLE video ADD COLUMN IF NOT EXISTS keep_flag INT NOT NULL DEFAULT 0;
ALTER TABLE video ADD COLUMN IF NOT EXISTS storage_checked BIGINT NOT NULL DEFAULT 0;
ALTER TABLE video ADD COLUMN IF NOT EXISTS title VARCHAR(200) NOT NULL DEFAULT '';
CREATE TABLE IF NOT EXISTS storage_policy (
 tenant VARCHAR(64) PRIMARY KEY, quota_bytes BIGINT NOT NULL DEFAULT 0,
 recovery_days INT NOT NULL DEFAULT 7
);
CREATE TABLE IF NOT EXISTS storage_object (
 tenant VARCHAR(64) NOT NULL, video CHAR(36) NOT NULL, bucket_kind VARCHAR(12) NOT NULL,
 object_key VARCHAR(255) NOT NULL, bytes BIGINT NOT NULL, confirmed INT NOT NULL DEFAULT 1,
 PRIMARY KEY(tenant,bucket_kind,object_key), KEY video_storage(tenant,video)
);
CREATE TABLE IF NOT EXISTS storage_reservation (
 upload_id CHAR(36) PRIMARY KEY, tenant VARCHAR(64) NOT NULL, bytes BIGINT NOT NULL,
 part_bytes BIGINT NOT NULL DEFAULT 0, KEY tenant_reservation(tenant)
);
INSERT IGNORE INTO storage_object(tenant,video,bucket_kind,object_key,bytes)
 SELECT v.tenant,v.uuid,'processed',CONCAT(m.prefix,r.path),r.size FROM video v
 JOIN media_version m ON m.video=v.uuid JOIN rendition r ON r.video=m.video AND r.revision=m.revision
 WHERE v.state!='deleted' AND NOT EXISTS (SELECT 1 FROM schema_version WHERE version=5);
INSERT IGNORE INTO storage_object(tenant,video,bucket_kind,object_key,bytes)
 SELECT v.tenant,v.uuid,'raw',v.raw_key,u.size FROM video v JOIN upload_session u ON u.object_key=v.raw_key
 WHERE v.raw_state='retained' AND NOT EXISTS (SELECT 1 FROM schema_version WHERE version=5);
INSERT IGNORE INTO storage_reservation(upload_id,tenant,bytes)
 SELECT u.id,v.tenant,u.size FROM upload_session u JOIN video v ON v.uuid=u.video
 WHERE u.state IN ('creating','uploading') AND NOT EXISTS (SELECT 1 FROM schema_version WHERE version=5);
INSERT IGNORE INTO schema_version(version) VALUES(5);
