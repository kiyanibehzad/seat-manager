import {APP_NAME,APP_ICON_URL} from './brand.js';
import {themeMarkup} from './auth-view.js';
import {mountTheme} from './theme.js';
import {footerMarkup} from './footer.js';

const root=document.querySelector('#app');
root.innerHTML=`<header class="app-header"><a class="brand" href="./" aria-label="${APP_NAME}، ورود"><img class="brand-icon" src="${APP_ICON_URL}" alt="" width="34" height="34"><bdi dir="ltr">${APP_NAME}</bdi></a><div class="header-tools">${themeMarkup()}</div></header><main id="main-content"><section class="auth-stage" aria-label="راه‌اندازی اولیه"></section></main>${footerMarkup()}`;
mountTheme();
const stage=root.querySelector('.auth-stage'),endpoint=new URL('./api/install.php',location.href);
let csrf=null,busy=false;
const heading='<div class="auth-heading"><h1 tabindex="-1">ساخت اولین مدیر کل</h1><p>این مرحله فقط یک‌بار انجام می‌شود. پس از ساخت حساب، با ایمیل و رمز انتخابی خود وارد می‌شوید.</p></div>';
const field=(name,label,{type='text',autocomplete='off',max=100,hint=''}={})=>`<div class="field"><label for="install-${name}">${label}</label><input id="install-${name}" name="${name}" type="${type}" autocomplete="${autocomplete}" maxlength="${max}" required ${type==='email'||type==='password'?'dir="ltr"':'dir="auto"'} ${type==='email'?'inputmode="email" autocapitalize="none" spellcheck="false"':''} ${hint?`aria-describedby="install-${name}-hint"`:''}>${hint?`<span class="hint" id="install-${name}-hint">${hint}</span>`:''}<span class="field-error" data-error-for="${name}" hidden></span></div>`;
function closed(installed){stage.innerHTML=`<div class="auth-heading"><h1>${installed?'راه‌اندازی انجام شده است':'راه‌اندازی فعال نیست'}</h1></div><p class="quiet">${installed?'حساب مدیر کل قبلاً ساخته شده است. برای استفاده از برنامه وارد شوید.':'مسئول راه‌اندازی باید نصب اولیه را روی سرور آماده کند.'}</p><a class="button primary" href="./?screen=login">ورود به برنامه</a>`;}
function form(){stage.innerHTML=heading+`<form aria-label="ساخت اولین مدیر کل" data-install-form><div class="message error" data-install-error role="alert" hidden></div>${field('install_token','کلید نصب یک‌بارمصرف',{type:'password',max:256,hint:'این کلید هنگام آماده‌سازی سرور ساخته می‌شود.'})}<div class="name-fields">${field('first_name','نام',{autocomplete:'given-name'})}${field('last_name','نام خانوادگی',{autocomplete:'family-name'})}</div>${field('email','ایمیل مدیر کل',{type:'email',autocomplete:'username',max:254})}${field('department_name','نام بخش اولیه',{max:120,hint:'بخش‌های دیگر را پس از ورود می‌توانید اضافه کنید.'})}${field('password','رمز عبور مدیر کل',{type:'password',autocomplete:'new-password',max:72,hint:'حداقل ۱۲ نویسه؛ رمز پیش‌فرضی وجود ندارد.'})}${field('password_confirm','تکرار رمز عبور',{type:'password',autocomplete:'new-password',max:72})}<p class="hint">ورود اولیه به ارسال ایمیل وابسته نیست. ارسال ایمیل و بازیابی رمز در مرحلهٔ راه‌اندازی ایمیل فعال می‌شوند.</p><button class="button primary" type="submit">ساخت مدیر کل و تکمیل راه‌اندازی</button><p class="form-status" data-install-status role="status" aria-live="polite"></p></form>`;}
async function readStatus(){
  const response=await fetch(endpoint,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}}),json=await response.json();
  if(!response.ok||!json.ok)throw new Error(json.error?.message||'اطلاعات نصب دریافت نشد.');
  if(typeof json.data?.csrf_token!=='string')throw new Error('نشست فرم آماده نشد. صفحه را تازه کنید.');
  csrf=json.data.csrf_token;return json.data;
}
async function load(){
  stage.innerHTML='<p class="quiet" role="status">در حال بررسی وضعیت راه‌اندازی…</p>';
  try{const status=await readStatus();if(status.installed||!status.available)closed(status.installed);else form();}
  catch(error){stage.innerHTML='<div class="auth-heading"><h1>ارتباط برقرار نشد</h1></div><div class="message error" data-install-error role="alert"></div><button class="button" data-install-retry>تلاش دوباره</button>';stage.querySelector('[data-install-error]').textContent=error.message;}
}
stage.addEventListener('click',event=>{if(event.target.closest('[data-install-retry]'))void load();});
stage.addEventListener('submit',async event=>{
  if(!event.target.matches('[data-install-form]'))return;event.preventDefault();if(busy)return;
  const form=event.target,errorBox=form.querySelector('[data-install-error]'),status=form.querySelector('[data-install-status]'),submit=form.querySelector('[type="submit"]');
  errorBox.hidden=true;for(const error of form.querySelectorAll('[data-error-for]'))error.hidden=true;for(const input of form.querySelectorAll('input'))input.removeAttribute('aria-invalid');
  const values=new FormData(form),data=Object.fromEntries(['email','first_name','last_name','department_name','password','password_confirm'].map(name=>[name,String(values.get(name)||'')]));
  if(data.password!==data.password_confirm){errorBox.textContent='تکرار رمز عبور یکسان نیست.';errorBox.hidden=false;form.elements.password_confirm.focus();return;}
  busy=true;submit.disabled=true;status.textContent='در حال ساخت مدیر کل…';
  try{
    const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,'X-Install-Token':String(values.get('install_token')||'').trim()},body:JSON.stringify(data)}),json=await response.json();
    if(!response.ok||!json.ok){
      if(response.status===409){const latest=await readStatus();if(latest.installed){closed(true);return;}}
      const fields=json.error?.fields||{};for(const [name,message]of Object.entries(fields)){const input=form.elements.namedItem(name),error=form.querySelector(`[data-error-for="${CSS.escape(name)}"]`);if(input)input.setAttribute('aria-invalid','true');if(error){error.textContent=message;error.hidden=false;}}
      throw new Error(json.error?.message||'راه‌اندازی انجام نشد. دوباره تلاش کنید.');
    }
    for(const input of form.querySelectorAll('input'))input.value='';csrf=null;
    stage.innerHTML='<div class="auth-heading"><h1>مدیر کل ساخته شد</h1></div><div class="message success" role="status">راه‌اندازی اولیه کامل شد و فرم نصب بسته است. با همان ایمیل و رمز انتخابی خود وارد شوید.</div><a class="button primary" href="./?screen=login">ورود به برنامه</a>';
  }catch(error){errorBox.textContent=error.message||'ارتباط برقرار نشد. دوباره تلاش کنید.';errorBox.hidden=false;status.textContent='';}
  finally{busy=false;if(form.isConnected){submit.disabled=false;form.elements.password.value='';form.elements.password_confirm.value='';}}
});
void load();
