export const PALETTES=Object.freeze([
  {id:'blue-coral',label:'آبی و مرجانی'},
  {id:'violet-teal',label:'بنفش و فیروزه‌ای'},
  {id:'indigo-amber',label:'نیلی و کهربایی'},
  {id:'peach-violet',label:'گلبهی و بنفش'},
]);
export const DEFAULT_PALETTE='blue-coral';
export const validPalette=value=>PALETTES.some(p=>p.id===value);
export const paletteStorageKey=userId=>'seat-manager:palette:'+(Number.isSafeInteger(userId)&&userId>0?'user:'+userId:'guest');

// Only a whitelist ID is stored. A blocked storage getter/read/write stays in memory.
export function createPalettePreference({storage}={}){
  const memory=new Map();let key=paletteStorageKey(null),choice;
  const storageArea=()=>storage===undefined?globalThis.localStorage:storage;
  function read(){
    try{const saved=storageArea()?.getItem(key);if(validPalette(saved)){memory.set(key,saved);return saved;}}catch{}
    return memory.get(key)||DEFAULT_PALETTE;
  }
  choice=read();
  return {
    snapshot:()=>({key,choice}),
    setUser(userId){const next=paletteStorageKey(userId);if(next!==key){key=next;choice=read();}return choice;},
    choose(value){if(!validPalette(value))return false;choice=value;memory.set(key,choice);try{storageArea()?.setItem(key,choice);}catch{}return true;},
    refresh(){choice=read();return choice;},
  };
}

const pair='<span class="palette-pair" aria-hidden="true"><i></i><i></i></span>';
export function paletteMarkup(){
  return `<div class="palette-control" data-palette-control><button type="button" class="palette-trigger" data-palette-toggle aria-label="رنگ‌بندی: آبی و مرجانی" aria-expanded="false" aria-controls="palette-panel" data-palette-option="${DEFAULT_PALETTE}">${pair}<span data-palette-label hidden>${PALETTES[0].label}</span><span aria-hidden="true" class="palette-chevron">⌄</span></button><div class="palette-panel" id="palette-panel" hidden><fieldset><legend>رنگ‌بندی برنامه</legend>${PALETTES.map(p=>`<label data-palette-option="${p.id}"><input type="radio" name="palette" value="${p.id}" ${p.id===DEFAULT_PALETTE?'checked':''}>${pair}<span>${p.label}</span></label>`).join('')}</fieldset></div></div>`;
}

export function mountPalette(root=document.documentElement,{documentRoot=document,storage,windowRoot=window}={}){
  const preference=createPalettePreference({storage}),lifecycle=new AbortController();
  const controls=()=>Array.from(documentRoot.querySelectorAll('[data-palette-control]'));
  function close({restore=false}={}){
    for(const control of controls()){
      const button=control.querySelector('[data-palette-toggle]'),panel=control.querySelector('.palette-panel');
      const wasOpen=!panel.hidden;panel.hidden=true;button.setAttribute('aria-expanded','false');if(restore&&wasOpen)button.focus({preventScroll:true});
    }
  }
  function sync(){
    const {choice}=preference.snapshot();root.dataset.palette=choice;
    for(const control of controls()){
      const button=control.querySelector('[data-palette-toggle]');button.dataset.paletteOption=choice;
      const label=PALETTES.find(p=>p.id===choice).label;control.querySelector('[data-palette-label]').textContent=label;button.setAttribute('aria-label','رنگ‌بندی: '+label);
      for(const radio of control.querySelectorAll('[name="palette"]'))radio.checked=radio.value===choice;
    }
  }
  function position(control){
    const panel=control.querySelector('.palette-panel'),anchor=control.getBoundingClientRect(),width=Math.min(280,documentRoot.documentElement.clientWidth-24);
    panel.style.width=width+'px';panel.style.setProperty('--palette-left',Math.max(12,Math.min(anchor.left,documentRoot.documentElement.clientWidth-width-12))-anchor.left+'px');
  }
  documentRoot.addEventListener('click',event=>{
    const button=event.target.closest('[data-palette-toggle]');if(!button)return;
    const control=button.closest('[data-palette-control]'),panel=control.querySelector('.palette-panel'),opening=panel.hidden;
    close();if(opening){panel.hidden=false;button.setAttribute('aria-expanded','true');position(control);panel.querySelector('input:checked')?.focus({preventScroll:true});}
  },{signal:lifecycle.signal});
  documentRoot.addEventListener('change',event=>{
    if(!event.target.matches('[name="palette"]')||!event.target.checked)return;
    preference.choose(event.target.value);sync();
  },{signal:lifecycle.signal});
  documentRoot.addEventListener('pointerdown',event=>{if(!event.target.closest('[data-palette-control]'))close();},{signal:lifecycle.signal});
  documentRoot.addEventListener('focusin',event=>{if(!event.target.closest('[data-palette-control]'))close();},{signal:lifecycle.signal});
  documentRoot.addEventListener('keydown',event=>{if(event.key==='Escape'&&controls().some(c=>!c.querySelector('.palette-panel').hidden)){event.preventDefault();close({restore:true});}},{signal:lifecycle.signal});
  windowRoot.addEventListener('resize',()=>{for(const c of controls())if(!c.querySelector('.palette-panel').hidden)position(c);},{signal:lifecycle.signal});
  windowRoot.addEventListener('storage',event=>{if(event.key===preference.snapshot().key){preference.refresh();sync();}},{signal:lifecycle.signal});
  sync();
  return {sync,setUser(userId){const before=preference.snapshot().key;preference.setUser(userId);if(before!==preference.snapshot().key)close();sync();},destroy(){close();lifecycle.abort();}};
}
