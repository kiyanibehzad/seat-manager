import test from 'node:test';
import assert from 'node:assert/strict';
import {fixtures,creditFixtures,testPermissions} from './operations-fixtures.js';
import {accountModel,dashboardModel} from '../src/operations/models.js';
import {creditRequestModel,creditHistoryModel,createCreditCommand,startCreditCommand,completeCreditCommand,creditRequestForm,completeCreditForm,creditListView,creditDetailView,summaryModel,summaryView,duration} from '../src/operations/credits.js';
import {routeFromUrl,routeUrl,notificationRoute} from '../src/operations/routes.js';
import {dailyView,personalAccountsView,usersView} from '../src/operations/views.js';
import {createOperationsApi} from '../src/operations/api.js';

test('credit payload needs an eligible owned account, positive whole amount and meaningful reason',()=>{
  const a=accountModel(fixtures().accounts[0],{personalOwner:1});
  assert.deepEqual(createCreditCommand(a,{requested_credits:'500',reason_text:'  تکمیل کار  ',score:99,seat:'premium'}).body,{account_id:101,requested_credits:500,reason_text:'تکمیل کار'});
  for(const value of ['','1.5','-1',0,100000001,Infinity,'۱۰۰'])assert.throws(()=>createCreditCommand(a,{requested_credits:value,reason_text:'نیاز'}));
  assert.throws(()=>createCreditCommand(a,{requested_credits:100,reason_text:'   '}));
  assert.throws(()=>createCreditCommand({...a,seat:'none'},{requested_credits:100,reason_text:'نیاز'}));
  assert.throws(()=>createCreditCommand({...a,locked:true},{requested_credits:100,reason_text:'نیاز'}));
  assert.throws(()=>createCreditCommand({...a,needs_review:true},{requested_credits:100,reason_text:'نیاز'}));
});
test('credit completion requires actual result, explicit confirmation and exact approved increase',()=>{
  const r=creditRequestModel(creditFixtures().request),start=startCreditCommand(r,{approved_credits:300,reason:'تأیید مقدار کمتر'});
  assert.equal(start.route,'/admin/credit-requests/start');assert.equal(start.body.approved_credits,300);
  const active={...r,status:'ongoing',approved_credits:300},v={limit_before:1000,limit_after:1300,confirmed:true,evidence:'سقف همین حساب بررسی شد.',observed_day:'1405/08/02',observed_time:'12:00',period_end:'1405/09/01'};
  const command=completeCreditCommand(active,v);assert.equal(command.body.limit_after,1300);assert.equal(command.body.confirmed,true);assert(command.body.period_ends_at);assert(!('consumed_credits'in command.body));assert(!('purchase'in command.body));
  for(const next of [{...v,limit_after:1200},{...v,confirmed:false},{...v,evidence:''},{...v,evidence:'a'.repeat(2001)}])assert.throws(()=>completeCreditCommand(active,next));
  assert.throws(()=>completeCreditCommand(r,v));
});
test('personal credit models enforce owner boundary and strip manager-only evidence',()=>{
  const f=creditFixtures();assert.equal(creditRequestModel(f.request,{personalOwner:1}).requested_credits,500);
  assert.throws(()=>creditRequestModel(f.request,{personalOwner:2}),e=>e.code==='privacy_boundary');
  const h=creditHistoryModel([{...f.history[0],evidence:'manager-only evidence',after:{approved_credits:300,secret:'hidden'}}],{personal:true});
  assert(!JSON.stringify(h).includes('manager-only'));assert(!JSON.stringify(h).includes('hidden'));
  assert.throws(()=>creditRequestModel({...f.request,requested_credits:NaN}));
});
test('credit forms retain real calendar controls and never imply purchase or auto reset',()=>{
  const f=fixtures(),r={...creditRequestModel(creditFixtures().request),status:'ongoing',approved_credits:300};
  const form=creditRequestForm(f.accounts.map(a=>accountModel(a)).filter(a=>a.owner_id===1));assert(form.includes('name="requested_credits"'));assert(form.includes('name="reason_text" required'));assert(!form.includes('value="102"'));
  const result=completeCreditForm(r);assert(result.includes('name="observed_day"'));assert(result.includes('name="period_end"'));assert.equal((result.match(/data-jalali readonly/g)||[]).length,2);assert(result.includes('data-requires-confirmation disabled'));assert(result.includes('خودکار تغییر نمی‌کند'));
  assert.match(result,/name="observed_time" value="\d\d:\d\d:\d\d"/);assert(result.includes('maxlength="2000"'));
});
test('credit history remains readable after ownership changes without leaking the new owner to personal views',()=>{
  const f=creditFixtures(),redacted={id:f.request.account.id,owner_id:1,email:f.request.account.email,alias:null,seat:'none',needs_review:true};
  const own=creditRequestModel({...f.request,account:redacted,review_note:'internal note'},{personalOwner:1});
  assert.equal(own.account.state_version,null);assert.equal(own.account.needs_review,true);assert.equal(own.review_note,'');assert(creditDetailView(own,{personal:true}).includes(f.request.account.email));
  const admin=creditRequestModel({...f.request,requester:{id:1,first_name:'درخواست‌دهنده',last_name:'اصلی'},account:{...f.request.account,owner_id:2,first_name:'مالک',last_name:'جدید',alias:'نام خصوصی جدید'}});
  const html=creditListView([admin]);assert(html.includes('درخواست‌دهنده اصلی'));assert(!html.includes('مالک جدید'));assert(!html.includes('نام خصوصی جدید'));
  const history=creditHistoryModel([{...f.history[0],action:'credit.started',actor_name:'مدیر خصوصی',reason:'یادداشت داخلی',evidence:'شرح داخلی'}],{personal:true});
  assert(!JSON.stringify(history).includes('خصوصی'));assert(!JSON.stringify(history).includes('داخلی'));
  const blocked=creditDetailView({...admin,blockers:['unpaid_account']});assert(blocked.includes('سیت فعال ندارد'));assert.match(blocked,/data-op-action="start-credit"[^>]*disabled/);assert(blocked.includes('data-op-action="reject-credit"'));
});
test('personal credit pagination keeps cursors and open requests are not lost behind older history',async()=>{
  const calls=[],api=createOperationsApi({read:async(route,query)=>{calls.push({route,query});if(query.status==='pending')return query.before_id===null?{items:[{id:800}],next_before_id:800}:{items:[{id:12}],next_before_id:null};if(query.status==='ongoing')return{items:[{id:7}],next_before_id:null};return {items:[],next_before_id:null};}});
  await api.personal.creditRequests({before_id:50});assert.equal(calls[0].query.before_id,50);
  const open=await api.personal.openCreditRequests();assert.deepEqual(open.items.map(r=>r.id),[800,12,7]);assert(calls.some(c=>c.query.status==='pending'&&c.query.before_id===800));
  const bad=createOperationsApi({read:async()=>({items:[],next_before_id:7})});await assert.rejects(bad.personal.openCreditRequests(),e=>e.code==='invalid_response');
});
test('credit list/detail use safe readable identities and retain personal cancellation only while pending',()=>{
  const r=creditRequestModel({...creditFixtures().request,reason_text:'<img src=x onerror=alert(1)>'});
  const detail=creditDetailView(r,{personal:true});assert(detail.includes('&lt;img'));assert(!detail.includes('<img'));assert(detail.includes('افزایش سقف مجاز'));assert(detail.includes('data-op-action="cancel-credit"'));assert(!detail.includes('data-op-action="start-credit"'));
  const list=creditListView([{...r,status:'completed',approved_credits:300}],{personal:true});assert(!list.includes('data-op-action="cancel-credit"'));assert(list.includes('dir="ltr"'));assert(list.includes('۳۰۰'));
});
test('credit deep links and daily items remain separate from seat requests and preserve server count',()=>{
  const raw=creditFixtures().request,d=dashboardModel({today:[{key:'credit:901',kind:'credit_request',credit_request:raw}],tomorrow:[],counts:{today:11,tomorrow:0},iran_today:'2026-10-24'});
  assert.equal(d.today[0].credit.id,901);assert.equal(d.today[0].request,null);
  const html=dailyView(d);assert(html.includes('درخواست اعتبار بیشتر'));assert(html.includes('data-op-action="admin-credit"'));assert(html.includes('۱۱'));assert(!html.includes('data-op-action="return-account"'));
  const base='https://example.test/app/',path=routeUrl(base,{page:'requests',tab:'credits',creditId:901});const route=routeFromUrl(new URL(path,base),testPermissions('user'));assert.equal(route.creditId,901);assert.equal(route.tab,'credits');
  assert.equal(notificationRoute(base+'?view=operations&tab=credits&credit=901',base,testPermissions('user')),null);
  assert.equal(notificationRoute(base+'?view=my-requests&tab=credits&credit=901',base,testPermissions('user')).creditId,901);
});
test('summary preserves account duration and separates requested from completed credits',()=>{
  const summary=summaryModel(creditFixtures().summary,{ownerId:1}),html=summaryView(summary,{personal:true});
  assert(html.includes('۳۰۰'));assert(html.includes('۸۰۰'));assert(html.includes('مصرف واقعی OpenAI نیستند'));assert(html.includes('چند حساب هم‌زمان'));assert(html.includes('۲ روز'));assert(html.includes('name="from_day"'));assert(html.includes('name="to_day"'));assert.equal(duration(3600),'۱ ساعت');
  assert.throws(()=>summaryModel({...creditFixtures().summary,owner_id:2},{ownerId:1}),e=>e.code==='privacy_boundary');
  const incomplete=summaryView(summaryModel({...creditFixtures().summary,accounts:[{...creditFixtures().summary.accounts[0],history_incomplete:true}]}));assert(incomplete.includes('سابقهٔ ناقص'));
});
test('summary and credit actions are reachable without adding global management pages to personal users',()=>{
  const f=fixtures(),accounts=f.accounts.filter(a=>a.owner_id===1).map(a=>accountModel(a));
  const personal=personalAccountsView({accounts,requests:[],user:f.users[0]});assert(personal.includes('data-op-action="credit-request"'));assert(personal.includes('data-op-action="my-summary"'));
  const expanded=usersView({users:f.users,departments:f.departments,expanded:1,accounts,canOperate:true});assert(expanded.includes('data-op-action="person-summary"'));
  const hr=usersView({users:f.users,departments:f.departments,expanded:1,accounts,canOperate:false});assert(!hr.includes('data-op-action="person-summary"'));
});
