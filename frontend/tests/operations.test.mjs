import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fixtures,testPermissions} from './operations-fixtures.js';
import {createOperationsApi,dayToInstant,instantToDay,observedInstant,retainedInstant,requestCommand,startRequestCommand,resultCommand,abortCommand} from '../src/operations/api.js';
import {accountModel,requestModel,operationModel,capacityModel,historyModel,etaLabel,dashboardModel} from '../src/operations/models.js';
import {datePickerCheck,jalaliMonthInfo,parseJalali,shiftJalaliDays,createDateSelectionSession} from '../src/operations/dates.js';
import {notificationRoute,routeFromUrl,routeUrl} from '../src/operations/routes.js';
import * as view from '../src/operations/views.js';
import {historyMarkup} from '../src/operations/ui.js';
import {resultForm,abortForm,startRequestForm} from '../src/operations/forms.js';
import {parsePastedUsers} from '../src/operations/bulk.js';

test('Iran whole-day boundaries, seconds and round-trip of an existing target instant',()=>{
  assert.equal(dayToInstant('۱۴۰۵/۰۸/۰۲'),'2026-10-23T20:30:00Z');assert.equal(dayToInstant('1405/08/02',true),'2026-10-24T20:29:59Z');
  assert.equal(instantToDay('2026-10-23T20:30:00Z'),'1405/08/02');assert.equal(observedInstant('1405/08/02','12:05'),'2026-10-24T08:35:00Z');
  assert.equal(retainedInstant('1405/08/02','2026-10-24T08:00:00Z'),'2026-10-24T08:00:00Z');assert.equal(retainedInstant('','2026-10-24T08:00:00Z'),null);
  assert.throws(()=>dayToInstant('1404/12/30'));assert.equal(jalaliMonthInfo(1403,12).days,30);assert.equal(jalaliMonthInfo(1404,12).days,29);assert.equal(shiftJalaliDays('1403/12/30',1),'1404/01/01');
});
test('date bounds reject future observed days and preserve values until explicit choice',()=>{
  assert(!datePickerCheck('1405/08/03',{maxInclusive:'1405/08/02'}).valid);assert(datePickerCheck('1405/08/02',{maxInclusive:'1405/08/02'}).valid);
  let chosen=null;const selection=createDateSelectionSession({value:'1405/08/02',today:'1405/08/02',bounds:{maxInclusive:'1405/08/02'},onCommit:value=>chosen=value});selection.month(1);selection.cancel();assert.equal(chosen,null);
  assert(!datePickerCheck('1405/08/02',{minExclusive:'1405/08/03'}).valid);assert.equal(parseJalali('۱۴۰۵/۸/۲'),'1405/08/02');
});
test('temporary-off payload omits hidden dates; renewal carries required own account and reason',()=>{
  const account=accountModel(fixtures().accounts[0],{personalOwner:1});const ordinary=requestCommand(account,{seat:'Standard',temporary:false,start:'1405/08/02',end:'1405/08/10',role:'admin',score:99});assert.deepEqual(ordinary.body,{account_id:101,kind:'seat',seat:'standard',temporary:false});
  assert.throws(()=>requestCommand(account,{kind:'renewal',end:'1405/08/10',reason_text:''}));
  const renewal=requestCommand(account,{kind:'renewal',end:'1405/08/10',reason_text:'دلیل واقعی'});assert.equal(renewal.body.kind,'renewal');assert.equal(renewal.body.seat,'premium');assert(!('starts_at'in renewal.body));
});
test('start command includes fresh destination/source versions and never derives capacity/score',()=>{
  const f=fixtures(),r=requestModel(f.request),source=accountModel(f.accounts[2]);
  const command=startRequestCommand(r,{mode:'transfer',source,sourceFinalSeat:'Standard',reason:'انتقال'});assert.equal(command.body.state_version,3);assert.equal(command.body.source_state_version,3);assert.equal(command.body.source_final_seat,'standard');assert(!('expected_revision'in command.body));assert(!('score'in command.body));assert.throws(()=>startRequestCommand(r,{mode:'transfer',reason:'انتقال'}));
});
test('manual retry uses the first immutable body/key, not a fresh command or edited object',async()=>{
  const calls=[],api=createOperationsApi({write:async(route,body,options)=>{calls.push(structuredClone({route,body,options}));return{};}});
  const command={route:'/requests/cancel',body:{request_id:501}};await api.command(command);command.body.request_id=999;await api.command(command);
  assert.equal(calls[0].body.idempotency_key,calls[1].body.idempotency_key);assert.equal(calls[1].body.request_id,501);assert.match(calls[0].body.idempotency_key,/^[\w-]{8,100}$/);
  await api.command({route:'/requests/cancel',body:{request_id:501}});assert.notEqual(calls[2].body.idempotency_key,calls[0].body.idempotency_key);
});
test('transfer source and embedded account loaders exhaust pagination beyond 100 records',async()=>{
  const calls=[],rows=Array.from({length:150},(_,i)=>({id:200-i,seat:i===149?'premium':'none',owner_id:2}));
  const api=createOperationsApi({read:async(route,query)=>{calls.push({route,query});return query.before_id===null?{items:rows.slice(0,100),next_before_id:101}:{items:rows.slice(100),next_before_id:null};}});
  const result=await api.admin.allAccounts({owner_id:2});assert.equal(result.items.length,150);assert.equal(result.items.at(-1).seat,'premium');assert.equal(result.next_before_id,null);assert.equal(calls[1].query.before_id,101);assert.equal(calls[1].query.owner_id,2);
  const bad=createOperationsApi({read:async()=>({items:[],next_before_id:7})});await assert.rejects(bad.admin.allAccounts(),error=>error.code==='invalid_response');
});
test('partial and complete result commands keep exact identities, versions, dates and confirmation',()=>{
  const op=operationModel(fixtures().operation),rows=op.parties.map(p=>({account_id:p.account.id,owner_id:p.account.owner_id,email:p.account.email,state_version:p.account.state_version,seat:p.target.seat,starts_at:p.target.starts_at,ends_at:p.target.ends_at,confirmed:true,observed_at:'2026-10-24T08:00:00Z'}));
  const partial=resultCommand(op,rows.slice(0,1),{complete:false,reason:'نتیجه جزئی',evidence:'بررسی شد'});assert.equal(partial.body.complete,false);assert.equal(partial.body.results.length,1);assert.equal(partial.body.results[0].owner_id,1);
  assert.throws(()=>resultCommand(op,rows.slice(0,1),{complete:true,reason:'نتیجه',evidence:'بررسی شد'}));assert.throws(()=>resultCommand(op,[{...rows[0],confirmed:false}],{reason:'نتیجه',evidence:'شرح'}));
  const complete=resultCommand(op,rows,{complete:true,reason:'نتیجه',evidence:'شرح'});assert.equal(complete.body.results.length,2);assert.equal(complete.body.results[0].starts_at,op.parties[0].target.starts_at);assert.equal(complete.body.capacity_confirmed,false);
});
test('rollback command is explicit, all-party, append-only and does not assume purchase reversal',()=>{
  const op=operationModel(fixtures().operation),rows=op.parties.map(p=>({account_id:p.account.id,owner_id:p.account.owner_id,email:p.account.email,state_version:p.account.state_version,seat:p.before.seat,starts_at:p.before.starts_at,ends_at:p.before.ends_at,confirmed:true,observed_at:'2026-10-24T08:00:00Z'}));
  const command=abortCommand(op,rows,{reason:'برگشت واقعی',evidence:'همه طرف‌ها بررسی شدند'});assert.equal(command.route,'/admin/operations/abort');assert.equal(command.body.capacity_reversed,false);assert(!('complete'in command.body));assert.equal(command.body.results[1].seat,'premium');assert.equal(command.body.results[1].state_version,3);
  const html=abortForm(op,{canCapacity:true});assert(html.includes('خاتمهٔ ناموفق'));assert(!html.includes('data-result-partial'));assert(html.includes('data-result-complete disabled'));
});
test('personal normalization and view cannot leak an operational source or finance metadata',()=>{
  const f=fixtures(),raw={...f.request,operation:f.operation,finance:{amount:'999'},source_email:f.accounts[2].email};const model=requestModel(raw,{personalOwner:1});const html=view.requestDetailView(model,{personal:true});assert(!html.includes(f.accounts[2].email));assert(!html.includes('999'));assert(!JSON.stringify(model).includes(f.accounts[2].email));
  const waiting=requestModel({...raw,status:'ongoing',operation_phase:'waiting_invite',eta:{rank:null,estimated_at:null,conditional:true,reason:'منتظر پذیرش دعوت همین حساب است؛ زمان تحویل قطعی مشخص نیست.'}},{personalOwner:1});assert.equal(waiting.phase,'waiting_invite');for(const html of [view.requestListView([waiting],{personal:true}),view.requestDetailView(waiting,{personal:true})]){assert(html.includes('منتظر پذیرش دعوت'));assert(html.includes(waiting.eta.reason));assert(!html.includes(f.accounts[2].email));assert(!html.includes('999'));}assert(!('operation'in waiting));assert.equal(requestModel({...raw,status:'completed',operation_phase:'waiting_invite'},{personalOwner:1}).phase,null);assert.equal(requestModel({...raw,status:'ongoing',operation_phase:'foreign_secret'},{personalOwner:1}).phase,null);
  assert.throws(()=>accountModel(f.accounts[2],{personalOwner:1}),error=>error.code==='privacy_boundary');assert.throws(()=>requestModel(f.request,{personalOwner:2}));
});
test('server ETA is shared by table/detail, terminal states have no future promise',()=>{
  const r=requestModel(fixtures().request,{personalOwner:1});const label=etaLabel(r);assert(view.requestListView([r],{personal:true}).includes(label));assert(view.requestDetailView(r,{personal:true}).includes(label));assert.equal(etaLabel({...r,status:'completed'}),'تکمیل‌شده');
});
test('permission-based navigation preserves personal pages for every role and blocks HR operations',()=>{
  for(const role of ['admin','hr','operator','user']){const permissions=testPermissions(role),pages=view.pagePermissions(permissions);assert(pages.includes('accounts'));assert(pages.includes('requests'));if(role==='hr'){assert(!pages.includes('seats'));assert(!pages.includes('finance'));}if(role==='operator'){assert(pages.includes('seats'));assert(!pages.includes('users'));}}
  const base='https://example.test/team/';assert.equal(notificationRoute(base+'#view=operations&request=501',base,testPermissions('user')),null);const own=notificationRoute(base+'#view=my-requests&renew_account=102',base,testPermissions('user'));assert.equal(own.renewId,102);assert.equal(own.page,'requests');assert.equal(notificationRoute('https://outside.test/#view=my-accounts',base,testPermissions('admin')),null);
  assert.equal(routeFromUrl(base+'?view=finance',testPermissions('hr')).page,'accounts');assert(routeUrl(base,{page:'seats',day:'tomorrow',operationId:7}).includes('operation=7'));
});
test('corporate email is omitted from employee list until details expand',()=>{
  const f=fixtures(),html=view.usersView({users:f.users,departments:f.departments});assert(!html.includes(f.users[0].email));assert(html.includes('class="sm-num">۴</bdi>'));assert(html.includes('class="sm-num">۵</bdi>'));assert(view.usersView({users:f.users,departments:f.departments,expanded:1}).includes(f.users[0].email));
  const locked={...accountModel(f.request.account),locked:true};for(const selected of [null,locked]){const html=view.personalAccountsView({accounts:[locked],requests:[],user:f.users[0],selected});assert(!html.includes('data-op-action="seat-request"'));assert(!html.includes('data-op-action="renew-request"'));assert(html.includes('در حال رسیدگی'));}assert(view.personalAccountsView({accounts:[locked],requests:[requestModel({...f.request,status:'ongoing'})],user:f.users[0]}).includes('data-op-action="personal-request"'));
});
test('server capacity and dashboard order/count are displayed without client recomputation',()=>{
  const f=fixtures(),capacity=capacityModel({...f.capacity,standard:{...f.capacity.standard,free:-2}});assert.equal(capacity.rows[0].free,-2);
  const dashboard=dashboardModel({today:[{key:'request:501',kind:'request',request:f.request}],tomorrow:[],counts:{today:42,tomorrow:0},iran_today:'2026-10-24'});assert.equal(dashboard.today[0].request.id,501);assert(view.dailyView({...dashboard,capacity}).includes('۴۲'));assert.equal(dashboard.iranToday,'1405/08/02');
  const request=requestModel(f.request),empty={...capacityModel(f.capacity),rows:[{type:'Premium',free:0},{type:'Standard',free:0}]},start=startRequestForm(request,{capacity:empty,sources:f.accounts.map(a=>accountModel(a))});assert(start.includes('value="reserve"  disabled'));assert(start.includes('value="transfer" selected'));assert(start.includes('ظرفیت آزاد Premium: ۰'));assert(start.includes('data-reserve-available="false"'));const renewal=startRequestForm({...request,kind:'renewal'},{capacity:empty});assert(renewal.includes('data-reserve-available="true"'));
});
test('partial operation remains ongoing and history shows recorded/observed time plus reason/evidence',()=>{
  const f=fixtures(),op=operationModel({...f.operation,phase:'partial',items:f.operation.items.map((item,i)=>({...item,result_recorded:i===0}))});const html=view.operationDetailView(op,{history:historyModel(f.history),canCapacity:true});assert(html.includes('عملیات هنوز ناتمام است'));assert(html.includes('ثبت بازگشت و خاتمهٔ ناموفق'));assert(html.includes('دلیل آزمون'));assert(html.includes('شرح بررسی آزمون'));assert(!html.includes('زمان ثبت نشده'));assert(!html.includes('لغو پیگیری'));assert(!html.includes('data-op-action="invitation"'));assert(!html.includes('data-op-action="defer-operation"'));
  const joining=operationModel(f.operation),untouched={...joining,parties:joining.parties.map((p,i)=>i===0?{...p,before:{...p.before,seat:'Standard'}}:p)};assert(!view.operationDetailView(untouched).includes('data-op-action="invitation"'));assert(view.operationDetailView(joining).includes('data-op-action="invitation"'));assert(view.operationDetailView(joining).includes('data-op-action="defer-operation"'));
  const h=historyMarkup(historyModel([{...f.history[0],action:'operation.rollback_recorded'}]));assert(h.includes('ثبت بازگشت واقعی'));assert(h.includes('زمان مشاهده'));
});
test('reason editor exposes labels rather than internal codes; paste produces editable ordinary users only',()=>{
  const html=view.reasonEditorView([{code:'internal_marker_9',label:'پروژه'}]),visible=html.replace(/<[^>]*>/g,'');assert(!visible.includes('internal_marker_9'));assert(!visible.includes('کد'));assert(html.includes('data-reason-code="internal_marker_9"'));
  const rows=parsePastedUsers('نام\tآزمون\tperson@example.test\tبخش آزمون\tکارمند',fixtures().departments);assert.equal(rows[0].department_id,1);assert.equal(rows[0].organizational_position,'employee');assert(!('app_role'in rows[0]));
});
test('staging builder stays separate and the runtime points at a versioned built entry',async()=>{
  const html=await readFile(new URL('../../public_html/index.html',import.meta.url),'utf8');assert.match(html,/src="\.\/assets\/(?:operations\/)?app\.js\?v=[a-f0-9]+"/);
  const script=await readFile(new URL('../build-staging.mjs',import.meta.url),'utf8');assert(script.includes("build/staging"));assert(!script.includes("writeFile(path.join(frontend,'../public_html"));
  const picker=await readFile(new URL('../src/operations/datepicker.js',import.meta.url),'utf8');assert(picker.includes('return valid;'));assert(picker.includes('data-calendar-clear'));const controller=await readFile(new URL('../src/operations/controller.js',import.meta.url),'utf8');assert(controller.includes('if(!picker.validate(f,{showMissing:true}))'));
});
