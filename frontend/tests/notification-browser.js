// Isolated DOM/keyboard fixture. Never copied to public_html or connected to a backend.
import { mountNotifications } from '../src/notification-controller.js';
import { themeMarkup } from '../src/auth-view.js';
import { mountTheme } from '../src/theme.js';
const subscribers=new Set();
let session=null,scenario='unread',read=false;
document.querySelector('#test-theme').innerHTML=themeMarkup();mountTheme();
const api={
  getSession:async()=>session,getSessionSnapshot:()=>session,
  subscribeSession(listener){subscribers.add(listener);return()=>subscribers.delete(listener);},
  notifications:{
    async list(_,{signal}={}){
      if(scenario==='loading')await new Promise((resolve,reject)=>{signal.addEventListener('abort',()=>reject(Object.assign(Error('متوقف شد'),{code:'aborted'})),{once:true});});
      if(scenario==='error')throw Error('دریافت اعلان‌ها ممکن نشد. دوباره تلاش کنید.');
      const items=['empty','other'].includes(scenario)?[]:[{id:101,event_type:'qa.fixture',title:'اعلانِ آزمون رابط',body:'این متن آزمایشی است و صرفاً برای بررسی نمایش و کنترل خواندن استفاده می‌شود.',action_url:'http://127.0.0.1:8791/?view=unimplemented',created_at:'2026-10-23T21:00:00Z',read_at:read?'2026-10-24T15:00:00Z':null}];
      return{items,unread_count:items.length&&!read?1:0,next_before_id:null};
    },
    async read(){read=true;return{updated:true};},async readAll(){read=true;return{updated:true};},
  },
};
mountNotifications({slot:document.querySelector('[data-notification-slot]'),api,baseUrl:location.href});
function choose(value){scenario=value;read=false;session=value==='anonymous'?null:{authenticated:true,user:{id:value==='other'?2:value},permissions:['notifications.read']};for(const listener of subscribers)listener(session);}
document.querySelector('#scenario').addEventListener('change',event=>choose(event.target.value));
choose('unread');
