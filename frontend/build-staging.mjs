import {readFile,writeFile,mkdir,readdir,copyFile,rm,access} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
const frontend=path.dirname(fileURLToPath(import.meta.url)),output=path.join(frontend,'build/staging'),assets=path.join(output,'assets');
const options=process.argv.slice(2);
if(options.some(option=>option!=='--public')||options.length>1)throw new Error('Supported build option: --public');
const publicBuild=options.includes('--public');
const sources=new Map();
async function collect(directory,prefix=''){for(const entry of await readdir(directory,{withFileTypes:true})){if(entry.isDirectory())await collect(path.join(directory,entry.name),prefix+entry.name+'/');else if(entry.name.endsWith('.js'))sources.set(prefix+entry.name,await readFile(path.join(directory,entry.name),'utf8'));}}
await collect(path.join(frontend,'src'));
const fonts=['iransans-regular.ttf','iransans-medium.ttf','iransans-bold.ttf'];
const fontRoot=path.join(frontend,'local-fonts');
const hasFonts=!publicBuild&&(await Promise.all(fonts.map(file=>access(path.join(fontRoot,file)).then(()=>true,()=>false)))).every(Boolean);
const publicFontRoot=path.join(frontend,'vendor-fonts/vazirmatn');
const publicFonts=[['Vazirmatn-Regular.woff2',400],['Vazirmatn-Medium.woff2',500],['Vazirmatn-Bold.woff2',700]];
const publicFontFiles=[...publicFonts.map(([file])=>file),'OFL.txt','AUTHORS.txt'];
const bundledFontContents=new Map();
if(!hasFonts){
  for(const file of publicFontFiles)bundledFontContents.set(file,await readFile(path.join(publicFontRoot,file)));
  for(const [file]of publicFonts)if(bundledFontContents.get(file).subarray(0,4).toString()!=='wOF2')throw new Error('A bundled WOFF2 font is invalid.');
  if(!bundledFontContents.get('OFL.txt').toString().includes('SIL OPEN FONT LICENSE Version 1.1'))throw new Error('The bundled font license is missing.');
}
let css=(await Promise.all(['styles/app.css','styles/footer.css','styles/operations/approved.css','styles/operations/integration.css','styles/palettes.css'].map(file=>readFile(path.join(frontend,file),'utf8')))).join('\n');
if(!hasFonts){
  css=css.replace(/^@font-face\{font-family:"ACC IRANSans";[^\n]+\}\r?\n/gm,'').replaceAll('"ACC IRANSans"','"ACC Vazirmatn"');
  css=publicFonts.map(([file,weight])=>`@font-face{font-family:"ACC Vazirmatn";src:url("./fonts/vazirmatn/${file}") format("woff2");font-weight:${weight};font-style:normal;font-display:swap}`).join('\n')+'\n'+css;
}
const icon=await readFile(path.join(frontend,'src/brand/icon.svg'));
const templates=new Map(await Promise.all(['index','install'].map(async name=>[name,await readFile(path.join(frontend,name+'.template.html'),'utf8')])));
const versionHash=createHash('sha256').update([...sources].sort().map(([,body])=>body).join('\n')+css+[...templates.values()].join('\n')).update(icon);
for(const contents of bundledFontContents.values())versionHash.update(contents);
const version=versionHash.digest('hex').slice(0,12);
await rm(output,{recursive:true,force:true});
await mkdir(path.join(assets,'brand'),{recursive:true});
for(const [file,source]of sources){const target=path.join(assets,file);await mkdir(path.dirname(target),{recursive:true});await writeFile(target,source.replace(/from (['"])(\.{1,2}\/[^'"]+\.js)\1/g,(_,q,url)=>`from ${q}${url}?v=${version}${q}`));}
await writeFile(path.join(assets,'app.css'),css);
await writeFile(path.join(assets,'brand/icon.svg'),icon);
async function copyFonts(target){
  if(hasFonts){await mkdir(path.join(target,'fonts'),{recursive:true});for(const font of fonts)await copyFile(path.join(fontRoot,font),path.join(target,'fonts',font));}
  else{const directory=path.join(target,'fonts/vazirmatn');await mkdir(directory,{recursive:true});for(const [file,contents]of bundledFontContents)await writeFile(path.join(directory,file),contents);}
}
await copyFonts(assets);
const previewAssets=path.join(frontend,'preview-assets');
await mkdir(previewAssets,{recursive:true});
await writeFile(path.join(previewAssets,'app.css'),css);
await rm(path.join(previewAssets,'fonts'),{recursive:true,force:true});
await copyFonts(previewAssets);
for(const [name,current] of templates){
  const entry=name==='index'?'operations/app.js':'install.js';
  const html=current.replace(/(href="\.\/assets\/app.css)(?:\?v=[^"]+)?"/,`$1?v=${version}"`).replace(/src="\.\/assets\/(?:operations\/app|app|install)\.js(?:\?v=[^"]+)?"/,`src="./assets/${entry}?v=${version}"`).replace('href="./assets/brand/icon.svg"',`href="./assets/brand/icon.svg?v=${version}"`);
  await writeFile(path.join(output,name+'.html'),html);
}
await writeFile(path.join(output,'build-info.json'),JSON.stringify({version,entry:'assets/operations/app.js',installer:'assets/install.js',contract:'docs/backend/API-OPERATIONS.md',publicPublished:false,font:{family:hasFonts?'ACC IRANSans':'ACC Vazirmatn',source:hasFonts?'licensed-local':'bundled-vazirmatn',weights:[400,500,700],bundled:true,publicMode:publicBuild}},null,2)+'\n');
console.log(`Staging ${version} ready; ${hasFonts?'licensed local fonts included':'bundled Vazirmatn fonts included'}; public_html was not written.`);
