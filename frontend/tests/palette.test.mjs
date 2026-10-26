import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {PALETTES,DEFAULT_PALETTE,createPalettePreference,paletteStorageKey,paletteMarkup} from '../src/palette.js';
const storage=()=>{const values=new Map([['acc-appearance','dark']]);return {values,getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value)};};

test('guest and every positive numeric user ID have independent restored preferences',()=>{
  const saved=storage(),p=createPalettePreference({storage:saved});
  assert.equal(p.snapshot().choice,DEFAULT_PALETTE);p.choose('peach-violet');
  assert.equal(p.setUser(1),DEFAULT_PALETTE);p.choose('violet-teal');
  assert.equal(p.setUser(2),DEFAULT_PALETTE);p.choose('indigo-amber');
  assert.equal(p.setUser(1),'violet-teal');assert.equal(p.setUser(null),'peach-violet');
  assert.equal(p.setUser(2),'indigo-amber');assert.equal(p.setUser(3),DEFAULT_PALETTE);
  assert.equal(saved.values.get('acc-appearance'),'dark');
  const reloaded=createPalettePreference({storage:saved});assert.equal(reloaded.snapshot().choice,'peach-violet');assert.equal(reloaded.setUser(1),'violet-teal');
  for(const id of ['1',-1,0,NaN,Infinity,1.5,{},'email@example.test'])assert.equal(paletteStorageKey(id),'seat-manager:palette:guest');
  assert.equal(paletteStorageKey(123),'seat-manager:palette:user:123');
});
test('same-user refresh and navigation never reset an in-memory preference',()=>{
  const saved=storage(),p=createPalettePreference({storage:saved});p.setUser(7);p.choose('indigo-amber');saved.values.set(paletteStorageKey(7),'blue-coral');
  assert.equal(p.setUser(7),'indigo-amber');assert.equal(p.snapshot().choice,'indigo-amber');
});
test('invalid or corrupt stored colors cannot alter attributes or create arbitrary saved values',()=>{
  for(const bad of ['__proto__','jade','{"id":"violet-teal"}','blue-coral\" onclick=bad()',null]){
    const saved=storage();saved.values.set(paletteStorageKey(null),bad);const p=createPalettePreference({storage:saved});assert.equal(p.snapshot().choice,DEFAULT_PALETTE);
    assert.equal(p.choose(bad),false);assert.equal(p.snapshot().choice,DEFAULT_PALETTE);assert.equal(saved.values.get(paletteStorageKey(null)),bad);
  }
});
test('closed localStorage getters, reads and writes degrade to isolated memory without throwing',()=>{
  const cases=[null,{get getItem(){throw Error('closed');},get setItem(){throw Error('closed');}},{getItem(){throw Error('closed');},setItem(){throw Error('quota');}}];
  for(const saved of cases){const p=createPalettePreference({storage:saved});p.choose('peach-violet');p.setUser(1);p.choose('violet-teal');assert.equal(p.setUser(2),DEFAULT_PALETTE);assert.equal(p.setUser(1),'violet-teal');assert.equal(p.setUser(null),'peach-violet');}
});
test('palette control offers exactly four native keyboard radio options and named two-color samples',()=>{
  const html=paletteMarkup();assert.equal((html.match(/name="palette"/g)||[]).length,4);assert(html.includes('aria-expanded="false"'));assert(html.includes('aria-controls="palette-panel"'));assert(html.includes('<legend>رنگ‌بندی برنامه</legend>'));assert(!html.includes('type="color"'));
  for(const p of PALETTES){assert(html.includes('value="'+p.id+'"'));assert(html.includes(p.label));}assert(html.includes('palette-pair" aria-hidden="true"'));
});

const pairs=text=>Object.fromEntries([...text.matchAll(/--([a-z-]+):light-dark\((#[0-9a-f]{6}),(#[0-9a-f]{6})\)/g)].map(m=>[m[1],[m[2],m[3]]]));
const luminance=hex=>{const rgb=hex.slice(1).match(/../g).map(v=>parseInt(v,16)/255).map(c=>c<=.04045?c/12.92:((c+.055)/1.055)**2.4);return rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;};
const ratio=(a,b)=>{const x=luminance(a),y=luminance(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);};
test('both themes meet normal-text contrast on neutral, primary, companion and semantic surfaces',async()=>{
  const css=await readFile(new URL('../styles/palettes.css',import.meta.url),'utf8'),base=pairs(css.slice(css.indexOf(':root{'),css.indexOf('}',css.indexOf(':root{'))));
  const check=(name,a,b,min=4.5)=>{for(let mode=0;mode<2;mode++)assert(ratio(a[mode],b[mode])>=min,name+' '+['light','dark'][mode]+' '+ratio(a[mode],b[mode]).toFixed(2));};
  for(const fg of ['text','muted'])for(const bg of ['canvas','surface','raised','field'])check(fg+'/'+bg,base[fg],base[bg]);
  for(const type of ['standard','premium','none']){check(type,base['seat-'+type+'-fg'],base['seat-'+type+'-bg']);check(type+'/surface',base['seat-'+type+'-fg'],base.surface);}
  for(const status of ['positive','warning','error'])check(status,base[status],base[status+'-bg']);
  for(const preset of PALETTES){const at=css.indexOf('[data-palette-option="'+preset.id+'"]'),block=css.slice(css.indexOf('{',at)+1,css.indexOf('}',at)),v=pairs(block);check(preset.id+'/primary',v['on-accent'],v.accent);check(preset.id+'/soft',v['accent-text'],v['accent-soft']);check(preset.id+'/companion',v['companion-text'],v['companion-soft']);check(preset.id+'/focus',v.accent,base.surface,3);assert(!/--(?:seat-|positive|warning|error)/.test(block));}
});
