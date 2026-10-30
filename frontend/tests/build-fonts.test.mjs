import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,mkdir,cp,readFile,writeFile,readdir,rm} from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import {fileURLToPath} from 'node:url';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';

const run=promisify(execFile),source=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const publicFiles=['Vazirmatn-Regular.woff2','Vazirmatn-Medium.woff2','Vazirmatn-Bold.woff2','OFL.txt','AUTHORS.txt'];
const privateFiles=['iransans-regular.ttf','iransans-medium.ttf','iransans-bold.ttf'];
async function fixture(t,{privateFonts=false}={}){
  const temporary=await mkdtemp(path.join(os.tmpdir(),'seat-manager-font-build-'));
  t.after(()=>rm(temporary,{recursive:true,force:true}));
  const root=path.join(temporary,'project'),frontend=path.join(root,'frontend');
  await mkdir(frontend,{recursive:true});
  for(const entry of ['build-staging.mjs','build.mjs','index.template.html','install.template.html','package.json','styles','src','vendor-fonts'])await cp(path.join(source,entry),path.join(frontend,entry),{recursive:true});
  if(privateFonts){await mkdir(path.join(frontend,'local-fonts'));for(const file of privateFiles)await writeFile(path.join(frontend,'local-fonts',file),'fixture-only licensed-font stand-in');}
  await mkdir(path.join(root,'public_html'));await writeFile(path.join(root,'public_html','untouched.txt'),'existing document root');
  return {root,frontend};
}
async function assertPublicFonts(frontend,assets){
  const css=await readFile(path.join(assets,'app.css'),'utf8');
  assert(!css.includes('ACC IRANSans'));assert(!/local\s*\(/i.test(css));
  const faces=[...css.matchAll(/@font-face\{([^}]+)\}/g)].map(match=>match[1]);
  assert.equal(faces.length,3);
  for(const [index,weight]of [400,500,700].entries()){
    assert(faces[index].includes('font-family:"ACC Vazirmatn"'));
    assert(faces[index].includes(`font-weight:${weight};`));
    const urls=[...faces[index].matchAll(/url\("([^"]+)"\)/g)].map(match=>match[1]);
    assert.deepEqual(urls,[`./fonts/vazirmatn/${publicFiles[index]}`]);
    assert(faces[index].includes('format("woff2")'));
  }
  assert.deepEqual((await readdir(path.join(assets,'fonts'))).sort(),['vazirmatn']);
  assert.deepEqual((await readdir(path.join(assets,'fonts/vazirmatn'))).sort(),[...publicFiles].sort());
  for(const file of publicFiles)assert.deepEqual(await readFile(path.join(assets,'fonts/vazirmatn',file)),await readFile(path.join(frontend,'vendor-fonts/vazirmatn',file)));
  assert.match(css,/font-family:"ACC Vazirmatn",\s*Tahoma/);
  return css;
}

test('a clean source build bundles local Persian fonts and their notices for the app and preview',async t=>{
  const {root,frontend}=await fixture(t);
  await run(process.execPath,[path.join(frontend,'build-staging.mjs')]);
  const css=await assertPublicFonts(frontend,path.join(frontend,'build/staging/assets'));
  assert.equal(await assertPublicFonts(frontend,path.join(frontend,'preview-assets')),css);
  assert.equal(await readFile(path.join(root,'public_html/untouched.txt'),'utf8'),'existing document root');
  const info=JSON.parse(await readFile(path.join(frontend,'build/staging/build-info.json'),'utf8'));
  assert.deepEqual(info.font,{family:'ACC Vazirmatn',source:'bundled-vazirmatn',weights:[400,500,700],bundled:true,publicMode:false});
});

test('the hosting build forwards --public and excludes available owner-only fonts',async t=>{
  const {root,frontend}=await fixture(t,{privateFonts:true});
  await mkdir(path.join(frontend,'preview-assets/fonts'),{recursive:true});await writeFile(path.join(frontend,'preview-assets/fonts','stale-private.ttf'),'stale');
  await run(process.execPath,[path.join(frontend,'build.mjs'),'--public']);
  const css=await assertPublicFonts(frontend,path.join(root,'public_html/assets'));
  assert.equal(await assertPublicFonts(frontend,path.join(frontend,'preview-assets')),css);
  assert.equal(await readFile(path.join(root,'public_html/untouched.txt'),'utf8'),'existing document root');
  assert.equal(JSON.parse(await readFile(path.join(frontend,'build/staging/build-info.json'),'utf8')).font.publicMode,true);
});

test('a normal build uses bundled Vazirmatn even when old private fonts exist',async t=>{
  const {frontend}=await fixture(t,{privateFonts:true});
  await run(process.execPath,[path.join(frontend,'build-staging.mjs')]);
  const css=await assertPublicFonts(frontend,path.join(frontend,'build/staging/assets'));
  assert.equal(await assertPublicFonts(frontend,path.join(frontend,'preview-assets')),css);
  const info=JSON.parse(await readFile(path.join(frontend,'build/staging/build-info.json'),'utf8'));
  assert.deepEqual(info.font,{family:'ACC Vazirmatn',source:'bundled-vazirmatn',weights:[400,500,700],bundled:true,publicMode:false});
});

test('a missing public font license stops before replacing existing staging or preview files',async t=>{
  const {frontend}=await fixture(t);
  await rm(path.join(frontend,'vendor-fonts/vazirmatn/OFL.txt'));
  await mkdir(path.join(frontend,'build/staging'),{recursive:true});await writeFile(path.join(frontend,'build/staging/existing.txt'),'preserve staging');
  await mkdir(path.join(frontend,'preview-assets'));await writeFile(path.join(frontend,'preview-assets/app.css'),'preserve preview');
  await assert.rejects(run(process.execPath,[path.join(frontend,'build-staging.mjs'),'--public']));
  assert.equal(await readFile(path.join(frontend,'build/staging/existing.txt'),'utf8'),'preserve staging');
  assert.equal(await readFile(path.join(frontend,'preview-assets/app.css'),'utf8'),'preserve preview');
});
