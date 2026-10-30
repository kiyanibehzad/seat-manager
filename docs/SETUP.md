# Local setup / راه‌اندازی محلی

This guide covers source preparation, the localhost development helper and a clean shared-host installation. Validate the PHP/database version, HTTPS, file layout, mail and cron on the selected host before operational use.

## 1. Prepare dependencies and assets

Use PHP 8.3+ with PDO MySQL, OpenSSL, mbstring and sessions. The development service helper also needs Python 3, PHP's POSIX support and the `mariadbd` and `mariadb-install-db` commands on the executable search path. The helper targets a POSIX local development environment; Windows support has not been established.

From the repository root:

```sh
composer install --working-dir=backend --no-dev --prefer-dist
node frontend/build.mjs
```

Node.js builds `public_html/index.html`, `public_html/install.html` and their assets from the frontend sources and templates. It is not an application server. A clean checkout includes Vazirmatn web fonts and their license; the build serves them from the application's own assets, without a CDN or device font installation. All builds use the same bundled Vazirmatn fonts, including local development and the hosted application. Public package verification also checks the approved font hashes and notices.

## 2. Start the localhost development environment

```sh
mkdir -p storage/logs storage/sessions storage/run
python3 bin/dev-services.py start
python3 bin/dev-services.py status
```

On first use, the helper initializes a project-local MariaDB instance, creates private development configuration, applies migrations and creates a test-only administrator. It starts the application at **http://127.0.0.1:8787/** and a local SMTP receiver with no external forwarding. The project's reminder/email runner runs each minute. Existing configuration and data are retained on ordinary restarts.

Open the generated `storage/dev-access.txt` locally to obtain the initial development login. Do not commit, publish or paste that file into an issue. Passwords and tokens are intentionally absent from this guide.

```sh
python3 bin/dev-services.py stop
```

This stops the development processes recorded by the helper. The helper also maintains separate test and subfolder-test endpoints; these are development tools, not extra production services.

## 3. Understand the local data and email behavior

- The main development database and the integration-test database are separate.
- Development/test email is restricted to the localhost SMTP receiver. It does not reach employees' corporate inboxes.
- Reminder processing does not automatically release a seat. An administrator still performs the change in OpenAI and records the checked result.
- The UI is built for manual seat administration; no workspace Admin API key is requested by this application.

برای اجرای محلی، پس از نصب وابستگی‌ها و ساخت رابط، فرمان `start` را اجرا کنید. اطلاعات ورود اولیه فقط در فایل خصوصی محلی ایجاد می‌شود. ایمیل‌های این محیط آزمایشی هستند و به ایمیل سازمانی واقعی ارسال نمی‌شوند. با توقف و اجرای معمول سرویس‌ها، دادهٔ موجود پاک نمی‌شود.

## 4. Tests

Frontend behavior tests:

```sh
node --test frontend/tests/*.test.mjs
```

The backend integration suites require the isolated test environment and database. `tests/backend/run.php` and `tests/backend/operations.php` deliberately recreate the test database. The browser fixture requires an explicit reset flag and also replaces test data. Run these only when that test database is disposable; never point them at the main development or a production database.

## Clean shared-host installation

The intended runtime is PHP and MySQL/MariaDB with InnoDB; only `public_html/` should be web-accessible. Keep `backend/`, `config/`, `database/`, `storage/` and build/development tools outside the document root. Use [config/app.example.php](../config/app.example.php) as the configuration reference; never commit a real `config/local.php`.

Build the combined release on the development machine after preparing dependencies and frontend assets:

```sh
php bin/build-release.php /absolute/private/output/seat-manager-release.zip
```

The archive includes the prepared PHP dependencies, API, frontend, `install.html`, migrations and example configuration. It contains no `config/local.php`, application/setup key, database credentials, users, sessions or local test data. `bin/build-package.php` remains the older backend-only builder; use `build-release.php` for the combined application. Keep release archives outside the web document root.

Create a dedicated database/user on the target and privately create `config/local.php` from the example. Set the real HTTPS `app_url`, database connection, `environment=production` and secure sessions. Generate a fresh 32-byte random application key, base64-encode it into `app_key`, and generate a separate high-entropy one-time setup token. Set `setup.enabled=true` and store only that token's SHA-256 hash in `setup.token_hash`. Keep the original token privately outside the document root, Git, URLs and logs. Retain the application key with the database backup; changing it makes existing encrypted email payloads unreadable.

Open **`/install.html` over HTTPS**. The person creating the first chief administrator enters the private setup token, their email, first/last name, initial department and a chosen password twice. There is **no fixed/default login**. The password follows the same policy as other new passwords: at least 12 Unicode characters and at most 72 UTF-8 bytes. The form gets a session CSRF token and submits JSON to `api/install.php` with `X-CSRF-Token` and `X-Install-Token`; neither secret belongs in a URL or browser storage.

The first chief can sign in immediately with the same email and chosen password. SMTP is not required for this initial flow and the chosen password is never emailed or returned by the API. Once the chief exists, the install form closes and the endpoint cannot install again or apply pending migrations through the retained setup key. You may then set `setup.enabled=false`, clear `setup.token_hash` and remove the privately retained original setup token. This additional cleanup does not replace the server's one-time installation guard.

