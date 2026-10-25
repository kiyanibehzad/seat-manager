# قرارداد عملیات مرحلهٔ دوم — نسخهٔ ۱

مبنای session/CSRF، JSON envelope، same-origin، `api/index.php?route=…` و خطاها همان API.md است؛ مسیرهای مرحلهٔ اول تغییر نمی‌کنند. همهٔ POST/PATCHها CSRF می‌خواهند. id عدد و bool واقعی است؛ تاریخ nullable با ISO-8601 و UTC برمی‌گردد. ساعت/روز جاری در Asia/Tehran سنجیده می‌شود. ورودی زمانی باید offset/Z صریح داشته باشد؛ رابط شمسی آن را به instant تبدیل می‌کند. برای انتخاب روز کامل: شروع 00:00:00 و پایان 23:59:59 ایران. زمان ثبت، زمان مشاهدهٔ نتیجه، بازهٔ درخواستی و بازهٔ مصوب فیلدهای جدا هستند.

در همهٔ mutationهای عملیات `idempotency_key` (۸ تا ۱۰۰ نویسه ASCII، ترجیحاً UUID تازه) بفرستید. تکرار همان کلید/actor/body همان نتیجه را برمی‌گرداند؛ همان کلید با محتوای متفاوت 409 است. `state_version` و `expected_revision` از پاسخ آخر گرفته شوند؛ 409 یعنی دوباره بخوانید. تأیید ظاهری جای کنترل سرور نیست.

## بخش شخصی — برای هر چهار نقش

| route | روش | ورودی / خروجی data |
|---|---|---|
| `/accounts` | GET | `items:[account]` فقط مال خود فرد |
| `/accounts` | POST | `email,alias?,idempotency_key` → `{account}`؛ ایمیل trim/lower و سراسری یکتا |
| `/accounts` | PATCH | `account_id,alias,idempotency_key` → `{account}`؛ alias خالی مجاز، ایمیل/مالک قابل تغییر نیست |
| `/requests` | GET | `items:[request]` شخصی؛ همان ETA مشترک جزئیات |
| `/requests` | POST | `account_id,kind:seat|renewal,seat:standard|premium,temporary:bool,starts_at?,ends_at?,reason_code?,reason_text?,idempotency_key` → `{request}` |
| `/requests/detail` | GET | `id` → `{request,history}` فقط خود فرد؛ اطلاعات طرف دیگر انتقال و مالی ندارد |
| `/requests/cancel` | POST | `request_id,idempotency_key`؛ فقط pending متعلق به فرد |
| `/history` | GET | `before_id?,limit?,account_id?` → `{items,next_before_id}` فقط تغییر حساب‌های خود فرد |
| `/reasons` | GET | `{items:[{code,label}],other_code:"other"}`؛ فهرست آماده از تنظیم مدیر می‌آید؛ مقدار ساختگی اولیه ندارد |

`account`: `id,owner_id,email,alias,seat,assignment_id,state_version,starts_at,ends_at,assigned_at,return_seat,assignment_version,locked,needs_review`. seat یکی از none/standard/premium است؛ label رابط No Seat/Standard/Premium. No Seat تمام تاریخ‌های تخصیص را null دارد. `return_seat` پیشنهاد برگشت به وضعیت قبل است؛ در تمدید از بین نمی‌رود. بخش شخصی operation کامل یا حساب طرف دیگر را دریافت نمی‌کند.

`request`: هویت و حساب خود، kind، requested_seat، temporary، requested_starts_at/requested_ends_at، approved_starts_at/approved_ends_at، status (pending/ongoing/completed/rejected/cancelled)، operation_phase، reason_code/text، created_at، reviewed_at/completed_at، score، blockers و `eta:{rank,estimated_at,conditional,reason}`. ordinary request هم‌حساب باز تکراری نمی‌شود؛ اصلاح با لغو/جدید. renewal به assignment/version جاری Premium موقت متصل و متن دلیل و پایان تازه الزامی است. Standard پیش‌فرض temporary=false است؛ حالت بدون پایان تاریخ مخفی نمی‌پذیرد.

## دسترسی عملیاتی — admin/operator، نه HR

