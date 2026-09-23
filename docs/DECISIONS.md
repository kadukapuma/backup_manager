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
