# Roadmap

Built in Phase 2: PostgreSQL support (`pg_dump` / `psql`) and remote database servers over an
SSH tunnel (see D24–D26 in DECISIONS.md).

Designed for, not built yet:

- Google Drive and OneDrive destinations (rclone backends `drive`, `onedrive`). `DestinationType` already reserves them.
- FTP destination.
- Telegram notifications (`notification_channels.type = telegram` is reserved).
- Automatic weekly test restores (`verification_runs.level = test_restore`).
- PostgreSQL `--format=custom` dumps with parallel `pg_restore -j` for very large databases, and a
  `pg_dumpall --globals-only` backup of roles per server.
- Per-connection restore that skips owners/privileges (`--no-owner`) for restoring into a server
  where the original roles do not exist.
- Agent mode for servers the panel cannot reach over SSH (behind NAT), uploading directly to storage.
- Website file backups (directories, not databases).
- MariaDB binlog point-in-time recovery.
- Multi-tenant SaaS mode.
- Public REST API with tokens.
- Download remote (SFTP/S3) copies through the browser via a queued "prepare download" job.
- Periodic re-verification of stored copies (remote hash check for `uploaded` copies, and for copies imported by a catalog rebuild).
- Retry or flush failed queue jobs from the dashboard (today: `php artisan queue:failed` / `queue:retry`).
- Optional separate restore credentials per connection, so the everyday backup user can stay read-only.
- Per-plan choice of compression level and parallelism.
