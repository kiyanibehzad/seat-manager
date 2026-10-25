import {esc,fa} from './ui.js';
import {iranDays,parseJalali,jalaliEpochDay,shiftJalaliDays,jalaliMonthInfo,shiftJalaliMonths,datePickerCheck,createDateSelectionSession} from './dates.js';
export function mountJalaliPicker({root,getBounds=()=>({})}){
    const lifecycle=new AbortController();
    const listen=(target,type,handler,capture=false)=>target.addEventListener(type,handler,{capture,signal:lifecycle.signal});
    const selector='input[data-jalali]';
    const enhanced=new WeakSet(),months=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'],weekdays=['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'];
    let current=null,sequence=0;
    const isField=field=>field?.matches?.(selector)&&root.contains(field);
    function fieldName(field){
      const label=field.labels?.[0],direct=label?(label.querySelector('span')?.textContent||label.textContent||'').trim():'';
      return direct||(field.hasAttribute('data-mg-approved-end')||field.name==='approvedEnd'?'پایان مورد تأیید':field.name==='start'?'تاریخ شروع':'تاریخ پایان');
    }
    function validate(scope=root,{showMissing=false}={}){
      let valid=true;
      for(const field of scope.querySelectorAll(selector)){
        const active=!field.disabled&&!field.closest('[hidden]'),hasValue=!!field.value.trim(),check=!active?{valid:true}:hasValue?datePickerCheck(field.value,getBounds(field)):field.required?{valid:false,reason:'تاریخ را انتخاب کنید.'}:{valid:true};
        valid=valid&&check.valid;
        const visibleError=!check.valid&&(hasValue||showMissing);
        field.setAttribute('aria-invalid',String(visibleError));field.setCustomValidity(check.valid?'':check.reason);
        const error=field.nextElementSibling;if(error?.matches('[data-date-error]')){error.textContent=visibleError?check.reason:'';error.hidden=!visibleError;}
      }
      return valid;
    }
    function enhance(){
      for(const field of root.querySelectorAll(selector)){
        if(enhanced.has(field))continue;enhanced.add(field);
        field.readOnly=true;field.inputMode='none';field.classList.add('sm-date-input');field.classList.add('cursor-interaction');field.placeholder='انتخاب تاریخ';
        field.setAttribute('role','combobox');field.setAttribute('aria-haspopup','dialog');field.setAttribute('aria-expanded','false');field.setAttribute('aria-autocomplete','none');
        field.setAttribute('aria-description','برای انتخاب تاریخ، Enter یا فاصله را بزنید.');
        const error=document.createElement('span');error.className='sm-date-error sm-error';error.dataset.dateError='';error.hidden=true;error.setAttribute('role','alert');field.insertAdjacentElement('afterend',error);
      }
      validate();
    }
    function close(focus=false){
      if(!current)return;const previous=current;current=null;previous.session.cancel();previous.field.setAttribute('aria-expanded','false');previous.field.removeAttribute('aria-controls');
      if(previous.native&&previous.box.matches(':popover-open'))previous.box.hidePopover();previous.box.remove();
      if(focus&&previous.field.isConnected&&!previous.field.disabled)previous.field.focus({preventScroll:true});
    }
    function place(){
      if(!current)return;const {box,field,native}=current,rect=field.getBoundingClientRect(),size=box.getBoundingClientRect(),width=size.width,height=size.height;
      const left=Math.max(12,Math.min(rect.right-width,window.innerWidth-width-12));let top=rect.bottom+6;
      if(top+height>window.innerHeight-12)top=Math.max(12,rect.top-height-6);
      if(native){box.style.left=left+'px';box.style.top=top+'px';}
      else{const parent=box.offsetParent||root.querySelector('.sm-product'),parentRect=parent.getBoundingClientRect();box.style.left=(left-parentRect.left-parent.clientLeft+parent.scrollLeft)+'px';box.style.top=(top-parentRect.top-parent.clientTop+parent.scrollTop)+'px';}
    }
    function render(focusGrid=true){
      if(!current)return;const {session,box,field}=current,[year,month]=session.view.split('/').map(Number),info=jalaliMonthInfo(year,month),today=iranDays().today;
      if(!info)return;const bounds=session.bounds,selected=session.selected,headingId=box.id+'-heading',hintId=box.id+'-hint',fieldId=box.id+'-field';
      if(!datePickerCheck(session.focus,bounds).valid){for(let day=1;day<=info.days;day++){const candidate=`${year}/${String(month).padStart(2,'0')}/${String(day).padStart(2,'0')}`;if(datePickerCheck(candidate,bounds).valid){session.focusDate(candidate);break;}}}
      const cells=Array.from({length:42},(_,index)=>{const day=index-info.weekday+1;if(day<1||day>info.days)return '<td class="sm-calendar-empty" aria-hidden="true"></td>';
        const date=`${year}/${String(month).padStart(2,'0')}/${String(day).padStart(2,'0')}`,check=datePickerCheck(date,bounds),full=new Intl.DateTimeFormat('fa-IR',{timeZone:'Asia/Tehran',dateStyle:'full'}).format(new Date((info.firstDay+day-1)*86400000+43200000));
        return `<td role="gridcell" aria-selected="${date===selected}"><button type="button" data-calendar-day="${date}" tabindex="${date===session.focus&&check.valid?'0':'-1'}" ${!check.valid?'disabled':''} ${date===today?'aria-current="date"':''} class="${date===selected?'is-selected':''} ${date===today?'is-today':''}" aria-label="${esc(full)}${!check.valid?' — '+esc(check.reason):''}">${fa(day)}</button></td>`;});
      box.innerHTML=`<div class="sm-calendar-heading"><button type="button" data-calendar-month="-1" aria-label="ماه قبل" ${!shiftJalaliMonths(session.focus,-1)?'disabled':''}>→</button><h2 id="${headingId}" aria-live="polite">${months[month-1]} ${fa(year)}</h2><button type="button" data-calendar-month="1" aria-label="ماه بعد" ${!shiftJalaliMonths(session.focus,1)?'disabled':''}>←</button></div><p class="sm-calendar-title" id="${fieldId}">انتخاب ${esc(fieldName(field))}</p><div class="sm-calendar-shortcuts">${[['امروز',0],['فردا',1],['پس‌فردا',2],['یک هفتهٔ دیگر',7]].map(([label,delta])=>{const date=shiftJalaliDays(today,delta),check=datePickerCheck(date,bounds);return `<button type="button" data-calendar-day="${date}" ${!check.valid?'disabled':''} aria-label="${label}، ${fa(date)}${!check.valid?' — '+esc(check.reason):''}">${label}</button>`;}).join('')}</div><table role="grid" aria-labelledby="${headingId}" aria-describedby="${hintId}" class="sm-calendar-grid"><thead><tr>${weekdays.map(day=>`<th scope="col" aria-label="${day}">${day==='پنجشنبه'?'پ':day==='جمعه'?'ج':day[0]}</th>`).join('')}</tr></thead><tbody>${Array.from({length:6},(_,i)=>`<tr>${cells.slice(i*7,i*7+7).join('')}</tr>`).join('')}</tbody></table><p class="sm-calendar-hint" id="${hintId}">${esc(bounds.blockedReason||bounds.hint||(bounds.minExclusive?`انتخاب باید پس از ${fa(bounds.minExclusive)} باشد.`:bounds.minInclusive?`انتخاب از ${fa(bounds.minInclusive)} به بعد مجاز است.`:'با کلیدهای جهت حرکت کنید؛ Enter تاریخ را انتخاب می‌کند.'))}</p><div class="sm-calendar-footer">${!field.required?`<button type="button" data-calendar-clear aria-label="پاک‌کردن ${esc(fieldName(field))}">پاک‌کردن تاریخ</button>`:''}<button type="button" data-calendar-close>بستن بدون انتخاب</button></div>`;
      box.setAttribute('aria-labelledby',fieldId+' '+headingId);box.setAttribute('aria-describedby',hintId);place();
      if(focusGrid){const day=Array.from(box.querySelectorAll('[data-calendar-day]:not(:disabled)')).find(el=>el.dataset.calendarDay===session.focus&&el.closest('table'))||box.querySelector('table [data-calendar-day]:not(:disabled)');if(day)day.focus({preventScroll:true});else box.querySelector('[data-calendar-close]').focus({preventScroll:true});}
    }
    function open(field){
      if(!isField(field)||field.disabled||field.closest('[hidden]'))return;close(false);
      const box=document.createElement('div');box.id=root.id+'-calendar-'+(++sequence);box.className='sm-calendar-popover';box.setAttribute('role','dialog');box.setAttribute('aria-label','انتخاب '+fieldName(field));
      const native=typeof box.showPopover==='function';if(native){box.setAttribute('popover','manual');box.style.position='fixed';}else box.style.position='absolute';
      const session=createDateSelectionSession({value:field.value,bounds:getBounds(field),onCommit:date=>{
        field.value=fa(date);close(false);field.focus({preventScroll:true});
        field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}));validate();
      }});
      current={field,box,session,native};field.setAttribute('aria-expanded','true');field.setAttribute('aria-controls',box.id);root.querySelector('.sm-product').appendChild(box);if(native)box.showPopover();render(true);
      requestAnimationFrame(()=>{if(current?.box===box)box.scrollIntoView({block:'nearest',inline:'nearest',behavior:'instant'});});
    }
    function moveFocus(offset){
      if(!current)return;const {session}=current;let date=session.focus;
      for(let count=0;count<740;count++){date=shiftJalaliDays(date,offset);if(!date||!parseJalali(date))return;if(datePickerCheck(date,session.bounds).valid){session.focusDate(date);render(true);return;}}
    }
    listen(document,'click',event=>{
      if(isField(event.target)){event.preventDefault();event.stopImmediatePropagation();open(event.target);return;}
      if(!current)return;const b=event.target.closest('button');if(!b||!current.box.contains(b))return;
      event.preventDefault();event.stopImmediatePropagation();if(b.disabled)return;
      if(b.hasAttribute('data-calendar-clear')&&!current.field.required){const field=current.field;field.value='';close(false);field.focus({preventScroll:true});field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}));validate();return;}
      if(b.hasAttribute('data-calendar-close')){close(true);return;}
      if(b.dataset.calendarMonth){current.session.month(Number(b.dataset.calendarMonth));render(false);const nav=current?.box.querySelector(`[data-calendar-month="${b.dataset.calendarMonth}"]`);nav?.focus({preventScroll:true});return;}
      if(b.dataset.calendarDay){const result=current.session.choose(b.dataset.calendarDay);if(!result.valid){const hint=current.box.querySelector('.sm-calendar-hint');hint.textContent=result.reason;}}
    },true);
    listen(document,'keydown',event=>{
      if(isField(event.target)&&['Enter',' '].includes(event.key)){event.preventDefault();event.stopImmediatePropagation();open(event.target);return;}
      if(current&&event.key==='Escape'&&(event.target===current.field||current.box.contains(event.target))){event.preventDefault();event.stopImmediatePropagation();close(true);return;}
      if(!current||!current.box.contains(event.target))return;
      if(event.key==='Escape'){event.preventDefault();event.stopImmediatePropagation();close(true);return;}
      if(['PageUp','PageDown'].includes(event.key)){event.preventDefault();event.stopImmediatePropagation();current.session.month((event.key==='PageUp'?-1:1)*(event.shiftKey?12:1));render(true);return;}
      if(!event.target.matches('[data-calendar-day]')||!event.target.closest('table'))return;
      const moves={ArrowLeft:1,ArrowRight:-1,ArrowUp:-7,ArrowDown:7};
      if(Object.hasOwn(moves,event.key)){event.preventDefault();event.stopImmediatePropagation();moveFocus(moves[event.key]);return;}
      if(['Home','End'].includes(event.key)){event.preventDefault();event.stopImmediatePropagation();const day=jalaliEpochDay(current.session.focus),weekday=(new Date(day*86400000+43200000).getUTCDay()+1)%7,target=shiftJalaliDays(current.session.focus,event.key==='Home'?-weekday:6-weekday);if(datePickerCheck(target,current.session.bounds).valid){current.session.focusDate(target);render(true);}return;}
    },true);
    listen(document,'pointerdown',event=>{if(current&&!current.box.contains(event.target)&&event.target!==current.field)close(false);},true);
    listen(document,'focusin',event=>{if(current&&!current.box.contains(event.target)&&event.target!==current.field)close(false);},true);
    listen(document,'change',event=>{if(root.contains(event.target)&&(isField(event.target)||event.target.closest('[data-personal-form]')))Promise.resolve().then(validate);},true);
    listen(window,'resize',place);listen(window,'scroll',place,true);listen(window,'blur',()=>close(false));
    return {enhance,close,validate,open,destroy(){close(false);lifecycle.abort();},get isOpen(){return !!current;}};
  }
