# Database Backup Manager

A web panel to discover MariaDB, MySQL and PostgreSQL databases, on this server or on other
servers through SSH, pick which ones to back up, run encrypted backups on schedules to several
storage locations, verify them, and restore them safely.

Built with Laravel 12, Inertia and React (TypeScript, shadcn/ui).

- **Discovery** runs every 15 minutes. New databases (for example a new fixflow tenant)
  follow glob rules like `kreethya_*` / `*_test`, or wait for approval, and trigger an alert.
- **Backups** run `mariadb-dump | zstd` or `pg_dump | zstd`. Before encryption each dump is
  checked with a zstd integrity test and for the dump tool's end marker (`-- Dump completed` /
  `-- PostgreSQL database dump complete`). It is then encrypted with **age**, hashed with
  SHA-256, and gets a JSON manifest.
- **Other servers** are reached through a built-in SSH tunnel. The panel creates a key per
  connection that can only forward to the database port; nothing is installed on the other server.
- **Destinations** are local disk, SFTP and S3-compatible storage, via rclone. Every copy is
  checked remotely (size + SHA-256/MD5).
- **Retention** is grandfather-father-son per destination. The newest good backup is never deleted.
- **Restore wizard**: pick a backup point, pick the copy to read from, then replace the
  original or restore into a new database. A safety backup is taken first, the SHA-256 is
  checked, and the table count is compared afterwards. Progress shows live.
- **Roles** (admin / operator / viewer), 2FA, audit log, optional IP allowlist, and email alerts.

Design notes: [docs/PLAN.md](docs/PLAN.md), [docs/DECISIONS.md](docs/DECISIONS.md),
[docs/ROADMAP.md](docs/ROADMAP.md).

---

## 1. Requirements

| Component | Version / notes |
|---|---|
| PHP | 8.3 (8.2 works). Extensions: `pdo_mysql`, `mbstring`, `openssl`, `intl` (optional), `redis` (production) |
| Composer | 2.x |
| Node.js | 20+ (only to build the frontend; you can build elsewhere and upload `public/build`) |
| App database | MariaDB/MySQL in production (SQLite works for development) |
| Queue / cache | Redis in production (`database` driver in development) |
| Shell | `bash`, and `/bin/sh` must support `set -o pipefail` (true on AlmaLinux) |
| Backup tools | `mariadb-dump`, `mariadb`, `zstd`, `age` (+ `age-keygen`), `rclone` ≥ 1.60, `sha256sum` |
| PostgreSQL (optional) | `pg_dump` and `psql`, same major version as the newest server you back up, or newer |
| SSH (optional) | `ssh`, `ssh-keygen`, `ssh-keyscan` (package `openssh-clients`) |
| Process control | Supervisor (queue workers) and cron (scheduler) |

System settings → **Tool check** shows the path and version of each binary and whether the pipefail check passes.

---

