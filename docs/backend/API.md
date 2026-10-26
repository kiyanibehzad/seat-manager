# قرارداد هویت و اعلان — نسخهٔ ۱

افزونهٔ اجرایی مرحلهٔ دوم در [API-OPERATIONS.md](API-OPERATIONS.md) مستند است؛ مسیرهای هویت/اعلان زیر حفظ شده‌اند. توضیح مرز مرحلهٔ اول در این سند، سابقهٔ تحویل آن مرحله است.

مبنای اتصال: `APP_URL + /api/index.php?route=/…` (مثلاً `http://127.0.0.1:8787/api/index.php?route=/session`). APP_URL آدرس ریشهٔ نصب است و می‌تواند زیرپوشه داشته باشد. هیچ rewrite لازم نیست. فرانت و API روی یک origin هستند؛ `credentials: 'same-origin'`، بدون CORS عمومی.

تمام ورودی‌های نوشتنی JSON object با `Content-Type: application/json`، اندازهٔ حداکثر ۳۲KiB؛ خروجی UTF-8 JSON و `Cache-Control: no-store` است.

```json
{"ok":true,"data":{},"error":null,"request_id":"…"}
```
```json
{"ok":false,"data":null,"error":{"code":"validation_failed","message":"…","fields":{}},"request_id":"…"}
```

ابتدا `GET /session` را بخوانید. `data.csrf_token` را در **همهٔ POST/PATCHها حتی ورود، ثبت‌نام، verify و بازیابی** با هدر `X-CSRF-Token` بفرستید. پس از ورود/خروج/تغییر یا بازیابی رمز، session/CSRF تازه را از پاسخ یا GET session بگیرید. توکن CSRF فقط در حافظهٔ رابط بماند. احراز هویت با cookie HttpOnly است؛ رمز، لینک بازیابی و توکن را در localStorage یا سوابق پیش‌نمایش ننویسید. لینک ایمیل توکن را در fragment دارد (`#action=reset&token=…`)؛ رابط آن را خوانده، از آدرس پاک کند و به POST مربوط بفرستد. توکن در query API قرار نگیرد.

| روش و route | ورودی / خروجی اصلی |
|---|---|
| GET `/health` | `status:ok`؛ فقط دسترس‌پذیری DB، بدون اطلاعات اتصال |
| GET `/session` | `authenticated`, `user` یا null، `csrf_token`, `permissions`, `app_url` |
| POST `/login` | `email,password` → session؛ خطای عمومی 401، محدودیت 429، حساب تأییدنشده/غیرفعال وارد نمی‌شود |
| POST `/logout` | `{}` → session ناشناس تازه |
| POST `/register` | `email,password,first_name,last_name` → پیام عمومی 202؛ دامنهٔ کامل مجاز، سپس ایمیل تأیید |
| POST `/verify` | `token` → 200؛ فقط تأیید مالکیت و ورود به انتظار HR، ورود فعال نمی‌شود |
| POST `/resend-verification` | `email` → پیام عمومی 202؛ محدودشده |
| POST `/forgot` | `email` → پیام عمومی 202؛ پاسخ وجود حساب را آشکار نمی‌کند |
| POST `/reset` | `token,password` → session ناشناس تازه؛ تمام نشست‌های قبلی باطل می‌شوند |
| POST `/change-password` | `current_password,password`؛ نیاز به ورود → session تازه؛ سایر نشست‌ها باطل |
| GET `/notifications` | `?unread=1&before_id=…&limit=20` → `items,unread_count,next_before_id`؛ فقط گیرندهٔ فعلی |
| POST `/notifications/read` | `id` → فقط اعلان متعلق به همین فرد، 404 برای دیگران |
| POST `/notifications/read-all` | `{}` → علامت‌گذاری اعلان‌های همین فرد |
| GET `/admin/registrations` | admin/hr؛ فقط ایمیل‌تأییدشده‌های منتظر HR |
| POST `/admin/registrations/approve` | `user_id,department_id,organizational_position` → کاربر با نقش user؛ اعلان و ایمیل واقعی فعال‌سازی |
| POST `/admin/registrations/reject` | `user_id,reason` → رد با ثبت سابقه و اطلاع خصوصی |
| GET `/admin/users` | admin/hr؛ اطلاعات لازم مدیریت کارکنان، بدون password/token |
| POST `/admin/users` | admin/hr؛ `email,first_name,last_name,department_id,organizational_position,employee_number?`؛ رمز تصادفی فقط در ایمیل، نقش پیش‌فرض user |
| PATCH `/admin/users` | admin/hr؛ `user_id` و `first_name?,last_name?,department_id?,organizational_position?,employee_number?,special_priority?` |
| POST `/admin/users/status` | admin/hr؛ `user_id,is_active`؛ تاریخچه محفوظ و نشست قبلی باطل؛ آخرین admin فعال قابل غیرفعال‌کردن نیست |
| POST `/admin/users/role` | فقط admin؛ `user_id,app_role` یکی از user/admin/hr/operator؛ آخرین admin محفوظ |
| GET `/departments` | تمام واردشده‌ها؛ `items` شامل id/name/base_score |
| POST `/admin/departments` | admin/hr؛ `name,base_score,id?`؛ درج/ویرایش |
| GET `/admin/settings` | فقط admin؛ دامنه‌ها و زمان معمول اقدام مدیر؛ هیچ secret |
| POST `/admin/settings` | فقط admin؛ `allowed_email_domains?,manual_action_hours?` |

