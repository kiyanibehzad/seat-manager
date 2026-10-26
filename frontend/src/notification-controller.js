import { createNotificationApi, safeNotificationUrl } from './notification-api.js';
import { createNotificationStore } from './notification-store.js';
import { notificationButtonMarkup, notificationPanelMarkup } from './notification-view.js';

export function mountNotifications({slot,api,baseUrl,navigation=null}) {
  const store=createNotificationStore({api:createNotificationApi(api),sessionApi:api,isVisible:()=>document.visibilityState==='visible'});
  let previous=null,panelScrollTop=0;
  function positionPanel(){
    const panel=slot.querySelector('.notification-panel');if(!panel||panel.hidden)return;
    const viewport=document.documentElement.clientWidth;
    panel.style.width=`${Math.min(390,viewport-32)}px`;
    const anchor=slot.getBoundingClientRect(),width=panel.getBoundingClientRect().width;
    const left=Math.max(16,Math.min(anchor.left,viewport-width-16));
    panel.style.setProperty('--notification-left',`${left-anchor.left}px`);
    panel.style.maxHeight=`${Math.max(160,innerHeight-anchor.bottom-24)}px`;
  }
  const unsubscribe=store.subscribe(state=>{
    const samePanel=state.open&&previous?.open&&state.userId===previous.userId;
    if(!samePanel)panelScrollTop=0;
    else if(!['loading','error'].includes(previous.status))panelScrollTop=slot.querySelector('.notification-panel')?.scrollTop??0;
    const active=document.activeElement;
    const wasInside=slot.contains(active);
    const focusAction=wasInside?active?.dataset.notificationAction:null;
    const focusId=wasInside?active?.dataset.notificationId:null;
    slot.hidden=!state.userId;
    if(!state.userId){slot.innerHTML='';previous=state;return;}
    slot.innerHTML=notificationButtonMarkup(state)+notificationPanelMarkup(state,{baseUrl,navigation});
    positionPanel();
    if(state.open&&!previous?.open)slot.querySelector('[data-notification-action="close"]')?.focus({preventScroll:true});
    else if(wasInside){
      const next=Array.from(slot.querySelectorAll('[data-notification-action]')).find(el=>el.dataset.notificationAction===focusAction&&el.dataset.notificationId===focusId&&!el.disabled);
      (next || slot.querySelector(`[data-notification-action="${state.open?'close':'toggle'}"]`))?.focus({preventScroll:true});
    }
    if(state.open){const panel=slot.querySelector('.notification-panel');if(panel)panel.scrollTop=panelScrollTop;}
    previous=state;
  });
  function close({restore=false}={}){store.close();if(restore)slot.querySelector('[data-notification-action="toggle"]')?.focus({preventScroll:true});}
  function click(event){
    const button=event.target.closest('[data-notification-action]');if(!button||!slot.contains(button))return;
    const action=button.dataset.notificationAction;
    if(action==='toggle')store.toggle();
    if(action==='close')close({restore:true});
    if(action==='refresh')void store.refresh();
    if(action==='more')void store.refresh({append:true});
    if(action==='read')void store.read(Number(button.dataset.notificationId));
    if(action==='read-all')void store.readAll();
    if(action==='navigate'&&navigation){
      const state=store.snapshot(),item=state.items.find(item=>item.id===Number(button.dataset.notificationId));
      const url=safeNotificationUrl(item?.action_url,baseUrl);
      if(url&&navigation.canHandle(url)){close();navigation.navigate(url);}
    }
  }
  const pointer=event=>{if(!slot.contains(event.target))close();};
  const focus=event=>{if(!slot.contains(event.target))close();};
  const keyboard=event=>{if(event.key==='Escape'&&store.snapshot().open){event.preventDefault();close({restore:true});}};
  const visibility=()=>{if(document.visibilityState!=='visible')close();store.visibilityChanged();};
  slot.addEventListener('click',click);
  document.addEventListener('pointerdown',pointer);
  document.addEventListener('focusin',focus);
  document.addEventListener('keydown',keyboard);
  document.addEventListener('visibilitychange',visibility);
  window.addEventListener('resize',positionPanel);
  const unsubscribeSession=api.subscribeSession(session=>store.setSession(session));
  return {
    setSession:session=>store.setSession(session),
    destroy(){unsubscribeSession();unsubscribe();store.destroy();slot.innerHTML='';slot.hidden=true;slot.removeEventListener('click',click);document.removeEventListener('pointerdown',pointer);document.removeEventListener('focusin',focus);document.removeEventListener('keydown',keyboard);document.removeEventListener('visibilitychange',visibility);window.removeEventListener('resize',positionPanel);},
  };
}
