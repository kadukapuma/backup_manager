# Decisions

Choices made where the brief left room. Newest at the bottom.

## D1. Project scaffolding
The folder was empty, so the Laravel 12 React starter kit was created with
`composer create-project laravel/react-starter-kit`. The installed release had no 2FA
and used PHPUnit, so Laravel Fortify (2FA only) and Pest were added.

## D2. PHP version
Production targets PHP 8.3. The code avoids 8.3-only syntax (typed class constants,
`json_validate`, `#[\Override]`) so the test suite also runs on the PHP 8.2 dev machine.
`composer.json` keeps `"php": "^8.2"`.

## D3. App database
SQLite in development and tests, MariaDB in production. Migrations avoid
engine-specific column types.

## D4. No public registration
Public registration and the welcome page were removed. Admins create users on the
Users page. `/` redirects to the dashboard.

## D5. Fortify is used only for 2FA
`Fortify::ignoreRoutes()` is called, and only the 2FA endpoints are registered by hand.
Login stays in the starter kit's controller: after the password is checked, a user
with confirmed 2FA is sent to Fortify's `two-factor-challenge`.

## D6. Downloads are admin-only
Operators can run backups and restores but cannot download backup files.

## D7. Model names
The table `connections` is backed by the model `ServerConnection`, and its relation is
`serverConnection()`. This avoids a clash with Eloquent's built-in `$connection` property.

## D8. Shell execution through Laravel's Process facade
All external commands go through `Illuminate\Support\Facades\Process`, which wraps
Symfony Process and supports `Process::fake()` in tests:
- Commands without a pipe are passed as arrays (no shell).
- Pipelines are strings run by Symfony's `fromShellCommandline()`. They use named
  placeholders (`"${:DB}"`) whose values come from the process environment, so user
  input is never concatenated into the command string.
- Pipelines start with `set -o pipefail`, so `/bin/sh` must support it. On AlmaLinux
  `/bin/sh` is bash. The System settings tool check verifies this.

## D9. age public key validation
A key must match `^age1[bech32 charset]{58}$`. The key is stored in the `settings` table,
not in `.env`, so admins can rotate it from the UI. The change is audit-logged.

## D10. Metadata over PDO, dumps over CLI
Discovery, the connection test, existence checks and table counts use PDO with a
5-second timeout, so no process and no password on a command line. Dumps and imports
use `mariadb-dump` / `mariadb` with a temporary `--defaults-extra-file`.

## D11. Connection test runs in the request
"Test connection" is a single `SELECT VERSION()` with a 5-second connect timeout, so it
runs synchronously and shows the result right away. Discovery, backups, uploads,
verification and restores are always queued.

## D12. Table count definition
`table_count` counts `BASE TABLE` rows only (views excluded), both in manifests and in
the post-restore check, so the numbers can be compared.

## D13. Rules and manual state
New databases get the state of the first matching rule, otherwise the connection's
policy. Rules never override a state set by hand. "Apply to existing" re-evaluates only
databases whose state came from a rule or the policy. "Use rules" on the Databases page
turns a manual choice back into an automatic one.

## D14. rclone configuration
Each rclone process gets `RCLONE_CONFIG=/notfound` (in-memory config only) plus
`RCLONE_CONFIG_BMDEST_*` variables built from the encrypted destination config.
Passwords go through `rclone obscure -` on stdin, never as an argument. Remote layout:
`<base_path>/<connection>/<database>/<file>`. For S3, the bucket is the first path segment.

## D15. Destination test is queued
"Test" writes a random 32-byte file, checks its size, reads it back, deletes it and
records free space (`rclone about`, where the backend supports it). It runs as a queued
job, and the page polls every 3 seconds while a test is running.

## D16. Level-1 verification happens before encryption
The server only has the age public key, so it cannot decrypt a finished backup. The
dump is therefore written compressed to a temporary file in the private tmp directory
(0700). It is checked with `zstd -t`, and `zstd -dc | tail -c 1024` must contain
`-- Dump completed`. Only then is it encrypted with `age -r`. The unencrypted file is
deleted in a `finally` block and never leaves the server.
`tee >(tail …)` was rejected: the process substitution can finish after the main
pipeline, so the check could race.

## D17. Upload and verification run inside the backup job
The per-database lock covers dump → upload → verify, so a restore can never overlap an
upload. Level-2 verification compares the size, then SHA-256 if the backend reports it
(local, most SFTP), otherwise MD5 (S3; rclone stores MD5 metadata for multipart
uploads). If neither hash is available the copy stays `uploaded` (size only), unless
`BM_VERIFY_BY_DOWNLOAD=true`, which downloads the copy and compares SHA-256.

## D18. When is a backup "successful"
A backup file is `success` when the dump passed level-1 checks and at least one
destination stored it. If no destination accepted it, the encrypted file stays in
staging for manual recovery and the file is marked `failed`. A run is `success` when
every file and copy succeeded, `failed` when no file succeeded, and `partial` otherwise.

## D19. Missed schedules
A plan runs at most once per dispatcher tick, however many slots were missed while the
server was down. `next_run_at` is moved forward before the run starts. Jobs killed without
reporting are marked failed by `backup-manager:reap-stuck` (hourly).

## D20. Downloads
Admins can download a backup only from a local-disk destination (the encrypted `.age`
file is streamed). Remote copies are fetched on the server with rclone (see README).
Pulling remote copies through the browser is listed in ROADMAP.

## D21. Retention semantics
GFS windows are counted back from "now" in the app timezone:
- the newest backup of each of the last N days
- the newest backup of each of the last N ISO weeks
- the newest backup of each of the last N months

