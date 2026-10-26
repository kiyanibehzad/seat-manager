import test from 'node:test';
import assert from 'node:assert/strict';
import {createAuthApi} from '../src/auth-api.js';
import {safeNotificationUrl,notificationDate,validateNotificationPage,createNotificationApi} from '../src/notification-api.js';
import {createNotificationStore} from '../src/notification-store.js';
import {notificationButtonMarkup,notificationPanelMarkup} from '../src/notification-view.js';

const item=(id,read_at=null)=>({id,event_type:'test',title:'عنوان آزمایشی',body:'متن آزمایشی',action_url:'https://example.test/tools/accounts/?view=registrations',created_at:'2026-10-23T21:00:00Z',read_at});
const page=(items=[],unreadCount=0,nextBeforeId=null)=>({items,unreadCount,nextBeforeId});
const session=id=>id===null?{authenticated:false,user:null,csrf_token:'anon',permissions:[]}:{authenticated:true,user:{id,app_role:'user'},csrf_token:'csrf-'+id,permissions:['notifications.read']};
const response=data=>({ok:true,status:200,json:async()=>({ok:true,data,error:null})});
const failure=code=>({ok:false,status:code==='csrf_invalid'?403:401,json:async()=>({ok:false,data:null,error:{code,message:'خطای آزمون'}})});
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return{promise,resolve,reject};};
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function fixture(){
  let current=session(null),visible=false;
  const calls=[],pages=[];
  let write=async()=>{};
  const sessionApi={getSession:async()=>current,getSessionSnapshot:()=>current};
  const api={list:async options=>{calls.push({kind:'list',options});const result=pages.shift();if(result instanceof Error)throw result;return await result;},read:async(id,options)=>{calls.push({kind:'read',id,options});await write();},readAll:async options=>{calls.push({kind:'read-all',options});await write();}};
  const store=createNotificationStore({api,sessionApi,isVisible:()=>visible});
  return {store,calls,pages,setWrite:fn=>write=fn,setVisible:value=>visible=value,setUser(id,token){current=session(id);if(token)current.csrf_token=token;store.setSession(current);},sessionApi};
}

test('initial/authenticated state is private; zero/unknown never produces a badge',async()=>{
  const f=fixture();assert.equal(f.store.snapshot().userId,null);assert.equal(f.calls.length,0);
  f.setUser(1);assert.equal(f.calls.length,0);f.setVisible(true);f.pages.push(page());await f.store.refresh();
  const s=f.store.snapshot();assert.equal(s.status,'ready');assert.equal(s.unreadCount,0);assert(!notificationButtonMarkup(s).includes('notification-count'));
  assert(notificationPanelMarkup({...s,open:true}).includes('اعلانی ندارید.'));
  const count=notificationButtonMarkup({...s,unreadCount:3});assert(count.includes('۳ خوانده‌نشده'));assert(count.includes('notification-count'));
  for(const status of ['loading','error']){const stale={...s,status,items:[item(1)],unreadCount:1,open:true};assert(!notificationButtonMarkup(stale).includes('notification-count'));assert(!notificationPanelMarkup(stale).includes('عنوان آزمایشی'));}
  f.store.destroy();assert.equal(f.store.snapshot().items.length,0);
});

test('old list response cannot cross a user boundary, even if transport ignores abort',async()=>{
  const f=fixture(),old=deferred();f.setUser(1);f.setVisible(true);f.pages.push(old.promise,page([item(22)],1));
  const pending=f.store.refresh();await flush();const signal=f.calls[0].options.signal;
  f.setUser(2);await flush();assert(signal.aborted);assert.equal(f.store.snapshot().userId,'2');assert.deepEqual(f.store.snapshot().items.map(x=>x.id),[22]);
  old.resolve(page([item(11)],9));await pending;assert.deepEqual(f.store.snapshot().items.map(x=>x.id),[22]);assert.equal(f.store.snapshot().unreadCount,1);
});

test('logout clears open list, count and pending writes, then ignores late completion',async()=>{
  const f=fixture();f.setUser(1);f.setVisible(true);f.pages.push(page([item(1)],1));await f.store.refresh();
  f.setVisible(false);f.store.toggle();assert(f.store.snapshot().open);f.setVisible(true);
  const write=deferred();f.setWrite(()=>write.promise);const pending=f.store.read(1);await flush();const signal=f.calls.at(-1).options.signal;
  f.setUser(null);assert(signal.aborted);assert.equal(f.store.snapshot().userId,null);assert.equal(f.store.snapshot().unreadCount,null);assert.equal(f.store.snapshot().open,false);assert.deepEqual(f.store.snapshot().items,[]);
  const count=f.calls.length;write.resolve();await pending;assert.equal(f.calls.length,count);assert.equal(f.store.snapshot().userId,null);
});

