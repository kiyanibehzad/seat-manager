import {ApiError} from '../auth-api.js';

const codes=['dashboard.read','seats.manage','registrations.review','people.manage','departments.manage'];
const invalid=()=>{throw new ApiError('invalid_response','اطلاعات گروه‌های دسترسی کامل دریافت نشد. دوباره تلاش کنید.');};
export function accessGroupName(user){
  if(typeof user?.access_group_name==='string'&&user.access_group_name.trim())return user.access_group_name;
  // Compatibility only while access_groups_ready is false; names never grant permissions.
  return ({admin:'مدیر کل',hr:'منابع انسانی',operator:'اپراتور حساب‌ها',user:'کاربر عادی'})[user?.app_role]||'گروه دسترسی مشخص نشده';
}
export function accessGroupsModel(data){
  if(!Array.isArray(data?.items)||!Array.isArray(data.capability_catalog))invalid();
  const capabilities=data.capability_catalog.map(c=>{
    if(!codes.includes(c.code)||typeof c.label!=='string'||!Array.isArray(c.requires)||c.requires.some(key=>!codes.includes(key)))invalid();
    return {key:c.code,label:c.label,requires:[...c.requires]};
  });
  if(capabilities.length!==codes.length||new Set(capabilities.map(c=>c.key)).size!==codes.length)invalid();
  const groups=data.items.map(g=>{
    if(typeof g.protected!=='boolean'||typeof g.is_super_admin!=='boolean'||g.is_super_admin&&!g.protected||!Number.isSafeInteger(g.id)||g.id<1||!Number.isSafeInteger(g.revision)||g.revision<1||typeof g.name!=='string'||!Array.isArray(g.capabilities)||g.capabilities.some(key=>!codes.includes(key)))invalid();
    return {id:g.id,name:g.name,revision:g.revision,protected:g.protected===true,is_super_admin:g.is_super_admin===true,system_key:g.system_key,capabilities:[...g.capabilities],user_count:g.user_count};
  });
  return {groups,capabilities};
}
export function toggleCapabilities(current,key,checked,catalog){
  const allowed=new Map(catalog.map(c=>[c.key,c])),selected=new Set(current.filter(code=>allowed.has(code)));
  if(!allowed.has(key))return [...selected];
  if(checked){
    const seen=new Set(),pending=[key];
    while(pending.length){const code=pending.pop();if(seen.has(code))continue;seen.add(code);selected.add(code);for(const dependency of allowed.get(code)?.requires||[])if(allowed.has(dependency))pending.push(dependency);}
  }else{
    selected.delete(key);let changed=true;
    while(changed){changed=false;for(const code of [...selected])if(allowed.get(code).requires.some(required=>!selected.has(required))){selected.delete(code);changed=true;}}
  }
  return catalog.map(c=>c.key).filter(code=>selected.has(code));
}
export function accessGroupCommand(group,{name,capabilities},catalog,key){
  if(group?.protected)throw new ApiError('forbidden','این گروه محافظت‌شده قابل ویرایش نیست.',{},403);
  name=String(name||'').trim();const selected=new Set(capabilities);
  if(!name)throw new ApiError('validation_failed','نام گروه را وارد کنید.',{name:'نام گروه الزامی است.'});
  if([...selected].some(code=>!codes.includes(code))||catalog.some(c=>selected.has(c.key)&&c.requires.some(required=>!selected.has(required))))throw new ApiError('validation_failed','امکانات وابستهٔ گروه را بررسی کنید.',{capabilities:'پیش‌نیاز امکانات انتخاب‌شده باید فعال باشد.'});
  return {route:'/admin/access-groups',method:group?'PATCH':'POST',key,body:{name,capabilities:catalog.map(c=>c.key).filter(code=>selected.has(code)),...(group?{group_id:group.id,expected_revision:group.revision}:{})}};
}
export function accessGroupAssignmentCommand(user,groupId,key){
  if(!Number.isSafeInteger(groupId)||groupId<1||!Number.isSafeInteger(user?.id)||user.id<1)throw new ApiError('validation_failed','فرد و گروه معتبر انتخاب کنید.');
  return {route:'/admin/users/access-group',key,body:{user_id:user.id,access_group_id:groupId}};
}
export function formPermission(kind){
  if(['add-account','rename-account'].includes(kind))return 'personal.read';
  if(['seat-request','renew-request'].includes(kind))return 'personal.request';
  if(['add-user','edit-user','bulk-preview'].includes(kind))return 'people.manage';
  if(kind==='department')return 'departments.manage';
  if(['approve-signup','reject-signup'].includes(kind))return 'registrations.review';
  if(['access-group','access-group-assignment'].includes(kind))return 'access_groups.manage';
  if(['settings','reasons'].includes(kind))return 'settings.manage';
  if(kind==='capacity')return 'capacity.manage';
  return 'seats.manage';
}
