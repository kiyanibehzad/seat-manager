import {ApiError} from '../auth-api.js';
import {esc,fa,field,table,stamp} from './ui.js';

const states={pending:'در صف',sending:'در حال ارسال',retry:'در انتظار تلاش دوباره',sent:'تحویل به سرویس ایمیل',failed:'ناموفق',uncertain:'نتیجه نامشخص',cancelled:'لغوشده'};
const categories={verification:'تأیید ایمیل',verify:'تأیید ایمیل',reset:'بازیابی رمز',password_reset:'بازیابی رمز',notification:'اعلان برنامه',test:'آزمایشی',auth:'پیام ورود'};
const invalid=()=>{throw new ApiError('invalid_response','اطلاعات ایمیل کامل دریافت نشد. دوباره تلاش کنید.');};
const sqlDate=value=>typeof value==='string'&&/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)?value.replace(' ','T')+'Z':value;
export const mailDate=value=>value?stamp(sqlDate(value)):'هنوز ثبت نشده';
export const canManageMail=session=>session?.user?.is_super_admin===true;
export function mailSettingsModel(data){
  const s=data?.settings,t=data?.status;if(!s||!t||typeof s.delivery_enabled!=='boolean'||typeof s.password_configured!=='boolean'||!['smtp','phpmail'].includes(s.transport)||!Number.isSafeInteger(s.revision)||s.revision<0||!Number.isSafeInteger(s.smtp_port)||s.smtp_port<1||s.smtp_port>65535||!['','tls','ssl'].includes(s.smtp_encryption))invalid();
  for(const key of ['from_email','from_name','smtp_host','smtp_username'])if(typeof s[key]!=='string')invalid();
  if(!t.counts||!Array.isArray(t.items)||typeof t.cron_ready!=='boolean'||typeof t.delivery_ready!=='boolean')invalid();
  const counts={};for(const key of Object.keys(states)){if(!Number.isSafeInteger(t.counts[key])||t.counts[key]<0)invalid();counts[key]=t.counts[key];}
  const items=t.items.map(item=>{if(!Number.isSafeInteger(item.id)||item.id<1||!Object.hasOwn(states,item.status)||!Number.isSafeInteger(item.attempts)||item.attempts<0||typeof item.retryable!=='boolean')invalid();return {id:item.id,category:categories[item.category]||'پیام برنامه',status:item.status,attempts:item.attempts,created_at:item.created_at,sent_at:item.sent_at,error_message:typeof item.error_message==='string'?item.error_message:'',retryable:item.retryable&&['pending','retry','failed'].includes(item.status)};});
  const status={counts,items,cron_ready:t.cron_ready,delivery_ready:t.delivery_ready};
  for(const key of ['last_success_at','worker_last_started_at','worker_last_finished_at','cron_last_started_at','cron_last_finished_at'])status[key]=typeof t[key]==='string'?t[key]:null;
  status.worker_error=!!t.worker_last_error_code;status.cron_error=!!t.cron_last_error_code;
  // Only the public contract is retained. A credential accidentally added to a response never reaches the DOM.
  return {settings:{delivery_enabled:s.delivery_enabled,transport:s.transport,from_email:s.from_email,from_name:s.from_name,smtp_host:s.smtp_host,smtp_port:s.smtp_port,smtp_encryption:s.smtp_encryption,smtp_username:s.smtp_username,password_configured:s.password_configured,revision:s.revision},status};
}
export function mailSettingsCommand(values,revision){
  const body={delivery_enabled:values.delivery_enabled===true,transport:values.transport,from_email:String(values.from_email||'').trim(),from_name:String(values.from_name||'').trim(),smtp_host:String(values.smtp_host||'').trim(),smtp_port:Number(values.smtp_port),smtp_encryption:values.smtp_encryption,smtp_username:String(values.smtp_username||'').trim(),clear_password:values.transport==='smtp'&&values.clear_password===true,expected_revision:revision};
  const password=String(values.smtp_password||'');if(password&&body.clear_password)throw new ApiError('validation_failed','برای رمز، فقط جایگزینی یا حذف را انتخاب کنید.',{clear_password:'حذف رمز و واردکردن رمز جدید هم‌زمان ممکن نیست.'});
  if(password&&values.transport==='smtp')body.smtp_password=password;
  return body;
}
const feedback='<p class="op-mail-feedback" data-mail-feedback role="status" hidden></p>';
function controls(s){
  return `<form class="sm-form-panel op-mail-form" data-mail-form="settings"><div class="sm-form-heading"><h3>روش ارسال و فرستنده</h3></div><div class="sm-form-grid">${field('delivery_enabled','ارسال خودکار ایمیل‌ها فعال باشد',{type:'checkbox',checked:s.delivery_enabled})}${field('transport','روش ارسال',{value:s.transport,options:[{value:'smtp',label:'سرویس SMTP'},{value:'phpmail',label:'ارسال از هاست · PHP mail'}]})}${field('from_name','نام فرستنده',{value:s.from_name,required:true,extra:'maxlength="120" autocomplete="organization"'})}${field('from_email','ایمیل فرستنده',{type:'email',value:s.from_email,required:true,extra:'maxlength="254" autocomplete="email"'})}</div><fieldset class="op-mail-smtp" data-mail-smtp ${s.transport==='smtp'?'':'hidden disabled'}><legend>اتصال SMTP</legend><div class="sm-form-grid">${field('smtp_host','نشانی سرور',{value:s.smtp_host,extra:'dir="ltr" maxlength="253" autocomplete="off"'})}${field('smtp_port','درگاه',{type:'number',value:s.smtp_port,required:true,extra:'min="1" max="65535" step="1" inputmode="numeric"'})}${field('smtp_encryption','رمزگذاری اتصال',{value:s.smtp_encryption,options:[{value:'',label:'انتخاب کنید'},{value:'tls',label:'STARTTLS'},{value:'ssl',label:'SSL / TLS'}]})}${field('smtp_username','نام کاربری',{value:s.smtp_username,extra:'dir="ltr" maxlength="254" autocomplete="off"'})}${field('smtp_password','رمز جدید SMTP · اختیاری',{type:'password',hint:s.password_configured?'رمز قبلی ذخیره شده است؛ برای حفظ آن، این فیلد را خالی بگذارید.':'رمزی ذخیره نشده است. اگر سرویس شما نیاز دارد، رمز را وارد کنید.',extra:'dir="ltr" maxlength="1024" autocomplete="new-password"'})}${field('clear_password','حذف رمز ذخیره‌شده',{type:'checkbox'})}</div></fieldset><p class="sm-small">ارسال آزمایشی و بررسی اتصال فقط پس از کلیک شما انجام می‌شوند. ذخیرهٔ تنظیمات به‌تنهایی تست ارسال انجام نمی‌دهد.</p><p class="sm-error" data-mail-form-error role="alert" hidden></p><div class="sm-form-footer"><button class="sm-button sm-primary" type="submit">ذخیرهٔ تنظیمات ایمیل</button><button class="sm-button" type="button" data-mail-action="reload" hidden>دریافت تنظیمات تازه</button></div></form>`;
}
function testMarkup(){return `<section class="op-mail-test"><h3>بررسی و ارسال آزمایشی</h3><p class="sm-small">تست با تنظیمات ذخیره‌شده انجام می‌شود؛ حتی اگر ارسال خودکار خاموش باشد. ایمیل آزمایشی فقط به گیرندهٔ مشخص‌شده می‌رود و بقیهٔ صف را ارسال نمی‌کند.</p><p class="sm-small" data-mail-dirty hidden>برای تست، ابتدا تغییرات تنظیمات را ذخیره کنید.</p><button type="button" class="sm-button" data-mail-action="connection" data-mail-test>بررسی اتصال بدون ارسال</button><form data-mail-form="test"><div class="sm-form-grid">${field('recipient','گیرندهٔ ایمیل آزمایشی',{type:'email',required:true,extra:'maxlength="254" autocomplete="email"'})}</div><p class="sm-error" data-mail-form-error role="alert" hidden></p><div class="sm-form-footer"><button type="submit" class="sm-button sm-primary" data-mail-test>ارسال ایمیل آزمایشی</button></div></form></section>`;}
export function mailStatusMarkup(status){
  const rows=status.items.map(item=>({id:item.id,cells:[`<strong>${esc(item.category)}</strong><span class="sm-small">شناسهٔ ${fa(item.id)}</span>`,`<span>${states[item.status]}</span>${item.error_message?`<p class="sm-small">${esc(item.error_message)}</p>`:''}`,`<span>${esc(mailDate(item.sent_at||item.created_at))}</span><span class="sm-small">${fa(item.attempts)} تلاش</span>`,item.retryable?`<button class="sm-button" type="button" data-mail-action="retry" data-mail-id="${item.id}" data-mail-test>درخواست تلاش دوباره</button>`:'<span class="sm-small">—</span>']}));
  return `<div class="sm-section-title"><h3>وضعیت ارسال و زمان‌بند</h3><button class="sm-button" type="button" data-mail-action="refresh">تازه‌سازی وضعیت</button></div><dl class="op-mail-summary"><div><dt>ارسال خودکار</dt><dd>${status.delivery_ready?'آمادهٔ اجرا':'آماده نیست'}</dd></div><div><dt>اجرای اخیر زمان‌بند</dt><dd>${status.cron_ready?'ثبت شده':'ثبت نشده یا قدیمی است'}</dd></div><div><dt>آخرین تحویل به سرویس ایمیل</dt><dd>${esc(mailDate(status.last_success_at))}</dd></div><div><dt>آخرین اجرای ارسال</dt><dd>${esc(mailDate(status.worker_last_finished_at))}${status.worker_error?' · با خطا':''}</dd></div><div><dt>آخرین اجرای زمان‌بند</dt><dd>${esc(mailDate(status.cron_last_finished_at))}${status.cron_error?' · با خطا':''}</dd></div></dl><p class="sm-small">زمان‌بند باید هر دقیقه اجرا شود. این وضعیت، اجرای موفق در پنج دقیقهٔ اخیر را نشان می‌دهد؛ تأیید نصب زمان‌بند روی هاست نیست. تحویل به سرویس ایمیل نیز دریافت در صندوق مقصد را تضمین نمی‌کند.</p><dl class="op-mail-counts">${Object.entries(states).map(([key,label])=>`<div><dt>${label}</dt><dd>${fa(status.counts[key])}</dd></div>`).join('')}</dl>${table({label:'آخرین وضعیت ایمیل‌ها',classes:'op-mail-table',columns:[{label:'نوع پیام',width:20,align:'right'},{label:'وضعیت و توضیح',width:37,align:'right'},{label:'زمان و تلاش‌ها',width:25},{label:'اقدام',width:18}],rows})}`;
}
export function mailPanelMarkup(view){return `<h2>ایمیل و ارسال</h2><p class="sm-small">تنظیمات ارسال ایمیل‌های برنامه؛ فقط در اختیار مدیر کل.</p>${feedback}<div data-mail-content>${controls(view.settings)}${testMarkup()}<section class="op-mail-status" data-mail-status>${mailStatusMarkup(view.status)}</section></div>`;}