test('same-user session rotation cancels old responses without losing the new notification session',async()=>{
  const f=fixture(),old=deferred();f.setUser(1);f.setVisible(true);f.pages.push(old.promise,page([item(22)],1));
  const pending=f.store.refresh();await flush();const signal=f.calls[0].options.signal;
  f.setUser(1,'rotated-token');await flush();assert(signal.aborted);old.reject(Object.assign(Error('old unauthenticated'),{code:'unauthenticated'}));await pending;
  assert.equal(f.store.snapshot().userId,'1');assert.deepEqual(f.store.snapshot().items.map(x=>x.id),[22]);assert.equal(f.store.snapshot().unreadCount,1);
});

test('a read invalidates an older refresh and uses only the new server read_at/count',async()=>{
  const f=fixture();f.setUser(1);f.setVisible(true);f.pages.push(page([item(1)],1));await f.store.refresh();
  const old=deferred();f.pages.push(old.promise,page([item(1,'2026-10-24T15:00:00Z')],0));const oldRefresh=f.store.refresh();await flush();
  await f.store.read(1);old.resolve(page([item(1)],99));await oldRefresh;
  assert.equal(f.store.snapshot().unreadCount,0);assert.equal(f.store.snapshot().items[0].read_at,'2026-10-24T15:00:00Z');assert.equal(f.calls.filter(x=>x.kind==='read').length,1);
});

test('duplicate read clicks do not repeat writes; read-all refreshes rather than inventing state',async()=>{
  const f=fixture();f.setUser(1);f.setVisible(true);f.pages.push(page([item(1)],1));await f.store.refresh();
  const write=deferred();f.setWrite(()=>write.promise);const first=f.store.read(1);await flush();await f.store.read(1);assert.equal(f.calls.filter(x=>x.kind==='read').length,1);assert.equal(f.store.snapshot().unreadCount,1);
  f.pages.push(page([item(1,'2026-10-24T15:00:00Z')],0));write.resolve();await first;
  await f.store.read(1);assert.equal(f.calls.filter(x=>x.kind==='read').length,1);
  f.pages.push(page([item(2)],2));await f.store.refresh();f.setWrite(async()=>{});f.pages.push(page([item(2,'2026-10-24T15:01:00Z')],0));await f.store.readAll();assert.equal(f.calls.filter(x=>x.kind==='read-all').length,1);assert.equal(f.store.snapshot().unreadCount,0);
});

test('errors remain errors, visibility suspends reads, and pagination uses the returned cursor',async()=>{
  const f=fixture();f.setUser(1);f.setVisible(true);f.pages.push(Error('ارتباط برقرار نشد'));await f.store.refresh();assert.equal(f.store.snapshot().status,'error');assert.equal(f.store.snapshot().unreadCount,null);
  assert(!notificationPanelMarkup({...f.store.snapshot(),open:true}).includes('اعلانی ندارید.'));
  f.pages.push(page([item(4),item(3)],2,3));await f.store.refresh();f.pages.push(page([item(3),item(2,'2026-10-24T15:00:00Z')],2));await f.store.refresh({append:true});assert.equal(f.calls.at(-1).options.beforeId,3);assert.deepEqual(f.store.snapshot().items.map(x=>x.id),[4,3,2]);
  f.setVisible(false);const count=f.calls.length;f.store.visibilityChanged();await f.store.refresh();assert.equal(f.calls.length,count);
  f.pages.push(page());f.setVisible(true);f.store.visibilityChanged();await flush();assert.equal(f.store.snapshot().unreadCount,0);
});

test('session expiry during refresh clears private state',async()=>{
  const f=fixture();f.setUser(1);f.setVisible(true);const error=Error('نشست منقضی شد');error.code='unauthenticated';f.pages.push(error);await f.store.refresh();assert.equal(f.store.snapshot().userId,null);assert.deepEqual(f.store.snapshot().items,[]);
});

test('navigation accepts only this origin and this installation; no route handler means no CTA',()=>{
  const base='https://example.test/tools/accounts/index.html';
  for(const bad of ['https://other.test/tools/accounts/','https://example.test/','https://example.test/tools/accounts-other/','javascript:alert(1)','//other.test/','https://user:pass@example.test/tools/accounts/','../outside','/tools/accounts/%2e%2e/private','/tools/accounts/%252e%252e/private','/tools/accounts/%2fprivate','/tools/accounts/\\evil','https://example.test:444/tools/accounts/'])assert.equal(safeNotificationUrl(bad,base),null,bad);
  assert.equal(safeNotificationUrl('?view=registrations',base),'https://example.test/tools/accounts/?view=registrations');
  const state={...page([item(1)],1),open:true};assert(!notificationPanelMarkup(state,{baseUrl:base}).includes('data-notification-action="navigate"'));
  assert(!notificationPanelMarkup(state,{baseUrl:base,navigation:{canHandle:()=>false}}).includes('data-notification-action="navigate"'));
  assert(notificationPanelMarkup(state,{baseUrl:base,navigation:{canHandle:url=>new URL(url).searchParams.get('view')==='registrations'}}).includes('data-notification-action="navigate"'));
});

