import test from 'node:test';
import assert from 'node:assert/strict';
import {mailFixture} from './mail-fixtures.js';
import {canManageMail,mailDate,mailSettingsModel,mailSettingsCommand,mailPanelMarkup} from '../src/operations/mail-settings.js';
import {settingsView} from '../src/operations/views.js';
test('mail section is reserved for the explicit chief flag, never a role/group label or settings permission',()=>{
  for(const session of [{user:{app_role:'admin'}},{user:{access_group_name:'مدیر کل'}},{user:{is_super_admin:false},permissions:['settings.manage']}])assert.equal(canManageMail(session),false);
  assert.equal(canManageMail({user:{is_super_admin:true}}),true);
  const base={settings:{},users:[],reasons:[]};assert(!settingsView(base).includes('data-mail-panel'));assert(settingsView({...base,canManageMail:true}).includes('data-mail-panel'));
});
test('public mail model whitelists fields and the password input never receives a returned credential',()=>{
  const raw=mailFixture().snapshot();raw.settings.smtp_password='never-render-this-secret';raw.status.items[0].body='never-render-this-body';raw.status.items[0].recipient='never-render-this-recipient';
  const view=mailSettingsModel(raw),html=mailPanelMarkup(view);assert(!JSON.stringify(view).includes('never-render'));assert(!html.includes('never-render'));assert(html.includes('name="smtp_password" value=""'));assert(html.includes('رمز قبلی ذخیره شده'));
  assert(!html.includes('data-mail-id="5"'));assert(html.includes('data-mail-id="4"'));assert(html.includes('ارسال خودکار دوباره متوقف'));
});
test('mail saves retain blank passwords, omit hidden SMTP password changes, and reject conflicting secret edits',()=>{
  const values={...mailFixture().snapshot().settings,smtp_password:''};const body=mailSettingsCommand(values,7);assert.equal(body.expected_revision,7);assert(!('smtp_password'in body));assert.equal(body.delivery_enabled,false);
  assert.throws(()=>mailSettingsCommand({...values,smtp_password:'synthetic-new',clear_password:true},7),error=>error.code==='validation_failed');
  const inactive=mailSettingsCommand({...values,transport:'phpmail',smtp_password:'synthetic-new',clear_password:true},7);assert(!('smtp_password'in inactive));assert.equal(inactive.clear_password,false);
});
test('mail metadata rejects missing real counts and unsafe retry states; UTC SQL timestamps display in Iran',()=>{
  const data=mailFixture().snapshot();delete data.status.counts.sent;assert.throws(()=>mailSettingsModel(data));
  const valid=mailFixture().snapshot();valid.status.items[0].retryable=true;assert.equal(mailSettingsModel(valid).status.items[0].retryable,false);
  assert.equal(mailDate('2026-10-26 08:00:00'),mailDate('2026-10-26T08:00:00Z'));
  const hostile=mailFixture().snapshot();hostile.settings.from_name='<img onerror=bad()>';hostile.status.items[0].error_message='<script>bad()</script>';const html=mailPanelMarkup(mailSettingsModel(hostile));assert(!html.includes('<img'));assert(!html.includes('<script>'));assert(html.includes('&lt;script&gt;'));
});