## 2. Local development

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
# set ADMIN_EMAIL / ADMIN_PASSWORD (12+ chars) in .env
php artisan migrate --seed
composer run dev      # web server, queue listener, logs, vite
```

Run the checks:

```bash
./vendor/bin/pest                 # unit + feature tests (all shell tools are faked)
./vendor/bin/pint --test          # PSR-12 / Laravel style
npx tsc --noEmit && npx eslint resources/js
```

### Optional integration test (real dump → encrypt → restore)

This needs a MariaDB server plus the real tools (Linux/WSL). It creates and drops databases named `bm_it_*`.

```bash
RUN_INTEGRATION=true IT_DB_HOST=127.0.0.1 IT_DB_PORT=3306 \
IT_DB_USER=root IT_DB_PASSWORD=secret ./vendor/bin/pest --testsuite=Integration
```

The PostgreSQL version creates and drops `bm_it_pg_*` databases. Add the `IT_SSH_*` values to
run it through an SSH tunnel (see the comment at the top of `tests/Integration/PostgresDumpRestoreTest.php`):

```bash
RUN_PG_INTEGRATION=true IT_PG_HOST=127.0.0.1 IT_PG_PORT=5432 \
IT_PG_USER=bm_backup IT_PG_PASSWORD=secret ./vendor/bin/pest --testsuite=Integration
```

Never run the tests inside a deployed copy whose config is cached (`php artisan config:cache`):
the tests would then use and wipe the real panel database. Use a separate checkout.

---

## 3. `.env` keys

The standard Laravel keys (`APP_*`, `DB_*`, `MAIL_*`, `REDIS_*`) work as usual. These keys are specific to this app:

| Key | Default | Purpose |
|---|---|---|
| `APP_TIMEZONE` | `Asia/Colombo` | Schedules, filenames, retention day boundaries |
| `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | – | The first admin, created by `db:seed` (password ≥ 12 chars) |
| `MARIADB_BIN_DIR` | empty (use `$PATH`) | Directory containing `mariadb-dump` and `mariadb`, e.g. `/usr/local/apps/mariadb114/bin` |
| `BM_MARIADB_DUMP_BIN`, `BM_MARIADB_BIN` | from `MARIADB_BIN_DIR` | Full-path overrides (use `mysqldump`/`mysql` for MySQL servers) |
| `BM_ZSTD_BIN`, `BM_AGE_BIN`, `BM_RCLONE_BIN`, `BM_SHA256SUM_BIN`, `BM_BASH_BIN` | names on `$PATH` | Tool paths |
| `PG_BIN_DIR` | empty (use `$PATH`) | Directory containing `pg_dump` and `psql`, e.g. `/usr/pgsql-17/bin` |
| `BM_PG_DUMP_BIN`, `BM_PSQL_BIN` | from `PG_BIN_DIR` | Full-path overrides |
| `BM_SSH_BIN`, `BM_SSH_KEYGEN_BIN`, `BM_SSH_KEYSCAN_BIN` | names on `$PATH` | SSH tool paths |
| `BM_SSH_CONNECT_TIMEOUT` | `15` | Seconds to wait for an SSH tunnel to come up |
| `BM_STAGING_PATH`, `BM_TMP_PATH` | `storage/app/private/backup-manager/{staging,tmp}` | Private work directories (0700). They need free space of roughly 2× the largest compressed dump |
| `AGE_IDENTITY_FILE` | empty | Optional age private key file for restores. When empty, you paste the key in the wizard |
| `BM_ZSTD_LEVEL` | `3` | zstd level (1–19) |
| `BM_STALE_AFTER_HOURS` | `26` | Default "no recent backup" threshold. Can also be changed in the UI |
| `BM_VERIFY_BY_DOWNLOAD` | `false` | Re-download a copy to check its SHA-256 when the backend reports no hash |
| `BM_IP_ALLOWLIST` | empty (off) | Comma-separated IPs/CIDRs allowed to open the panel, e.g. `203.0.113.10,10.0.0.0/8` |
| `BM_REQUIRE_2FA` | `false` | Force every user to enable 2FA before using the panel |
| `BM_TRUSTED_PROXIES` | empty | Proxy IPs (or `*`) when behind a reverse proxy/Cloudflare, so the IP allowlist and audit log see real client IPs |
| `BM_QUEUE_BACKUPS`, `BM_QUEUE_RESTORES`, `BM_QUEUE_DEFAULT` | `backups`, `restores`, `default` | Queue names |
| `BM_BACKUP_TIMEOUT`, `BM_RESTORE_TIMEOUT` | `21600` | Seconds per database backup / restore |
| `BM_LOCK_SECONDS` | `28800` | Lifetime of the per-database lock |
| `BM_LOCK_WAIT_ATTEMPTS`, `BM_LOCK_WAIT_SECONDS` | `30`, `60` | How long a backup waits for a busy database |
| `APP_VERSION` | git commit | Written into manifests when `.git` is not deployed |

Production values:

```dotenv
APP_ENV=production
APP_DEBUG=false
QUEUE_CONNECTION=redis
CACHE_STORE=redis          # locks need a shared cache; redis or database
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
```

---

## 4. Encryption keys (age)

