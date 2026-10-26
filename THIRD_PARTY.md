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

## Optional IRANSans fonts

The local development installation uses licensed IRANSans font files. The copies inspected for this project carry a FontIran **All Rights Reserved** notice; no permission to redistribute those files publicly was provided with the project. They are therefore excluded from the public repository and from public source history.

The application uses its bundled Vazirmatn fonts when these optional files are absent. To use your own appropriately licensed copies in a private deployment, place the following files in the ignored `frontend/local-fonts/` directory, then run a normal build without `--public`:

```text
iransans-regular.ttf
iransans-medium.ttf
iransans-bold.ttf
```

Do not add purchased font files, purchase records or license credentials to a public commit. A license to use a font in your installation should not be assumed to include permission to redistribute its source files.

نسخهٔ عمومی فونت وزیرمتن را همراه مجوز آن داخل خود برنامه دارد و به فونت نصب‌شده روی دستگاه وابسته نیست. فایل‌های ایران‌سنس در نسخهٔ عمومی نیستند؛ برای نصب شخصی و با مجوز مناسب می‌توان فایل‌های خود را در پوشهٔ محلی بالا قرار داد. فونت تجاری یا فایل‌های خرید و اطلاعات مجوز را در commit عمومی قرار ندهید.
