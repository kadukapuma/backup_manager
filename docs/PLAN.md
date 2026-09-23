# Database Backup Manager — Implementation Plan (Phase 1)

Status: **approved and implemented (Phase 1)**. The build choices are recorded in [DECISIONS.md](DECISIONS.md), later work in [ROADMAP.md](ROADMAP.md).

---

## 0. What I found and what has to happen first

| Finding | Effect | Proposal |
|---|---|---|
| The project folder is **empty**. There is no Laravel project and no git repo. | The brief assumes the React starter kit is already installed. | Step 0 creates it: `composer create-project laravel/react-starter-kit .`, `npm install`, `git init`, first commit. |
| Local PHP is **8.2.12** (from `F:\Programs\PHP`). The brief says 8.3. | Laravel 12 runs on 8.2. Some 8.3-only syntax (typed class constants, `#[\Override]`, `json_validate`) would not run here. | **Option A (recommended):** upgrade local PHP to 8.3 and set `"php": "^8.3"`. **Option B:** write 8.2-compatible code and target 8.3 in production. |
| Not installed on this Windows machine: MariaDB, `mariadb-dump`, `zstd`, `age`, `rclone`, Redis. WSL is available. | The shell pipeline can't run natively on Windows. | Unit and feature tests use `Process::fake()`, so they run anywhere. The optional integration test (`RUN_INTEGRATION=true`) runs on Linux: WSL or the VPS. Dev uses the `database` queue and the `database` cache store, so Redis isn't needed. |
| App's own database in development | The starter kit defaults to SQLite. | SQLite in dev and tests, MariaDB in production. Migrations are kept portable. |

---

## 1. Architecture overview

```
HTTP (Inertia pages) ─► Controller ─► FormRequest ─► Action/Service ─► dispatch Job
                                          │                              │
                                     Policy check                 Queue worker
                                                                          │
Scheduler (routes/console.php) ─every min─► DispatchDueBackupPlans ──────┤
                               ─every 15m─► DiscoverDatabasesJob ────────┤
                               ─daily────► ApplyRetentionJob / prune ────┘
                                                                          ▼
                                           Infrastructure adapters (Symfony Process)
                                           MariaDbClient · DumpPipeline · RcloneClient · AgeCrypto
```

### Folder layout (added to the starter kit's structure)

```
app/
  Actions/            # one class per use case (RunBackupPlan, StartRestore, ApproveDatabase…)
  Data/               # DTOs (readonly classes): DumpResult, Manifest, RetentionPolicy, RemoteObject…
  Enums/              # all statuses/types (backed string enums)
  Http/Controllers/   # thin; one controller per page area
  Http/Requests/      # Form Requests per action
  Http/Middleware/    # IpAllowlist
  Jobs/               # queued work (see §4)
  Models/
  Notifications/      # mail notifications
  Policies/
  Services/
    Backup/           # BackupPipeline, ManifestBuilder, FilenameBuilder
    Restore/          # RestorePipeline, PostCheck
    Discovery/        # DatabaseDiscoverer, RuleMatcher
    Retention/        # GfsRetentionCalculator (pure, unit-tested)
    Storage/          # RcloneClient, RcloneEnvBuilder (per-type config → env vars)
    Database/         # MariaDbClient, DefaultsFile (temp 0600 cnf), DatabaseName validator
    Crypto/           # AgeEncryptor/Decryptor, IdentityFile (temp 0600)
    Tools/            # ToolRegistry: binary paths + version checks
    Audit/            # AuditLogger
  Support/            # Cron helper (next N runs), LockKeys
config/backup-manager.php
resources/js/pages/{dashboard,connections,databases,rules,destinations,plans,runs,restore,notifications,users,audit,settings}/
resources/js/types/models.ts   # TS types mirroring API resources (strict, no any)
```