Backups are encrypted with an age **public key** before they leave the server. The
**private key** is never stored in the database. It is needed only to restore.

```bash
# On your own machine (not the server):
age-keygen -o kreethya-backup.key
# Public key: age1q....   <- paste this into System settings → age public key
```

- Store `kreethya-backup.key` in the company password manager **and** on offline media (at least two copies).
  **Without it, no backup can ever be restored.**
- When restoring, paste the `AGE-SECRET-KEY-1…` line into the wizard. It is kept encrypted only until the job
  starts, then wiped. Alternatively, set `AGE_IDENTITY_FILE` to a 0600 file readable by the worker user.
  This is convenient, but it means someone who takes over the server can also decrypt backups.
- Rotating the key: generate a new pair and save the new public key. Old backups still need the old private key.

---

## 5. MariaDB user for the panel

Discovery and backups only need read access. Restores need to create and drop databases.
Create one dedicated user:

```sql
CREATE USER 'bm_backup'@'localhost' IDENTIFIED BY 'a-long-random-password';

-- backup (read) privileges
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES, PROCESS, SHOW DATABASES
  ON *.* TO 'bm_backup'@'localhost';
-- MariaDB 11.x: allow dumping stored routines
GRANT SHOW CREATE ROUTINE ON *.* TO 'bm_backup'@'localhost';

-- restore privileges (skip if you will never restore through the panel)
GRANT CREATE, DROP, ALTER, INDEX, INSERT, UPDATE, DELETE, REFERENCES,
      CREATE VIEW, CREATE ROUTINE, ALTER ROUTINE, EXECUTE, CREATE TEMPORARY TABLES,
      SET USER
  ON *.* TO 'bm_backup'@'localhost';
FLUSH PRIVILEGES;
```

`SET USER` (MariaDB ≥ 10.5.2) lets restores recreate views, triggers and routines whose
`DEFINER` is another user. For MySQL 8, use `SET_USER_ID` / `SYSTEM_USER` instead.

Add the connection under **Connections**. You can use `127.0.0.1:3306` or the socket path (Webuzo usually uses
`/var/lib/mysql/mysql.sock`), then press **Test**.

---

## 5b. PostgreSQL, and databases on other servers (SSH)

### PostgreSQL client tools on the panel server

`pg_dump` refuses to dump a server with a newer major version, so install the client tools
for the newest PostgreSQL you back up (or newer) from the official PostgreSQL repository.
Only the client package is needed, not a server:

```bash
sudo dnf install -y https://download.postgresql.org/pub/repos/yum/reporpms/EL-9-x86_64/pgdg-redhat-repo-latest.noarch.rpm
sudo dnf -qy module disable postgresql
sudo dnf install -y postgresql17          # client only: pg_dump, psql
/usr/pgsql-17/bin/pg_dump --version
```

Then set `PG_BIN_DIR=/usr/pgsql-17/bin` in `.env` and run `php artisan config:cache`.

### PostgreSQL role for the panel

On the PostgreSQL server:

```sql
CREATE ROLE bm_backup LOGIN PASSWORD 'a-long-random-password';
-- backups (PostgreSQL 14+): read every table in every database
GRANT pg_read_all_data TO bm_backup;
-- restores into a new database
ALTER ROLE bm_backup CREATEDB;
```

Dumps keep the original owners and privileges. A restore replays them, so it needs a role
that may assign those owners: either a superuser (`ALTER ROLE bm_backup SUPERUSER;`), or a
role that is a member of every owner role. Restores **over** an existing database also end its
open sessions and drop it, which needs ownership of the database or superuser. If the panel
role cannot do this, restore into a **new** database and switch the application over by hand.

Make sure `pg_hba.conf` allows the role to log in with a password (`scram-sha-256`) from
`127.0.0.1`. The SSH tunnel arrives from there.

### Databases on another server (SSH tunnel)

The panel connects to the other server over SSH and forwards one local port to the database.
The database port stays closed to the internet, and nothing is installed on the other server.

