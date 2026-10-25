import {ApiError} from '../auth-api.js';
import {parseJalali,jalaliEpochDay,iranDate} from './dates.js';

export const seatToApi=value=>({none:'none',Standard:'standard',Premium:'premium'})[value]??null;
export const seatFromApi=value=>({none:'none',standard:'Standard',premium:'Premium'})[value]??null;
export function dayToInstant(value,end=false){
  const day=parseJalali(value);if(!day)throw new ApiError('validation_failed','تاریخ شمسی معتبر انتخاب کنید.');
  // The calendar epoch helper anchors at UTC noon; Iran civil midnight is UTC−03:30.
  const epoch=jalaliEpochDay(day);
  return new Date(epoch*86400000-12600000+(end?86399000:0)).toISOString().replace('.000Z','Z');
}
export function instantToDay(value){const date=new Date(value);return value&&Number.isFinite(date.getTime())?iranDate(date):null;}
export function observedInstant(day,time){const match=String(time).match(/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/);if(!match)throw new ApiError('validation_failed','ساعت مشاهده را وارد کنید.');return new Date(new Date(dayToInstant(day)).getTime()+(Number(match[1])*3600+Number(match[2])*60+Number(match[3]||0))*1000).toISOString().replace('.000Z','Z');}
export function retainedInstant(day,original,end=false){if(!day)return null;return instantToDay(original)===parseJalali(day)?original:dayToInstant(day,end);}
export function newCommandKey(){return crypto.randomUUID();}
const list=data=>{if(!data||!Array.isArray(data.items))throw new ApiError('invalid_response','فهرست کامل دریافت نشد. دوباره تلاش کنید.');return data;};
const object=data=>{if(!data||typeof data!=='object'||Array.isArray(data))throw new ApiError('invalid_response','اطلاعات کامل دریافت نشد. دوباره تلاش کنید.');return data;};
export function createOperationsApi(transport){
  const read=(route,query={},signal)=>transport.read(route,query,{signal}).then(object);
  async function allAccounts(query={},signal){
    const items=[],cursors=new Set();let before=null;
    do{const page=list(await read('/admin/accounts',{...query,limit:100,before_id:before},signal));items.push(...page.items);before=page.next_before_id??null;if(before!==null){if(!Number.isSafeInteger(before)||before<1||cursors.has(before))throw new ApiError('invalid_response','ادامهٔ فهرست حساب‌ها کامل دریافت نشد. دوباره تلاش کنید.');cursors.add(before);}}while(before!==null);
    return {items,next_before_id:null};
  }
  const commands=new WeakMap();
  return {
    personal:{accounts:(query={},signal)=>read('/accounts',query,signal).then(list),requests:signal=>read('/requests',{},signal).then(list),request:(id,signal)=>read('/requests/detail',{id},signal),history:(query,signal)=>read('/history',query,signal).then(list),reasons:signal=>read('/reasons',{},signal).then(list)},
    people:{users:signal=>read('/admin/users',{},signal).then(list),registrations:signal=>read('/admin/registrations',{},signal).then(list),departments:signal=>read('/departments',{},signal).then(list),settings:signal=>read('/admin/settings',{},signal)},
    admin:{allAccounts,requests:(query={},signal)=>read('/admin/requests',query,signal).then(list),accounts:(query,signal)=>read('/admin/accounts',query,signal).then(list),queue:signal=>read('/admin/queue',{},signal),dashboard:signal=>read('/admin/dashboard',{},signal),request:(id,signal)=>read('/admin/requests/detail',{id},signal),operations:(query,signal)=>read('/admin/operations',query,signal).then(list),operation:(id,signal)=>read('/admin/operations/detail',{id},signal),capacity:signal=>read('/admin/capacity',{},signal),history:(query,signal)=>read('/admin/history',query,signal).then(list),finance:(query={},signal)=>read('/admin/finance',query,signal)},
    command:(command,signal)=>{
      if(!commands.has(command)){const {route,body,method='POST',idempotent=true}=command;commands.set(command,{route,method,body:structuredClone(idempotent?{...body,idempotency_key:command.key||newCommandKey()}:body)});}
      const saved=commands.get(command);return transport.write(saved.route,structuredClone(saved.body),{method:saved.method,signal});
    },
  };
}