| route | روش | ورودی / خروجی |
|---|---|---|
| `/admin/accounts` | GET | `owner_id?,q?,before_id?,limit?` → `{items,next_before_id}` همراه هویت فرد برای عملیات |
| `/admin/queue` | GET | `{requests,renewals,capacity}`؛ صف زنده با امتیاز بخش+سرپرست+ویژه، تمدیدها جدا بر نزدیک‌ترین پایان |
| `/admin/dashboard` | GET | `{today,tomorrow,counts,iran_today}`؛ فقط موعد/واقعیت ثبت‌شده، بدون تاریخ جعلی ETA |
| `/admin/requests/detail` | GET | `id` → درخواست و حساب/سوابق مرتبطِ عملیاتی |
| `/admin/requests/revalidate` | POST | `request_id,state_version,reason,idempotency_key`؛ بازبینی صریح درخواست pending بعد از تغییر حساب، بدون بازنویسی درخواست اصلی |
| `/admin/requests/reject` | POST | `request_id,reason,idempotency_key`؛ pending فقط، نتیجه/اعلان واقعی |
| `/admin/requests/start` | POST | `request_id,state_version,mode:reserve|transfer|convert,source_account_id?,source_state_version?,source_final_seat?,approved_ends_at?,override_reason?,reason,idempotency_key` → `{operation}` |
| `/admin/operations/start` | POST | اقدام مستقیم: `kind:direct|return|reclaim|scheduled_downgrade|paid_conversion,account_id,state_version,target:{seat,starts_at?,ends_at?},effect_at?,reason,idempotency_key` → `{operation}` |
| `/admin/operations` | GET | `status?,before_id?,limit?` → `{items,next_before_id}`؛ فهرست کامل ongoing و نتایج تکمیل/لغو |
| `/admin/operations/detail` | GET | `id` → `{operation,history}`، هویت دقیق هر حساب، قبل/هدف/واقعیت، hold و مانع |
| `/admin/operations/invitation` | POST | `operation_id,waiting:bool,idempotency_key`؛ فقط برچسب همان ongoing، تکمیل نیست |
| `/admin/operations/defer` | POST | `operation_id,effect_at,reason,idempotency_key`؛ ثبت زمان اثر آینده، بدون تغییر سیت یا تعداد خرید |
| `/admin/operations/result` | POST | ساختار زیر؛ واقعیت جزئی یا تکمیل نهایی |
| `/admin/operations/cancel` | POST | `operation_id,reason,idempotency_key`؛ فقط وقتی هیچ واقعیتی ثبت نشده؛ hold/lock آزاد و درخواست به pending برمی‌گردد |
| `/admin/corrections` | POST | `account_id,state_version,state:{seat,starts_at?,ends_at?,return_seat?},observed_at,reason,evidence,confirmed:true,idempotency_key`؛ اصلاح واقعی append-only؛ برای حساب قفل‌شده از result همان operation استفاده شود |
| `/admin/history` | GET | `account_id?,operation_id?,before_id?,limit?` → تاریخچهٔ کاملِ قبل/بعد/عامل/ثبت/مشاهده/دلیل؛ حذف و ویرایش سابقه API ندارد |
| `/admin/capacity` | GET | purchased/assigned/held/free برای هر نوع، revision و reconciliation_required |

شروع، فقط lock/hold می‌سازد. کلیک شروع یا ارسال دعوت، تخصیص واقعی نیست. تأیید نهاییِ تازه، مالکیت، نسخهٔ وضعیت و ظرفیت را دوباره می‌سنجد. انتقال Premium فقط از شخص دیگر و فقط مبدأ Premium بدون عملیات باز؛ وضعیت بعدِ مبدأ صریح standard/none. هر دو حساب قفل می‌شوند؛ Premium مبدأ دوباره رزرو عددی نمی‌شود. چهار ترکیب SPEC با hold لازم Standard رعایت می‌شوند.

```json
{
  "operation_id":10,"complete":false,"reason":"ثبت نتیجهٔ بخش انجام‌شده",
  "evidence":"نتیجهٔ پنل OpenAI برای همین حساب بررسی شد",
  "results":[{"account_id":7,"owner_id":3,"email":"owner@example.test","state_version":2,"confirmed":true,"seat":"standard","starts_at":null,"ends_at":null,"observed_at":"2026-10-24T16:00:00Z"}],
  "capacity_confirmed":false,"idempotency_key":"unique-command-key"
}
```

برای complete=true، **تمام حساب‌های operation** با email/owner_id/state_version تازه و confirmed=true دوباره فرستاده شوند؛ هر واقعیت باید با هدف مصوب بخواند. نتیجهٔ جزئی وضعیت واقعی همان طرف، سابقه و hold باقی‌مانده را ثبت می‌کند و operation partial/ongoing می‌ماند. واقعیت متفاوت با هدف با reason/evidence ثبت می‌شود؛ تکمیلِ خلاف هدف یا تأیید یک‌طرفه قبول نیست. تاریخ گذشتهٔ پایان، واقعیتِ عملیات ازقبلشروع‌شده را بی‌صدا رد/عوض نمی‌کند. shortage ناشی از واقعیت جزئی/اصلاح، آشکار و مانع تخصیص تازه است؛ دادهٔ واقعی با هدف فرضی جایگزین نمی‌شود. هیچ درخواست مرتبط خودکار رد/لغو نمی‌شود؛ staleها نیاز به revalidate دارند.

