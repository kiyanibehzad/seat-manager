import {footerMarkup} from './src/footer.js';
import { authMarkup, sessionMarkup, brandMarkup, themeMarkup } from './src/auth-view.js';
import { mountTheme } from './src/theme.js';

const cases = [
  ['login','ورود',{screen:'login'}],
  ['login-error','ورود · خطا',{screen:'login',error:'ایمیل یا رمز عبور صحیح نیست.'}],
  ['login-loading','ورود · در حال انجام',{screen:'login',loading:'در حال ورود…'}],
  ['register','ثبت‌نام',{screen:'register'}],
  ['register-error','ثبت‌نام · دامنهٔ نامعتبر',{screen:'register',error:'از ایمیل سازمانی با دامنهٔ مجاز شرکت استفاده کنید.',field:'email'}],
  ['verification-sent','ثبت‌نام · درخواست دریافت شد',{screen:'verification-sent'}],
  ['verify','تأیید ایمیل',{screen:'verify'}],
  ['pending','تأیید ایمیل · انتظار منابع انسانی',{screen:'pending'}],
  ['resend','ارسال دوبارهٔ تأیید',{screen:'resend'}],
  ['rate-limit','ارسال دوباره · محدودیت',{screen:'resend',error:'درخواست‌های زیادی ثبت شده است. کمی بعد دوباره تلاش کنید.'}],
  ['forgot','فراموشی رمز',{screen:'forgot'}],
  ['forgot-sent','بازیابی · درخواست دریافت شد',{screen:'forgot-sent'}],
  ['reset','انتخاب رمز جدید',{screen:'reset'}],
  ['reset-error','رمز جدید · خطا',{screen:'reset',error:'رمز عبور انتخاب‌شده شرایط لازم را ندارد.',field:'password'}],
  ['reset-success','رمز جدید · موفق',{screen:'reset-success'}],
  ['invalid-link','لینک بازیابی نامعتبر',{screen:'invalid-link',linkKind:'reset'}],
  ['invalid-verification','لینک تأیید نامعتبر',{screen:'invalid-link',linkKind:'verify'}],
  ['change-password','تغییر رمز',{screen:'change-password'}],
  ['change-success','تغییر رمز · موفق',{screen:'change-success'}],
  ['unavailable','خطای ارتباط',{screen:'unavailable'}],
  ['session','ورود انجام شد',{screen:'session'}],
];
const root=document.querySelector('#app');
const select=document.querySelector('#preview-state');
select.innerHTML=cases.map(([key,label])=>`<option value="${key}">${label}</option>`).join('');
const theme=mountTheme();
function render(key){
  const [, ,state]=cases.find(item=>item[0]===key) || cases[0];
  select.value=key;
  const content=state.screen==='session'?sessionMarkup({user:{first_name:'نام',last_name:'کاربر',email:'employee@example.com',app_role:'user'},permissions:['personal.read','personal.request']}):authMarkup({...state,values:{}});
  root.innerHTML=`<header class="app-header">${brandMarkup()}<div class="header-tools">${themeMarkup()}</div></header><main id="main-content"><section class="${state.screen==='session'?'session-container':'auth-stage'}" aria-label="پیش‌نمایش صفحه">${content}</section></main>${footerMarkup()}`;
  theme.sync();
  if(state.error){const error=root.querySelector('[data-form-error]');error.hidden=false;error.textContent=state.error;if(state.field)root.querySelector(`[name="${state.field}"]`)?.setAttribute('aria-invalid','true');}
  if(state.loading){const form=root.querySelector('form');form.setAttribute('aria-busy','true');for(const input of form.elements)input.disabled=true;form.querySelector('[data-form-status]').textContent=state.loading;}
  const url=new URL(location.href);url.searchParams.set('state',key);history.replaceState(null,'',url.pathname+url.search);
}
select.addEventListener('change',()=>render(select.value));
root.addEventListener('click',event=>{
  const link=event.target.closest('[data-screen]');
  if(link){event.preventDefault();render(link.dataset.screen);}
  const action=event.target.closest('[data-action]');
  if(action){event.preventDefault();render(action.dataset.action==='change-password'?'change-password':action.dataset.action==='session'?'session':'login');}
});
root.addEventListener('submit',event=>{
  event.preventDefault();
  const status=event.target.querySelector('[data-form-status]');
  if(status)status.textContent='این فرم فقط برای بررسی ظاهر است؛ اطلاعات ارسال نشد.';
});
render(new URL(location.href).searchParams.get('state') || 'login');