Before inviting employees or relying on verification, password recovery and email reminders, the chief configures the sender in **Settings → Email**. SMTP is the default; PHP mail is a secondary hosting option. Migration 004 stores these settings encrypted under the installation's existing app_key and defaults automatic delivery OFF, even when legacy SMTP configuration is enabled. The API never returns the SMTP password. Save the settings, explicitly check the connection, send a test to a specified recipient and inspect the inbox/spam before enabling automatic delivery. For ongoing delivery/reminders, configure the hosting control panel to run the private `bin/cron.php` with compatible PHP CLI each minute and verify successive scheduler timestamps in Settings. See [email setup](backend/MAIL.md). Initial login is independent of email and does not prove delivery. The older token-gated JSON installation mode without a chosen password is retained only for installations with explicitly prepared and enabled encrypted mail settings; a fresh installation requires the chosen-password flow.

Fresh installation creates only the chief administrator, initial department, allowed email domain and default access groups. It does not import local test users, OpenAI accounts or seat assignments. After login, enter the organization's actual users, departments and purchased seat capacities.

Composer dependencies and frontend assets should be prepared before uploading to shared hosting; neither Composer nor Node.js needs to run there. Retain dependency licenses in the prepared package. See the [installation recipe](backend/INSTALL.md), [identity API](backend/API.md), [operations API](backend/API-OPERATIONS.md) and [operations guide](backend/OPERATIONS.md) for the current contracts and worker behavior.

## Access-group integration (migration 003)

The current access model is one access group per user, separate from the organizational department. Read [ACCESS-GROUPS-CONTRACT.md](backend/ACCESS-GROUPS-CONTRACT.md) for the five capability checkboxes and their dependencies. The protected `user` group remains personal-only; only the protected chief group can manage financial data, purchased capacity, main settings and group assignments.

For an existing installation, pause writes and the mail/reminder worker and retain one coordinated private snapshot of the database, configuration/app_key, backend/API and frontend assets. DDL is not fully transactional; rollback restores that complete matching set while writes and cron remain stopped. Migration 003 adds the group tables and membership/notification columns, maps existing users without changing their organization, accounts or seat history, and increments authentication versions. Existing sessions will need to sign in again. Migration 004 adds encrypted email settings and service status without changing users, assignments or the existing queue; automatic delivery starts OFF. Do not edit applied checksummed migrations.

After selecting the **intended target configuration**, the existing CLI migrator is sufficient:

```sh
ACC_CONFIG=/absolute/private/config-for-selected-target.php php /absolute/path/openai-acc-manager/bin/migrate.php
```

Use this command only with the intended target configuration and a coordinated backup/maintenance window. The localhost service helper also applies available migrations on startup, so do not use its `start` as a read-only readiness check before integration is approved. A fresh interactive installation applies its initial migrations itself; the closed installer is not an upgrade mechanism.

Before 003 is applied, existing role behavior remains available and `session.access_groups_ready` is false; group-edit endpoints return `503 migration_required`. After migration, permissions and managerial notification recipients come from the real group. `app_role` is only a legacy compatibility label.

The isolated access-group regression can run locally without resetting the existing QA fixture:

```sh
php tests/backend/access-groups.php
```

It guards the existing project-only MariaDB instance, creates its own random database/user, starts a temporary localhost HTTP server with separate sessions, tests baseline-to-group upgrade and fresh installation, then removes only its own temporary resources. It does not read the human login file, private main/QA configuration or deployment backups. Existing destructive suites must use their independently configured database/port harness; they must not be pointed at QA while browser testing is in progress.

## Credit-request upgrade (migration 005)

Migration `005_credit_requests.sql` adds credit-request and event records. Apply it through the private CLI migrator with the same coordinated backup and maintenance procedure described above. It does not change existing users, passwords, seat assignments, purchased capacity, SMTP settings or queued messages. Do not use the first-install page to upgrade an existing installation.

The application records an administrator's manually verified increase to an OpenAI monthly credit limit. It does not buy credits, update OpenAI, reserve a personal credit wallet, measure actual consumption or reset limits automatically. A usage-period end can be recorded as context; confirm the actual period and limits in OpenAI rather than assuming that a calendar month or a billing cycle applies. Existing backend seat endpoints remain available before the migration; the new endpoints require the migration. Keep maintenance active until migration 005 and the matching frontend/backend are installed together: the updated personal account view also reads open credit requests.

Person summaries cover only actual seat changes recorded in this installation. Concurrent accounts have their own durations; their aggregate is labelled account-days. Planned end dates do not prove that a seat was released. Requested and completed approved credits remain separate from actual usage reported by OpenAI.

`tests/backend/credit-requests.php` resets its guarded test database. Run it only in a separate disposable database/HTTP harness, following the `ACC_TEST_*` overrides in `tests/backend/OperationsSupport.php`. It verifies the 004→005 upgrade, existing data preservation, authorization, concurrency, request lifecycle and summary calculations. `tests/backend/mail-templates.php` is a pure rendering/MIME check and needs no database, SMTP connection or delivery.
