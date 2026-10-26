import test from 'node:test';
import assert from 'node:assert/strict';
import {fixtures,testPermissions,accessGroupFixtures} from './operations-fixtures.js';
import {accessGroupName,accessGroupsModel,accessGroupCommand,accessGroupAssignmentCommand,toggleCapabilities,formPermission} from '../src/operations/access.js';
import {createOperationsApi} from '../src/operations/api.js';
import {routeFromUrl,routeUrl,notificationRoute} from '../src/operations/routes.js';
import {sessionMarkup} from '../src/auth-view.js';
import {dashboardModel} from '../src/operations/models.js';
import {dailyView,accessGroupsView,pagePermissions,usersView,signupsView,panelNavigation} from '../src/operations/views.js';
import {accessGroupForm,accessGroupAssignmentForm,userForm,signupForm} from '../src/operations/forms.js';

const {groups,capabilities}=accessGroupsModel(accessGroupFixtures());

test('daily-only view keeps both days and server tasks but offers no operational action',()=>{
  const f=fixtures(),data=dashboardModel({today:[{key:'request:501',kind:'request',request:f.request}],tomorrow:[],counts:{today:1,tomorrow:0},iran_today:'2026-10-26'});
  const html=dailyView({...data,canOperate:false});assert(html.includes('کارهای امروز'));assert(html.includes('data-id="tomorrow"'));assert(dailyView({...data,day:'tomorrow',canOperate:false}).includes('کارهای فردا'));assert(html.includes(f.request.account.email));assert(!html.includes('data-op-action="admin-request"'));assert(!html.includes('data-op-action="operation-detail"'));assert(!html.includes('data-op-action="return-account"'));assert(html.includes('فقط مشاهده'));
  assert(dailyView({...data,canOperate:true}).includes('data-op-action="admin-request"'));
});
test('named access group is escaped and chief remains protected without an edit action',()=>{
  const html=accessGroupsView({groups:[{id:1,name:'مدیر کل',protected:true,is_super_admin:true,capabilities:[]},{id:7,name:'<img src=x onerror=bad()>',protected:false,capabilities:['registrations.review']}],capabilities});
  assert(!html.includes('<img'));assert(html.includes('&lt;img'));assert(html.includes('تأیید و رد ثبت‌نام'));assert(html.includes('data-op-action="edit-access-group" data-id="7"'));assert(!html.includes('data-op-action="edit-access-group" data-id="1"'));assert(html.includes('محافظت‌شده'));
});
test('group form exposes five simple options and assignment chooses exactly one independent group',()=>{
  const group={id:7,name:'گروه بررسی',capabilities:['registrations.review']};const html=accessGroupForm(group,capabilities);assert.equal((html.match(/name="capabilities"/g)||[]).length,5);assert(html.includes('checked  value="registrations.review"'));assert(!html.includes('name="department_id"'));assert(html.includes('اعلان‌ها'));
  const assignment=accessGroupAssignmentForm({...fixtures().users[0],access_group_id:7},[{id:1,name:'مدیر کل'},group]);assert(assignment.includes('name="access_group_id"'));assert(assignment.includes('value="7" selected'));assert(!assignment.includes('name="app_role"'));assert(!assignment.includes('name="department_id"'));
});