Other conventions:
- `declare(strict_types=1)` everywhere, Pint (PSR-12 preset from the starter kit), Pest, TypeScript strict.
- Controllers return data through Eloquent **API Resources**, so secrets can't reach Inertia props by accident. Every secret field is sent as `{ is_set: bool, masked: "••••1234" }`. On update, a blank value means "keep the existing one".

---

## 2. Data model

All tables have `id` and timestamps unless noted. The **(enc)** columns use Laravel `encrypted` / `encrypted:array` casts.

| Table | Columns (beyond the spec) / notes |
|---|---|
| `connections` | name, driver enum (`mariadb`,`mysql`; `pgsql` reserved), host, port, username, password **(enc)**, socket?, `new_database_policy` enum (`auto_include`,`pending`), is_active, last_tested_at, last_test_status, last_test_message |
| `databases` | connection_id, name, state enum (`included`,`excluded`,`pending`), state_source enum (`manual`,`rule`,`policy`), size_bytes, table_count, default_charset, default_collation, first_seen_at, last_seen_at, missing_since?, last_success_backup_file_id? (denormalised for the dashboard). Unique (connection_id, name) |
| `selection_rules` | connection_id, type (`include`/`exclude`), pattern (glob), priority (lower number = evaluated first), is_active |
| `destinations` | name, type enum (`local`,`sftp`,`s3`; later `google_drive`,`ftp`, `onedrive`), config **(enc json)**, base_path, is_active, last_tested_at, last_test_status, last_test_message, free_space_bytes?, used_bytes? |
| `backup_plans` | name, connection_id, cron_expression, timezone (default `Asia/Colombo`), compression (`zstd`), compression_level (3), encrypt (true), retention json `{keep_daily,keep_weekly,keep_monthly}`, `all_included_databases` bool (default true), is_active, last_run_at, next_run_at |
| `backup_plan_database` | plan_id, database_id. Used only when `all_included_databases = false` |
| `backup_plan_destination` | plan_id, destination_id |
| `backup_runs` | plan_id?, connection_id, trigger (`scheduled`,`manual`,`pre_restore`), status (`queued`,`running`,`success`,`partial`,`failed`), started_at, finished_at, triggered_by?, summary json (counts, bytes, duration) |
| `backup_files` | run_id, database_id, filename, size_bytes, sha256, md5 (used for S3 remote checks), duration_ms, status (`queued`,`running`,`success`,`failed`), error?, manifest json, log (text, secrets redacted) |
| `backup_copies` | backup_file_id, destination_id, remote_path, status (`pending`,`uploaded`,`verified`,`failed`,`deleted`), verified_at?, error?, deleted_at? |
| `restore_jobs` | backup_file_id, source_destination_id?, target_connection_id, target_database, mode (`replace`,`new_copy`), safety_backup_file_id?, status (`queued`,`safety_backup`,`downloading`,`verifying`,`restoring`,`post_check`,`success`,`failed`), progress_step, started_at, finished_at, requested_by, error?, post_check json, `age_identity` **(enc, nullable, wiped when the job ends)** |
| `verification_runs` | backup_file_id, backup_copy_id?, level (`checksum`,`remote`,`test_restore`), status, details json |
| `notification_channels` | name, type (`mail`; `telegram` reserved), config **(enc json)**, events json, is_active |
| `audit_logs` | user_id?, action, subject_type?, subject_id?, ip, user_agent, meta json, created_at (no updated_at; append-only, no update or delete route) |
| `settings` | key, value json. Holds the age public key, stale threshold hours and dashboard options. Cached. |
| Spatie tables | roles/permissions (published migration) |
| Starter kit | users (+ 2FA columns), jobs, failed_jobs, cache, sessions |

Enums: `ConnectionDriver`, `NewDatabasePolicy`, `DatabaseState`, `StateSource`, `RuleType`, `DestinationType`, `RunTrigger`, `RunStatus`, `BackupFileStatus`, `CopyStatus`, `RestoreMode`, `RestoreStatus`, `VerificationLevel`, `VerificationStatus`, `NotificationEvent`, `AuditAction`, `Role`.

