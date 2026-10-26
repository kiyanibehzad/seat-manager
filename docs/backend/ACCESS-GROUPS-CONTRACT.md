# قرارداد گروه‌های دسترسی — ۲۶ اکتبر ۲۰۲۶

هر کاربر دقیقاً یک `access_group_id` دارد؛ `department_id` مستقل و فقط عامل سازمانی/امتیاز صف است. چندگروهی، ارث‌بری و تعریف مجوز دلخواهِ فیلدبه‌فیلد نداریم. همهٔ گروه‌ها بخش شخصی و اعلان خودشان را دارند. نام گروه نقشی در امتیاز یا مجوز ندارد.

## قابلیت‌های قابل انتخاب و وابستگی‌ها

| capability | عنوان checkbox | وابستگی لازم | مجوزهای داخلی حاصل |
|---|---|---|---|
| `dashboard.read` | کارهای روزانه | `seats.manage` | خواندن dashboard و مسیر کامل پرونده از قابلیت سیت‌ها |
| `seats.manage` | مدیریت سیت‌ها و درخواست‌ها | ندارد | seats.manage، requests.manage، capacity.read و تاریخچه/صف عملیاتی |
| `registrations.review` | تأیید و رد ثبت‌نام | ندارد | فقط فهرست منتظر تأیید و approve/reject؛ بدون لیست/ویرایش افراد |
| `people.manage` | مدیریت افراد | ندارد | فهرست، ساخت تکی/لیستی، مشخصات/بخش/سمت و فعال/غیرفعال‌کردن |
| `departments.manage` | مدیریت بخش‌ها و امتیازها | `people.manage` | تعریف/امتیاز بخش و کنترل امتیاز ویژهٔ کارکنان |

سرور مجموعهٔ ناقص را با 422 و fields.capabilities رد می‌کند؛ catalog وابستگی را می‌دهد تا رابط checkbox لازم را هم روشن کند. خاموش‌کردن پیش‌نیاز، در رابط باید قابلیت وابسته را هم خاموش کند. کنترل امتیاز ویژه فقط با departments.manage است؛ people.manage به‌تنهایی special_priority را نمی‌نویسد و رابط کنترل ویرایش آن را نشان ندهد. این یک قاعدهٔ ثابتِ قابلیت «امتیازها» است، نه تنظیم مجوز برای تک‌تک فیلدها. خواندن بخش‌ها برای انتخاب بخش در فرم ثبت‌نام/کارمند، مثل قبل برای کاربران واردشده مجاز است.

مدیر کل با گروه محافظت‌شدهٔ admin همهٔ قابلیت‌ها را دارد. finance.read، capacity.manage، settings.manage، roles.manage و access_groups.manage فقط متعلق به همین گروه‌اند و checkbox نیستند. گروه admin قابل حذف/کاهش قابلیت/ویرایش نیست. آخرین مدیر کل فعالِ تأییدشده قابل غیرفعال‌کردن یا انتقال به گروه دیگر نیست.

گروه‌های اولیه: user=فقط شخصی؛ hr=registrations.review+people.manage+departments.manage؛ operator=dashboard.read+seats.manage+registrations.review؛ admin=همه. گروه user نیز شخصی‌فقط و محافظت‌شده است (protected=true، is_super_admin=false، capabilities=[]). گروه‌های hr/operator و گروه‌های سفارشی قابل ویرایش‌اند؛ تغییر گروه روی همهٔ اعضای همان گروه اثر دارد. کاربران تازه/تأیید ثبت‌نام/ساخت تکی و لیستی، به‌صورت پیش‌فرض عضو گروه اولیهٔ user می‌شوند؛ فقط مدیر کل می‌تواند گروه فرد را عوض کند.

## endpointها

تمام مسیرها زیر `api/index.php?route=…` با همان envelope/session/CSRF هستند. سه mutation جدید idempotency_key می‌خواهند و تغییرات زیر قفل identity و operations با بررسی مجدد مجوز انجام می‌شوند.

| route | روش | ورودی / data |
|---|---|---|
| `/admin/access-groups` | GET | فقط مدیر کل؛ `{items:[group],capability_catalog:[…]}` |
| `/admin/access-groups` | POST | `{name,capabilities:[code…],idempotency_key}` → `{group}` |
| `/admin/access-groups` | PATCH | `{group_id,name,capabilities:[code…],expected_revision,idempotency_key}` → `{group}` |
| `/admin/users/access-group` | POST | `{user_id,access_group_id,idempotency_key}` → `{user}`؛ کاربر تأییدشده |