// Build only documented writes. Derived score, role and capacity never enter these payloads.
export function requestCommand(account,values,key){
  const kind=values.kind==='renewal'?'renewal':'seat',temporary=kind==='renewal'||values.temporary===true;
  const body={account_id:account.id,kind,seat:kind==='renewal'?'premium':seatToApi(values.seat),temporary};
  if(!body.seat||body.seat==='none')throw new ApiError('validation_failed','نوع سیت را انتخاب کنید.');
  if(temporary){if(kind!=='renewal')body.starts_at=dayToInstant(values.start);body.ends_at=dayToInstant(values.end,true);}
  if(values.reason_code)body.reason_code=values.reason_code;
  if(values.reason_text?.trim())body.reason_text=values.reason_text.trim();
  if(kind==='renewal'&&!body.reason_text)throw new ApiError('validation_failed','دلیل تمدید را وارد کنید.');
  return {route:'/requests',body,key};
}
export function startRequestCommand(request,{mode='reserve',source=null,sourceFinalSeat=null,approvedEnd=null,overrideReason='',reason},key){
  const body={request_id:request.id,state_version:request.account.state_version,mode,reason};
  if(overrideReason.trim())body.override_reason=overrideReason.trim();
  if(approvedEnd)body.approved_ends_at=dayToInstant(approvedEnd,true);
  if(mode==='transfer'){
    if(!source||!Number.isSafeInteger(source.state_version)||!['none','Standard'].includes(sourceFinalSeat))throw new ApiError('validation_failed','مبدأ و وضعیت نهایی آن را انتخاب کنید.');
    Object.assign(body,{source_account_id:source.id,source_state_version:source.state_version,source_final_seat:seatToApi(sourceFinalSeat)});
  }
  return {route:'/admin/requests/start',body,key};
}
export function resultCommand(operation,selected,{complete=false,reason,evidence,capacityConfirmed=false,finance=null},key){
  if(!reason?.trim()||!evidence?.trim())throw new ApiError('validation_failed','دلیل و شرح بررسی نتیجه را وارد کنید.');
  if(!Array.isArray(selected)||!selected.length||selected.some(row=>row.confirmed!==true))throw new ApiError('validation_failed','نتیجهٔ هر حساب انتخاب‌شده باید تأیید شود.');
  if(complete&&(selected.length!==operation.parties.length||operation.parties.some(p=>!selected.some(row=>row.account_id===p.account.id))))throw new ApiError('validation_failed','برای تکمیل، تأیید تازهٔ تمام حساب‌ها لازم است.');
  return {route:'/admin/operations/result',key,body:{operation_id:operation.id,complete,reason:reason.trim(),evidence:evidence.trim(),capacity_confirmed:capacityConfirmed,...finance?{finance}:{},results:selected.map(row=>({account_id:row.account_id,owner_id:row.owner_id,email:row.email,state_version:row.state_version,confirmed:true,seat:seatToApi(row.seat),starts_at:row.seat==='none'?null:row.starts_at!==undefined?row.starts_at:row.start?dayToInstant(row.start):null,ends_at:row.seat==='none'?null:row.ends_at!==undefined?row.ends_at:row.end?dayToInstant(row.end,true):null,observed_at:row.observed_at}))}};
}
export function abortCommand(operation,selected,{reason,evidence,capacityReversed=false,finance=null},key){
  const result=resultCommand(operation,selected,{complete:true,reason,evidence},key).body;
  return {route:'/admin/operations/abort',key,body:{operation_id:result.operation_id,reason:result.reason,evidence:result.evidence,results:result.results,capacity_reversed:capacityReversed,...finance?{finance}:{}}};
}
