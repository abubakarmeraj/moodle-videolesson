# Advanced native/systemd installation

Use a **clean dedicated Ubuntu 24.04** host with systemd PID 1, sudo/root and
Python 3.12. WSL2 was qualified; an ordinary Ubuntu VM/server run remains pending.
The installer intentionally refuses an unowned existing MariaDB installation,
application directories or role accounts. On an existing Moodle host use Compose
or a separate service host; never delete the existing DB to bypass this refusal.

From the extracted service archive's `video-lesson/` root, provision HTTPS storage
and the protected policy described in [Docker](docker.md) and [storage](storage.md):

```sh
sudo bash deploy/installer/install.sh --config /protected/operator-policy.json --check
sudo bash deploy/installer/install.sh --config /protected/operator-policy.json
sudo /opt/video-lesson/current/.venv/bin/python deploy/installer/check.py
```

The installer verifies the RC1 service manifest, installs dependencies and locked
Python requirements, creates separate runtime/migrator users, restricted Redis
ACLs and service DB accounts, runs migrations 001–005, and creates systemd roles
and a 15-minute janitor timer. Storage secrets use systemd LoadCredential.

Configuration is root-protected under `/etc/video-lesson`; application release
and venv are under `/opt/video-lesson`; private writable staging under
`/var/lib/video-lesson`. API/gateway bind loopback **18090/18091**. Configure a
trusted TLS proxy independently. Copy only the six-field handoff into the
protected Moodle-host file and Test Connection as in [Docker](docker.md).

Rerunning the same source/policy retains identity. `--reconfigure` is explicit,
not routine rotation; take matched recovery first. Changed source requires an
explicit upgrade plan, not an automatic overwrite of `current`. Logs must be
sanitized. `--qualification-only` intentionally has unusable storage and is not
an installation that teachers can upload to.