export function mountMailSettings({root,transport}){
  const lifecycle=new AbortController();let destroyed=false,busy=false,dirty=false,view=null;
  root.innerHTML=`<h2>ایمیل و ارسال</h2>${feedback}<p class="sm-small" data-mail-loading role="status">در حال دریافت تنظیمات ایمیل…</p><button type="button" class="sm-button" data-mail-action="reload" hidden>تلاش دوباره</button>`;
  const message=(text,error=false)=>{const el=root.querySelector('[data-mail-feedback]');if(!el)return;el.hidden=!text;el.textContent=text;el.classList.toggle('sm-error',error);el.setAttribute('role',error?'alert':'status');};
  function sync(){
    for(const el of root.querySelectorAll('button,input,select,textarea'))el.disabled=busy;
    const form=root.querySelector('[data-mail-form="settings"]'),smtp=root.querySelector('[data-mail-smtp]');
    if(form&&smtp){const isSmtp=form.elements.transport.value==='smtp';smtp.hidden=!isSmtp;smtp.disabled=!isSmtp;form.elements.smtp_host.required=isSmtp&&form.elements.delivery_enabled.checked;form.elements.smtp_encryption.required=isSmtp&&form.elements.delivery_enabled.checked;}
    for(const el of root.querySelectorAll('[data-mail-test]'))el.disabled=busy||dirty;
    const note=root.querySelector('[data-mail-dirty]');if(note)note.hidden=!dirty;
    root.setAttribute('aria-busy',String(busy));
  }
  function render(){root.innerHTML=mailPanelMarkup(view);sync();}
  function clearSecrets(){for(const el of root.querySelectorAll('input[type="password"]'))el.value='';}
  function clearErrors(){for(const el of root.querySelectorAll('[data-mail-field-error]'))el.remove();for(const el of root.querySelectorAll('[aria-invalid]')){el.removeAttribute('aria-invalid');el.removeAttribute('aria-describedby');}for(const el of root.querySelectorAll('[data-mail-form-error]')){el.hidden=true;el.textContent='';}}
  function showError(error,form=null){
    if(form)message('');
    if(!view){root.querySelector('[data-mail-loading]')?.setAttribute('hidden','');root.querySelector('[data-mail-action="reload"]')?.removeAttribute('hidden');}
    if(form){const el=form.querySelector('[data-mail-form-error]');el.hidden=false;el.textContent=error.message||'انجام این کار ممکن نشد.';let first=null;
      for(const [name,detail]of Object.entries(error.fields||{})){const mapped=name==='email'?(form.dataset.mailForm==='test'?'recipient':'from_email'):name,input=Array.from(form.elements).find(el=>el.name===mapped);if(!input||typeof detail!=='string')continue;const hint=document.createElement('span');hint.className='sm-error';hint.dataset.mailFieldError='';hint.id='mail-'+mapped+'-error';hint.textContent=detail;input.setAttribute('aria-invalid','true');input.setAttribute('aria-describedby',hint.id);input.after(hint);first??=input;}first?.focus();
    }else message(error.message||'انجام این کار ممکن نشد.',true);
    if(error.status===409){const reload=root.querySelector('[data-mail-action="reload"]');if(reload)reload.hidden=false;}
  }
  async function read(replace=false){
    const incoming=mailSettingsModel(await transport.read('/admin/mail',{}, {signal:lifecycle.signal}));if(destroyed)return;
    if(!view||replace){view=incoming;dirty=false;render();}else{view.status=incoming.status;root.querySelector('[data-mail-status]').innerHTML=mailStatusMarkup(view.status);sync();}
  }
  async function task(work,form=null){
    if(busy||destroyed)return;
    const active=document.activeElement,focus=root.contains(active)?{name:active.name,action:active.dataset.mailAction,id:active.dataset.mailId,form:active.closest('[data-mail-form]')?.dataset.mailForm,type:active.type}:null;
    busy=true;clearErrors();message('در حال انجام…');sync();
    try{await work();}catch(error){if(!destroyed&&error.code!=='aborted'){busy=false;sync();if(['network_error','invalid_response'].includes(error.code)&&form?.dataset.mailForm==='test')message('نتیجهٔ ارسال مشخص نیست؛ وضعیت صف و صندوق گیرنده را بررسی کنید. ارسال آزمایشی خودکار تکرار نمی‌شود.',true);else showError(error,form);}}
    finally{
      clearSecrets();busy=false;if(!destroyed){sync();const current=document.activeElement;
        if(focus&&(current===document.body||root.contains(current)&&current.getAttribute('aria-invalid')!=='true')){
          const elements=Array.from(root.querySelectorAll('button,input,select,textarea'));
          let next=elements.find(el=>focus.action?el.dataset.mailAction===focus.action&&el.dataset.mailId===focus.id:focus.name?el.name===focus.name:focus.form&&el.type==='submit'&&el.closest('[data-mail-form]')?.dataset.mailForm===focus.form);
          if(!next||next.disabled||!next.getClientRects().length)next=root.querySelector('[data-mail-action="refresh"]');
          if(next&&!next.disabled)next.focus({preventScroll:true});
        }
      }
    }
  }
  root.addEventListener('submit',event=>{
    const form=event.target.closest('[data-mail-form]');if(!form)return;event.preventDefault();if(busy||!form.reportValidity())return;
    const kind=form.dataset.mailForm;
    // Read before disabling controls. Password stays in this one request only.
    if(kind==='settings'){
      const values={};for(const name of ['delivery_enabled','transport','from_email','from_name','smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password','clear_password'])values[name]=form.elements[name].type==='checkbox'?form.elements[name].checked:form.elements[name].value;
      let body;try{body=mailSettingsCommand(values,view.settings.revision);}catch(error){showError(error,form);clearSecrets();return;}
      void task(async()=>{const result=await transport.write('/admin/mail',body,{signal:lifecycle.signal});if(destroyed)return;view=mailSettingsModel(result);dirty=false;render();message('تنظیمات ایمیل ذخیره شد.');},form);
    }else if(kind==='test'&&!dirty){const recipient=form.elements.recipient.value.trim(),idempotency_key=crypto.randomUUID();void task(async()=>{const result=await transport.write('/admin/mail/send-test',{recipient,idempotency_key},{signal:lifecycle.signal});if(destroyed)return;let refreshed=true;try{await read();}catch(error){if(error.code==='aborted')throw error;refreshed=false;}if(destroyed)return;message((result.message||'وضعیت آزمایش ثبت شد.')+(refreshed?'':' دریافت وضعیت صف ناموفق بود؛ تازه‌سازی کنید.'),result.status!=='sent'||!refreshed);},form);}
  },{signal:lifecycle.signal});
  root.addEventListener('input',event=>{if(event.target.closest('[data-mail-form="settings"]')){dirty=true;sync();}},{signal:lifecycle.signal});
  root.addEventListener('change',event=>{if(event.target.closest('[data-mail-form="settings"]')){dirty=true;if(event.target.name==='transport')clearSecrets();sync();}},{signal:lifecycle.signal});
  root.addEventListener('click',event=>{
    const button=event.target.closest('[data-mail-action]');if(!button||busy)return;const action=button.dataset.mailAction;
    if(action==='refresh'||action==='reload')void task(async()=>{await read(action==='reload');message(action==='reload'?'تنظیمات تازه دریافت شد.':'وضعیت ارسال به‌روز شد.');});
    else if(action==='connection'&&!dirty)void task(async()=>{const result=await transport.write('/admin/mail/test-connection',{}, {signal:lifecycle.signal});if(!destroyed)message(result.message||'بررسی اتصال پایان یافت.',result.ok!==true);});
    else if(action==='retry'&&!dirty){const id=Number(button.dataset.mailId),item=view.status.items.find(item=>item.id===id&&item.retryable);if(!item)return;void task(async()=>{await transport.write('/admin/mail/retry',{id},{signal:lifecycle.signal});await read();message('درخواست تلاش دوباره ثبت شد؛ نتیجه را در وضعیت ارسال بررسی کنید.');});}
  },{signal:lifecycle.signal});
  void task(async()=>{await read(true);});
  return {destroy(){destroyed=true;lifecycle.abort();clearSecrets();view=null;}};
}
