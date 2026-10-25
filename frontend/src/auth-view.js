import {APP_NAME, APP_ICON_URL} from './brand.js';
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const route = (screen, label) => `<a href="?screen=${screen}" data-screen="${screen}">${label}</a>`;
const submit = (label, extra = '') => `<button class="button primary" type="submit" ${extra}>${label}</button>`;
const field = (name, label, {type='text', autocomplete='', value='', required=true, hint='', extra=''} = {}) => `<div class="field"><label for="auth-${name}">${label}</label><input id="auth-${name}" name="${name}" type="${type}" ${required?'required':''} ${autocomplete?`autocomplete="${autocomplete}"`:''} ${type==='email'?'dir="ltr" inputmode="email" autocapitalize="none" spellcheck="false"':type==='password'?'dir="ltr"':'dir="auto"'} value="${esc(value)}" ${hint?`aria-describedby="auth-${name}-hint"`:''} ${extra}>${hint?`<span class="hint" id="auth-${name}-hint">${hint}</span>`:''}<span class="field-error" id="auth-${name}-error" hidden></span></div>`;
const emailField = values => field('email','ایمیل سازمانی',{type:'email',autocomplete:'username',value:values.email,extra:'maxlength="254"'});
const passwordField = (name='password', label='رمز عبور', fresh=false) => field(name,label,{type:'password',autocomplete:fresh?'new-password':'current-password',extra:'maxlength="1024"'});
const errorSlot = '<div class="message error" data-form-error role="alert" hidden></div>';
const statusSlot = '<p class="form-status" data-form-status role="status" aria-live="polite"></p>';
const form = (kind, contents, label) => `<form data-form="${kind}" aria-label="${label}">${errorSlot}${contents}${statusSlot}</form>`;
const heading = (title, text='') => `<div class="auth-heading"><h1 data-page-heading tabindex="-1">${title}</h1>${text?`<p>${text}</p>`:''}</div>`;
const info = (text, kind='info') => `<div class="message ${kind}" role="status">${esc(text)}</div>`;
const back = label => `<div class="auth-footer">${route('login',label || 'بازگشت به ورود')}</div>`;

export function themeMarkup() {
  return `<fieldset class="theme-control"><legend>تم برنامه</legend>${[['system','سیستم'],['light','روز'],['dark','شب']].map(([key,label])=>`<label><input type="radio" name="appearance" value="${key}" ${key==='system'?'checked':''}><span>${label}</span></label>`).join('')}</fieldset>`;
}

export function brandMarkup() {
  return `<a class="brand" href="?screen=login" data-screen="login" aria-label="${APP_NAME}، ورود"><img class="brand-icon" src="${APP_ICON_URL}" alt="" width="34" height="34"><bdi dir="ltr">${APP_NAME}</bdi></a>`;
}

