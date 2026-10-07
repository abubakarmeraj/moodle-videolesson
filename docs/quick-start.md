# Quick start

Use [requirements](installation.md) and [Docker installation](docker.md) before these steps.

1. Install the Video Lesson ZIP in Moodle; complete its normal upgrade screen/CLI.
2. On the service host, extract the service archive, provision private raw/processed
   buckets and HTTPS routing, then prepare the protected operator policy and run Compose.
3. Copy only `moodle-connection.json` securely to an outside-webroot file on the
   Moodle host (root:PHP-runtime group, directory 0750/file 0640). Save that local
   path in **Video Service configuration**. Never paste keys into URLs or public logs.
4. Test Connection: require **COMPATIBLE**, schema 001–005, storage/Redis ready and
   available worker capacity. `/health` alone is not this check.
5. Create a Video Lesson, choose Save and display, upload short synthetic media,
   wait for Ready, and verify student playback/completion.

The separate service is required. This is not a ZIP-only Linux installer.
Use [troubleshooting](troubleshooting.md) for setup/readiness failures.
