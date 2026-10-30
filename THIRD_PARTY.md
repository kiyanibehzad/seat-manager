# Third-party notices

No project-wide open-source license has been selected for Seat Manager. This document records dependency information and does not replace or expand any upstream license.

## PHPMailer

- Package: `phpmailer/phpmailer`.
- Locked version: `v7.1.1`, recorded in [backend/composer.lock](backend/composer.lock).
- Upstream: [PHPMailer](https://github.com/PHPMailer/PHPMailer).
- Declared license: **LGPL-2.1-only**.

Composer installs the upstream license at `backend/vendor/phpmailer/phpmailer/LICENSE`. Retain that license and the component's notices when including the installed dependency in a deployment package. Generated Composer files also include their own notices in `backend/vendor/composer/`; retain those with the generated files. Installed dependencies are not committed to this repository.

## Bundled Vazirmatn fonts

- Upstream: [Vazirmatn by the Vazirmatn Project Authors](https://github.com/rastikerdar/vazirmatn).
- Pinned release: **v33.003**.
- License: **SIL Open Font License 1.1**, included unchanged at `frontend/vendor-fonts/vazirmatn/OFL.txt`.
- Original author notices: `frontend/vendor-fonts/vazirmatn/AUTHORS.txt`.
- Unmodified upstream web fonts: `Vazirmatn-Regular.woff2`, `Vazirmatn-Medium.woff2` and `Vazirmatn-Bold.woff2`.

The public build copies these five files to `public_html/assets/fonts/vazirmatn/`. They are loaded locally from the application, without a CDN or reliance on fonts installed on the user's device. Keep the copyright, license and author notices with redistributed copies. The public release builder checks the pinned file hashes and rejects unrecognized font files.

## Historical private font copies

Earlier local development builds used private IRANSans copies. Those files remain excluded from the repository and public source history. The build no longer reads private fonts: local, hosted and distributable versions all use bundled Vazirmatn.

Do not add purchased font files, purchase records or license credentials to a public commit.

تمام نسخه‌های برنامه فونت آزاد وزیرمتن را همراه مجوز آن در خود برنامه دارند و به فونت نصب‌شده روی دستگاه وابسته نیستند. فایل‌های تجاری قدیمی وارد خروجی ساخته‌شده نمی‌شوند.
