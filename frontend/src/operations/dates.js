import {latin} from './ui.js';
  const persianDayFormatter=new Intl.DateTimeFormat('en-US-u-ca-persian-nu-latn',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit'});
export function iranDate(value=new Date()) {
    const parts=Object.fromEntries(persianDayFormatter.formatToParts(value).map(p=>[p.type,p.value]));
    return `${parts.year}/${parts.month}/${parts.day}`;
  }
export function iranDays() {
    const parts=Object.fromEntries(new Intl.DateTimeFormat('en-US',{timeZone:'Asia/Tehran',year:'numeric',month:'numeric',day:'numeric'}).formatToParts(new Date()).map(p=>[p.type,p.value]));
    const noon=Date.UTC(Number(parts.year),Number(parts.month)-1,Number(parts.day),12);
    return {today:iranDate(new Date(noon)),tomorrow:iranDate(new Date(noon+86400000)),nextWeek:iranDate(new Date(noon+7*86400000))};
  }
export function parseJalali(raw) {
    const m=latin(raw||'').trim().match(/^(1[34]\d{2})\/(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])$/);if(!m)return null;
    const normalized=`${m[1]}/${m[2].padStart(2,'0')}/${m[3].padStart(2,'0')}`;
    let lo=Math.floor(Date.UTC(Number(m[1])+620,0,1,12)/86400000),hi=Math.floor(Date.UTC(Number(m[1])+622,11,31,12)/86400000);
    while(lo<=hi){const mid=Math.floor((lo+hi)/2),date=iranDate(new Date(mid*86400000+12*3600000));if(date===normalized)return normalized;if(date<normalized)lo=mid+1;else hi=mid-1;}
    return null;
  }
  const datePickerEpochCache=new Map();
export function jalaliEpochDay(raw){
    const text=latin(raw||'').trim(),match=text.match(/^(\d{4})\/(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])$/);if(!match)return null;
    const canonical=`${match[1]}/${match[2].padStart(2,'0')}/${match[3].padStart(2,'0')}`;
    if(datePickerEpochCache.has(canonical))return datePickerEpochCache.get(canonical);
    const year=Number(match[1]);if(year<1200||year>1700)return null;
    let low=Math.floor(Date.UTC(year+620,0,1,12)/86400000),high=Math.floor(Date.UTC(year+622,11,31,12)/86400000),result=null;
    while(low<=high){const day=Math.floor((low+high)/2),label=iranDate(new Date(day*86400000+43200000));if(label===canonical){result=day;break;}if(label<canonical)low=day+1;else high=day-1;}
    datePickerEpochCache.set(canonical,result);return result;
  }
export function shiftJalaliDays(raw,offset){const day=jalaliEpochDay(raw);return day===null?null:iranDate(new Date((day+offset)*86400000+43200000));}
export function jalaliMonthInfo(year,month){
    if(!Number.isInteger(year)||!Number.isInteger(month)||year<1300||year>1499||month<1||month>12)return null;
    const first=`${year}/${String(month).padStart(2,'0')}/01`,firstDay=jalaliEpochDay(first),next=month===12?`${year+1}/01/01`:`${year}/${String(month+1).padStart(2,'0')}/01`,nextDay=jalaliEpochDay(next);
    if(firstDay===null||nextDay===null)return null;
    return {year,month,first,firstDay,days:nextDay-firstDay,weekday:(new Date(firstDay*86400000+43200000).getUTCDay()+1)%7};
  }
export function shiftJalaliMonths(raw,delta){
    const valid=parseJalali(raw);if(!valid)return null;const [y,m,d]=valid.split('/').map(Number),index=y*12+m-1+delta,year=Math.floor(index/12),month=((index%12)+12)%12+1,info=jalaliMonthInfo(year,month);if(!info)return null;
    return `${year}/${String(month).padStart(2,'0')}/${String(Math.min(d,info.days)).padStart(2,'0')}`;
  }
export function datePickerCheck(raw,bounds={}){
    const date=parseJalali(raw);if(!date)return {valid:false,reason:'تاریخ شمسی معتبر انتخاب کنید.'};
    if(bounds.blockedReason)return {valid:false,reason:bounds.blockedReason};
    if(bounds.minInclusive&&date<bounds.minInclusive)return {valid:false,reason:'این تاریخ پیش از حد مجاز فیلد است.'};
    if(bounds.minExclusive&&date<=bounds.minExclusive)return {valid:false,reason:bounds.reason||'تاریخ باید بعد از تاریخ مبنا باشد.'};
    if(bounds.maxInclusive&&date>bounds.maxInclusive)return {valid:false,reason:bounds.maxReason||'تاریخ نباید پس از حد مجاز باشد.'};
    if(bounds.maxExclusive&&date>=bounds.maxExclusive)return {valid:false,reason:bounds.maxReason||'تاریخ باید پیش از حد مجاز باشد.'};
    return {valid:true,date,reason:''};
  }
export function createDateSelectionSession({value='',today=iranDays().today,bounds={},onCommit=()=>{}}){
    const selected=parseJalali(value);let focus=selected&&datePickerCheck(selected,bounds).valid?selected:datePickerCheck(today,bounds).valid?today:bounds.minExclusive?shiftJalaliDays(bounds.minExclusive,1):bounds.minInclusive||selected||today;
    if(!parseJalali(focus))focus=today;
    let view=focus.slice(0,7),closed=false;
    return {original:value,selected,get focus(){return focus;},get view(){return view;},get closed(){return closed;},bounds,
      focusDate(raw){const valid=parseJalali(raw);if(!valid)return false;focus=valid;view=valid.slice(0,7);return true;},
      month(delta){const next=shiftJalaliMonths(focus,delta);if(!next)return false;focus=next;view=next.slice(0,7);return true;},
      choose(raw){if(closed)return {valid:false,reason:'تقویم بسته است.'};const check=datePickerCheck(raw,bounds);if(!check.valid)return check;closed=true;onCommit(check.date);return check;},
      cancel(){closed=true;return value;}
    };
  }
