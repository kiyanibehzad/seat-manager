import {esc,field,button,table,heading,fa,stamp} from './ui.js';
export const bulkFields=['first_name','last_name','email','department_id','organizational_position','employee_number','initial_openai_email'];
export function parsePastedUsers(text,departments){
  return text.trim().split(/\r?\n/).filter(line=>line.trim()).map(line=>{
    const values=line.split('\t'),row=Object.fromEntries(bulkFields.map((field,i)=>[field,(values[i]||'').trim()]));
    const department=departments.find(d=>String(d.id)===row.department_id||d.name===row.department_id);row.department_id=department?.id??row.department_id;
    if(row.organizational_position==='کارمند')row.organizational_position='employee';if(row.organizational_position==='سرپرست بخش')row.organizational_position='supervisor';return row;
  });
}
export function bulkDraftRows(form){return Array.from(form.querySelectorAll('[data-bulk-row]')).map(tr=>{const row={};for(const field of bulkFields){const value=tr.querySelector(`[data-bulk-field="${field}"]`)?.value?.trim()||'';row[field]=field==='department_id'&&/^\d+$/.test(value)?Number(value):value;}return row;});}
export function bulkMarkup({rows,departments,preview=null}){
  const labels=['نام','نام خانوادگی','ایمیل سازمانی','بخش','سمت سازمانی','شمارهٔ پرسنلی','ایمیل OpenAI اولیه'];
  const grid=table({label:'کاربرگ ورود لیستی',classes:'op-bulk-table',columns:[...labels.map(label=>({label,align:'right'})),{label:'حذف'}],rows:rows.map((row,index)=>({id:index,cells:[...bulkFields.map((key,i)=>{
    const options=key==='department_id'?departments.map(d=>({value:d.id,label:d.name})):key==='organizational_position'?[{value:'',label:'انتخاب سمت'},{value:'employee',label:'کارمند'},{value:'supervisor',label:'سرپرست بخش'}]:null;
    const control=options?`<select data-bulk-field="${key}" aria-label="${labels[i]} ردیف ${fa(index+1)}">${options.map(o=>`<option value="${esc(o.value)}" ${String(o.value)===String(row[key])?'selected':''}>${esc(o.label)}</option>`).join('')}</select>`:`<input data-bulk-field="${key}" value="${esc(row[key]||'')}" aria-label="${labels[i]} ردیف ${fa(index+1)}" ${key.includes('email')?'type="email" dir="ltr"':'dir="auto"'}>`;
    const errors=preview?.rows.find(item=>item.row===index+1)?.errors||{};
    return `<div class="op-bulk-cell">${control}${i===0&&errors.row?`<span class="op-bulk-row-error" data-bulk-row-error role="alert">${esc(errors.row)}</span>`:''}${errors[key]?`<span class="sm-error">${esc(errors[key])}</span>`:''}</div>`;
  }),button('حذف ردیف','bulk-remove',index)]}))});
  return heading('ورود لیستی کارکنان','ردیف‌ها را پیش از ثبت بررسی و اصلاح کنید.',button('بازگشت به کاربران','back'))+`<label class="sm-field">جای‌گذاری فهرست<textarea data-bulk-paste rows="3" placeholder="نام، نام خانوادگی، ایمیل، بخش، سمت، شمارهٔ پرسنلی، ایمیل OpenAI — ستون‌ها با Tab"></textarea></label><p class="sm-small">پیش‌نمایش کاربر یا رمز ایجاد نمی‌کند. ثبت نهایی فقط پس از بررسی همهٔ ردیف‌ها انجام می‌شود.</p><form data-op-form="bulk-preview">${grid.replace(/<tr data-record-id="(\d+)"/g,'<tr data-bulk-row="$1"')}${button('＋ ردیف خالی','bulk-add')}<p class="sm-error" role="alert" data-op-form-error hidden></p><p class="sm-small" role="status" data-op-form-status></p><div class="sm-form-footer"><button type="submit" class="sm-button sm-primary">بررسی ردیف‌ها</button>${preview?.valid?button('تأیید و ایجاد کاربران','bulk-commit',preview.batch_id,{primary:true}):''}</div>${preview?`<p class="sm-small" data-bulk-preview-status>${preview.valid?'تمام ردیف‌ها بدون خطا بررسی شدند.':'ردیف‌های دارای خطا را اصلاح و دوباره بررسی کنید.'} · اعتبار پیش‌نمایش تا ${esc(stamp(preview.expires_at))}</p>`:''}</form>`;
}
