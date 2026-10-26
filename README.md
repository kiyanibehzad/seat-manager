# Seat Manager

**مدیریت حساب‌ها و سیت‌ها**

A Persian-first internal web application for requesting and managing OpenAI accounts and Standard/Premium seats. Employees submit requests; an administrator performs the actual change in OpenAI and records its result here. Seat Manager does not automatically change an OpenAI workspace or purchase seats.

سامانه‌ای داخلی برای ثبت درخواست، تمدید و گردش سیت‌ها؛ با پنل شخصی کارکنان و مدیران، اولویت‌بندی، ظرفیت، سوابق، اعلان و یادآوری. اقدام واقعی در OpenAI دستی است و نتیجهٔ بررسی‌شده در برنامه ثبت می‌شود.

## Features

- Multiple named accounts per person; Standard, Premium and No Seat status.
- Request queues, temporary or indefinite assignments, renewal approval and two-account transfers.
- Named permission groups, independent of organizational departments and priority scores; one group per person, with administrator, HR, operator and employee presets.
- A daily work desk, full seat/request queues and independent registration review; personal accounts and private history for every group.
- Purchased/assigned/held/free capacity, conditional waiting-time estimates and an audit trail.
- Corporate-domain registration, email verification and approval by an authorized reviewer; password recovery.
- In-app notifications, queued email and temporary Premium reminders; chief-only SMTP sender settings, explicit connection/send tests and queue/scheduler status.
- Persian RTL interface, Jalali date pickers, Iran time, light/dark/system themes and personal color palettes.

## Status

The frontend and backend are integrated. A combined offline release builder and a one-time first-administrator setup page are available. Each installation needs its own private database/configuration, HTTPS and reminder schedule. The chief configures the sender in Settings, explicitly tests delivery and then enables automatic email. Fresh installations and the email-settings upgrade default automatic delivery off. Initial setup lets the owner choose an email and password without waiting for email delivery. There is no default login.

## Build and run locally

Runtime: **PHP 8.3+**, PDO MySQL, OpenSSL, mbstring, sessions and a MySQL/MariaDB database using InnoDB and utf8mb4. Composer and Node.js are development/build tools; they are not required on the hosting account when prepared dependencies and built assets are deployed.

```sh
git clone https://github.com/kiyanibehzad/seat-manager.git
cd seat-manager
composer install --working-dir=backend --no-dev --prefer-dist
node frontend/build.mjs
```

Then follow [local setup](docs/SETUP.md). The optional local service helper additionally uses Python 3 and MariaDB command-line tools. Its development mail receiver stays on localhost and does not forward messages to real inboxes.

برای راه‌اندازی محلی، [راهنمای نصب و اجرا](docs/SETUP.md) را دنبال کنید. تنظیمات خصوصی، پایگاه داده، نشست‌ها و ایمیل‌های آزمایشی وارد مخزن نمی‌شوند. فایل‌های لازم رابط از خود برنامه بارگذاری می‌شوند و به CDN وابسته نیستند.

After building the frontend and preparing PHP dependencies, create a combined hosting archive outside the web root:

```sh
php bin/build-release.php /absolute/new-seat-manager-release.zip
php tests/backend/release-package.php /absolute/new-seat-manager-release.zip
```

Only `public_html/` is public. The archive includes no real configuration, installation key or local data. Follow [the installation guide](docs/backend/INSTALL.md) before opening `/install.html` to create the first administrator.

## Fonts and dependencies

IRANSans files are not distributed in this public repository. The interface uses a local system-font fallback when they are absent. If you have the appropriate license, add the three optional font files to the ignored `frontend/local-fonts/` directory before building. See [third-party notices](THIRD_PARTY.md).

## Development

```sh
node --test frontend/tests/*.test.mjs
```

Backend integration tests use a separate test database; some deliberately recreate its contents. Do not run them against a database whose contents you need to preserve. See [setup](docs/SETUP.md), [identity API](docs/backend/API.md), [permission groups](docs/backend/ACCESS-GROUPS-CONTRACT.md), [operations API](docs/backend/API-OPERATIONS.md), [operational behavior](docs/backend/OPERATIONS.md) and [email setup and delivery](docs/backend/MAIL.md).

## License

No project-wide open-source license has been selected. Public repository visibility does not itself grant an additional software license. Third-party components retain their own licenses; see [THIRD_PARTY.md](THIRD_PARTY.md).