`user`: `id,email,first_name,last_name,department_id,department_name,organizational_position,app_role,employee_number,special_priority,is_active,email_verified,approval_status`. هیچ password/hash/token در user نیست. تمام نقش‌ها `personal.read`, `personal.request` دارند؛ این‌ها قرارداد مرحلهٔ بعدند و endpoint حساب/درخواست هنوز ساخته نشده است. HR فقط مدیریت کارکنان/بخش‌ها، operator فقط مجوز عملیات آینده و admin مجموع مجوزهای مصوب را دارد. supervisor سمت سازمانی است و مجوز مدیریتی نمی‌دهد. نقش مدیریتی به امتیاز سازمانی اضافه نمی‌شود.

اعلان: `id,event_type,title,body,action_url,created_at,read_at`. تاریخ/زمان API ISO-8601 UTC است؛ رابط در Asia/Tehran و تقویم شمسی نمایش دهد. metadata خصوصی عملیات دوطرفه به گیرندهٔ دیگر نرسد.

کدهای متداول: 400 `invalid_json`؛ 401 `unauthenticated`/`invalid_credentials`؛ 403 `csrf_invalid`/`forbidden`؛ 404 `not_found`؛ 405 `method_not_allowed`؛ 409 `conflict`؛ 422 `validation_failed`/`invalid_token`؛ 429 `rate_limited`؛ 500 `internal_error`؛ 503 `unavailable`. پیام فارسی برای نمایش مناسب است؛ منطق رابط به `code` متکی باشد.

## مرز تحویل

مرحلهٔ اول هویت، session، ثبت‌نام، مدیریت کارکنان/بخش، اعلان و outbox واقعی را تحویل می‌دهد. درخواست/سیت/ظرفیت، امتیاز صف و تاریخچهٔ عملیات سیت هنوز endpoint ندارند. رویداد ساختگی برای پرکردن اعلان وجود ندارد. یادآوری Premium قرارداد زمان‌بندی دارد و به منبع واقعی تخصیص در مرحلهٔ بعد وصل خواهد شد. فعال‌سازی کاربر هیچ سیتی تخصیص نمی‌دهد.

## تفاوت ساخت مدیریتی با ثبت‌نام کارکنان

