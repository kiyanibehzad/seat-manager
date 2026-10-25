const identity=session=>session?.authenticated&&session.user?.id!=null?String(session.user.id):null;
const empty=()=>({userId:null,open:false,status:'idle',items:[],unreadCount:null,nextBeforeId:null,loadingMore:false,writing:false,error:''});

// Owns one user's ephemeral data. No timers, storage, optimistic counts or timestamps.
export function createNotificationStore({api,sessionApi,isVisible=()=>true}) {
  let state=empty(),generation=0,readVersion=0,sessionToken=null;
  let readAbort=null,writeAbort=null;
  const listeners=new Set();
  const emit=()=>{for(const listener of listeners)listener(snapshot());};
  function snapshot(){return {...state,items:state.items.map(item=>({...item}))};}
  function invalidateRead(){readVersion++;readAbort?.abort();readAbort=null;}
  function clear(){generation++;invalidateRead();writeAbort?.abort();writeAbort=null;sessionToken=null;state=empty();emit();}
  function setSession(session){
    const next=identity(session),nextToken=session?.csrf_token??null;if(next===state.userId&&(!next||nextToken===sessionToken))return;
    clear();if(next){sessionToken=nextToken;state.userId=next;emit();if(isVisible())void refresh();}
  }
  const live=(owner,gen)=>state.userId===owner&&generation===gen&&identity(sessionApi.getSessionSnapshot())===owner&&(sessionApi.getSessionSnapshot()?.csrf_token??null)===sessionToken;

  async function refresh({append=false}={}) {
    if(!state.userId||!isVisible()||state.writing)return;
    if(append&&!state.nextBeforeId)return;
    invalidateRead();
    const abort=new AbortController();readAbort=abort;
    const version=readVersion,gen=generation,owner=state.userId,beforeId=append?state.nextBeforeId:null;
    state.error='';state.loadingMore=append;if(!append)state.status='loading';emit();
    const current=()=>live(owner,gen)&&version===readVersion&&!abort.signal.aborted;
    try {
      const session=await sessionApi.getSession();
      if(identity(session)!==owner){if(gen===generation)setSession(session);return;}
      if(!current())return;
      const page=await api.list({beforeId,signal:abort.signal});
      if(!current())return;
      state.items=append?[...new Map([...state.items,...page.items].map(item=>[item.id,item])).values()]:page.items;
      state.unreadCount=page.unreadCount;state.nextBeforeId=page.nextBeforeId;state.status='ready';
    }catch(error){
      if(!current())return;
      if(error.code==='unauthenticated'){clear();return;}
      state.error=error.message||'اعلان‌ها دریافت نشدند. دوباره تلاش کنید.';state.status='error';state.unreadCount=null;
    }finally{
      if(current()){state.loadingMore=false;readAbort=null;emit();}
    }
  }

  async function mark(id=null) {
    if(!state.userId||state.writing)return;
    if(id!==null&&!state.items.some(item=>item.id===id&&item.read_at===null))return;
    if(id===null&&!(state.unreadCount>0))return;
    invalidateRead();
    const abort=new AbortController();writeAbort=abort;
    const gen=generation,owner=state.userId;
    const current=()=>live(owner,gen)&&!abort.signal.aborted;
    state.writing=true;state.error='';state.loadingMore=false;if(state.status==='loading')state.status=state.items.length?'ready':'idle';emit();
    try {
      const session=await sessionApi.getSession();
      if(identity(session)!==owner){if(gen===generation)setSession(session);return;}
      if(!current())return;
      if(id===null)await api.readAll({signal:abort.signal});else await api.read(id,{signal:abort.signal});
      if(!current())return;
      state.writing=false;writeAbort=null;
      await refresh();
    }catch(error){
      if(!current())return;
      if(error.code==='unauthenticated'){clear();return;}
      state.error=error.message||'خوانده‌شدن اعلان ثبت نشد. دوباره تلاش کنید.';
    }finally{if(current()){state.writing=false;writeAbort=null;emit();}}
  }

  return {
    snapshot,setSession,refresh,read:id=>mark(id),readAll:()=>mark(),
    toggle(){if(!state.userId)return;state.open=!state.open;emit();if(state.open)void refresh();},
    close(){if(state.open){state.open=false;emit();}},
    visibilityChanged(){if(!isVisible()){invalidateRead();return;}if(state.userId)void refresh();},
    subscribe(listener){listeners.add(listener);listener(snapshot());return()=>listeners.delete(listener);},
    destroy(){clear();listeners.clear();},
  };
}
