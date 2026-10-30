import {ApiError} from '../auth-api.js';
import {seatFromApi,instantToDay} from './api.js';
import {stamp,fa,statusText} from './ui.js';
const invalid=()=>{throw new ApiError('invalid_response','اطلاعات کامل دریافت نشد. دوباره تلاش کنید.');};
export const blockerText=code=>({inactive_owner:'کاربر غیرفعال است.',ownership_review:'مالکیت حساب نیازمند بازبینی است.',stale_account:'وضعیت حساب تغییر کرده است؛ بازبینی لازم است.',stale_assignment:'تخصیص فعلی با زمان ثبت تمدید متفاوت است.',expired_window:'بازهٔ درخواستی گذشته است؛ درخواست تازه لازم است.',account_locked:'حساب در عملیات دیگری در حال رسیدگی است.',capacity_shortage:'ظرفیت قابل واگذاری کافی نیست.',queue_priority:'نوبت درخواست نرسیده است.'})[code]||'پرونده نیازمند بررسی است.';
export function accountModel(data,{personalOwner=null}={}){
  if(!data||!Number.isSafeInteger(data.id)||!Number.isSafeInteger(data.owner_id)||typeof data.email!=='string'||!Number.isSafeInteger(data.state_version)||!seatFromApi(data.seat))invalid();
  if(personalOwner!==null&&data.owner_id!==personalOwner)throw new ApiError('privacy_boundary','اطلاعات این حساب متعلق به نشست جاری نیست.');
  const account={id:data.id,owner_id:data.owner_id,email:data.email,name:data.alias||'',seat:seatFromApi(data.seat),state_version:data.state_version,start:instantToDay(data.starts_at),end:instantToDay(data.ends_at),starts_at:data.starts_at,ends_at:data.ends_at,assigned_at:data.assigned_at,return_seat:seatFromApi(data.return_seat),locked:data.locked===true,needs_review:data.needs_review===true,assignment_id:data.assignment_id,assignment_version:data.assignment_version};
  if(personalOwner===null)Object.assign(account,{first_name:data.first_name,last_name:data.last_name,is_active:data.is_active,department_name:data.department_name,department_id:data.department_id,organizational_position:data.organizational_position,special_priority:data.special_priority,score:data.score,operation_id:data.operation_id});
  return account;
}
export function requestModel(data,options={}){
  if(!data||!Number.isSafeInteger(data.id)||!['pending','ongoing','completed','cancelled','rejected'].includes(data.status)||!seatFromApi(data.requested_seat))invalid();
  const account=accountModel(data.account,options);
  if(options.personalOwner!==null&&options.personalOwner!==undefined&&data.owner_id!==options.personalOwner)throw new ApiError('privacy_boundary','درخواست متعلق به نشست جاری نیست.');
  return {id:data.id,owner_id:data.owner_id,account,kind:data.kind,target:seatFromApi(data.requested_seat),temporary:data.temporary===true,status:data.status,phase:data.status==='ongoing'&&['working','partial','waiting_invite','scheduled'].includes(data.operation_phase)?data.operation_phase:null,requested_start:instantToDay(data.requested_starts_at),requested_end:instantToDay(data.requested_ends_at),approved_start:instantToDay(data.approved_starts_at),approved_end:instantToDay(data.approved_ends_at),requested_starts_at:data.requested_starts_at,requested_ends_at:data.requested_ends_at,approved_starts_at:data.approved_starts_at,approved_ends_at:data.approved_ends_at,reason_code:data.reason_code,reason_label:data.reason_label,reason_text:data.reason_text,rejection_reason:data.rejection_reason,created_at:data.created_at,reviewed_at:data.reviewed_at,completed_at:data.completed_at,score:data.score,blockers:Array.isArray(data.blockers)?data.blockers.map(blockerText):[],blocker_codes:Array.isArray(data.blockers)?[...data.blockers]:[],eta:data.eta&&typeof data.eta==='object'?{rank:data.eta.rank,estimated_at:data.eta.estimated_at,conditional:data.eta.conditional===true,reason:typeof data.eta.reason==='string'?data.eta.reason:''}:null};
}
const creditInteger=value=>Number.isSafeInteger(value)&&value>=0&&value<=100000000;
export function creditRequestModel(raw,options={}){
  if(!raw||!Number.isSafeInteger(raw.id)||raw.id<1||!Number.isSafeInteger(raw.owner_id)||!['pending','ongoing','completed','rejected','cancelled'].includes(raw.status)||!creditInteger(raw.requested_credits)||raw.requested_credits<1||typeof raw.reason_text!=='string')invalid();
  if(options.personalOwner!=null&&raw.owner_id!==options.personalOwner)throw new ApiError('privacy_boundary','درخواست اعتبار متعلق به نشست جاری نیست.');
  // Historical requests retain their original owner even after the account moves.
  // The personal API deliberately omits the new owner's current state/version.
  const redacted=options.personalOwner!=null&&raw.account?.owner_id===raw.owner_id&&Number.isSafeInteger(raw.account?.id)&&raw.account.id>0&&typeof raw.account.email==='string'&&raw.account.needs_review===true&&raw.account.seat==='none'&&raw.account.state_version===undefined;
  const account=redacted?{id:raw.account.id,owner_id:raw.owner_id,email:raw.account.email,name:'',seat:'none',state_version:null,start:null,end:null,starts_at:null,ends_at:null,locked:false,needs_review:true}:accountModel(raw.account,options);
  const requester=raw.requester&&raw.requester.id===raw.owner_id&&typeof raw.requester.first_name==='string'&&typeof raw.requester.last_name==='string'?{id:raw.owner_id,first_name:raw.requester.first_name,last_name:raw.requester.last_name}:account.owner_id===raw.owner_id?{id:raw.owner_id,first_name:account.first_name,last_name:account.last_name}: {id:raw.owner_id,name:'درخواست‌دهندهٔ قبلی'};
  for(const name of ['approved_credits','limit_before','limit_after'])if(raw[name]!=null&&!creditInteger(raw[name]))invalid();
  return {id:raw.id,owner_id:raw.owner_id,account,requester,requested_credits:raw.requested_credits,approved_credits:raw.approved_credits??null,status:raw.status,reason_text:raw.reason_text,review_note:options.personalOwner!=null?'':typeof raw.review_note==='string'?raw.review_note:'',rejection_reason:typeof raw.rejection_reason==='string'?raw.rejection_reason:'',created_at:raw.created_at,reviewed_at:raw.reviewed_at,completed_at:raw.completed_at,limit_before:raw.limit_before??null,limit_after:raw.limit_after??null,observed_at:raw.observed_at,period_ends_at:raw.period_ends_at,score:raw.score,blockers:Array.isArray(raw.blockers)?raw.blockers:[]};
}
export const requestStatusText=request=>request.status==='ongoing'&&request.phase==='waiting_invite'?'منتظر پذیرش دعوت':request.status==='ongoing'&&request.phase==='partial'?'نتیجهٔ جزئی؛ ناتمام':statusText(request.status);
export function etaLabel(request){
  if(['completed','rejected','cancelled'].includes(request.status))return statusText(request.status);
  if(!request.eta)return 'فعلاً قابل برآورد نیست.';
  const {rank,estimated_at,conditional,reason}=request.eta;
  const estimate=estimated_at?`${conditional?'برآورد مشروط: ':'برآورد: '}${stamp(estimated_at)}`:reason||'فعلاً قابل برآورد نیست.';
  return `${Number.isSafeInteger(rank)&&rank>0?'نوبت '+fa(rank)+' · ':''}${estimate}${estimated_at&&reason?' · '+reason:''}`;
}
export function capacityModel(data){
  if(!data||!Number.isSafeInteger(data.revision))invalid();
  return {revision:data.revision,reconciliation_required:data.reconciliation_required===true,rows:['standard','premium'].map(type=>{const row=data[type];if(!row||['purchased','assigned','held','free'].some(k=>!Number.isSafeInteger(row[k])))invalid();return {type:seatFromApi(type),purchased:row.purchased,allocated:row.assigned,reserved:row.held,free:row.free};})};
}
export function operationModel(data){
  if(!data||!Number.isSafeInteger(data.id)||!Array.isArray(data.items)||!['ongoing','completed','cancelled'].includes(data.status))invalid();
  const state=value=>({seat:seatFromApi(value?.seat),start:instantToDay(value?.starts_at),end:instantToDay(value?.ends_at),starts_at:value?.starts_at??null,ends_at:value?.ends_at??null});
  const parties=data.items.map(item=>{const account=accountModel(item.account);if(!seatFromApi(item.target?.seat))invalid();return {account,before:state(item.before),target:state(item.target),recorded:item.result_recorded===true,role:data.kind==='transfer'?(item.before?.seat==='premium'&&item.target?.seat!=='premium'?'source':'destination'):'account'};});
  return {id:data.id,request_id:data.request_id,kind:data.kind,status:data.status,outcome:data.outcome,phase:data.phase,capacityStrategy:data.capacity_strategy,capacityApplied:data.capacity_applied===true,parties,held:data.held,effect_at:data.effect_at,reason:data.reason||'',override_reason:data.override_reason||'',created_at:data.created_at,completed_at:data.completed_at,reconciliation_required:data.reconciliation_required===true};
}
export function dashboardModel(data){
  if(!data||!Array.isArray(data.today)||!Array.isArray(data.tomorrow)||!data.counts)invalid();
  const task=row=>{
    const credit=row.credit_request?creditRequestModel(row.credit_request):null,request=row.request?requestModel(row.request):null,operation=row.operation?operationModel(row.operation):null,account=row.account?accountModel(row.account):credit?.account||request?.account||(operation?.parties.find(p=>p.role==='destination')||operation?.parties[0])?.account;
    if(!account)invalid();
    return{id:row.key,kind:row.kind,account,request,credit,operation,label:row.kind==='renewal'?'درخواست تمدید':row.kind==='inactive_reclaim'?'پیگیری کاربر غیرفعال':row.kind==='expiry'?'پیگیری پایان تخصیص':operation?.kind==='transfer'?'انتقال Premium':operation?'عملیات در حال انجام':'درخواست سیت',warning:row.related_renewal?'تمدید مرتبط در انتظار تصمیم است.':request?.blockers[0]||''};
  };
  return {today:data.today.map(task),tomorrow:data.tomorrow.map(task),counts:{today:data.counts.today,tomorrow:data.counts.tomorrow},iranToday:instantToDay(data.iran_today+'T12:00:00Z')};
}
export function historyModel(items){
  if(!Array.isArray(items))invalid();
  const labels={'seat.changed':'تغییر سیت','operation.result':'ثبت نتیجهٔ عملیات','operation.started':'شروع عملیات','operation.result_confirmed':'تأیید نتیجهٔ واقعی','state.corrected':'اصلاح وضعیت واقعی','operation.partial':'ثبت نتیجهٔ جزئی','operation.rollback_recorded':'ثبت بازگشت واقعی','operation.cancelled':'لغو پیگیری پیش از ثبت نتیجه','operation.deferred':'ثبت موعد اثر','request.revalidated':'بازبینی درخواست','account.corrected':'اصلاح وضعیت حساب','request.created':'ثبت درخواست','request.rejected':'رد درخواست','request.cancelled':'لغو درخواست','request.started':'شروع رسیدگی'};
  const snapshot=value=>value&&typeof value==='object'?{seat:seatFromApi(value.seat),start:instantToDay(value.starts_at),end:instantToDay(value.ends_at)}:null;
  return items.map(item=>({id:item.id,email:item.email,alias:item.alias,description:typeof item.description==='string'?item.description:labels[item.action]||'ثبت تغییر',actor_name:typeof item.actor_name==='string'?item.actor_name:'',created_at:item.recorded_at||item.created_at,observed_at:item.observed_at,reason:item.reason||'',evidence:item.evidence||'',before:snapshot(item.before),after:snapshot(item.after)}));
}