`POST /admin/users` مسیر provisioning توسط admin/hr است: حساب مستقیم approved/فعال است، رمز اولیهٔ تصادفی فقط به ایمیل سازمانی می‌رود و ورود اول نیاز به تعویض اجباری ندارد. تأیید ایمیل در این مسیر، تأیید سازمانی **از طرف ایجادکنندهٔ مجاز** است؛ به معنی کلیک verify توسط کارمند نیست. `audit_log` عامل ایجاد را حفظ می‌کند. دو گیت verify مالکیت و تأیید HR/admin فقط برای self signup با `/register` هستند. هیچ‌کدام از این مسیرها سیت تخصیص نمی‌دهند.

توکن verify تا ۲۴ ساعت و reset تا ۱ ساعت اعتبار دارد؛ یک‌بارمصرف است. `resend-verification` یا forgot تازه، لینک قبلی همان purpose را باطل و ایمیل ارسال‌نشدهٔ آن را لغو می‌کند. مصرف توکن، لینک منقضی/مصرف‌شده و حتی درخواست هم‌زمانِ تکراری نتیجهٔ دوم نمی‌سازند. تغییر/بازیابی رمز همهٔ لینک‌های auth قبلی را باطل می‌کند. تغییر نقش، غیرفعال‌سازی و بازیابی رمز تمام نشست‌های قبلی، شامل نشست فعلی فرد هدف، را در درخواست بعدی باطل می‌کند؛ پس از تغییر رمز توسط خود فرد، فقط نشست جاری با شناسه و CSRF تازه حفظ می‌شود.

نمونهٔ واقعی شکل session (id عدد، bool واقعی و nullableها null هستند):

```json
{"ok":true,"data":{"authenticated":true,"user":{"id":12,"email":"employee@example.test","first_name":"علی","last_name":"نمونه","employee_number":null,"department_id":3,"department_name":"فنی","organizational_position":"employee","app_role":"user","special_priority":false,"is_active":true,"approval_status":"approved","email_verified":true},"csrf_token":"<64 hex characters>","permissions":["personal.read","personal.request","notifications.read"],"app_url":"http://127.0.0.1:8787"},"error":null,"request_id":"<16 hex characters>"}
```

ناشناس: `authenticated:false,user:null,permissions:[]` و csrf_token معتبر. پاسخ login/logout/change-password/reset همان شکل session است. فهرست کاربران و ثبت‌نام‌ها `data:{"items":[user,…]}`؛ ایجاد/ویرایش/تأیید/وضعیت/نقش `data:{"user":user}` (ایجاد HTTP 201، سایر موارد 200). `department_id` و `department_name` قبل از تأیید HR می‌توانند null باشند. سمت همیشه employee/supervisor و role همیشه user/admin/hr/operator است. رمز و auth_version خصوصی در پاسخ نیستند.

```json
{"ok":true,"data":{"items":[{"id":27,"event_type":"registration.approved","title":"حساب شما فعال شد","body":"ثبت‌نام شما تأیید شد. اکنون می‌توانید وارد برنامه شوید.","action_url":"http://127.0.0.1:8787/","created_at":"2026-10-24T14:30:00Z","read_at":null}],"unread_count":1,"next_before_id":null},"error":null,"request_id":"<16 hex characters>"}
```

`POST /notifications/read` و read-all پاسخ `data:{"updated":true}` دارند. endpointهای فهرست مدیریتی فعلاً حداکثر ۵۰۰ فرد را برمی‌گردانند؛ ورود لیستی و صفحه‌بندی کامل کارکنان جزو مرحلهٔ بعد هستند. 413 `payload_too_large` و 415 `invalid_content_type` نیز ممکن است.

## محدودیت تلاش و IP مشترک دفتر

