import {readFile,writeFile,mkdir,readdir,copyFile,rm,access} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
const frontend=path.dirname(fileURLToPath(import.meta.url)),output=path.join(frontend,'build/staging'),assets=path.join(output,'assets');
const sources=new Map();
async function collect(directory,prefix=''){for(const entry of await readdir(directory,{withFileTypes:true})){if(entry.isDirectory())await collect(path.join(directory,entry.name),prefix+entry.name+'/');else if(entry.name.endsWith('.js'))sources.set(prefix+entry.name,await readFile(path.join(directory,entry.name),'utf8'));}}
await collect(path.join(frontend,'src'));
const fonts=['iransans-regular.ttf','iransans-medium.ttf','iransans-bold.ttf'];
const fontRoot=path.join(frontend,'local-fonts');
const hasFonts=(await Promise.all(fonts.map(file=>access(path.join(fontRoot,file)).then(()=>true,()=>false)))).every(Boolean);
let css=(await Promise.all(['styles/app.css','styles/operations/approved.css','styles/operations/integration.css'].map(file=>readFile(path.join(frontend,file),'utf8')))).join('\n');
if(!hasFonts)css=css.replace(/^@font-face\{[^\n]+\}\r?\n/gm,'');
const icon=await readFile(path.join(frontend,'src/brand/icon.svg'));
const version=createHash('sha256').update([...sources].sort().map(([,body])=>body).join('\n')+css).update(icon).digest('hex').slice(0,12);
await rm(output,{recursive:true,force:true});
await mkdir(path.join(assets,'brand'),{recursive:true});
for(const [file,source]of sources){const target=path.join(assets,file);await mkdir(path.dirname(target),{recursive:true});await writeFile(target,source.replace(/from (['"])(\.{1,2}\/[^'"]+\.js)\1/g,(_,q,url)=>`from ${q}${url}?v=${version}${q}`));}
await writeFile(path.join(assets,'app.css'),css);
await writeFile(path.join(assets,'brand/icon.svg'),icon);
if(hasFonts){await mkdir(path.join(assets,'fonts'),{recursive:true});for(const font of fonts)await copyFile(path.join(fontRoot,font),path.join(assets,'fonts',font));}
const previewAssets=path.join(frontend,'preview-assets');
await mkdir(previewAssets,{recursive:true});
await writeFile(path.join(previewAssets,'app.css'),css);
await rm(path.join(previewAssets,'fonts'),{recursive:true,force:true});
if(hasFonts){await mkdir(path.join(previewAssets,'fonts'),{recursive:true});for(const font of fonts)await copyFile(path.join(fontRoot,font),path.join(previewAssets,'fonts',font));}
const current=await readFile(path.join(frontend,'index.template.html'),'utf8');
const html=current.replace(/(href="\.\/assets\/app.css)(?:\?v=[^"]+)?"/,`$1?v=${version}"`).replace(/src="\.\/assets\/(?:operations\/)?app.js(?:\?v=[^"]+)?"/,`src="./assets/operations/app.js?v=${version}"`).replace('href="./assets/brand/icon.svg"',`href="./assets/brand/icon.svg?v=${version}"`);
await writeFile(path.join(output,'index.html'),html);
await writeFile(path.join(output,'build-info.json'),JSON.stringify({version,entry:'assets/operations/app.js',contract:'docs/backend/API-OPERATIONS.md',publicPublished:false},null,2)+'\n');
console.log(`Staging ${version} ready; ${hasFonts?'licensed local fonts included':'system-font fallback (no proprietary fonts)'}; public_html was not written.`);