export function authMarkup(state) {
  const values=state.values || {};
  switch (state.screen) {
    case 'loading': return heading('در حال آماده‌سازی…') + '<p class="quiet" role="status">لطفاً کمی صبر کنید.</p>';
    case 'unavailable': return heading('ارتباط برقرار نشد','برنامه در حال حاضر در دسترس نیست.') + info(state.message || 'کمی بعد دوباره تلاش کنید.','error') + '<button class="button primary" data-action="retry">تلاش دوباره</button>';
    case 'register': return heading('ثبت‌نام با ایمیل سازمانی','پس از تأیید ایمیل، ثبت‌نام شما توسط منابع انسانی بررسی می‌شود.') + form('register',`<div class="name-fields">${field('first_name','نام',{autocomplete:'given-name',value:values.first_name,extra:'maxlength="100"'})}${field('last_name','نام خانوادگی',{autocomplete:'family-name',value:values.last_name,extra:'maxlength="100"'})}</div>${emailField(values)}${passwordField('password','رمز عبور',true)}${passwordField('password_confirm','تکرار رمز عبور',true)}<p class="hint">از ایمیل سازمانی خود استفاده کنید. دامنهٔ مجاز را شرکت تعیین می‌کند.</p>${submit('ثبت‌نام')}`,'ثبت‌نام') + `<div class="auth-footer">حساب دارید؟ ${route('login','وارد شوید')}</div>`;
    case 'forgot': return heading('بازیابی رمز عبور','ایمیل سازمانی خود را وارد کنید تا راهنمای بازیابی را دریافت کنید.') + form('forgot',`${emailField(values)}${submit('دریافت راهنمای بازیابی')}`,'درخواست بازیابی رمز') + back();
    case 'verification-sent': return heading('درخواست ثبت‌نام دریافت شد') + info(state.message || 'اگر این نشانی مجاز و قابل ثبت باشد، راهنمای تأیید به ایمیل سازمانی شما فرستاده می‌شود. پوشهٔ هرزنامه را هم بررسی کنید.') + '<p class="quiet">بعد از تأیید ایمیل، فعال‌شدن حساب به تأیید منابع انسانی نیاز دارد.</p>' + form('resend',`${emailField(values)}${submit('ارسال دوبارهٔ راهنمای تأیید','data-resend')}`,'ارسال دوبارهٔ راهنمای تأیید') + back();
    case 'resend': return heading('ارسال دوبارهٔ راهنمای تأیید','ایمیل سازمانی ثبت‌نام خود را وارد کنید.') + form('resend',`${emailField(values)}${submit('ارسال راهنمای تأیید','data-resend')}`,'ارسال دوبارهٔ راهنمای تأیید') + back();
    case 'forgot-sent': return heading('درخواست بازیابی دریافت شد') + info(state.message || 'اگر حساب فعالی با این ایمیل وجود داشته باشد، راهنمای بازیابی برای آن ارسال می‌شود. پوشهٔ هرزنامه را هم بررسی کنید.') + `<div class="auth-footer">${route('forgot','ثبت درخواست دوباره')}<span aria-hidden="true"> · </span>${route('login','بازگشت به ورود')}</div>`;
    case 'verify': return heading('تأیید ایمیل سازمانی','برای تأیید نشانی ایمیل و ادامهٔ ثبت‌نام، دکمهٔ زیر را بزنید.') + form('verify',submit('تأیید ایمیل'),'تأیید ایمیل') + back();
    case 'pending': return heading('منتظر تأیید منابع انسانی') + info(state.message || 'ایمیل شما تأیید شد. ثبت‌نام برای بررسی در اختیار منابع انسانی است. پس از فعال‌شدن حساب، می‌توانید وارد شوید.') + '<p class="quiet">فعال‌شدن حساب برنامه، سیت OpenAI تخصیص نمی‌دهد.</p>' + back('بازگشت به صفحهٔ ورود');
    case 'reset': return heading('انتخاب رمز جدید','رمز جدید را برای حساب خود وارد کنید.') + form('reset',`${passwordField('password','رمز جدید',true)}${passwordField('password_confirm','تکرار رمز جدید',true)}${submit('ذخیرهٔ رمز جدید')}`,'انتخاب رمز جدید') + back();
    case 'reset-success': return heading('رمز عبور تغییر کرد') + info('اکنون می‌توانید با رمز جدید وارد شوید.','success') + `<a class="button primary" href="?screen=login" data-screen="login">ورود به حساب</a>`;
    case 'invalid-link': return heading('این لینک قابل استفاده نیست','ممکن است لینک منقضی شده یا قبلاً استفاده شده باشد.') + `<div class="auth-links">${route(state.linkKind==='verify'?'resend':'forgot',state.linkKind==='verify'?'دریافت راهنمای تأیید جدید':'دریافت لینک بازیابی جدید')}${route('login','بازگشت به ورود')}</div>`;
    case 'change-password': return heading('تغییر رمز عبور') + form('change-password',`${passwordField('current_password','رمز فعلی')}${passwordField('password','رمز جدید',true)}${passwordField('password_confirm','تکرار رمز جدید',true)}${submit('ذخیرهٔ رمز جدید')}`,'تغییر رمز عبور') + '<div class="auth-footer"><button class="text-button" data-action="session">بازگشت به حساب</button></div>';
    case 'change-success': return heading('رمز عبور تغییر کرد') + info('رمز جدید ذخیره شد.','success') + '<button class="button primary" data-action="session">بازگشت به حساب</button>';
    default: return heading('ورود به حساب','برای مدیریت حساب‌های OpenAI وارد شوید.') + form('login',`${emailField(values)}${passwordField()}<div class="form-link">${route('forgot','رمز عبور را فراموش کرده‌اید؟')}</div>${submit('ورود')}`,'ورود به حساب') + `<div class="auth-footer">حساب ندارید؟ ${route('register','ثبت‌نام با ایمیل سازمانی')}</div><div class="auth-secondary">${route('resend','ارسال دوبارهٔ راهنمای تأیید')}</div>`;
  }
}

export function sessionMarkup(session) {
  const person=session.user;
  const name=[person.first_name,person.last_name].filter(Boolean).join(' ') || person.email;
  const role={admin:'مدیر کل',hr:'منابع انسانی',operator:'اپراتور حساب‌ها',user:'کاربر عادی'}[person.app_role] || '';
  return `<section class="session-stage" aria-label="حساب واردشده"><div class="session-top"><div><h1 data-page-heading tabindex="-1">${esc(name)}</h1><p class="quiet">${esc(role)}</p><bdi dir="ltr" class="session-email">${esc(person.email)}</bdi></div><div class="session-actions"><button class="button" data-action="change-password">تغییر رمز عبور</button><button class="button" data-action="logout">خروج</button></div></div><div class="message success" role="status">ورود شما انجام شد.</div><p class="quiet">می‌توانید رمز عبور خود را تغییر دهید یا از حساب خارج شوید.</p><div class="message error" role="alert" data-form-error hidden></div></section>`;
}

// Empty until the real panel is integrated. No fabricated count or announcement.
export function notificationSlotMarkup() {
  return '<div data-notification-slot hidden></div>';
}
