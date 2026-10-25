# Local setup / راه‌اندازی محلی

This guide describes source preparation and the existing localhost development helper. It is not a production deployment certification or a one-click hosting installer.

## 1. Prepare dependencies and assets

Use PHP 8.3+ with PDO MySQL, OpenSSL, mbstring and sessions. The development service helper also needs Python 3, PHP's POSIX support and the `mariadbd` and `mariadb-install-db` commands on the executable search path. The helper targets a POSIX local development environment; Windows support has not been established.

From the repository root:

```sh
composer install --working-dir=backend --no-dev --prefer-dist
node frontend/build.mjs
```

Node.js builds `public_html/index.html` and its assets from the frontend source and template. It is not an application server. Optional licensed fonts can be added before the build as described in [THIRD_PARTY.md](../THIRD_PARTY.md); a build without them uses system-font fallbacks.

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

## Production preparation

The intended runtime is PHP and MySQL/MariaDB with InnoDB; only `public_html/` should be web-accessible. Keep `backend/`, `config/`, `database/`, `storage/` and build/development tools outside the document root. Use [config/app.example.php](../config/app.example.php) as the configuration reference; never commit a real `config/local.php`.

A production installation still needs its own database credentials, generated application/setup keys, HTTPS, SMTP and PHP CLI cron configuration, plus validation on the chosen host. The install endpoint currently accepts JSON and uses a one-time setup token; there is no friendly setup wizard. The existing `bin/build-package.php` makes a backend-only archive, not a combined ready-to-deploy frontend/backend release. Do not deploy an archive from local test or work directories.

Composer dependencies and frontend assets should be prepared before uploading to shared hosting; neither Composer nor Node.js needs to run there. Retain dependency licenses in the prepared package. See the [identity API](backend/API.md), [operations API](backend/API-OPERATIONS.md) and [operations guide](backend/OPERATIONS.md) for the current contracts and worker behavior.
