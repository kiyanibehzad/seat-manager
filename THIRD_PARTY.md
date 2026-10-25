# Third-party notices

No project-wide open-source license has been selected for Seat Manager. This document records dependency information and does not replace or expand any upstream license.

## PHPMailer

- Package: `phpmailer/phpmailer`.
- Locked version: `v7.1.1`, recorded in [backend/composer.lock](backend/composer.lock).
- Upstream: [PHPMailer](https://github.com/PHPMailer/PHPMailer).
- Declared license: **LGPL-2.1-only**.

Composer installs the upstream license at `backend/vendor/phpmailer/phpmailer/LICENSE`. Retain that license and the component's notices when including the installed dependency in a deployment package. Generated Composer files also include their own notices in `backend/vendor/composer/`; retain those with the generated files. Installed dependencies are not committed to this repository.

## Optional IRANSans fonts

The local development installation uses licensed IRANSans font files. The copies inspected for this project carry a FontIran **All Rights Reserved** notice; no permission to redistribute those files publicly was provided with the project. They are therefore excluded from the public repository and from public source history.

The application works with local system fonts when these optional files are absent. To use your own appropriately licensed copies, place the following files in the ignored `frontend/local-fonts/` directory, then rebuild:

```text
iransans-regular.ttf
iransans-medium.ttf
iransans-bold.ttf
```

Do not add purchased font files, purchase records or license credentials to a public commit. A license to use a font in your installation should not be assumed to include permission to redistribute its source files.

نسخهٔ عمومی شامل فایل‌های ایران‌سنس نیست. در صورت داشتن مجوز مناسب، فایل‌های خود را در پوشهٔ محلی بالا قرار دهید؛ در نبود آن‌ها، فونت پیش‌فرض محلی سیستم استفاده می‌شود. فونت یا فایل‌های خرید و اطلاعات مجوز را در commit عمومی قرار ندهید.
