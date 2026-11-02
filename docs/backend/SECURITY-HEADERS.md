# هدرهای امنیتی مرورگر

برنامه، صفحهٔ نصب و API را با هدرهای پاسخ زیر ارائه کنید. Seat Manager برای نمایش در قاب توکار طراحی نشده است.

```http
Content-Security-Policy: frame-ancestors 'none'
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
```

این سیاست CSP فقط محافظت در برابر نمایش داخل قاب را اضافه می‌کند و سیاست محدودکنندهٔ تازه‌ای برای اسکریپت و استایل ایجاد نمی‌کند؛ بنابراین ویژگی‌های استایل درون‌خطی رابط فعلی را مختل نمی‌کند. نصب‌کننده، CSP مستقل قبلی خود را حفظ می‌کند. دستور `frame-ancestors` باید در هدر پاسخ HTTP فرستاده شود و قراردادن آن در عنصر متای HTML کافی نیست.

## وب‌سرور Apache و LiteSpeed سازگار

بستهٔ آماده، فایل `public_html/.htaccess` را همراه `DirectoryIndex index.html` دارد. در Apache 2.4 با `mod_headers` و `AllowOverride FileInfo`، سیاست روی HTML و PHP، از جمله پاسخ‌های خطا، اعمال می‌شود. شرط `IfModule` مانع خطای هاست‌های فاقد این ماژول می‌شود، اما در چنین هاستی مدیر سرور باید هدرها را تنظیم کند. اگر هاست دستورهای `Header` را ممنوع کرده است، تنظیم باید در میزبان مجازی سرور انجام شود.

API مبتنی بر PHP هم این هدرها را پیش از راه‌اندازی برنامه می‌فرستد؛ بنابراین نبود پایگاه داده یا تنظیمات، محافظت پاسخ خطا را حذف نمی‌کند. هدر HTMLای که مستقیم توسط وب‌سرور ارائه می‌شود باید در همان وب‌سرور تنظیم شود.

## فعال‌سازی تدریجی HSTS

سیاست اولیه در پاسخ‌های واقعی HTTPS به شکل زیر است.

```http
Strict-Transport-Security: max-age=86400
```

این مقدار، شروع یک‌روزه برای همین نام میزبان است و `includeSubDomains` یا `preload` ندارد. پیش از افزایش مدت، پایداری HTTPS و تمدید گواهی را بررسی کنید. در HTTP معمولی محیط محلی، HSTS فرستاده نمی‌شود. روش جایگزین PHP و شرط Apache، هدرهای `X-Forwarded-Proto` و `Forwarded`، شمارهٔ درگاه یا مقدار Host درخواست را مدرک برقرار بودن TLS نمی‌دانند.

پراکسی معکوس باید محیط معتبر HTTPS وب‌سرور را درست تنظیم کند و HSTS را در نقطهٔ پایان TLS قرار دهد. هدر دلخواه کاربر نباید باعث فعال‌شدن تشخیص HTTPS شود.

HSTS روی بازدیدهای بعدی اثر دارد و جای هدایت اولیهٔ امن از HTTP به HTTPS را نمی‌گیرد. پس از آماده‌شدن HTTPS، این هدایت را برای نام میزبان ثابت و مورد نظر در کنترل‌پنل یا میزبان مجازی تنظیم کنید. برنامه از Host دریافتیِ کاربر مقصد هدایت نمی‌سازد و توسعهٔ HTTP محلی را به TLS مجبور نمی‌کند.

## وب‌سرور Nginx یا پراکسی ارائه‌دهندهٔ فایل‌های ایستا

Nginx فایل `.htaccess` را اجرا نمی‌کند. همچنین ممکن است پراکسی معکوس، `index.html`، `install.html` و فایل‌های رابط را بدون مراجعه به Apache یا PHP ارائه کند. سیاست را با تنظیم سفارشی مورد پشتیبانی کنترل‌پنل، در بلوک `server` مربوط به HTTPS همان میزبان قرار دهید.

```nginx
add_header Content-Security-Policy "frame-ancestors 'none'" always;
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "no-referrer" always;
add_header Strict-Transport-Security "max-age=86400" always;
```

خط HSTS فقط در بلوک HTTPS قرار می‌گیرد. اگر یک `location` فرزند، دستور `add_header` دارد، ارث‌بری هدرها را در همان محل نیز بررسی کنید؛ در نسخه‌های قدیمی‌تر Nginx، هدرهای والد در این حالت به ارث نمی‌رسند.

از تکرار هدرها توسط پراکسی و PHP یا بک‌اند جلوگیری کنید. در تنظیم مربوط، برای بالادست HTTP از `proxy_hide_header` و برای بالادست FastCGI از `fastcgi_hide_header` استفاده کنید و سپس سیاست نهایی را در سرور TLS اضافه کنید. هنگام ترکیب این پیش‌فرض‌ها، CSP گسترده‌تر یا HSTS طولانی‌ترِ از قبل تأییدشده را ضعیف نکنید.

## بررسی پس از استقرار

پاسخ نهایی مسیرهای عمومی را بررسی کنید؛ وجود فایل تنظیمات در ZIP به‌تنهایی کافی نیست.

```sh
curl --head https://your-fixed-app-host.example/
curl --head https://your-fixed-app-host.example/install.html
curl --head 'https://your-fixed-app-host.example/api/index.php?route=%2Fhealth'
curl --head https://your-fixed-app-host.example/api/install.php
```

هدرهای جلوگیری از قاب‌بندی باید در هر چهار مسیر حاضر باشند و روی HTTPS دقیقاً یک سیاست HSTS مورد نظر دیده شود. ممکن است API روش HEAD را پشتیبانی نکند؛ هدرهای آن پاسخ هم باید حاضر باشند. نمایش عادی، ورود و وضعیت نصب‌کننده را نیز بررسی کنید.

ارسال `X-Forwarded-Proto: https` به‌تنهایی روی درخواست HTTP نباید باعث اضافه‌شدن HSTS توسط برنامه شود. آزمون این بخش با `php tests/backend/security-headers.php` اجرا می‌شود و فقط از فایل‌های ساختگی محلی و HTTP استفاده می‌کند؛ به پایگاه داده یا SMTP نصب دست نمی‌زند.

## منابع اصلی

- [ماژول هدرهای Apache](https://httpd.apache.org/docs/2.4/mod/mod_headers.html)
- [متغیرهای شرط‌های Apache](https://httpd.apache.org/docs/2.4/expr.html)
- [ماژول هدرها و ارث‌بری در Nginx](https://nginx.org/en/docs/http/ngx_http_headers_module.html)
- [رفتار HSTS](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Strict-Transport-Security)
- [دستور frame-ancestors در CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/frame-ancestors)
