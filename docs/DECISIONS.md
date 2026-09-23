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