پیش‌فرض پنجره ۱۵ دقیقه است: ۸ **تلاش ناموفق** برای هر ایمیل و ۱۰۰۰ تلاش ناموفق برای هر IP. ورود موفق شمارندهٔ IP را مصرف نمی‌کند و شمارندهٔ ایمیل همان فرد را پاک می‌کند. ثبت‌نام ۳۰۰ درخواست برای IP در پنجره دارد تا ورود اولیهٔ ۱۰۰–۱۵۰ نفر پشت NAT ممکن باشد. این اعداد در `auth_limits` قابل تنظیم‌اند؛ X-Forwarded-For بدون پراکسی مورداعتماد خوانده نمی‌شود. forgot/resend محدودیت مستقل ۵ برای هر ایمیل و ۶۰۰ برای IP دارند. verify و reset نیز هرکدام ۶۰۰ تلاش برای IP در همان پنجره دارند؛ سقف‌ها شامل تلاش معتبر/نامعتبر و قابل تنظیم‌اند. توکن تصادفی ۲۵۶بیتی، هش‌شده و یک‌بارمصرف است و این سقف از حدس انبوه/abuse جلوگیری می‌کند. آزمون NAT، ۱۵۰ نفر را از register و resend و verify تا تأیید HR و forgot/reset پوشش می‌دهد.

## سیاست رمز و متن راهنمای رابط

رمز **تازه** در register/reset/change-password و ساخت اولین مدیر با رمز انتخابی حداقل ۱۲ نویسهٔ Unicode دارد؛ حروف فارسی و ASCII هرکدام یک نویسه شمرده می‌شوند. محدودیت فنی bcrypt حداکثر ۷۲ بایت UTF-8 است و پشت صحنه کنترل می‌شود؛ ۳۶ حرف فارسیِ دوبایتی یا ۷۲ حرف ASCII در مرز معتبر قرار می‌گیرند. متن کمبود طول: «رمز عبور باید حداقل ۱۲ نویسه داشته باشد.» متن بیش‌ازحد طولانی: «رمز عبور طولانی است؛ رمز کوتاه‌تری انتخاب کنید.» راهنمای فرم: «حداقل ۱۲ نویسه». الزام ترکیب حروف/عدد/نماد افزوده نشده است. login و current_password حداقل تازه را به رمز موجود تحمیل نمی‌کنند؛ guard سقف بایت جلوی پذیرفتن پسوند اضافی پس از مرز bcrypt را می‌گیرد.

## تکمیل قرارداد فهرست کارکنان — ۲۵ اکتبر ۲۰۲۶

در `GET /admin/users` هر عضو `data.items` علاوه بر مشخصات user، فیلد `score` دارد: integer حاصل **امتیاز بخش + (supervisor ? 1 : 0) + (special_priority ? 1 : 0)**. برای فرد دارای بخش، مقدار از ۱ تا ۷ است؛ app_role در آن اثری ندارد. تا وقتی بخش مشخص نشده، مقدار null است. مشخصات فرد/بخش و score در یک SELECT سازگار سمت سرور خوانده می‌شوند. admin و hr هر دو این فهرست را دارند؛ فرانت امتیاز را دوباره محاسبه نکند. نمونهٔ بخشی از ردیف: `{"department_id":3,"organizational_position":"supervisor","special_priority":true,"app_role":"hr","score":7}` با امتیاز پایهٔ بخش ۵.


## گروه دسترسی — قرارداد جاری

پس از migration 003، user دارای `access_group_id:int`, `access_group_name:string`, `is_super_admin:bool` است؛ session علاوه بر permissions، `capabilities` و `access_groups_ready` می‌دهد. قبل از ادغام، id/name گروه null و ready=false است. `app_role` فقط سازگاری است. مجوز ثبت‌نام اکنون `registrations.review` و مستقل از `people.manage` است. گروه پایهٔ user محافظت‌شده و شخصی‌فقط است. مرجع دقیق endpoint/وابستگی/انتساب در [ACCESS-GROUPS-CONTRACT.md](ACCESS-GROUPS-CONTRACT.md) است.

## راه‌اندازی اولیه و اولین ورود

