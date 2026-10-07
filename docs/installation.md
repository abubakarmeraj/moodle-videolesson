# Requirements and installation

RC1 targets **Moodle 5.2**, plugin requires build version 2026042000 or newer.
Fresh Moodle **5.2.2**, PHP **8.3.6**, MariaDB **10.11.14** and Ubuntu **24.04**
under WSL2 were qualified. This does not certify every PHP release, Moodle version
or ordinary Linux VM. Moodle retains its own supported database configuration.

The service needs Python **3.12**, its locked dependencies, a separate MariaDB
10.11 schema, Redis, private S3-compatible storage, trusted HTTPS API/gateway/storage
endpoints and private disk-backed worker staging. Protocol **1**, migrations **001–005**.
FFmpeg/FFprobe require H.264/libx264 and AAC. Qualified native FFmpeg was **6.1.1**;
the reviewed Compose worker uses **5.1.9**. The container uses pinned Python/Debian,
MariaDB, Redis 7.2 and Nginx image inputs; no images are published by this repository.

The qualified Docker environment used Engine **29.1.3**, Compose v2 **2.40.3**.
Use a supported Docker Engine/Compose v2 installation and review compatibility when
changing pinned images/packages. There is no supplied Moodle or storage-server container.

1. Install the Moodle ZIP normally. Component is `mod_videolesson`, installed at
   Moodle's `mod/videolesson` (under `public/` where the Moodle layout uses it).
2. Use [Compose](docker.md), recommended even when Moodle already shares the host.
   [Native Linux](native-linux.md) refuses an unowned existing database installation.
3. Configure the protected handoff and run Test Connection, then a small-media smoke.

Allocate staging from actual source size/duration and rendition policy. A large
upload setting is not a disk-capacity or processing-speed promise. See
[configuration](configuration.md) and [known limitations](known-limitations.md).
