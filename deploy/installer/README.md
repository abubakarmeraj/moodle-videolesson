# Native/systemd deployment

See [native Linux installation](../../docs/native-linux.md).
Ubuntu24.04 clean dedicated host, systemd, Python3.12 and explicit operator policy.
Reviewed tuple: plugin3.1.0-rc1, service0.5.0-rc1, protocol1/schema001–005.
The installer verifies docs/release/SERVICE-MANIFEST.json using IDENTITY.json.
It refuses unowned existing databases and silent changed-source upgrades.

Same-source rerun retains generated identity/secrets. Protected handoff and trusted
HTTPS are required; `--qualification-only` is deliberately not media-capable.