---

## 3. Core pipelines: detailed design

### 3.1 Discovery (`DiscoverDatabasesJob`, per connection, every 15 min + Refresh button)
1. Connect with a runtime-configured PDO connection (`config(['database.connections.bm_target_{id}' => …])`). The password is decrypted in memory only.
2. `SHOW DATABASES`, then drop system schemas and any name that fails `^[A-Za-z0-9_]{1,64}$`. Invalid names are logged as warnings and never stored.
3. Read size, table count, charset and collation from `information_schema.TABLES` / `SCHEMATA`.
4. For a new name, `RuleMatcher` runs over the active rules in priority order and the first glob match wins (state_source=`rule`). If nothing matches, the connection policy applies (state_source=`policy`). A **pending** database triggers the `database.pending` notification.
5. Existing names: update the stats and `last_seen_at`, and clear `missing_since`. Names that have disappeared get `missing_since = now()` if it isn't already set. Nothing is deleted.
6. Manual state changes (`state_source=manual`) are never overwritten by rules.

### 3.2 Backup
`RunBackupPlan` action creates a `backup_run`, creates one `backup_file` row per target database, then dispatches `BackupDatabaseJob` for each one. A `Bus::batch` `finally` callback runs `FinalizeRunJob`.

**`BackupDatabaseJob` (per DB):**
1. `Cache::lock("bm:db:{connection}:{name}", timeout)->get()`. If the lock isn't free, the job is released back to the queue, up to a limit, and then marked failed with "locked by another job". A running restore holds the same lock.
2. Write a temp `--defaults-extra-file` (0600, in `storage/app/private/tmp`), deleted in `finally`.
3. **Stage 1: dump and compress.** `Process::fromShellCommandline` runs under `bash -o pipefail`:
   `"${:DUMP}" --defaults-extra-file="${:CNF}" --single-transaction --quick --routines --triggers --events --no-tablespaces --hex-blob --default-character-set=utf8mb4 -- "${:DB}" | "${:ZSTD}" -T0 -"${:LEVEL}" -q -o "${:TMP}"`
4. **Level-1 check (decision below):** `zstd -t` checks integrity, then `zstd -dc TMP | tail -c 512` must contain `-- Dump completed`. If either check fails, the file is marked failed.
5. **Stage 2: encrypt.** `age -r "${:PUBKEY}" -o "${:PART}" "${:TMP}"`, then rename `.part` to the final `{db}__{YYYYmmdd-HHMMSS}__{trigger}.sql.zst.age`. The plaintext `.zst` is deleted in `finally`.
6. Compute SHA-256 and MD5 with PHP streaming `hash_file`. The configured `sha256sum` binary is used only for the tool check and restore verification. Build `Manifest`: db, connection, created_at (ISO, Asia/Colombo), size, sha256, md5, tool versions, table_count, charset/collation, app version, encrypted/compression flags, age recipient fingerprint. Write it to `…sql.zst.age.manifest.json`.
7. **Upload** to every destination in the plan, one after another inside the job, so the lock covers the whole lifecycle. Uses `rclone copyto` with a per-destination env (`RCLONE_CONFIG_BMDEST_TYPE=sftp`, `…_HOST`, `…_PASS` obscured via `rclone obscure -` over stdin, and so on). `backup_copy` rows are set to uploaded or failed.
8. **Level-2 verification:** `rclone lsjson --hash` on the remote object. Size must match, plus SHA-256 where the backend supports it (local, some sftp) or MD5 for S3 (single-part only; multipart ETags fall back to size + `rclone check --download` when `verify_download=true`). Results go into `verification_runs` and copies are set to `verified`.
9. The local staging file is deleted after all uploads succeed, unless the plan includes a `local` destination, which keeps its own copy.
10. The lock is released in `finally`.

