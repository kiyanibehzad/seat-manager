import { createAuthApi, consumeMailAction, installationUrl } from './auth-api.js';
import { mountAuth } from './auth-controller.js';
import { mountTheme } from './theme.js';

const mailAction=consumeMailAction({href:location.href,replace:path=>history.replaceState(null,'',path)});
const root=document.querySelector('#app');
const theme=mountTheme();
const baseUrl=installationUrl(location.href);
const api=createAuthApi({baseUrl});
mountAuth({
  root,api,mailAction,theme,
  initialScreen:new URL(location.href).searchParams.get('screen') || 'login',
  navigate:screen=>{
    const url=new URL(location.href);url.hash='';url.searchParams.set('screen',screen);history.pushState(null,'',url.pathname+url.search);
  },
});
