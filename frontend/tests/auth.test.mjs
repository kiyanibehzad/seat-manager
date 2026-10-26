import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createAuthApi,consumeMailAction,installationUrl,ApiError} from '../src/auth-api.js';
import {authMarkup,sessionMarkup,notificationSlotMarkup} from '../src/auth-view.js';

const anonymous=(csrf='csrf-one')=>({authenticated:false,user:null,csrf_token:csrf,permissions:[],app_url:'https://example.test/tools/accounts'});
const signedIn=(csrf='csrf-two')=>({authenticated:true,user:{id:7,first_name:'نام',last_name:'کاربر',email:'employee@example.test',app_role:'hr'},permissions:['personal.read','people.manage'],csrf_token:csrf,app_url:'https://example.test/tools/accounts'});
const ok=data=>({ok:true,status:200,json:async()=>({ok:true,data,error:null})});
const fail=(status,code,fields={})=>({ok:false,status,json:async()=>({ok:false,data:null,error:{code,message:'پیام خطا',fields}})});
function fixture(responses){
  const calls=[];
  const api=createAuthApi({baseUrl:'https://example.test/tools/accounts/index.html?screen=login#ignored',fetchImpl:async(url,options)=>{
    calls.push({url:new URL(url),options});
    assert(responses.length,'unexpected network request');
    const response=responses.shift();if(response instanceof Error)throw response;return response;
  }});
  return {api,calls};
}

test('nested installation stays on the same origin and retains installation directory',()=>{
  assert.equal(installationUrl('https://example.test/tools/accounts/index.html?x=1#anything').href,'https://example.test/tools/accounts/');
  assert.equal(installationUrl('https://example.test/tools/accounts/').href,'https://example.test/tools/accounts/');
});

test('mail action is removed before use, never moved into query or fragment of replacement',()=>{
  for(const action of ['reset','verify']){
    let replacement=null;
    const value=consumeMailAction({href:`https://example.test/tools/accounts/?screen=login#action=${action}&token=opaque-mail-value`,replace:path=>replacement=path});
    assert.deepEqual(value,{action,token:'opaque-mail-value'});assert.equal(replacement,'/tools/accounts/?screen=login');
    assert(!replacement.includes('opaque-mail-value'));
  }
  let replaced=false;assert.equal(consumeMailAction({href:'https://example.test/#section',replace:()=>replaced=true}),null);assert.equal(replaced,false);
  const invalid=consumeMailAction({href:'https://example.test/#action=other&token=opaque',replace:()=>replaced=true});assert.equal(invalid.action,'invalid');assert.equal(invalid.token,null);assert(replaced);
});

test('login bootstraps CSRF, sends a whitelisted body, accepts only real server identity and rotates token',async()=>{
  const {api,calls}=fixture([ok(anonymous()),ok(signedIn()),ok(anonymous('csrf-three'))]);
  const session=await api.login({email:'employee@example.test',password:'test-only-password',app_role:'admin',approval_status:'approved'});
  assert.equal(session.user.app_role,'hr');assert.deepEqual(session.permissions,['personal.read','people.manage']);
  const post=calls[1];assert.equal(post.url.pathname,'/tools/accounts/api/index.php');assert.equal(post.url.searchParams.get('route'),'/login');
  assert.deepEqual(JSON.parse(post.options.body),{email:'employee@example.test',password:'test-only-password'});
  assert.equal(post.options.headers['X-CSRF-Token'],'csrf-one');
  await api.logout();assert.equal(calls[2].options.headers['X-CSRF-Token'],'csrf-two');assert.equal(api.getSessionSnapshot().authenticated,false);
  for(const {options,url}of calls){assert.equal(options.credentials,'same-origin');assert.equal(options.cache,'no-store');assert.equal(options.redirect,'error');assert(!url.href.includes('test-only-password'));}
});

test('register cannot submit role or HR approval, confirmation password stays local',async()=>{
  const {api,calls}=fixture([ok(anonymous()),ok({message:'پاسخ عمومی'})]);
  await api.register({email:'employee@example.test',password:'test-only-password',first_name:'نام',last_name:'کاربر',role:'admin',is_active:true,approval_status:'approved',password_confirm:'extra'});
  assert.equal(calls[1].url.searchParams.get('route'),'/register');
  assert.deepEqual(Object.keys(JSON.parse(calls[1].options.body)).sort(),['email','first_name','last_name','password']);
});

