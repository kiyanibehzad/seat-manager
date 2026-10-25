import { ApiError, installationUrl } from './auth-api.js';

export function safeNotificationUrl(value, baseUrl) {
  if(typeof value!=='string'||!value.trim()||/[\u0000-\u0020\u007f\\]/.test(value))return null;
  try {
    const base=installationUrl(baseUrl);
    const url=new URL(value,base);
    if(!['http:','https:'].includes(url.protocol)||url.origin!==base.origin||url.username||url.password)return null;
    // Reject encoded path separators/traversal as well as paths outside the installation.
    if(/%(?:2f|5c|2e|25)/i.test(url.pathname))return null;
    if(!url.pathname.startsWith(base.pathname))return null;
    return url.href;
  }catch{return null;}
}

export function notificationDate(value) {
  if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:\d{2})$/.test(value))return '';
  const date=new Date(value);if(!Number.isFinite(date.getTime()))return '';
  return new Intl.DateTimeFormat('fa-IR-u-ca-persian',{timeZone:'Asia/Tehran',dateStyle:'medium',timeStyle:'short'}).format(date);
}

export function validateNotificationPage(data) {
  const invalid=()=>{throw new ApiError('invalid_response','اعلان‌ها کامل دریافت نشدند. دوباره تلاش کنید.');};
  if(!data||!Array.isArray(data.items)||!Number.isSafeInteger(data.unread_count)||data.unread_count<0)invalid();
  if(data.next_before_id!==null&&(!Number.isSafeInteger(data.next_before_id)||data.next_before_id<1))invalid();
  const seen=new Set();
  const items=data.items.map(item=>{
    if(!item||!Number.isSafeInteger(item.id)||item.id<1||seen.has(item.id)||typeof item.title!=='string'||typeof item.body!=='string'||!notificationDate(item.created_at)||(item.read_at!==null&&!notificationDate(item.read_at)))invalid();
    seen.add(item.id);
    return {id:item.id,event_type:String(item.event_type||''),title:item.title,body:item.body,action_url:typeof item.action_url==='string'?item.action_url:null,created_at:item.created_at,read_at:item.read_at};
  });
  return {items,unreadCount:data.unread_count,nextBeforeId:data.next_before_id};
}

export function createNotificationApi(transport) {
  return {
    async list({beforeId=null,signal}={}) { return validateNotificationPage(await transport.notifications.list({before_id:beforeId,limit:20},{signal})); },
    async read(id,{signal}={}) { const result=await transport.notifications.read(id,{signal});if(result?.updated!==true)throw new ApiError('invalid_response','خوانده‌شدن اعلان ثبت نشد. دوباره تلاش کنید.'); },
    async readAll({signal}={}) { const result=await transport.notifications.readAll({signal});if(result?.updated!==true)throw new ApiError('invalid_response','خوانده‌شدن اعلان‌ها ثبت نشد. دوباره تلاش کنید.'); },
  };
}
