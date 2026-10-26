import {pageTitle} from './brand.js';
import {footerMarkup} from './footer.js';
import { authMarkup, sessionMarkup, brandMarkup, themeMarkup, notificationSlotMarkup } from './auth-view.js';
import { mountNotifications } from './notification-controller.js';

const publicScreens = new Set(['login','register','forgot','resend']);
const secretInputs = root => root.querySelectorAll('input[type="password"]');
const busyLabels = {login:'در حال ورود…',register:'در حال ثبت‌نام…',forgot:'در حال ارسال درخواست…',resend:'در حال ارسال درخواست…',verify:'در حال تأیید…',reset:'در حال ذخیره…','change-password':'در حال ذخیره…'};

export function mountAuth({ root, api, mailAction=null, initialScreen='login', navigate=null, theme=null, onAuthenticated=null,baseUrl=location.href,notificationNavigation=null,renderSession=null }) {
  const state={screen:'loading',values:{},message:'',session:null,linkKind:mailAction?.action};
  let token=mailAction?.token || null;
  let busy=false;
  let disposed=false;
  let sessionCleanup=null;

  root.innerHTML=`<header class="app-header">${brandMarkup()}<div class="header-tools">${notificationSlotMarkup()}${themeMarkup()}</div></header><main id="main-content"><section class="auth-stage" aria-label="دسترسی به حساب"></section></main>${footerMarkup()}`;
  const stage=root.querySelector('.auth-stage');
  const notifications=mountNotifications({slot:root.querySelector('[data-notification-slot]'),api,baseUrl,navigation:notificationNavigation});
  const unsubscribeSession=api.subscribeSession(session=>{
    if(disposed)return;
    theme?.setUser?.(session?.authenticated?session.user.id:null);
    const previousId=state.session?.authenticated?state.session.user.id:null;
    state.session=session;
    if(previousId&&!session?.authenticated){token=null;state.screen='login';render({focus:true});}
    else if(previousId&&session?.authenticated&&session.user.id!==previousId){token=null;state.screen='session';render({focus:true});}
  });

  function render({focus=false}={}) {
    if(disposed)return;
    for(const input of secretInputs(root))input.value='';
    theme?.setUser?.(state.session?.authenticated?state.session.user.id:null);
    const signedIn=state.screen==='session';
    sessionCleanup?.();sessionCleanup=null;
    stage.className=signedIn?'session-container':'auth-stage';
    if(signedIn&&renderSession){stage.innerHTML='';sessionCleanup=renderSession({container:stage,session:state.session})||null;}
    else stage.innerHTML=signedIn?sessionMarkup(state.session):authMarkup(state);
    theme?.sync();
    if(!(signedIn&&renderSession))document.title=pageTitle(stage.querySelector('h1')?.textContent);
    if(focus)stage.querySelector('[data-page-heading]')?.focus({preventScroll:true});
  }

  function go(screen,{keepMessage=false,history=true}={}) {
    if(busy)return;
    if(!keepMessage)state.message='';
    if(!['verify','reset','invalid-link'].includes(screen))token=null;
    state.screen=screen;
    if(history&&publicScreens.has(screen))navigate?.(screen);
    render({focus:true});
  }

  function showError(error, form=null) {
    let slot=stage.querySelector('[data-form-error]');
    if(!slot){slot=document.createElement('div');slot.className='message error';slot.dataset.formError='';slot.setAttribute('role','alert');stage.prepend(slot);}
    slot.hidden=false;slot.textContent=error.message || 'انجام این کار ممکن نشد. دوباره تلاش کنید.';
    let first=null;
    for(const [name,raw] of Object.entries(error.fields || {})) {
      // API field names are not interpolated into selectors.
      const input=Array.from(form?.elements || []).find(el=>el.name===name);
      if(!input)continue;
      input.setAttribute('aria-invalid','true');
      const message=stage.querySelector(`#auth-${input.name}-error`);
      if(message){message.textContent=Array.isArray(raw)?raw.join(' '):String(raw);message.hidden=false;input.setAttribute('aria-describedby',[input.getAttribute('aria-describedby'),message.id].filter(Boolean).join(' '));}
      first??=input;
    }
    first?.focus();
  }

  function setBusy(form, value, kind='') {
    busy=value;
    if(!form)return;
    form.setAttribute('aria-busy',String(value));
    for(const control of form.elements)control.disabled=value;
    const status=form.querySelector('[data-form-status]');
    if(status)status.textContent=value?(busyLabels[kind] || 'در حال انجام…'):'';
  }

  function acceptSession(session) {
    state.session=session;
    if(!session?.authenticated)return false;
    token=null;
    state.screen='session';
    render({focus:true});
    onAuthenticated?.({user:session.user,permissions:[...session.permissions]});
    return true;
  }

  async function initialize() {
    if(busy)return;
    state.screen='loading';render();busy=true;
    try {
      const session=await api.getSession();
      if(disposed)return;
      state.session=session;
      // An email action must not silently mutate the signed-in account.
      if(mailAction){
        state.screen=mailAction.action==='invalid'?'invalid-link':mailAction.action;
        if(!token)state.screen='invalid-link';
      }else if(acceptSession(session))return;
      else state.screen=publicScreens.has(initialScreen)?initialScreen:'login';
      render();
    }catch(error){state.screen='unavailable';state.message=error.message;render();}
    finally{busy=false;}
  }

  async function submit(form) {
    if(busy||!form.reportValidity())return;
    const kind=form.dataset.form;
    const data=new FormData(form);
    const email=String(data.get('email') || '').trim().toLowerCase();
    if(email)state.values.email=email;
    if(kind==='register')for(const name of ['first_name','last_name'])state.values[name]=String(data.get(name)||'').trim();
    const password=String(data.get('password')||'');
    if(['register','reset','change-password'].includes(kind)&&password!==data.get('password_confirm')){
      showError({message:'تکرار رمز عبور با رمز جدید یکسان نیست.',fields:{password_confirm:'رمزها باید یکسان باشند.'}},form);return;
    }
    const errorSlot=form.querySelector('[data-form-error]');if(errorSlot)errorSlot.hidden=true;
    for(const input of form.querySelectorAll('[aria-invalid]'))input.removeAttribute('aria-invalid');
    for(const message of form.querySelectorAll('.field-error')){message.hidden=true;message.textContent='';}
    setBusy(form,true,kind);
    try {
      let result;
      if(kind==='login'){
        result=await api.login({email,password});
        if(!result?.authenticated)throw new Error('ورود کامل نشد. دوباره تلاش کنید.');
        acceptSession(result);
      }else if(kind==='register'){
        result=await api.register({email,password,first_name:state.values.first_name,last_name:state.values.last_name});
        state.screen='verification-sent';state.message=result?.message || '';render({focus:true});
      }else if(kind==='forgot'){
        result=await api.forgot(email);state.screen='forgot-sent';state.message=result?.message || '';render({focus:true});
      }else if(kind==='resend'){
        result=await api.resendVerification(email);state.screen='verification-sent';state.message=result?.message || '';render({focus:true});
      }else if(kind==='verify'){
        if(!token){state.screen='invalid-link';render({focus:true});return;}
        result=await api.verify(token);token=null;state.screen='pending';state.message=result?.message || '';render({focus:true});
      }else if(kind==='reset'){
        if(!token){state.screen='invalid-link';render({focus:true});return;}
        await api.reset({token,password});token=null;state.session=null;state.screen='reset-success';render({focus:true});
      }else if(kind==='change-password'){
        result=await api.changePassword({current_password:String(data.get('current_password')||''),password});state.session=result;state.screen='change-success';render({focus:true});
      }
    }catch(error){
      setBusy(form,false,kind);
      if(error.code==='invalid_token'){
        token=null;state.screen='invalid-link';render({focus:true});
      }else if(error.code==='unauthenticated'){
        state.session=null;state.screen='login';render({focus:true});showError(error);
      }else showError(error,form);
    }finally{
      for(const input of secretInputs(root))input.value='';
      setBusy(form,false,kind);
    }
  }

  async function click(event) {
    const link=event.target.closest('[data-screen]');
    if(link){
      if(event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
      event.preventDefault();if(state.session?.authenticated)go('session');else if(publicScreens.has(link.dataset.screen))go(link.dataset.screen);return;
    }
    const control=event.target.closest('[data-action]');if(!control||busy)return;
    const action=control.dataset.action;
    if(action==='retry'){await initialize();return;}
    if(action==='change-password'&&state.session?.authenticated){go('change-password');return;}
    if(action==='session'&&state.session?.authenticated){go('session');return;}
    if(action==='logout'){
      busy=true;control.disabled=true;
      notifications.setSession(null);
      try{await api.logout();state.session=null;state.values={};state.screen='login';state.message='';render({focus:true});navigate?.('login');}
      catch(error){notifications.setSession(state.session);showError(error);control.disabled=false;}
      finally{busy=false;}
    }
  }
  root.addEventListener('click',click);
  const submitListener=event=>{const form=event.target.closest('[data-form]');if(!form)return;event.preventDefault();void submit(form);};
  root.addEventListener('submit',submitListener);
  // Browser back/forward never replays a token or a write.
  const popstate=()=>{
    if(busy)return;
    const screen=new URL(location.href).searchParams.get('screen');
    if(state.session?.authenticated)go('session',{history:false});else go(publicScreens.has(screen)?screen:'login',{history:false});
  };
  window.addEventListener('popstate',popstate);
  void initialize();
  return {destroy(){disposed=true;token=null;sessionCleanup?.();unsubscribeSession();notifications.destroy();for(const input of secretInputs(root))input.value='';root.removeEventListener('click',click);root.removeEventListener('submit',submitListener);window.removeEventListener('popstate',popstate);},getState:()=>({screen:state.screen,busy})};
}
