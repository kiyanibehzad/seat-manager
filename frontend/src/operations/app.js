import {createAuthApi,consumeMailAction,installationUrl} from '../auth-api.js';
import {mountAuth} from '../auth-controller.js';
import {mountTheme} from '../theme.js';
import {mountOperations} from './controller.js';
import {notificationRoute} from './routes.js';
const mailAction=consumeMailAction({href:location.href,replace:path=>history.replaceState(null,'',path)});
const baseUrl=installationUrl(location.href),api=createAuthApi({baseUrl}),theme=mountTheme();
let panel=null,currentSession=null;
const notificationNavigation={
  canHandle:url=>!!panel&&!!currentSession&&!!notificationRoute(url,baseUrl,currentSession.permissions),
  navigate:url=>{const route=currentSession&&notificationRoute(url,baseUrl,currentSession.permissions);if(route&&panel)panel.navigate(route);},
};
mountAuth({root:document.querySelector('#app'),api,baseUrl,theme,mailAction,notificationNavigation,
  initialScreen:new URL(location.href).searchParams.get('screen')||'login',
  navigate:screen=>{const url=new URL(location.href);url.searchParams.set('screen',screen);url.hash='';history.pushState(null,'',url.pathname+url.search);},
  renderSession:({container,session})=>{currentSession=session;panel=mountOperations({container,transport:api,session,baseUrl});return()=>{panel?.destroy();panel=null;currentSession=null;};},
});
