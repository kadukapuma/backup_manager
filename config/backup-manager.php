<?php

declare(strict_types=1);

$mariadbBinDir = rtrim((string) env('MARIADB_BIN_DIR', ''), '/');
$mariadbBin = static fn (string $name): string => $mariadbBinDir !== '' ? $mariadbBinDir.'/'.$name : $name;
$pgBinDir = rtrim((string) env('PG_BIN_DIR', ''), '/');
$pgBin = static fn (string $name): string => $pgBinDir !== '' ? $pgBinDir.'/'.$name : $name;

return [

    /*
    | Paths to the external tools. Bare names are resolved through $PATH.
    */
    'binaries' => [
        'bash' => env('BM_BASH_BIN', 'bash'),
        'mariadb_dump' => env('BM_MARIADB_DUMP_BIN', $mariadbBin('mariadb-dump')),
        'mariadb' => env('BM_MARIADB_BIN', $mariadbBin('mariadb')),
        'zstd' => env('BM_ZSTD_BIN', 'zstd'),
        'age' => env('BM_AGE_BIN', 'age'),
        'rclone' => env('BM_RCLONE_BIN', 'rclone'),
        'sha256sum' => env('BM_SHA256SUM_BIN', 'sha256sum'),
        'pg_dump' => env('BM_PG_DUMP_BIN', $pgBin('pg_dump')),
        'psql' => env('BM_PSQL_BIN', $pgBin('psql')),
        'ssh' => env('BM_SSH_BIN', 'ssh'),
        'ssh_keygen' => env('BM_SSH_KEYGEN_BIN', 'ssh-keygen'),
        'ssh_keyscan' => env('BM_SSH_KEYSCAN_BIN', 'ssh-keyscan'),
    ],

    /*
    | SSH tunnels to remote database servers. Seconds to wait for the tunnel's
    | local port to accept connections.
    */
    'ssh' => [
        'connect_timeout' => (int) env('BM_SSH_CONNECT_TIMEOUT', 15),
    ],

    /*
    | Working directories. Staging holds finished encrypted files until they are
    | uploaded; tmp holds short-lived secrets (defaults files, age identities)
    | and intermediate dumps. Both are created with 0700 permissions.
    */
    'staging_path' => env('BM_STAGING_PATH', storage_path('app/private/backup-manager/staging')),
    'tmp_path' => env('BM_TMP_PATH', storage_path('app/private/backup-manager/tmp')),

    /*
    | Path to an age identity (private key) file used for restores. When empty,
    | the private key must be pasted or uploaded in the restore wizard.
    */
    'age_identity_file' => env('AGE_IDENTITY_FILE'),

    'compression_level' => (int) env('BM_ZSTD_LEVEL', 3),

    /*
    | A database whose newest successful backup is older than this is "stale".
    | Can be overridden on the Settings page.
    */
    'stale_after_hours' => (int) env('BM_STALE_AFTER_HOURS', 26),

    /*
    | Comma-separated IPs / CIDR ranges allowed to reach the panel. Empty = off.
    */
    'ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('BM_IP_ALLOWLIST', ''))))),

    'require_two_factor' => (bool) env('BM_REQUIRE_2FA', false),

    'queues' => [
        'backups' => env('BM_QUEUE_BACKUPS', 'backups'),
        'restores' => env('BM_QUEUE_RESTORES', 'restores'),
        'default' => env('BM_QUEUE_DEFAULT', 'default'),
    ],

    'timeouts' => [
        'backup' => (int) env('BM_BACKUP_TIMEOUT', 6 * 3600),
        'restore' => (int) env('BM_RESTORE_TIMEOUT', 6 * 3600),
        'command' => (int) env('BM_COMMAND_TIMEOUT', 300),
    ],

    /*
    | Lock lifetime for "one job per database" locks. Must exceed the longest
    | backup or restore.
    */
    'lock_seconds' => (int) env('BM_LOCK_SECONDS', 8 * 3600),

    /*
    | How many times a backup job waits for a busy database lock before failing.
    */
    'lock_wait_attempts' => (int) env('BM_LOCK_WAIT_ATTEMPTS', 30),
    'lock_wait_seconds' => (int) env('BM_LOCK_WAIT_SECONDS', 60),

    /*
    | Re-download each uploaded file and compare SHA-256 when the remote cannot
    | report a comparable hash (e.g. S3 multipart uploads). Slower but stricter.
    */
    'verify_by_download' => (bool) env('BM_VERIFY_BY_DOWNLOAD', false),

    'discovery_interval_minutes' => 15,

    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    // MariaDB/MySQL system schemas, plus PostgreSQL's maintenance and template databases.
    'system_databases' => ['information_schema', 'performance_schema', 'mysql', 'sys', 'postgres', 'template0', 'template1'],

    'app_version' => env('APP_VERSION', 'unknown'),
];
