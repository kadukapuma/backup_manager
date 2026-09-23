# Roadmap (after Phase 1)

Designed for, not built yet:

- Google Drive and OneDrive destinations (rclone backends `drive`, `onedrive`). `DestinationType` already reserves them.
- FTP destination.
- Telegram notifications (`notification_channels.type = telegram` is reserved).
- Automatic weekly test restores (`verification_runs.level = test_restore`).
- PostgreSQL support (`ConnectionDriver::Pgsql`: `pg_dump` / `psql` adapters).
- Remote database servers over an SSH tunnel.
- Website file backups (directories, not databases).
- MariaDB binlog point-in-time recovery.
- Multi-tenant SaaS mode.
- Public REST API with tokens.
- Download remote (SFTP/S3) copies through the browser via a queued "prepare download" job.