test('catalog dependencies select prerequisites and remove dependent capabilities',()=>{
  assert.deepEqual(toggleCapabilities([],'dashboard.read',true,capabilities),['dashboard.read','seats.manage']);
  assert.deepEqual(toggleCapabilities(['dashboard.read','seats.manage','registrations.review'],'seats.manage',false,capabilities),['registrations.review']);
  assert.deepEqual(toggleCapabilities([],'departments.manage',true,capabilities),['people.manage','departments.manage']);
  assert.deepEqual(toggleCapabilities(['people.manage','departments.manage'],'people.manage',false,capabilities),[]);
  assert.deepEqual(toggleCapabilities(['registrations.review'],'finance.read',true,capabilities),['registrations.review']);
});
test('group commands use documented versions and preserve immutable retry identity',async()=>{
  const command=accessGroupCommand(groups[2],{name:'  گروه جدید  ',capabilities:['seats.manage','dashboard.read']},capabilities,'group-command-key');
  assert.equal(command.route,'/admin/access-groups');assert.equal(command.method,'PATCH');assert.deepEqual(command.body,{name:'گروه جدید',capabilities:['dashboard.read','seats.manage'],group_id:3,expected_revision:1});
  const calls=[],api=createOperationsApi({write:async(route,body,options)=>{calls.push({route,body,options});return {};}});await api.command(command);command.body.expected_revision=99;command.body.capabilities.push('people.manage');await api.command(command);
  assert.deepEqual(calls[1].body,calls[0].body);assert.equal(calls[0].body.idempotency_key,'group-command-key');assert.equal(calls[0].body.expected_revision,1);
  const create=accessGroupCommand(null,{name:'تأییدکننده',capabilities:['registrations.review']},capabilities);assert.equal(create.method,'POST');assert(!('expected_revision' in create.body));
  assert.throws(()=>accessGroupCommand(groups[0],{name:'changed',capabilities:[]},capabilities),e=>e.code==='forbidden');assert.throws(()=>accessGroupCommand(groups[3],{name:'changed',capabilities:[]},capabilities),e=>e.code==='forbidden');
  assert.throws(()=>accessGroupCommand(null,{name:'x',capabilities:['dashboard.read']},capabilities),e=>!!e.fields.capabilities);assert.throws(()=>accessGroupCommand(null,{name:'x',capabilities:['finance.read']},capabilities));assert.throws(()=>accessGroupCommand(null,{name:' ',capabilities:[]},capabilities));
  const assigned=accessGroupAssignmentCommand(fixtures().users[1],5);assert.equal(assigned.route,'/admin/users/access-group');assert.deepEqual(assigned.body,{user_id:2,access_group_id:5});assert(!('app_role' in assigned.body));assert(!('department_id' in assigned.body));
});
test('group defaults and real names never grant permissions by legacy role or group title',()=>{
  const f=fixtures(),personal=testPermissions('user');assert.equal(groups[0].protected,true);assert.equal(groups[3].protected,true);assert.deepEqual(groups[0].capabilities,[]);assert.equal(groups[2].protected,false);assert(groups[2].capabilities.includes('registrations.review'));
  const named={...f.users[0],app_role:'admin',access_group_name:'گروه دلخواه <script>x</script>'};assert.equal(accessGroupName(named),named.access_group_name);const html=sessionMarkup({user:named});assert(html.includes('گروه دلخواه &lt;script&gt;'));assert(!html.includes('<script>'));
  const nav=panelNavigation({page:'accounts'},personal);assert(!nav.includes('data-id="settings"'));assert(!nav.includes('data-id="users"'));assert(!nav.includes('data-id="seats"'));assert(nav.includes('حساب‌های من'));assert(nav.includes('درخواست‌های من'));
  assert.equal(formPermission('access-group'),'access_groups.manage');assert.equal(formPermission('approve-signup'),'registrations.review');
});
test('registration-only reviewer has independent list, form and notification route without people.manage',()=>{
  const p=testPermissions('reviewer'),base='https://example.test/team/',f=fixtures();assert(!p.includes('people.manage'));assert(pagePermissions(p).includes('registrations'));assert(!pagePermissions(p).includes('users'));assert.equal(routeFromUrl(base,p).page,'registrations');
  const route=notificationRoute(base+'?view=registrations',base,p);assert.equal(route.page,'registrations');assert.equal(routeFromUrl(base+routeUrl(base,route),p).page,'registrations');assert.equal(notificationRoute(base+'?view=registrations',base,testPermissions('user')),null);
  const html=signupsView(f.users,{canManagePeople:false});assert(html.includes('approve-signup'));assert(html.includes('reject-signup'));assert(!html.includes('data-id="users"'));assert(!html.includes('edit-user'));assert(!html.includes('add-user'));
  const form=signupForm(f.users[1],f.departments);assert(form.includes('name="department_id"'));assert(!form.includes('name="access_group_id"'));assert(form.includes('گروه پایهٔ شخصی'));
  const forbidden=routeFromUrl(base+'?view=users',p);assert.equal(forbidden.denied,true);assert.equal(forbidden.page,'accounts');
});
test('people-only cannot edit special priority and department permissions stay independent',()=>{
  const f=fixtures();const ordinary=userForm(f.users[1],f.departments,{canManageScores:false});assert(!ordinary.includes('name="special_priority"'));assert(userForm(f.users[1],f.departments,{canManageScores:true}).includes('name="special_priority"'));
  const html=usersView({users:f.users,departments:f.departments,canManageScores:false});assert(!html.includes('data-op-special'));assert(html.includes('بخش سازمانی'));assert(html.includes('گروه دسترسی'));assert(html.includes(f.users[0].access_group_name));assert(usersView({users:f.users,departments:f.departments,canManageScores:true}).includes('data-op-special'));
  assert(!pagePermissions(['capacity.read','personal.read']).includes('overview'));assert(pagePermissions(testPermissions('operator')).includes('overview'));assert(!pagePermissions(testPermissions('people')).includes('departments'));
});
test('group list adapter uses exact route and rejects incomplete or privileged capability catalogs',async()=>{
  const calls=[],api=createOperationsApi({read:async(route,query)=>{calls.push({route,query});return accessGroupFixtures();}});assert.equal(accessGroupsModel(await api.access.groups()).groups.length,6);assert.deepEqual(calls,[{route:'/admin/access-groups',query:{}}]);
  const raw=accessGroupFixtures();raw.capability_catalog.pop();assert.throws(()=>accessGroupsModel(raw),e=>e.code==='invalid_response');const bad=accessGroupFixtures();bad.capability_catalog[0].code='finance.read';assert.throws(()=>accessGroupsModel(bad));const missing=accessGroupFixtures();delete missing.items[0].protected;assert.throws(()=>accessGroupsModel(missing));
});