## فقط admin

| route | روش | ورودی / خروجی |
|---|---|---|
| `/admin/capacity` | POST | `standard,premium,expected_revision,confirmed:true,reason,evidence,finance?,idempotency_key`؛ تعدادهای واقعی ثبت‌شده، نه مبلغ کیف پول |
| `/admin/finance` | GET | ledger واقعی ثبت ظرفیت/تبدیل؛ مبلغ نامعلوم null است |
| `/admin/reasons` | POST | `items:[{code,label}],idempotency_key`؛ کد other رزروشده؛ نام‌های نهایی را مسئول وارد می‌کند |

finance اختیاری: `{amount:"…",currency:"…",reference:"…"}` صرفاً اطلاعات واقعیِ اقدام خارج برنامه است. برنامه هیچ پرداختی انجام نمی‌دهد. paid_conversion یا mode=convert فقط admin؛ تبدیل Standard→Premium با تعداد خرید standard−1/premium+1 و تغییر همان حساب در یک transaction **هنگام ثبت واقعیت و capacity_confirmed=true** انجام می‌شود. شروع آن تعداد خرید را تغییر نمی‌دهد. scheduled_downgrade برای دورهٔ بعد می‌تواند بدون Standard آزاد ثبت شود؛ تا اثر واقعی Premium می‌ماند، خرید/ظرفیت آینده قطعی فرض نمی‌شود. اگر تکمیل به تبدیل تعداد خرید نیاز دارد، تأیید آن فقط admin است.

## ورود لیستی کارکنان — admin/hr

`POST /admin/users/bulk-preview`: `{rows:[{email,first_name,last_name,department_id,organizational_position,employee_number?,initial_openai_email?}]}` حداکثر ۲۰۰ ردیف/۲۵۶KiB → `{batch_id,expires_at,rows:[{row,normalized,errors}],valid}`. هیچ کاربر/رمزی در preview ساخته نمی‌شود.

`POST /admin/users/bulk-commit`: `{batch_id,idempotency_key}`؛ فقط همان reviewer و تا یک ساعت. تمام ردیف‌ها و یکتایی ایمیل برنامه/OpenAI دوباره داخل transaction بررسی می‌شوند؛ خطای حتی یک ردیف، هیچ کاربر/ایمیل جزئی ثبت نمی‌کند. پاسخ `{users:[user…]}` بدون رمز. مسیر تکی `/admin/users` هم `initial_openai_email?` می‌پذیرد؛ مجوز نقش تازه/امتیاز خودکار ندارد.

## اعلان، ایمیل و یادآوری

ثبت درخواست به admin/operator، شروع/رد/تکمیل به owner و تغییر واقعی سیت به owner همان حساب notification/outbox واقعی می‌دهد. پیام انتقال شخصی فقط ایمیل/قبل/بعد خودش را دارد. cron یادآوری Premium موقت را از assignment واقعی، owner/version/end جاری دوباره اعتبارسنجی می‌کند؛ یک روز ایران پیش از پایان، in-app + ایمیل با لینک تمدید، یک‌بار برای هر نسخه. تمدید/تغییر/انتقال یادآوری قبلی را لغو/تنظیم تازه می‌کند. پایان هیچ سیت/ظرفیتی را خودکار تغییر نمی‌دهد. زمان‌بندی واقعی توسعه صرفاً local sink است.

## نسخه‌ها و خاتمهٔ ناموفق — روشن‌سازی اتصال

ورودی ISO با fraction مثل خروجی JS `2026-10-24T16:00:00.000Z` هم پذیرفته و به دقت ثانیه UTC نرمال می‌شود.

| mutation | نسخهٔ لازم |
|---|---|
| admin/requests/start | `state_version` مقصد؛ برای mode=transfer، `source_state_version` مبدأ هم لازم است |
| admin/operations/start، admin/corrections، admin/requests/revalidate | `state_version` همان حساب |
| admin/operations/result یا abort | `state_version` تازه در **هر** عضو results |
| admin/capacity POST | `expected_revision` ظرفیت |
| ایجاد/alias حساب، ایجاد/لغو/رد درخواست، invitation/defer/cancel، reasons، bulk | نسخهٔ حساب از کلاینت لازم نیست؛ وضعیت و مجوز تازه زیر قفل DB کنترل می‌شوند؛ batch صاحب/انقضای خودش را دارد |

