# Single-server deployment boundaries

The Moodle plugin is separate from Video Service roles. Compose is recommended
when a host already runs Moodle/MariaDB; native installation requires a clean
dedicated host. No installer edits an existing Moodle database or Redis setup.

API/gateway listen behind trusted HTTPS; service MariaDB and Redis are private.
Runtime roles have DML only, migrator DDL; Redis ACLs constrain each role to the
generated tenant namespace. Worker capacity heartbeat is not aggregate autoscaling.
Use private disk staging, one video job, two codec threads and explicit CPU/memory
headroom. Janitor cadence15minutes; cleanup remains safety/retention gated.

Configuration/credentials remain outside application source. Backups must match
Moodle references, service database, tenant/subject identity and object inventory.
Never clone one writable tenant into two competing sites. See public installation,
configuration and recovery docs for exact operator procedures.
