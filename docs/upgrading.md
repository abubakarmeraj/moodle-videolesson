# Upgrading

Keep a matched recovery point of Moodle data, service MariaDB, protected generated
configuration and both buckets. Do not restore only one side of references/accounting.

RC1 coordinates plugin **2026100701 / 3.1.0-rc1** with service **0.5.0-rc1**,
protocol1/schema001–005. The previous development plugin/service tuple is not the
advertised RC1 tuple. Stop runtime roles during controlled source replacement;
preserve DB/Redis identities, secrets, tenant and policy. Independently verify
artifact manifests before replacement. Do not regenerate an installation handoff.

From plugin2026100700, Moodle runs a version savepoint only; no new columns/tables
or media mutation. Purge Moodle caches normally after source replacement. Service
0.5.0-dev.1 → 0.5.0-rc1 changes release/license metadata, not schema: migrations
remain001–005, no006. Run the supported migrator to verify existing schema; do not
manually edit version rows. Earlier plugin versions may have real supported upgrades.

Compose: use the new extracted bundle and existing `VIDEO_CONFIG` directory,
rebuild/start using the documented overlay. Native: stop roles, prepare a new
verified release/venv and explicitly switch `current` after matched backup; the
installer intentionally refuses a silent changed-source adoption. There is no
automatic in-place native upgrade command in RC1.

Require Test Connection and one small upload/playback/completion smoke before
resuming normal use. Existing ready media need no retranscode, reseed or URL changes.
The new original plugin artwork uses stable filenames; normal Moodle/browser
asset cache refresh may be needed. Never bypass Moodle downgrade protection.
