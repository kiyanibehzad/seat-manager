export class ApiError extends Error {
  constructor(code, message, fields = {}, status = 0) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.fields = fields && typeof fields === 'object' ? fields : {};
    this.status = status;
  }
}

export function installationUrl(locationHref) {
  const base = new URL('./', locationHref);
  base.search = '';
  base.hash = '';
  return base;
}

// Cookie authenticates the session. The CSRF token lives only in this closure.
export function createAuthApi({ baseUrl, fetchImpl = globalThis.fetch.bind(globalThis) }) {
  const base = installationUrl(baseUrl);
  let csrf = null;
  let session = null;
  let bootstrap = null;
  let sessionEpoch = 0;
  const sessionListeners = new Set();
  function publishSession(value) { for (const listener of sessionListeners) listener(value); }

  async function request(route, body, { query = {}, signal, method } = {}) {
    const requestEpoch=sessionEpoch;
    const endpoint = new URL('api/index.php', base);
    endpoint.searchParams.set('route', route);
    for (const [key,value] of Object.entries(query)) if(value !== null && value !== undefined) endpoint.searchParams.set(key,String(value));
    const headers = { Accept: 'application/json' };
    if (body !== undefined) {
      if (!csrf) throw new ApiError('session_required', 'ارتباط با برنامه هنوز آماده نیست. دوباره تلاش کنید.');
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = csrf;
    }
    let response;
    try {
      response = await fetchImpl(endpoint, {
        method: body === undefined ? 'GET' : method === 'PATCH' ? 'PATCH' : 'POST',
        credentials: 'same-origin', cache: 'no-store', redirect: 'error',
        signal: signal ? AbortSignal.any([signal,AbortSignal.timeout(20000)]) : AbortSignal.timeout(20000),
        headers, ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      });
    } catch {
      if(signal?.aborted)throw new ApiError('aborted','درخواست متوقف شد.');
      throw new ApiError('network_error', 'ارتباط با برنامه برقرار نشد. اتصال خود را بررسی و دوباره تلاش کنید.');
    }
    let payload;
    try { payload = await response.json(); }
    catch { throw new ApiError('invalid_response', 'پاسخ برنامه قابل دریافت نیست. کمی بعد دوباره تلاش کنید.'); }
    if (!response.ok || payload?.ok !== true) {
      const error = payload?.error;
      if(error?.code === 'unauthenticated') {
        if(requestEpoch!==sessionEpoch)throw new ApiError('session_changed','نشست برنامه تغییر کرده است. دوباره تلاش کنید.');
        sessionEpoch++; session=null; csrf=null; bootstrap=null; publishSession(null);
      }
      throw new ApiError(error?.code || 'unavailable', error?.message || 'انجام این کار ممکن نشد. دوباره تلاش کنید.', error?.fields, response.status);
    }
    return payload.data;
  }

  function rememberSession(data) {
    if (!data || typeof data.authenticated !== 'boolean' || typeof data.csrf_token !== 'string' || !data.csrf_token) {
      throw new ApiError('invalid_session', 'نشست برنامه دریافت نشد. دوباره تلاش کنید.');
    }
    if (data.authenticated && (!data.user || !Array.isArray(data.permissions))) {
      throw new ApiError('invalid_session', 'اطلاعات ورود کامل دریافت نشد. دوباره تلاش کنید.');
    }
    csrf = data.csrf_token;
    session = data;
    publishSession(session);
    return data;
  }

  async function getSession() {
    if (!bootstrap) {
      const epoch=sessionEpoch;
      const task=request('/session').then(data=>{
        if(epoch!==sessionEpoch)throw new ApiError('session_changed','نشست برنامه تغییر کرده است.');
        return rememberSession(data);
      }).finally(() => { if(bootstrap===task)bootstrap=null; });
      bootstrap=task;
    }
    return bootstrap;
  }

  async function post(route, values, rotates = false, options = {}) {
    if (!csrf) await getSession();
    // A session read started before a rotation must never resurrect the old user.
    if(rotates){sessionEpoch++;bootstrap=null;}
    let result;
    try { result = await request(route, values, options); }
    catch (error) {
      if (error.code === 'csrf_invalid') {
        csrf = null;
        await getSession();
        throw new ApiError('csrf_invalid', 'نشست فرم به‌روز شد. دوباره دکمه را بزنید.');
      }
      throw error;
    }
    if (rotates) {
      csrf = null;
      if (typeof result?.authenticated === 'boolean' && result?.csrf_token) rememberSession(result);
      else await getSession();
    }
    return result;
  }

  return {
    getSession,
    login: ({ email, password }) => post('/login', { email, password }, true).then(() => session),
    logout: () => post('/logout', {}, true).then(() => session),
    register: ({ email, password, first_name, last_name }) => post('/register', { email, password, first_name, last_name }),
    verify: token => post('/verify', { token }),
    resendVerification: email => post('/resend-verification', { email }),
    forgot: email => post('/forgot', { email }),
    reset: ({ token, password }) => post('/reset', { token, password }, true),
    changePassword: ({ current_password, password }) => post('/change-password', { current_password, password }, true).then(() => session),
    getSessionSnapshot: () => session,
    subscribeSession(listener) { sessionListeners.add(listener); return () => sessionListeners.delete(listener); },
    read: (route,query={},options={}) => request(route,undefined,{...options,query}),
    write: (route,body,options={}) => post(route,body,false,options),
    notifications: {
      list: ({before_id=null,limit=20}={},options={}) => request('/notifications',undefined,{...options,query:{before_id,limit}}),
      read: (id,options={}) => post('/notifications/read',{id},false,options),
      readAll: (options={}) => post('/notifications/read-all',{},false,options),
    },
  };
}

// Read and remove mail credentials before any network call or UI rendering.
export function consumeMailAction({ href, replace }) {
  const url = new URL(href);
  if (!url.hash) return null;
  const params = new URLSearchParams(url.hash.slice(1));
  const action = params.get('action');
  const token = params.get('token');
  if (!params.has('token') && !params.has('action')) return null;
  url.hash = '';
  replace(url.pathname + url.search);
  if (!['verify', 'reset'].includes(action) || !token || token.length > 4096) return { action: 'invalid', token: null };
  return { action, token };
}