The newest successful backup is always kept. Retention runs per database and per
destination. The policy for a pair comes from the active plans that cover the database
and use that destination; when plans overlap, the largest count of each kind wins. Copies
on destinations that no active plan covers are never pruned automatically. Retention
takes the database lock, so it never deletes a copy that a running restore may be
reading. It runs after every successful run and again daily at 04:30. Deleted copies stay
in the catalog with `status=deleted`.

## D22. Restore safety rules
- **Replace** restores only over the database the backup came from, on the same connection.
  **New copy** needs a name that does not exist yet, so nothing is overwritten by accident.
- When the target exists, a `pre_restore` backup runs inline in the restore job. The job
  already holds the lock, so it cannot wait for a separate job. It is stored on the
  destinations of the plans that cover the database, or on every active destination.
  If it cannot be stored anywhere, the restore aborts before anything is dropped.
- MariaDB has no atomic `RENAME DATABASE`, so replace means drop + recreate + import.
  The safety backup is the way back.
- The restore takes the lock on the target and on the source database, so backups and
  retention cannot touch either one while it runs.
- Copies are tried in this order: the chosen copy, then local copies, then the rest. Each
  download is checked against the catalog SHA-256 before decryption.
- The post-check compares the number of base tables with the manifest.
- A pasted age identity is reduced to its `AGE-SECRET-KEY-1…` line and stored with the
  `encrypted` cast only until the job starts. The job moves it to a 0600 temp file and
  deletes that file in `finally`. The hourly reaper wipes keys of restores that never
  started.

## D23. Catalog rebuild
"Rebuild catalog" lists a destination recursively and reads every `*.manifest.json`. It
imports a file only when:
- the manifest is valid,
- the database name passes validation,
- the data file sits next to the manifest, and
- the file's size matches the manifest.

Files are matched by database + SHA-256, so running it again creates nothing new.
Connections are matched by name. Unknown connection names go to an inactive placeholder
connection called "Imported (unassigned)", so their backups can still be restored into
any active connection with "new copy". Imported copies are `uploaded` (not `verified`)
until they are checked again. Each rebuild is recorded as a run whose summary holds the
counts and the skip reasons.

## D24. PostgreSQL dumps
- `pg_dump --format=plain | zstd`, the same shape as MariaDB, so staging, encryption,
  verification, retention, catalog and restore work unchanged. Plain SQL ends with
  `-- PostgreSQL database dump complete`, which is the level-1 completeness check. (The custom
  format would allow parallel restores but has no reliable end marker to check before
  encryption; see ROADMAP.)
- Owners and privileges are kept in the dump, so a restore on the same server gives back an
  identical database. Restoring therefore needs a role that may assign those owners.
- Metadata comes from `psql` (unaligned output), not `pdo_pgsql`, so the panel's PHP needs no
  extra extension. Table counts use `pg_class` (relkind `r`/`p`) because
  `information_schema.tables` hides tables the role has no rights on.
- The password is passed in a temporary 0600 `PGPASSFILE`, never on a command line or in
  `PGPASSWORD`. `-w` makes psql fail instead of prompting.
- Restores: end the sessions on the database (`pg_terminate_backend`), `DROP DATABASE`,
  `CREATE DATABASE … TEMPLATE template0` with the manifest's encoding and locale (each as a
  separate `-c`, because these cannot run in a transaction), then import with
  `psql -v ON_ERROR_STOP=1 --single-transaction`. A failed statement rolls back the whole import.
- `postgres`, `template0` and `template1` count as system databases and are never backed up.
- Manifests record `engine`. A backup is only restored into a connection of the same family
  (PostgreSQL vs MariaDB/MySQL); the wizard only lists matching servers and the request checks it.

## D25. SSH tunnels to other servers
- Pull model: the panel opens `ssh -N -L 127.0.0.1:<free port>:<db host>:<db port>` for each
  operation (test, discovery, dump, recreate, import, table count) and closes it afterwards.
  The database code gets a copy of the connection that points at the local port, so the
  MariaDB and PostgreSQL code paths are the same with and without SSH.
- Per connection, the panel generates an ed25519 key with `ssh-keygen`. The private key is
  stored with the `encrypted` cast (APP_KEY) and written to a 0600 temp file only while ssh
  runs. The UI shows an `authorized_keys` line with
  `restrict,port-forwarding,permitopen="<db host>:<db port>"`, so a stolen key can only reach
  that one port.
- Host keys: trust on first use. The first **Test** runs `ssh-keyscan` and stores the keys;
  every tunnel then runs with `StrictHostKeyChecking=yes` and only that known_hosts file.
  Changing the SSH host or port forgets the pinned keys; so does the "forget" switch in the form.
- ssh runs with `-F /dev/null`, `BatchMode=yes`, `IdentitiesOnly=yes`, `IdentityAgent=none`,
  `ExitOnForwardFailure=yes` and keep-alives, so user config, agents and prompts never change
  what happens.
- Readiness check: ssh binds the local port only after authentication, so the panel waits until
  it can no longer bind that port itself. It never connects to the port, because MariaDB counts
  half-open connections against `max_connect_errors`. If another process takes the port in the
  meantime, it retries with a new port.
- Unix sockets are not supported through SSH; use the host/port as seen from the SSH server.

## D26. Why not an agent (yet)
For servers the operator controls, SSH needs nothing installed on the target and no inbound
port besides SSH. An agent that dumps locally and uploads directly to storage is the answer
for servers behind NAT or with very large databases, and is on the roadmap.