test('every mutation uses JSON and the current memory CSRF, reset/change/logout consume rotations',async()=>{
  const {api,calls}=fixture([ok(anonymous()),ok({}),ok({}),ok({}),ok({}),ok(anonymous('after-reset')),ok(signedIn('after-change'))]);
  await api.forgot('employee@example.test');await api.resendVerification('employee@example.test');await api.verify('opaque-token');
  await api.register({email:'employee@example.test',password:'test-only-password',first_name:'نام',last_name:'کاربر'});
  await api.reset({token:'opaque-token',password:'test-only-password'});
  await api.changePassword({current_password:'test-only-current',password:'test-only-new-password'});
  assert.equal(calls.at(-1).options.headers['X-CSRF-Token'],'after-reset');
  assert.equal(api.getSessionSnapshot().csrf_token,'after-change');
  for(const {options,url}of calls.slice(1)){assert.equal(options.method,'POST');assert.equal(options.headers['Content-Type'],'application/json');assert(options.headers['X-CSRF-Token']);assert(!url.href.includes('opaque-token'));}
});

test('CSRF rejection refreshes session without replaying the write',async()=>{
  const {api,calls}=fixture([ok(anonymous()),fail(403,'csrf_invalid'),ok(anonymous('renewed')),ok({message:'پاسخ عمومی'})]);
  await assert.rejects(api.forgot('employee@example.test'),error=>error.code==='csrf_invalid');
  assert.equal(calls.filter(c=>c.options.method==='POST').length,1);
  await api.forgot('employee@example.test');assert.equal(calls.at(-1).options.headers['X-CSRF-Token'],'renewed');
});

test('errors preserve field/code evidence without treating a failed request as successful',async()=>{
  const {api}=fixture([ok(anonymous()),fail(422,'validation_failed',{email:'دامنه مجاز نیست'}),fail(429,'rate_limited'),fail(422,'invalid_token')]);
  await assert.rejects(api.register({email:'wrong@example.test'}),error=>error instanceof ApiError&&error.status===422&&error.fields.email==='دامنه مجاز نیست');
  await assert.rejects(api.resendVerification('employee@example.test'),error=>error.code==='rate_limited');
  await assert.rejects(api.verify('opaque'),error=>error.code==='invalid_token');
});

test('malformed/network/session failures are visible and do not submit credentials',async()=>{
  for(const response of [new Error('private network detail'),{ok:true,status:200,json:async()=>{throw Error('private parser detail')}},ok({authenticated:false})]){
    const {api,calls}=fixture([response]);
    await assert.rejects(api.login({email:'employee@example.test',password:'test-only-password'}),error=>!error.message.includes('private'));
    assert.equal(calls.length,1);assert.equal(calls[0].options.method,'GET');
  }
});

test('session refresh fallback uses GET and bootstrapping is deduplicated',async()=>{
  const {api,calls}=fixture([ok(anonymous()),ok({message:'ok'}),ok(signedIn())]);
  const [one,two]=await Promise.all([api.getSession(),api.getSession()]);assert.equal(one,two);assert.equal(calls.length,1);
  assert((await api.login({email:'employee@example.test',password:'test-only-password'})).authenticated);assert.equal(calls[2].options.method,'GET');
});

test('all specified screens render without secrets, fake permission controls or fabricated notifications',()=>{
  for(const screen of ['login','register','forgot','verification-sent','resend','forgot-sent','verify','pending','reset','reset-success','invalid-link','change-password','change-success','unavailable']){
    const html=authMarkup({screen,values:{email:'"/><img src=x onerror=bad()>'},message:'<script>bad()</script>'});
    assert(html.includes('<h1'));assert(!html.includes('<script>'));assert(!html.includes('<img'));assert(!html.includes('name="role"'));assert(!html.includes('name="approval_status"'));assert(!html.includes('localStorage'));
  }
  const signed=sessionMarkup(signedIn());assert(signed.includes('منابع انسانی'));assert(!signed.includes('csrf-two'));assert(!signed.includes('csrf_token'));
  assert.equal(notificationSlotMarkup(),'<div data-notification-slot hidden></div>');
});

test('production entry omits preview code; auth secrets never enter preference storage',async()=>{
  const entry=await readFile(new URL('../../public_html/index.html',import.meta.url),'utf8');assert(!entry.includes('preview'));assert.match(entry,/\.\/assets\/(?:operations\/)?app\.js/);
  const service=await readFile(new URL('../src/auth-api.js',import.meta.url),'utf8');assert(!service.includes('localStorage'));assert(!service.includes('sessionStorage'));
  const controller=await readFile(new URL('../src/auth-controller.js',import.meta.url),'utf8');assert(!controller.includes('localStorage'));assert(!controller.includes('widgetState'));
  const app=await readFile(new URL('../src/app.js',import.meta.url),'utf8');assert(app.indexOf('consumeMailAction({')<app.indexOf('createAuthApi({'));
});
