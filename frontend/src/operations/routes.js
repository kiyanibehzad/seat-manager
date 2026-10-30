import {safeNotificationUrl} from '../notification-api.js';
import {pagePermissions} from './views.js';
const id=value=>/^\d+$/.test(String(value||''))&&Number(value)>0?Number(value):null;
export function routeFromUrl(href,permissions){
  const url=new URL(href),params=new URLSearchParams(url.hash.slice(1));for(const [key,value]of url.searchParams)params.set(key,value);
  const aliases={'my-accounts':'accounts','my-requests':'requests',operations:'seats'};
  const view=params.get('view'),allowed=pagePermissions(permissions),page=aliases[view]||view||(allowed.find(p=>!['accounts','requests'].includes(p))||'accounts');
  if(!allowed.includes(page))return {page:allowed.includes('accounts')?'accounts':allowed[0],tab:'pending',day:'today',denied:true};
  return {page,tab:['pending','renewals','ongoing','history','accounts','credits'].includes(params.get('tab'))?params.get('tab'):'pending',day:params.get('day')==='tomorrow'?'tomorrow':'today',creditId:id(params.get('credit')),summary:params.get('summary')==='1',summaryOwnerId:id(params.get('owner')),fromDay:params.get('from_day')||null,toDay:params.get('to_day')||null,creditStatus:['pending','ongoing','completed','rejected','cancelled','all'].includes(params.get('credit_status'))?params.get('credit_status'):'pending',requestId:id(params.get('request')),operationId:id(params.get('operation')),accountId:id(params.get('account')),renewId:id(params.get('renew_account')),signups:view==='registrations',before:id(params.get('before')),historyKind:params.get('history_kind')==='requests'?'requests':'operations'};
}
export function routeUrl(baseUrl,route){const url=new URL(baseUrl);url.search='';url.hash='';url.searchParams.set('view',route.page==='accounts'?'my-accounts':route.page==='requests'?'my-requests':route.signups?'registrations':route.page==='seats'?'operations':route.page);for(const [field,param]of Object.entries({tab:'tab',day:'day',creditId:'credit',summaryOwnerId:'owner',fromDay:'from_day',toDay:'to_day',creditStatus:'credit_status',requestId:'request',operationId:'operation',accountId:'account',renewId:'renew_account',before:'before',historyKind:'history_kind'}))if(route[field]!=null)url.searchParams.set(param,String(route[field]));if(route.summary)url.searchParams.set('summary','1');return url.pathname+url.search;}
export function notificationRoute(url,baseUrl,permissions){
  const safe=safeNotificationUrl(url,baseUrl);if(!safe)return null;
  const parsed=new URL(safe),params=new URLSearchParams(parsed.hash.slice(1));for(const [key,value]of parsed.searchParams)params.set(key,value);
  if(!['my-accounts','my-requests','operations','registrations'].includes(params.get('view')))return null;
  const route=routeFromUrl(safe,permissions),wanted={ 'my-accounts':'accounts','my-requests':'requests',operations:'seats',registrations:'registrations'}[params.get('view')];
  return route.page===wanted?route:null;
}
