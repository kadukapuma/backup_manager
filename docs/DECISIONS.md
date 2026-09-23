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