**`FinalizeRunJob`:** sets the run status (all success → `success`; some → `partial`; none → `failed`), fills the summary, runs `ApplyRetentionJob` for the plan's databases, writes the audit log entry and sends `run.failed` / `run.partial` notifications.

**Retention (`GfsRetentionCalculator`, pure function, unit-tested):** input is a list of `(copy_id, created_at)` for one database × one destination (verified copies only), plus `keep_daily/weekly/monthly`. The newest copy for each of the last N days, ISO weeks and months is kept, in Asia/Colombo time. The newest successful copy is **always** kept. Output is the set to delete. `ApplyRetentionJob` runs `rclone deletefile` for the file and its manifest, marks the copy `deleted` and keeps the DB rows for history.

### 3.3 Restore
Wizard: pick database → timeline → pick copy → pick target → confirm (type the target name) → provide the age identity (paste or upload; or skip if `AGE_IDENTITY_FILE` is set in `.env`).

**`RestoreDatabaseJob`:**
1. Takes the lock on the **target** database (and on the source, if they differ) for the whole job.
2. If the target exists, runs a `pre_restore` backup synchronously through the same `BackupPipeline` to the plan's destinations, or to the local staging destination if the database has no plan. **If it fails, the restore aborts.**
3. Download: a local copy is used first (a `local` destination); otherwise `rclone copyto` downloads from the chosen remote.
4. SHA-256 of the download must equal `backup_files.sha256`.
5. The identity is written to a 0600 temp file and `restore_jobs.age_identity` is set to null right away. The temp file is deleted in `finally`.
6. Charset/collation come from the manifest. For the target: `DROP DATABASE IF EXISTS` + `CREATE DATABASE … CHARACTER SET … COLLATE …` (the name is validated and backtick-quoted, via the mariadb client with the defaults file).
7. Import runs as a pipeline: `age -d -i ID FILE | zstd -dc | mariadb --defaults-extra-file=CNF --database=TARGET`.
8. Post-check: the target's table count must match `manifest.table_count`, and the result goes to `post_check`. A mismatch means `failed` with details.
9. Audit log and notification (`restore.success` / `restore.failed`).

Progress: `restore_jobs.status` + `progress_step` are updated at each step. The page uses Inertia v2 `usePoll(3000)` while the job isn't finished. The runs detail page works the same way.

### 3.4 Catalog rebuild (`RebuildCatalogJob`, admin)
`rclone lsjson -R --include "*.manifest.json"` on a destination, then download each manifest, then match or create the `databases` row (connection matched by name; unmatched ones go under a placeholder connection called "Imported (unassigned)"). `backup_files` and `backup_copies` are upserted (idempotent on sha256 + destination) under a synthetic run with trigger `manual` and summary `catalog_rebuild`.

---

## 4. Jobs and scheduler

| Job | Queue | Trigger |
|---|---|---|
| `DispatchDueBackupPlans` (command `bm:dispatch-due`) | – | `everyMinute()->withoutOverlapping()`: plans where `next_run_at <= now`; recalculates `next_run_at` via `dragonmantank/cron-expression` |
| `DiscoverDatabasesJob` | `default` | every 15 min per active connection, plus the Refresh button |
| `BackupDatabaseJob` | `backups` | per DB per run (timeout 6 h, tries 1, not retried automatically) |
| `FinalizeRunJob` | `default` | batch finally |
| `ApplyRetentionJob` | `default` | after a run, plus a daily sweep |
| `RestoreDatabaseJob` | `restores` | wizard |
| `TestConnectionJob` / `TestDestinationJob` | `default` | button (UI polls the result) |
| `RebuildCatalogJob` | `default` | settings |
| `CheckStaleBackups` | – | hourly → `backup.stale` notification (deduplicated per DB per day) |

Supervisor runs separate workers for `backups,restores` and `default`, so a long dump doesn't block tests or discovery.

---

## 5. Security implementation