1. **On the other server**, create a user that only exists for the tunnel:
   ```bash
   sudo useradd -m -s /sbin/nologin bmtunnel
   sudo install -d -m 700 -o bmtunnel -g bmtunnel /home/bmtunnel/.ssh
   ```
2. **In the panel**, add a connection: driver, database host/port **as seen from that server**
   (usually `127.0.0.1` and `5432` / `3306`), database user and password. Turn on
   **Connect through SSH** and enter the server address, SSH port and `bmtunnel`. Save.
3. The connection card shows an `authorized_keys` line that starts with
   `restrict,port-forwarding,permitopen="127.0.0.1:5432"`. Put that line into
   `/home/bmtunnel/.ssh/authorized_keys` on the other server:
   ```bash
   sudo nano /home/bmtunnel/.ssh/authorized_keys      # paste the line
   sudo chown bmtunnel:bmtunnel /home/bmtunnel/.ssh/authorized_keys
   sudo chmod 600 /home/bmtunnel/.ssh/authorized_keys
   sudo restorecon -Rv /home/bmtunnel/.ssh           # SELinux (AlmaLinux)
   ```
   With these options the key can only open a tunnel to that one address. It cannot run
   commands, open a shell or forward anywhere else.
4. Press **Test**. The first test pins the server's SSH host key and shows its fingerprint.
   Compare it with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on that server. After
   that the panel refuses to connect if the key changes. If you reinstall the server, edit the
   connection and choose "Forget the pinned host key".
5. Press **Refresh** to discover the databases, include them, and add the connection to a plan.

If `sshd_config` has `AllowUsers` or `AllowGroups`, add `bmtunnel` there. `AllowTcpForwarding`
must not be `no`.

---

## 6. Installing the tools on AlmaLinux 9

```bash
sudo dnf install -y epel-release
sudo dnf install -y zstd age rclone supervisor redis bash coreutils
sudo systemctl enable --now redis supervisord
```

If the EPEL `rclone` is older than 1.60, install the official build:
`curl https://rclone.org/install.sh | sudo bash`.

On Webuzo, MariaDB 11.4 client tools are in `/usr/local/apps/mariadb114/bin`, so set
`MARIADB_BIN_DIR` to that directory. Check them with `/usr/local/apps/mariadb114/bin/mariadb-dump --version`.

---

## 7. Deployment on Webuzo / AlmaLinux

The example assumes the app runs as the Webuzo user `kreethya` at `/home/kreethya/apps/backup-manager`,
served on `backup.kreethya.com`.

1. **PHP 8.3**: in Webuzo, install PHP 8.3 and enable `pdo_mysql`, `mbstring`, `openssl`, `redis`, `intl`.
   The CLI is usually `/usr/local/apps/php83/bin/php`. Use it everywhere below as `php`.
2. **Code**
   ```bash
   cd /home/kreethya/apps
   git clone <repo-url> backup-manager && cd backup-manager
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build          # or build locally and upload public/build
   ```
3. **App database**: in Webuzo, create a MariaDB database and user for the panel itself (for example `bm_app`).
   This is not the backup user from section 5.
4. **Environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   # edit .env: APP_URL, DB_*, REDIS_*, MAIL_*, ADMIN_*, MARIADB_BIN_DIR, BM_IP_ALLOWLIST ...
   chmod 600 .env
   php artisan migrate --force
   php artisan db:seed --force      # roles, permissions and the admin user (safe to re-run)
   php artisan storage:link
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
   Back up `APP_KEY` somewhere safe as well. It encrypts the stored connection and destination passwords.
5. **Permissions**: the web server user and the queue worker must run as the same user (`kreethya`).
   ```bash
   chmod -R u+rwX,go-rwx storage bootstrap/cache
   ```
