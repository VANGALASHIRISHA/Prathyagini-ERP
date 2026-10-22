# Local license bypass (development only)

This lets you run and click through the full ERP on your own computer with
XAMPP, without a licence activated against the central licence server. It is
**off by default** and only ever takes effect on `localhost` / `127.0.0.1` /
`::1` — never on a public domain.

## How it works

1. `.env` has `APP_LOCAL_LICENSE_BYPASS=true` (or `false` to disable).
2. `config.php` reads that into `config('license')['local_development_bypass']`,
   defaulting to `false` if the variable is missing, empty, or not a
   recognised boolean.
3. `app/license_client.php`, inside `license_enforce_request()`, calls
   `license_local_bypass_active()`. That returns `true` only when **both**:
   - the config flag is `true`, **and**
   - the current request's `Host` header resolves to `localhost`, `127.0.0.1`,
     or `::1` (checked via `license_request_hostname()`, which also handles
     the bracketed IPv6 form `[::1]:8000`).
4. If the bypass is active, `license_enforce_request()` returns immediately —
   same as it already does for `login.php`, `setup_admin.php`, etc. Every
   other licence state (`UNLICENSED`, `SUSPENDED`, `REVOKED`, `EXPIRED`, plan
   feature checks, read-only mode) is skipped only in that case.
5. On any other hostname (your live domain, a staging domain, an IP that
   isn't loopback), the flag is ignored and the existing licence enforcement
   runs exactly as it did before this change.

Nothing about login, sessions, roles, permissions, or the database schema is
touched. A bypassed user still has to log in normally and still only sees
what their role allows — this only skips the *licence* check, not
authentication or authorization.

## Enabling it locally

1. Open `.env` in the project root.
2. Set:
   ```
   APP_LOCAL_LICENSE_BYPASS=true
   ```
3. Reload the page. You should be able to reach every dashboard
   (`manager_dashboard.php`, `cashier_dashboard.php`, `godown_dashboard.php`,
   `super_admin_dashboard.php`, `reports.php` for auditor, etc.) without
   hitting "ERP license activation required."

## Disabling it (do this before deploying)

1. Open `.env`.
2. Set:
   ```
   APP_LOCAL_LICENSE_BYPASS=false
   ```
   or delete the line entirely — the default is `false` either way.
3. Confirm `config.php` is unchanged from the version in this repo (it should
   read the flag from the environment, not have it hard-coded to `true`).

**Never commit or deploy an `.env` with this flag set to `true`.** It is
reliable even if you forget, because the hostname check still refuses to
bypass anything outside `localhost` / `127.0.0.1` / `::1` — but there is no
reason to carry the flag into production, and leaving it off is simpler to
reason about.

## Running the project locally through XAMPP

1. Make sure a MySQL/MariaDB server is running and the database named in
   `.env` (`DB_NAME`) exists with the schema from `sql/database.sql`
   imported.
2. From the project root, start PHP's built-in server pointed at the
   `public/` folder (works with XAMPP's bundled PHP):
   ```
   C:\xampp\php\php.exe -S localhost:8000 -t public
   ```
   (Using XAMPP's Apache + vhost pointed at `public/` instead works too —
   the bypass logic is unaffected either way, since it only looks at the
   request's `Host` header.)
3. Open `http://localhost:8000/login.php` and log in with a local user.
4. With `APP_LOCAL_LICENSE_BYPASS=true`, every dashboard should load even
   though `license_client_state` has no valid activation.

## Files changed by this feature

- `app/license_client.php` — added `license_request_hostname()`,
  `license_local_bypass_active()`, and a call to the latter at the top of
  `license_enforce_request()`.
- `config.php` — `license.local_development_bypass` now reads
  `APP_LOCAL_LICENSE_BYPASS` from `.env` (via the existing `$pick()` helper),
  defaulting to `false`.
- `.env` — added `APP_LOCAL_LICENSE_BYPASS` (see above for the value to use).
- `LOCAL_LICENSE_DEVELOPMENT.md` — this file.

No other file was changed. UI, layout, CSS/JS, the database schema, barcode
handling, stock/godown logic, billing, and every other feature are untouched.