test('dates use Persian calendar/Iran timezone and untrusted text is escaped',()=>{
  const label=notificationDate('2026-10-23T21:00:00Z');assert.match(label,/۲ آبان ۱۴۰۵/);assert.match(label,/۰:۳۰/);assert.equal(notificationDate('not-a-date'),'');
  const hostile={...item(1),title:'<img src=x onerror=bad()>',body:'<script>bad()</script>'};const html=notificationPanelMarkup({...page([hostile],1),open:true});assert(!html.includes('<img'));assert(!html.includes('<script>'));assert(html.includes('&lt;img'));assert(html.includes('datetime="2026-10-23T21:00:00Z"'));
  const valid=validateNotificationPage({items:[item(1)],unread_count:1,next_before_id:null});assert.equal(valid.unreadCount,1);
  for(const data of [{items:[],unread_count:-1,next_before_id:null},{items:[item(1),item(1)],unread_count:1,next_before_id:null},{items:[{...item(1),created_at:'invalid'}],unread_count:1,next_before_id:null}])assert.throws(()=>validateNotificationPage(data),error=>error.code==='invalid_response');
});

test('notification cards keep title/time in the header and read/navigation actions separate from body',()=>{
  const base='https://example.test/tools/accounts/',items=[item(1),{...item(2,'2026-10-24T15:00:00Z'),title:'اعلان خوانده‌شده'}];
  const html=notificationPanelMarkup({...page(items,1),open:true,writing:true},{baseUrl:base,navigation:{canHandle:()=>true}});
  assert.equal((html.match(/class="notification-item"/g)||[]).length,2);
  for(const card of html.matchAll(/<li class="notification-item"[^>]*>(.*?)<\/li>/gs)){
    const content=card[1],time=content.indexOf('<time '),body=content.indexOf('class="notification-body"'),actions=content.indexOf('class="notification-item-actions"');
    assert(time>content.indexOf('class="notification-item-heading"')&&time<body&&body<actions);
    assert(!content.slice(0,actions).includes('data-notification-action='));
  }
  assert(html.includes('data-notification-action="read" data-notification-id="1" disabled'));
  assert(!html.includes('data-notification-action="read" data-notification-id="2"'));
  assert(html.includes('notification-read-label'));assert.equal((html.match(/data-notification-action="navigate"/g)||[]).length,2);
});

test('real API contract: nested GET cursor plus CSRF-protected read/read-all and no POST replay',async()=>{
  const calls=[],queue=[response(session(1)),response({items:[item(1)],unread_count:1,next_before_id:null}),response({updated:true}),failure('csrf_invalid'),response(session(1))];
  const transport=createAuthApi({baseUrl:'https://example.test/tools/accounts/',fetchImpl:async(url,options)=>{calls.push({url:new URL(url),options});return queue.shift();}});
  await transport.getSession();const api=createNotificationApi(transport);await api.list({beforeId:8});await api.read(1);
  assert.equal(calls[1].url.pathname,'/tools/accounts/api/index.php');assert.equal(calls[1].url.searchParams.get('route'),'/notifications');assert.equal(calls[1].url.searchParams.get('before_id'),'8');assert.equal(calls[1].url.searchParams.get('limit'),'20');
  assert.equal(calls[2].options.headers['X-CSRF-Token'],'csrf-1');assert.deepEqual(JSON.parse(calls[2].options.body),{id:1});
  await assert.rejects(api.readAll(),error=>error.code==='csrf_invalid');assert.equal(calls.filter(c=>c.url.searchParams.get('route')==='/notifications/read-all').length,1);assert.deepEqual(JSON.parse(calls[3].options.body),{});
});

test('old session/401 responses cannot resurrect or invalidate a newer login',async()=>{
  const stale=deferred(),old401=deferred();let count=0;
  const transport=createAuthApi({baseUrl:'https://example.test/',fetchImpl:async(url)=>{
    const route=new URL(url).searchParams.get('route');
    if(route==='/session')return ++count===1?response(session(1)):stale.promise;
    if(route==='/notifications')return old401.promise;
    if(route==='/logout')return response(session(null));
    if(route==='/login')return response(session(2));
  }});
  await transport.getSession();const pendingSession=transport.getSession(),pendingNotification=transport.notifications.list();
  await transport.logout();stale.resolve(response(session(1)));await assert.rejects(pendingSession,error=>error.code==='session_changed');assert.equal(transport.getSessionSnapshot().authenticated,false);
  await transport.login({email:'test@example.test',password:'test-only'});old401.resolve(failure('unauthenticated'));await assert.rejects(pendingNotification,error=>error.code==='session_changed');assert.equal(transport.getSessionSnapshot().user.id,2);
});
