UPDATE video v JOIN upload_session u ON u.video=v.uuid AND u.object_key=v.raw_key AND u.state='complete'
SET v.raw_expires=v.raw_expires-(CAST(JSON_UNQUOTE(JSON_EXTRACT(u.policy,'$.raw_retention_days')) AS UNSIGNED)-1)*86400
WHERE v.raw_state='retained' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(u.policy,'$.raw_retention_days')) AS UNSIGNED)>1
AND NOT EXISTS (SELECT 1 FROM schema_version WHERE version=4);
INSERT IGNORE INTO schema_version(version) VALUES (4);
