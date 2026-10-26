// Synthetic mail contracts only. Never call a mail server or persist any credential.
export function mailFixture(){
  let settings={delivery_enabled:false,transport:'smtp',from_email:'sender@example.test',from_name:'مدیر سیت آزمایشی',smtp_host:'smtp.example.test',smtp_port:587,smtp_encryption:'tls',smtp_username:'sender@example.test',password_configured:true,revision:1};
  let status={counts:{pending:2,sending:0,retry:0,sent:1,failed:1,uncertain:1,cancelled:0},items:[{id:5,category:'test',status:'uncertain',attempts:1,created_at:'2026-10-26 08:00:00',sent_at:null,error_code:'smtp_acceptance_unknown',error_message:'نتیجهٔ تحویل مشخص نیست؛ ارسال خودکار دوباره متوقف است.',retryable:false},{id:4,category:'auth',status:'failed',attempts:1,created_at:'2026-10-26 07:30:00',sent_at:null,error_code:'smtp_pre_delivery_failure',error_message:'اتصال یا احراز هویت SMTP ناموفق بود؛ تنظیمات ایمیل را بررسی کنید.',retryable:true},{id:3,category:'notification',status:'sent',attempts:1,created_at:'2026-10-26 07:00:00',sent_at:'2026-10-26 07:01:00',error_message:null,retryable:false}],last_success_at:'2026-10-26 07:01:00',worker_last_started_at:'2026-10-26 08:00:00',worker_last_finished_at:'2026-10-26 08:00:02',worker_last_error_code:null,cron_last_started_at:null,cron_last_finished_at:null,cron_last_error_code:null,cron_ready:false,cron_interval_minutes:1,delivery_ready:false};
  const snapshot=()=>structuredClone({settings,status});
  return {snapshot,async read(scenario){if(scenario==='mail-unavailable')throw Object.assign(Error('تنظیمات ایمیل موقتاً دریافت نشد. سایر تنظیمات در دسترس‌اند.'),{code:'unavailable',status:503});return snapshot();},async write(route,body,scenario){
    if(scenario==='mail-conflict'&&route==='/admin/mail')throw Object.assign(Error('تنظیمات تغییر کرده است؛ اطلاعات تازه را دریافت کنید.'),{code:'conflict',status:409});
    if(scenario==='mail-field-error'&&route==='/admin/mail')throw Object.assign(Error('نشانی سرور را بررسی کنید.'),{code:'validation_failed',status:422,fields:{smtp_host:'نشانی سرور معتبر وارد کنید.'}});
    if(route==='/admin/mail'){
      const {smtp_password,clear_password,expected_revision,...plain}=body;
      settings={...settings,...plain,password_configured:clear_password?false:smtp_password?true:settings.password_configured,revision:settings.revision+1};return snapshot();
    }
    if(route==='/admin/mail/test-connection')return {ok:scenario!=='mail-test-failure',code:'fixture',message:scenario==='mail-test-failure'?'اتصال آزمایشی برقرار نشد.':'اتصال آزمایشی بررسی شد؛ هیچ ایمیلی ارسال نشد.'};
    if(route==='/admin/mail/send-test'){
      const state=scenario==='mail-test-uncertain'?'uncertain':scenario==='mail-test-failure'?'failed':'sent';
      status.counts[state]++;status.items.unshift({id:100+status.items.length,category:'test',status:state,attempts:1,created_at:'2026-10-26 09:00:00',sent_at:state==='sent'?'2026-10-26 09:00:01':null,error_message:state==='sent'?null:'نتیجهٔ ارسال آزمایشی نیازمند بررسی است.',retryable:false});
      if(scenario==='mail-test-uncertain')throw Object.assign(Error('ارتباط قطع شد.'),{code:'network_error'});
      return {id:status.items[0].id,status:state,code:'fixture',message:state==='sent'?'پیام آزمایشی به سرویس ایمیل تحویل داده شد؛ صندوق گیرنده را بررسی کنید.':'ارسال آزمایشی ناموفق بود؛ تنظیمات را بررسی کنید.'};
    }
    if(route==='/admin/mail/retry'){const item=status.items.find(item=>item.id===body.id);status.counts[item.status]--;status.counts.pending++;item.status='pending';item.error_message=null;return {id:body.id,status:'pending'};}
    throw Error('مسیر آزمایشی ناشناخته');
  }};
}