6. **Web**: in Webuzo, add the domain `backup.kreethya.com` with document root
   `/home/kreethya/apps/backup-manager/public`, and enable HTTPS (Let's Encrypt).
7. **Queue workers (Supervisor)**: `/etc/supervisord.d/backup-manager.ini`
   ```ini
   [program:bm-backups]
   command=/usr/local/apps/php83/bin/php /home/kreethya/apps/backup-manager/artisan queue:work redis --queue=backups,restores --sleep=3 --tries=1 --timeout=22000 --memory=512
   user=kreethya
   numprocs=2
   process_name=%(program_name)s_%(process_num)02d
   autostart=true
   autorestart=true
   stopwaitsecs=22100
   stdout_logfile=/home/kreethya/apps/backup-manager/storage/logs/worker-backups.log
   redirect_stderr=true

   [program:bm-default]
   command=/usr/local/apps/php83/bin/php /home/kreethya/apps/backup-manager/artisan queue:work redis --queue=default --sleep=3 --tries=1 --timeout=3700
   user=kreethya
   numprocs=1
   process_name=%(program_name)s_%(process_num)02d
   autostart=true
   autorestart=true
   stopwaitsecs=3800
   stdout_logfile=/home/kreethya/apps/backup-manager/storage/logs/worker-default.log
   redirect_stderr=true
   ```
   ```bash
   sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
   ```
   `numprocs` on `bm-backups` is how many databases are dumped in parallel.
   The worker `--timeout` must be larger than `BM_BACKUP_TIMEOUT`.
   On Redis, also set `retry_after` in `config/queue.php` (`REDIS_QUEUE_RETRY_AFTER`) above the timeout.
8. **Scheduler (cron)**: `crontab -e` as `kreethya`:
   ```cron
   * * * * * cd /home/kreethya/apps/backup-manager && /usr/local/apps/php83/bin/php artisan schedule:run >> /dev/null 2>&1
   ```
   It runs these jobs:
   - discovery every 15 minutes
   - due plans every minute
   - stale-backup alerts hourly
   - stuck-job reaper hourly
   - retention sweep daily at 04:30
9. **First login**:
   - Sign in as the admin and enable 2FA (Settings → Two-factor auth).
   - Paste the age public key in System settings and check the Tool check.
   - Add a connection, destinations (at least one off-server), a notification channel, then a backup plan.
   - Press **Run now** once and open the run to confirm every copy is *verified*.

### Updating

```bash
cd /home/kreethya/apps/backup-manager
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force && php artisan db:seed --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

---

## 8. Disaster recovery without the panel

Every backup is a normal file, so you can restore by hand:

```bash
# fetch a copy (or copy it from the local backup directory)
rclone copy remote:backups/vps_main/shop/shop__20260923-020000__scheduled.sql.zst.age .
sha256sum shop__*.age          # compare with the sha256 in the .manifest.json next to it

mariadb -e "CREATE DATABASE shop_recovered CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
age -d -i kreethya-backup.key shop__20260923-020000__scheduled.sql.zst.age \
  | zstd -dc | mariadb shop_recovered
```

For a PostgreSQL backup, replace the last line with:

```bash
createdb -T template0 -E UTF8 shop_recovered
age -d -i kreethya-backup.key shop__*.sql.zst.age | zstd -dc | psql -v ON_ERROR_STOP=1 --single-transaction -d shop_recovered
```

The manifest's `engine` field says which kind of dump a file is (`pgsql`, or `mariadb`/`mysql`;
missing in older manifests means MariaDB).

On a new server, install the panel, add the old destinations, then use **System settings → Rebuild catalog**.
All old backups then appear in the restore wizard.

Remote layout: `<base path>/<connection-slug>/<database>/<db>__<YYYYmmdd-HHMMSS>__<trigger>.sql.zst.age` (+ `.manifest.json`).

---

## 9. Roles

| | admin | operator | viewer |
|---|:-:|:-:|:-:|
| View dashboard, databases, runs, plans, destinations | ✓ | ✓ | ✓ |
| Include / exclude / approve databases, refresh discovery | ✓ | ✓ | – |
| Back up now, run a plan now | ✓ | ✓ | – |
| Restore | ✓ | ✓ | – |
| Download backup files (local copies) | ✓ | – | – |
| Delete backups, rebuild catalog, system settings | ✓ | – | – |
| Manage connections, destinations, plans, rules, notifications | ✓ | – | – |
| Users & roles, audit log | ✓ | – | – |