- **Secrets:** `encrypted` casts; `$hidden` on models; API Resources return masked values only; `Log` context processor redacts known keys; Process error output is passed through `SecretRedactor` before it's stored.
- **Shell:** only Symfony Process; pipelines use `fromShellCommandline` with `"${:NAME}"` placeholders and `bash -o pipefail`; everything else uses array commands. `DatabaseName` value object validates `^[A-Za-z0-9_]{1,64}$` and rejects system schemas. The glob pattern is validated as `^[A-Za-z0-9_*?]{1,64}$`.
- **MariaDB creds:** `DefaultsFile` writes the temp file with `chmod 0600` and deletes it in `finally`.
- **rclone:** `RcloneEnvBuilder` passes env per process, with no config file (`RCLONE_CONFIG=/dev/null`).
- **age:** only the public key is stored. The identity comes from a paste/upload (encrypted column, wiped at job start) or from the `AGE_IDENTITY_FILE` path.
- **AuthZ:** Spatie roles + Policies on every controller action (`authorize`/`can` middleware). Permission matrix:

| Ability | admin | operator | viewer |
|---|:-:|:-:|:-:|
| View everything (except users and audit log) | ✓ | ✓ | ✓ |
| Include/exclude/approve DBs, run backup now, refresh discovery | ✓ | ✓ | – |
| Restore (wizard + confirm) | ✓ | ✓ | – |
| Download a backup file | ✓ | – | – |
| CRUD connections/destinations/plans/rules/notification channels | ✓ | – | – |
| Delete backups manually, rebuild catalog, settings | ✓ | – | – |
| Users & roles, audit log | ✓ | – | – |

- **2FA:** uses the starter kit's Fortify 2FA if it's included (recent versions include it); otherwise Fortify is added. An optional `REQUIRE_2FA=true` sends users without 2FA to the setup page.
- **Audit:** the `AuditLogger` service is called from actions, and a listener on the `Login`/`Failed` auth events. Actions covered: login, login_failed, logout, 2fa changes, run, restore, download, delete, settings change, credential change, user/role change.
- **IP allowlist:** `IpAllowlist` middleware; `BM_IP_ALLOWLIST=1.2.3.4,10.0.0.0/8` (CIDR via `IpUtils`); empty means disabled; trusted proxies are configurable.
- **Locks:** `Cache::lock` on the `database` store in dev and `redis` in prod; the lock key is shared by backup and restore.

---

## 6. Pages (Inertia + React + shadcn/ui)

| # | Page | Key components |
|---|---|---|
| 1 | Dashboard | Health card (green/red + reasons), stale DBs table, last 10 runs, storage per destination (bar), pending DBs (approve inline), failed jobs count |
| 2 | Connections | Table, create/edit dialog, masked password, Test button with status badge |
| 3 | Databases | Connection tabs, search, state toggle, bulk include/exclude/approve, size, last good backup, "Back up now", missing badge |
| 4 | Selection rules | CRUD, drag or numeric priority, live preview (debounced request → matched DB list per rule) |
| 5 | Destinations | Type select → dynamic form (local: path; sftp: host/port/user/pass or key; s3: provider/endpoint/region/bucket/key/secret), Test button |
| 6 | Backup plans | Cron presets + custom, next 5 run times (server-computed), DB selector (all included / pick), destinations multi-select, retention inputs |
| 7 | Runs | Filters (status/trigger/plan/date), detail with per-DB × per-destination matrix, logs, live polling |
| 8 | Restore wizard | Stepper with 5 steps, timeline, confirm-by-typing, progress view |
| 9 | Notifications | Channels (mail), event checkboxes, Send test |
| 10 | Users & roles | CRUD, role select, reset 2FA |
| 11 | Audit log | Filters (user/action/date/subject), meta JSON viewer |
| 12 | Settings | Tool check table (path, found, version), age public key (validated `age1…`), stale threshold, IP allowlist status (read-only from `.env`), Rebuild catalog |

