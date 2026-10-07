# Uninstall and data ownership

Uninstalling the Moodle plugin is not a bucket-deletion command. Shared media,
retention holds, service references and recovery state need explicit review.

Stop uploads/jobs and take a matched backup before retiring a tenant. Use the
plugin's supported lifecycle/storage operations and dry-run service reconciliation,
not arbitrary SQL or bucket-wide deletes. Verify no remaining reference/hold or
in-flight work before permanent cleanup. Physical usage is released only after
verified deletion.

Stop Compose with `stop` or `down` **without `-v`** to preserve the DB volume;
retain protected configuration and buckets. Native roles/timer may be disabled
after review; application/config/database/staging removal is a separate deliberate
operator action. No destructive uninstall script is supplied. Moodle's normal
plugin uninstall removes Moodle-owned plugin data according to Moodle's rules,
not an external service or all object storage.
