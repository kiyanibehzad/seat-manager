import { notificationDate, safeNotificationUrl } from './notification-api.js';
const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fa=value=>new Intl.NumberFormat('fa-IR').format(value);
const bell='<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>';

export function notificationButtonMarkup(state) {
  const count=!['loading','error'].includes(state.status)&&state.unreadCount>0?state.unreadCount:null;
  return `<button type="button" class="notification-trigger" data-notification-action="toggle" aria-label="اعلان‌ها${count?'، '+fa(count)+' خوانده‌نشده':''}" aria-expanded="${state.open}" aria-controls="notification-panel">${bell}${count?`<span class="notification-count" aria-hidden="true">${fa(count)}</span>`:''}</button>`;
}

export function notificationPanelMarkup(state,{baseUrl,navigation=null}={}) {
  const stale=['loading','error'].includes(state.status);
  const items=(stale?[]:state.items).map(item=>{
    const url=navigation?safeNotificationUrl(item.action_url,baseUrl):null;
    // A safe URL alone is not enough: the product must have an implemented handler.
    const canNavigate=!!url&&navigation.canHandle(url);
    const date=notificationDate(item.created_at);
    return `<li class="notification-item" data-notification-id="${item.id}" ${item.read_at===null?'data-unread="true"':''}><div class="notification-item-heading"><h3>${esc(item.title)}</h3>${item.read_at===null?'<span class="notification-unread-label">جدید</span>':''}</div><p class="notification-body">${esc(item.body)}</p><time datetime="${esc(item.created_at)}">${esc(date)}</time><div class="notification-item-actions">${item.read_at===null?`<button type="button" class="notification-text-button" data-notification-action="read" data-notification-id="${item.id}" ${state.writing?'disabled':''}>خواندم</button>`:'<span class="notification-read-label">خوانده‌شده</span>'}${canNavigate?`<button type="button" class="notification-text-button" data-notification-action="navigate" data-notification-id="${item.id}">مشاهدهٔ مورد</button>`:''}</div></li>`;
  }).join('');
  return `<section id="notification-panel" class="notification-panel" aria-labelledby="notification-title" ${state.open?'':'hidden'}><div class="notification-panel-heading"><h2 id="notification-title">اعلان‌ها</h2><button type="button" class="notification-close" data-notification-action="close" aria-label="بستن اعلان‌ها">×</button></div><div class="notification-toolbar">${!stale&&state.unreadCount>0?`<button type="button" class="notification-text-button" data-notification-action="read-all" ${state.writing?'disabled':''}>همه را خواندم</button>`:''}<button type="button" class="notification-text-button" data-notification-action="refresh" ${state.writing||state.status==='loading'?'disabled':''}>تازه‌سازی</button></div>${state.error?`<p class="notification-error" role="alert">${esc(state.error)}</p>`:''}<p class="notification-status" role="status" aria-live="polite">${state.writing?'در حال ثبت…':state.status==='loading'?'در حال دریافت اعلان‌ها…':state.status==='ready'&&!state.items.length?'اعلانی ندارید.':''}</p>${items?`<ul class="notification-list">${items}</ul>`:''}${!stale&&state.nextBeforeId?`<button type="button" class="notification-more" data-notification-action="more" ${state.writing||state.loadingMore?'disabled':''}>${state.loadingMore?'در حال دریافت…':'اعلان‌های بیشتر'}</button>`:''}</section>`;
}
