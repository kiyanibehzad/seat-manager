import {fixtures,testPermissions} from './operations-fixtures.js';
const build=await(await fetch('../build/staging/build-info.json',{cache:'no-store'})).json();
const {mountOperations}=await import('../build/staging/assets/operations/controller.js?v='+build.version);
const {mountTheme}=await import('../build/staging/assets/theme.js?v='+build.version);
const {themeMarkup}=await import('../build/staging/assets/auth-view.js?v='+build.version);
document.querySelector('#test-theme').innerHTML=themeMarkup();mountTheme();
let f=fixtures(),role='admin',page='overview',scenario='normal',panel=null,writes=0;
const requests=[f.request,{...f.request,id:502,status:'rejected',rejection_reason:'نتیجهٔ آزمون بررسی درخواست'}];
const transport={
  async read(route,query={}){
    if(scenario==='error')throw Object.assign(Error('خطای آزمایشی دریافت اطلاعات'),{code:'unavailable',status:503});
    const empty=scenario==='empty',items=value=>({items:empty?[]:value,next_before_id:null});
    // Visual-only DTO variants. These never call a real API or alter the base fixture.
    const renewalAccount={...f.accounts[0],seat:'premium',ends_at:'2026-11-03T20:29:59Z',assignment_id:101,assignment_version:1};
    const capacityPartial=scenario==='partial-capacity';
    const conversionAccount={...f.accounts[0],seat:'premium',starts_at:f.request.requested_starts_at,ends_at:f.request.requested_ends_at,assignment_id:101,state_version:4,locked:true,operation_id:f.operation.id};
    const conversionBefore={seat:'standard',starts_at:f.accounts[0].starts_at,ends_at:null,return_seat:'none'};
    const visualOperation=capacityPartial?{...f.operation,kind:'paid_conversion',phase:'partial',capacity_strategy:'convert',capacity_applied:true,held:{standard:0,premium:0},items:[{account:conversionAccount,before:conversionBefore,target:{seat:'premium',starts_at:conversionAccount.starts_at,ends_at:conversionAccount.ends_at,return_seat:'standard'},result_recorded:true}]}:scenario==='partial'?{...f.operation,phase:'partial',held:{standard:0,premium:1},items:f.operation.items.map((item,i)=>i===1?{...item,result_recorded:true,account:{...item.account,seat:'none',starts_at:null,ends_at:null,assignment_id:null,state_version:item.account.state_version+1}}:item)}:f.operation;
    const visualRequest=scenario==='stale'?{...f.request,blockers:['stale_account'],expected_state_version:2}:scenario==='renewal'?{...f.request,kind:'renewal',account:renewalAccount,requested_seat:'premium',requested_ends_at:'2026-11-10T20:29:59Z',temporary:true,reason_text:'دلیل نمونه برای تمدید',blockers:[]}:(scenario==='partial'||capacityPartial)?{...f.request,status:'ongoing',operation_phase:'partial',account:visualOperation.items[0].account,eta:{rank:null,estimated_at:null,conditional:false,reason:'بخشی از نتیجه ثبت شده و پرونده هنوز کامل نیست.'}}:f.request;
    const visualRequests=[visualRequest,...requests.slice(1)];
    const visualAccounts=scenario==='renewal'?f.accounts.map(a=>a.id===renewalAccount.id?renewalAccount:a):(scenario==='partial'||capacityPartial)?f.accounts.map(a=>visualOperation.items.find(i=>i.account.id===a.id)?.account||a):f.accounts;
    const visualCapacity=capacityPartial?{...f.capacity,standard:{purchased:3,assigned:0,held:0,free:3},premium:{purchased:2,assigned:2,held:0,free:0}}:scenario==='partial'?{...f.capacity,premium:{...f.capacity.premium,assigned:0,held:1,free:0}}:f.capacity;
    if(route==='/accounts')return items(visualAccounts.filter(a=>a.owner_id===1));
    if(route==='/requests')return items(visualRequests.filter(r=>r.owner_id===1));
    if(route==='/reasons')return{...items(f.reasons),other_code:'other'};
    if(route==='/departments')return items(f.departments);
    if(route==='/admin/users')return items(f.users);
    if(route==='/admin/registrations')return items([{...f.users[1],approval_status:'pending_approval'}]);
    if(route==='/admin/accounts')return items(visualAccounts.filter(a=>!query.owner_id||a.owner_id===query.owner_id));
    if(route==='/admin/queue')return{requests:empty||scenario==='renewal'||scenario==='partial'||capacityPartial?[]:[visualRequest],renewals:!empty&&scenario==='renewal'?[visualRequest]:[],capacity:visualCapacity};
    if(route==='/admin/dashboard')return{today:empty?[]:[{key:'request:501',kind:scenario==='renewal'?'renewal':'request',request:visualRequest}],tomorrow:[],counts:{today:empty?0:1,tomorrow:0},iran_today:'2026-10-24'};
    if(route==='/admin/capacity')return{capacity:visualCapacity};
    if(route==='/admin/operations')return items([visualOperation]);
    if(route==='/admin/operations/detail')return{operation:visualOperation,history:f.history};
    if(route==='/requests/detail'||route==='/admin/requests/detail'){const request=visualRequests.find(r=>r.id===Number(query.id));if(!request||(route==='/requests/detail'&&request.owner_id!==1))throw Object.assign(Error('درخواست پیدا نشد'),{status:404});return{request,history:f.history};}
    if(route==='/admin/requests')return items(visualRequests);
    if(route==='/history'||route==='/admin/history')return items(f.history.filter(h=>!query.account_id||h.account_id===query.account_id));
    if(route==='/admin/settings')return{manual_action_hours:24,allowed_email_domains:['example.test']};
    if(route==='/admin/finance')return items([{id:1,recorded_at:'2026-10-24T08:00:00Z',reason:'ثبت آزمون ظرفیت',finance:null}]);
    throw Error('مسیر آزمون ناشناخته است');
  },
  async write(route,body){
    writes++;document.querySelector('#write-count').textContent=writes+' ثبت آزمایشی';document.querySelector('#write-count').dataset.count=String(writes);
    if(route==='/requests'){const account=f.accounts.find(a=>a.id===body.account_id);const r={...f.request,id:800+writes,account,kind:body.kind,requested_seat:body.seat,requested_starts_at:body.starts_at??null,requested_ends_at:body.ends_at??null,status:'pending'};requests.push(r);return{request:r};}
    if(route==='/admin/operations/result'){f.operation={...f.operation,status:body.complete?'completed':'ongoing',phase:body.complete?'completed':'partial',items:f.operation.items.map(item=>({...item,result_recorded:body.results.some(r=>r.account_id===item.account.id)}))};return{operation:f.operation};}
    if(route==='/admin/operations/abort'){f.operation={...f.operation,status:'cancelled',phase:'cancelled',outcome:'rolled_back'};return{operation:f.operation};}
    if(route==='/admin/requests/start'||route==='/admin/operations/start'||route==='/admin/corrections')return{operation:f.operation};
    if(route==='/admin/users/bulk-preview')return{batch_id:1,expires_at:new Date(Date.now()+3600000).toISOString(),rows:body.rows.map((normalized,i)=>({row:i+1,normalized,errors:scenario==='row-error'&&i===0?{row:'این ردیف نیازمند اصلاح اطلاعات است؛ ثبت نهایی انجام نشده است.'}:{}})),valid:scenario!=='row-error'};
    return{};
  },
};
function mount(){panel?.destroy();panel=mountOperations({container:document.querySelector('#test-panel'),transport,session:{authenticated:true,user:{...f.users[0],app_role:role},permissions:testPermissions(role)},baseUrl:new URL('.',location.href),initialRoute:{page,tab:'pending',day:'today'},navigate:()=>{}});}
for(const [selector,assign]of [['#test-role',v=>role=v],['#test-page',v=>page=v],['#test-state',v=>scenario=v]])document.querySelector(selector).addEventListener('change',event=>{assign(event.target.value);mount();});
document.querySelector('#audit-dates').addEventListener('click',()=>{const form=document.querySelector('[data-op-form]');document.querySelector('#date-audit').textContent=JSON.stringify({nativeReportValidity:form?.reportValidity(),dates:Array.from(form?.querySelectorAll('[data-jalali]')||[]).map(input=>({name:input.name,readOnly:input.readOnly,willValidate:input.willValidate,value:input.value,invalid:input.getAttribute('aria-invalid')}))});});
mount();