نصاب مسیر مستقل **`APP_URL + /api/install.php`** است و از پارامتر route در index.php استفاده نمی‌کند. رابط آن **`APP_URL + /install.html`** است. این مسیر فقط نخستین مدیر کل یک نصب خام را می‌سازد؛ شناسه یا رمز پیش‌فرض ندارد و مسیر ارتقای نصب موجود نیست. در محیط production، HTTPS و همان origin لازم‌اند؛ CORS عمومی ارائه نمی‌شود.

| درخواست | ورودی | پاسخ data |
|---|---|---|
| GET `api/install.php` | بدون کلید نصب | `installed:bool`, `available:bool`, `csrf_token:string` |
| POST `api/install.php`، روش فرم | JSON شامل `email,first_name,last_name,department_name,password,password_confirm`؛ هدرهای `X-Install-Token` و `X-CSRF-Token` | HTTP 201 و `message`؛ بدون رمز یا کلید نصب |

GET نشست فرم را آماده می‌کند ولی migration اجرا نمی‌کند و قبل از وجود جدول users نیز کار می‌کند. CSRF فقط در حافظهٔ رابط بماند؛ کلید نصب فقط در هدر ارسال شود. کلید نصب از مقدار تصادفی خصوصیِ بیرون ریشهٔ وب گرفته می‌شود؛ config تنها SHA-256 آن را در setup.token_hash دارد و setup.enabled باید برای نصب اولیه true باشد. این دو توکن نباید در URL، localStorage، Git یا لاگ نوشته شوند.

رمز و تکرار آن باید یکسان باشند و سیاست رمز تازهٔ بالا را رعایت کنند. در این روش SMTP لازم نیست، رمز انتخابی هرگز به outbox نمی‌رود و اولین مدیر با ایمیل و همان رمز می‌تواند بلافاصله از مسیر عادی login وارد شود. ایجاد مدیر به معنی تخصیص حساب یا سیت OpenAI نیست. پاسخ موفق نشست واردشده ایجاد نمی‌کند؛ کاربر پس از موفقیت به فرم ورود می‌رود.

خطاهای اصلی: 403 برای کلید نصب نامعتبر/نصب غیرفعال، origin نامعتبر یا CSRF نامعتبر؛ 409 وقتی اولین کاربر قبلاً وجود دارد؛ 422 برای فیلد یا تکرار رمز نامعتبر؛ 429 برای سقف تلاش؛ 503 برای آماده‌نبودن سرویس. پاسخ خطای اعتبارسنجی، fields را با همان قالب API می‌دهد و مقدار رمز یا کلید را برنمی‌گرداند. POSTهای نصب با محدودکنندهٔ فایل خصوصی، حتی قبل از ساخت schema، حداکثر ۲۰ تلاش در ۱۵ دقیقه برای هر IP دارند.

نصب موفق مدیر را فعال و عضو گروه محافظت‌شدهٔ admin می‌کند. نصب تکراری، پیش از اجرای migration و دوباره داخل تراکنش با قفل هویت رد می‌شود؛ درخواست هم‌زمان مدیر دوم نمی‌سازد و کلید باقی‌مانده نمی‌تواند migrationهای بعدی را از مسیر نصب اجرا کند. GET پس از نصب `installed=true,available=false` می‌دهد و رابط فرم را نمی‌نمایاند. بعد از تأیید ورود، خاموش‌کردن setup و حذف اصل کلید خصوصی پاک‌سازی تکمیلی است.

برای سازگاری، روش قدیمی POST بدون password/password_confirm با همان X-Install-Token باقی است؛ در آن حالت SMTP فعال الزامی است، رمز تصادفی ساخته و فقط در صف ایمیل قرار می‌گیرد و CSRF فرم انتخاب رمز لازم نیست. فرم جدید همیشه از روش رمز انتخابی با CSRF استفاده می‌کند. راهنمای بسته و تنظیمات خصوصی در [INSTALL.md](INSTALL.md) است.