Sidebar navigation follows the starter kit's `AppSidebar`, with items filtered by permissions shared through Inertia props.

---

## 7. Build steps (a commit after each; migrations + Pest + Pint + `tsc --noEmit` must pass)

0. Scaffold the starter kit, git init, confirm 2FA status, set timezone `Asia/Colombo`, add `config/backup-manager.php` and the `.env.example` keys.
1. Roles & permissions (Spatie), policies skeleton, admin seeder from `.env`, IP allowlist middleware, audit log (model + service + auth listeners + page). Users & roles page.
2. Tools layer: `ToolRegistry`, `DefaultsFile`, `DatabaseName`, `SecretRedactor`, Settings page with the tool check and age public key.
3. Connections CRUD + test.
4. Databases + discovery job + selection rules + preview, and the pending notification (mail channel base).
5. Destinations (local/sftp/s3) + `RcloneClient` + test.
6. Backup plans + cron helper + scheduler dispatcher.
7. Backup pipeline (dump, L1 check, encrypt, manifest, upload, L2 verify) + runs pages + "Back up now".
8. Retention (GFS) + deletion.
9. Restore wizard + job + safety backup + post-check.
10. Notifications page (mail channels, events, test) + stale check.
11. Dashboard.
12. Catalog rebuild.
13. Integration test (`RUN_INTEGRATION=true`), README, ROADMAP, DECISIONS, final Pint/tsc/test pass.

---

## 8. Tests (Pest)

- **Unit:** `RuleMatcher` (glob, priority, inactive), `GfsRetentionCalculator` (daily/weekly/monthly boundaries, always keep newest, TZ edges), `FilenameBuilder` / `ManifestBuilder`, `DatabaseName`, cron next-N runs, `RcloneEnvBuilder` (no secrets on the argv), `SecretRedactor`.
- **Feature:** policy matrix per role for every route, CRUD for each resource, discovery with a fake target (mocked `MariaDbClient` interface), `BackupDatabaseJob` / `RestoreDatabaseJob` with `Process::fake()` asserting the command shape (placeholders, no password on the command line, defaults file deleted), lock contention, run status aggregation, audit log entries, secret masking in Inertia props.
- **Integration (opt-in):** real dump → encrypt → local destination → restore into `bm_it_restore_*` against a local MariaDB, using a throwaway age key pair.

---

## 9. Decisions I will record in `docs/DECISIONS.md` (objections welcome now)

1. **Level-1 check:** dump to a *compressed, unencrypted* temp file (0600, private dir), verify with `zstd -t` and the `-- Dump completed` tail, then encrypt with age and delete the temp file. Verifying after encryption isn't possible because the private key isn't on the server, and `tee >(tail)` has a race with process substitution. Cost: temporary disk space of about the compressed size. The data never leaves the server unencrypted.
2. **Upload inside the backup job** (not a separate job), so the per-DB lock covers dump → upload → verify and staging clean-up is deterministic.
3. **age identity at restore time** is stored encrypted in `restore_jobs.age_identity` only until the job starts, then set to null. The queue worker needs it, and there is no other secure hand-off.
4. **S3 remote verification** uses MD5/ETag (S3 has no SHA-256 in rclone). Multipart uploads fall back to size plus an optional download check.
5. **Glob rules**: only `*` and `?` are supported, and matching is case-sensitive, like MariaDB on Linux.
6. **The first matching rule by priority wins**; manual state is never overwritten.

---

## 10. Open questions for you

1. **Scaffold:** OK to create the Laravel 12 React starter kit in this folder with `laravel/react-starter-kit`, since it's currently empty?
2. **PHP version:** upgrade local PHP to 8.3 (recommended), or keep the code compatible with 8.2?
3. **Dev app database:** SQLite for the app's own data in dev/tests (recommended), or a local MariaDB/MySQL?
4. Anything to change in the **role matrix** in §5? In particular, should operators be allowed to download backups?