`group`: `{id:int,name:string,system_key:string|null,is_super_admin:bool,protected:bool,revision:int,capabilities:[code…],user_count:int}`. catalog هر قابلیت `{code,label,requires:[code…]}` است. id واقعی از API گرفته شود؛ اسم/system_keyِ گروه در کلاینت مبنای مجوز نباشد. DELETE وجود ندارد.

نمونهٔ ساخت: `{"name":"پشتیبانی","capabilities":["dashboard.read","seats.manage","registrations.review"],"idempotency_key":"uuid-for-this-command"}`.

`POST /admin/users/role` برای سازگاری باقی می‌ماند: app_role یکی از user/hr/operator/admin را به گروه اولیهٔ متناظر نگاشت می‌کند؛ فقط مدیر کل، همان قواعد انتساب/آخرین مدیر، بدون ساخت مجوز از app_role. مسیر جدید برای گروه سفارشی استفاده شود. ایجاد/ویرایش معمول کارکنان، bulk و approve هیچ group_id/app_role دلخواهی نمی‌پذیرند.

## user / session

همهٔ پاسخ‌های user، از جمله session و فهرست افراد، این فیلدهای افزوده را دارند:

```json
{"access_group_id":3,"access_group_name":"اپراتور حساب‌ها","is_super_admin":false}
```

session علاوه بر permissions سابق، `capabilities:[پنج کد قابل انتخابِ مجاز همین گروه]` و `access_groups_ready:bool` می‌دهد. personal.read/personal.request/notifications.read برای همه هستند. برای نمایش منو/عملیات به permissions تکیه شود؛ app_role فقط سازگاری داده‌ای است. نمونهٔ گروه اپراتور بعد از migration، registrations.review دارد ولی people.manage ندارد. dashboard فقط با dashboard.read و فهرست ثبت‌نام فقط با registrations.review باز می‌شوند.

تغییر نام/قابلیت گروه با revision تازه ثبت می‌شود و auth_version اعضا را افزایش می‌دهد؛ انتساب گروه هم auth_version فرد را افزایش می‌دهد. نشست‌های قدیمی در درخواست بعدی باطل می‌شوند؛ رابط از session تازه استفاده کند. تغییر access_group، department/position/special_priority یا score را دست نمی‌زند.

## اعلان و ایمیل

registration.ready به کاربران فعال/تأییدشدهٔ دارای registrations.review و request.created/expiry.action_required به دارندگان قابلیت سیت فرستاده می‌شوند؛ تطبیق بر پایهٔ گروه واقعی است. شخصی‌ها گیرندهٔ خودشان می‌مانند. پس از از‌دست‌رفتن قابلیت، اعلان‌های مدیریتی وابسته در فهرست/شمارش/علامت‌گذاری پنهان‌اند و outbox پیش از SMTP مجوز گیرنده را دوباره بررسی می‌کند. SMTP/توکن/رمز در پاسخ یا لاگ نمی‌آید.

## ترتیب ادغام

migration افزایشی `003_access_groups.sql` داده‌های فعلی را با app_role قبلی به چهار گروه اولیه نگاشت و نشست‌ها را با auth_version تازه منقضی می‌کند؛ هویت/حساب/سیت/سوابق/امتیاز حفظ می‌شوند. نصب تازه هم همین چهار گروه را می‌سازد. فایل‌های 001/002 تغییر نمی‌کنند.

**در این مرحله migration روی main یا QA اجرا نمی‌شود.** تا قبل از اعمال 003، session.access_groups_ready=false و نام/id گروه null است؛ رفتار مجوزهای قبلی برای تداوم خدمت برقرار است و endpointهای گروه 503 migration_required می‌دهند. پس از marker migration، تنها گروه واقعی مبنای مجوز است. هماهنگ‌کننده اعمال migration و استقرار فرانت را نهایی می‌کند. راه‌انداز فعلی dev-services خودش migrationهای موجود را اجرا می‌کند؛ قبل از هماهنگی ادغام، برای اعمال ناخواستهٔ 003 سرویس‌ها را restart نکنید.