`POST /admin/operations/abort`: `operation_id,reason,evidence,results:[تمام حساب‌ها با همان ساختار result],capacity_reversed?:bool,finance?,idempotency_key`. هر نتیجه باید بازگشت واقعی به seat/بازهٔ **before اصلی** همان حساب را تصدیق کند. هیچ تغییری در OpenAI خودکار انجام نمی‌شود. واقعیت بازگشت append-only ثبت، lock/hold آزاد و operation با `status:cancelled,outcome:rolled_back` خاتمه می‌یابد؛ ادعای انجام هدف اولیه نمی‌شود. درخواست مربوط pending و، اگر نسخه عوض شده، نیازمند revalidate می‌ماند. cancel ساده فقط قبل از هر واقعیت ثبت‌شده است؛ outcome آن cancelled_before_result است. نتیجهٔ موفق outcome=target_completed و اصلاح مستقل outcome=corrected دارد.

برای rollback تبدیل ظرفیتِ قبلاً ثبت‌شده، فقط اگر تعدادهای خرید واقعاً هم برگشته‌اند admin با capacity_reversed=true دلتا را معکوس می‌کند؛ بازگشت خودکار خرید وجود ندارد. اگر صرفاً حساب از Standard آزاد استفاده کرده باشد، این flag false می‌ماند.

scheduled_downgrade دو نتیجهٔ واقعی متمایز دارد: استفاده از Standard آزاد، با capacity_confirmed=false، خرید را ثابت می‌گذارد؛ اگر OpenAI تعداد خرید را تبدیل کرده باشد admin با capacity_confirmed=true و شاهد، delta standard+1/premium−1 را هم‌زمان با ثبت واقعیت حساب اعمال می‌کند. تا تأیید واقعی، فقط موعد و قفل پیگیری وجود دارند.

`GET /admin/requests?status=…&before_id=…&limit=…&owner_id=…&account_id=…` برای admin/operator، فهرست همهٔ درخواست‌ها (حتی رد/لغوشدهٔ بدون operation) را با `{items:[request],next_before_id}` برمی‌گرداند؛ همهٔ فیلترها اختیاری‌اند و limit حداکثر ۱۰۰ است. HR مجوز این سوابق عملیاتی را ندارد.

Dashboard پرونده‌ها را یک‌بار می‌سازد و رویدادهای مرتبط را در همان ردیف نگه می‌دارد: pending بررسی، حتی با شروع هفتهٔ بعد، در امروز است؛ تمدید pending مقدم است و پایان همان حساب ردیف دوباره نمی‌سازد. عملیات فعال working/partial/waiting_invite امروز است، با اشاره به پایان نزدیک. scheduledِ بدون کار فوری فقط در موعد اثر امروز/فردا ظاهر می‌شود؛ ماه بعد از هر دو تب پنهان و در فهرست عملیات محفوظ است. یک پرونده هم‌زمان در دو تب تکرار نمی‌شود. ظرفیت با یک statement snapshot خوانده می‌شود و GETهای ترکیبی queue/dashboard/history در transaction خواندنی یکپارچه‌اند.

Timestampهای سوابق: `history.items[].recorded_at` زمان ثبت برنامه و `observed_at` زمان مشاهدهٔ واقعیت است؛ سابقه فیلد created_at ندارد. `finance.items[].recorded_at` زمان ثبت ledger است. operation و request همچنان created_at و completed_at خودشان را دارند. بخش شخصی reason/evidence داخلی، نام actor و شناسهٔ درخواست شخص دیگر را دریافت نمی‌کند.

## مرحلهٔ عملیات در درخواست شخصی — ۲۵ اکتبر ۲۰۲۶

`request.operation_phase` فقط از operation فعالِ دارای **همان request_id** خوانده می‌شود؛ مقدار آن برای ongoing یکی از working/waiting_invite/partial/scheduled و در نبود عملیات فعالِ مرتبط null است. برای pending و نتیجه‌های پایان‌یافته null است. عملیات دیگری که همان حساب را قفل کرده، phase خودش را به این درخواست منتقل نمی‌کند. وضعیت اصلی همچنان ongoing است؛ وضعیت تازه‌ای به نام waiting_invite ساخته نشده است.

نمونهٔ فیلدهای مربوط: `{"status":"ongoing","operation_phase":"waiting_invite","eta":{"rank":null,"estimated_at":null,"conditional":false,"reason":"منتظر پذیرش دعوت و بررسی عضویت و سیت همین حساب در OpenAI توسط مدیر است."}}`. رابط می‌تواند برچسب «منتظر پذیرش دعوت» را با این enum نشان دهد؛ پذیرش دعوت/ثبت سیت هنوز تأیید نشده است. فازهای working/partial/scheduled هم توضیح ETA متناسب و بدون تاریخ ساختگی دارند.

این افزوده در فهرست و جزئیات شخصی یکسان است و operation کامل، شناسه/هویت/ایمیل طرف دیگر انتقال یا دلیل داخلی مدیر را به پاسخ شخصی اضافه نمی‌کند. فیلد مدیریتی کارکنان `GET /admin/users → data.items[].score` در API.md تعریف شده است.
