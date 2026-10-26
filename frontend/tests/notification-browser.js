// Isolated DOM/keyboard fixture. Never copied to public_html or connected to a backend.
import { mountNotifications } from '../src/notification-controller.js';
import { themeMarkup } from '../src/auth-view.js';
import { mountTheme } from '../src/theme.js';
const subscribers=new Set();
let session=null,scenario='unread',read=new Set();
document.querySelector('#test-theme').innerHTML=themeMarkup();mountTheme();
const api={
  getSession:async()=>session,getSessionSnapshot:()=>session,
  subscribeSession(listener){subscribers.add(listener);return()=>subscribers.delete(listener);},
  notifications:{
    async list(query={}, {signal}={}){
      if(scenario==='loading')await new Promise((resolve,reject)=>{signal.addEventListener('abort',()=>reject(Object.assign(Error('متوقف شد'),{code:'aborted'})),{once:true});});
      if(scenario==='error')throw Error('دریافت اعلان‌ها ممکن نشد. دوباره تلاش کنید.');
      const sample={id:101,event_type:'qa.fixture',title:'درخواست سیت شما در انتظار بررسی است',body:'درخواست تخصیص Premium برای حساب کاری شما ثبت شد. نتیجهٔ بررسی از همین بخش اطلاع داده می‌شود.',action_url:'?view=registrations',created_at:'2026-10-23T21:00:00Z',read_at:null};
      const examples=[sample,{...sample,id:102,title:'بررسی حساب شما تکمیل شد',body:'وضعیت حساب به‌روز شد. برای مشاهدهٔ نتیجه و تاریخ پایان، جزئیات را باز کنید.',created_at:'2026-10-23T18:30:00Z',read_at:'2026-10-24T15:00:00Z'},{...sample,id:103,title:'ظرفیت تازه برای رسیدگی به درخواست‌ها ثبت شد',body:'درخواست‌ها بر اساس نوبت و ظرفیت ثبت‌شده بررسی می‌شوند. زمان تحویل هنوز قطعی نیست.',action_url:null,created_at:'2026-10-23T16:00:00Z'}];
      if(scenario==='long')examples[0]={...sample,title:'نیاز به بازبینی اطلاعات حساب سازمانی و پیگیری درخواست تغییر سیت برای پروژهٔ جدید',body:'اطلاعات حساب شما نیازمند بازبینی است. لطفاً متن توضیحات را کامل بخوانید و سپس برای پیگیری نتیجه، جزئیات درخواست را باز کنید.\nایمیل حساب: very.long.organizational.account.for.notification.layout.review.without.spaces@example.test\nپس از اصلاح اطلاعات، درخواست دوباره بررسی می‌شود. برای ثبت نتیجهٔ نهایی باید وضعیت واقعی حساب در سرویس بررسی شود. نمایش عبارت <متن آزمایشی> فقط برای اطمینان از خوانایی است.'};
      const older=[{...examples[1],id:98,title:'نتیجهٔ بررسی درخواست قبلی'},{...examples[1],id:97,title:'ثبت اطلاعات حساب قبلی',body:'اطلاعات قبلی حساب برای پیگیری سوابق ثبت شده است.'}];
      const items=(['empty','other'].includes(scenario)?[]:query.before_id?older:['multiple','long'].includes(scenario)?examples:[sample]).map(item=>({...item,read_at:read.has(item.id)?'2026-10-24T15:00:00Z':item.read_at}));
      return{items,unread_count:['empty','other'].includes(scenario)?0:(['multiple','long'].includes(scenario)?examples:[sample]).filter(item=>item.read_at===null&&!read.has(item.id)).length,next_before_id:['multiple','long'].includes(scenario)&&!query.before_id?99:null};
    },
    async read(id){read.add(id);return{updated:true};},async readAll(){read=new Set([101,102,103,98,97]);return{updated:true};},
  },
};
mountNotifications({slot:document.querySelector('[data-notification-slot]'),api,baseUrl:location.href,navigation:{canHandle:url=>new URL(url).searchParams.get('view')==='registrations',navigate:()=>{document.querySelector('#outside-control').textContent='مشاهدهٔ آزمایشی انجام شد';}}});
function choose(value){scenario=value;read=new Set();session=value==='anonymous'?null:{authenticated:true,user:{id:value==='other'?2:value},permissions:['notifications.read']};for(const listener of subscribers)listener(session);}
document.querySelector('#scenario').addEventListener('change',event=>choose(event.target.value));
choose('unread');
