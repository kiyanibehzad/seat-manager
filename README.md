# Seat Manager

**مدیریت حساب‌ها و سیت‌ها**

A Persian-first internal web application for requesting and managing OpenAI accounts and Standard/Premium seats. Employees submit requests; an administrator performs the actual change in OpenAI and records its result here. Seat Manager does not automatically change an OpenAI workspace or purchase seats.

سامانه‌ای داخلی برای ثبت درخواست، تمدید و گردش سیت‌ها؛ با پنل شخصی کارکنان و مدیران، اولویت‌بندی، ظرفیت، سوابق، اعلان و یادآوری. اقدام واقعی در OpenAI دستی است و نتیجهٔ بررسی‌شده در برنامه ثبت می‌شود.

## Features

- Multiple named accounts per person; Standard, Premium and No Seat status.
- Request queues, temporary or indefinite assignments, renewal approval and two-account transfers.
- Separate administrator, HR, operator and employee permissions; private personal history.
- Purchased/assigned/held/free capacity, conditional waiting-time estimates and an audit trail.
- Corporate-domain registration, email verification and HR approval; password recovery.
- In-app notifications, queued SMTP email and temporary Premium reminders.
- Persian RTL interface, Jalali dates, Iran time, and light/dark/system themes.

## Status

The frontend and backend are integrated and have been reviewed locally. This repository is source code, not a finished one-click hosting package. A combined deployment archive, a friendly installation wizard and validation against a selected production host/SMTP service remain separate work.

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

## Fonts and dependencies

IRANSans files are not distributed in this public repository. The interface uses a local system-font fallback when they are absent. If you have the appropriate license, add the three optional font files to the ignored `frontend/local-fonts/` directory before building. See [third-party notices](THIRD_PARTY.md).

## Development

```sh
node --test frontend/tests/*.test.mjs
```

Backend integration tests use a separate test database; some deliberately recreate its contents. Do not run them against a database whose contents you need to preserve. See [setup](docs/SETUP.md), [identity API](docs/backend/API.md), [operations API](docs/backend/API-OPERATIONS.md) and [operational behavior](docs/backend/OPERATIONS.md).

## License

No project-wide open-source license has been selected. Public repository visibility does not itself grant an additional software license. Third-party components retain their own licenses; see [THIRD_PARTY.md](THIRD_PARTY.md).
