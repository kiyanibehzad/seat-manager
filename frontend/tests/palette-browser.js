const build=await(await fetch('../build/staging/build-info.json',{cache:'no-store'})).json();
const {mountAuth}=await import('../build/staging/assets/auth-controller.js?v='+build.version);
const {mountTheme}=await import('../build/staging/assets/theme.js?v='+build.version);
const listeners=new Set(),theme=mountTheme();let controller=null;
const makeUser=id=>({authenticated:true,user:{id,first_name:'کاربر',last_name:'آزمایشی '+id,email:'palette-'+id+'@example.test',access_group_name:'گروه آزمایشی',app_role:'user'},permissions:['personal.read','personal.request','notifications.read'],csrf_token:'synthetic-palette-'+id});
let session=makeUser(1);
function publish(next){session=next;document.querySelector('#palette-test-context').textContent=next?.authenticated?'شناسهٔ آزمایشی '+next.user.id:'مهمان';for(const listener of listeners)listener(next);}
const api={getSession:async()=>session,getSessionSnapshot:()=>session,subscribeSession(listener){listeners.add(listener);return()=>listeners.delete(listener);},async logout(){if(document.querySelector('#palette-test-logout-error').checked)throw Error('خروج آزمایشی ناموفق بود؛ نشست قبلی محفوظ است.');publish({authenticated:false,user:null,permissions:[],csrf_token:'synthetic-guest'});},notifications:{async list(){return{items:[{id:1,title:'اعلان آزمایشی',body:'این اعلان فقط برای بررسی ظاهر رنگ‌بندی است.',event_type:'fixture',action_url:null,created_at:'2026-10-26T10:00:00Z',read_at:null}],unread_count:1,next_before_id:null};},async read(){return{updated:true};},async readAll(){return{updated:true};}}};
function mount(){controller?.destroy();controller=mountAuth({root:document.querySelector('#app'),api,theme,baseUrl:location.href});}
document.querySelector('#palette-test-user').addEventListener('change',event=>{const wasSignedIn=session?.authenticated,id=event.target.value;publish(id==='guest'?{authenticated:false,user:null,permissions:[],csrf_token:'synthetic-guest'}:makeUser(Number(id)));if(!wasSignedIn&&session.authenticated)mount();});
document.querySelector('#palette-test-expire').addEventListener('click',()=>publish(null));
mount();
