// Explicitly authorized LOCAL integration. No API/config/DB/SMTP or hosting changes.
import {mkdir,cp,copyFile,rename,rm,readFile,writeFile,chmod,access} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {randomUUID} from 'node:crypto';
const frontend=path.dirname(fileURLToPath(import.meta.url));
await import('./build-staging.mjs');
const staging=path.join(frontend,'build/staging'),publicRoot=path.join(frontend,'../public_html');
const backupRoot=path.join(frontend,'../../'+path.basename(path.dirname(frontend))+'-frontend-backups');
await mkdir(backupRoot,{recursive:true,mode:0o700});await chmod(backupRoot,0o700);
const id=randomUUID(),backup=path.join(backupRoot,'before-local-'+id),prepared=path.join(frontend,'build','.publish-'+id);
await mkdir(backup,{mode:0o700});await mkdir(prepared,{recursive:true});
await cp(path.join(staging,'assets'),path.join(prepared,'assets'),{recursive:true});
await copyFile(path.join(staging,'index.html'),path.join(prepared,'index.html'));
await mkdir(publicRoot,{recursive:true});
const oldIndex=await access(path.join(publicRoot,'index.html')).then(()=>true,()=>false);
const oldAssets=await access(path.join(publicRoot,'assets')).then(()=>true,()=>false);
if(oldIndex)await copyFile(path.join(publicRoot,'index.html'),path.join(backup,'index.html'));
const info=JSON.parse(await readFile(path.join(staging,'build-info.json'),'utf8'));
let movedOld=false,movedNew=false;
try{
  if(oldAssets){await rename(path.join(publicRoot,'assets'),path.join(backup,'assets'));movedOld=true;}
  await rename(path.join(prepared,'assets'),path.join(publicRoot,'assets'));movedNew=true;
  await rename(path.join(prepared,'index.html'),path.join(publicRoot,'index.html'));
}catch(error){
  if(movedNew)await rm(path.join(publicRoot,'assets'),{recursive:true,force:true});
  if(movedOld)await rename(path.join(backup,'assets'),path.join(publicRoot,'assets'));
  if(oldIndex)await copyFile(path.join(backup,'index.html'),path.join(publicRoot,'index.html'));
  throw error;
}finally{await rm(prepared,{recursive:true,force:true});}
await writeFile(path.join(backup,'metadata.json'),JSON.stringify({replacedBy:info.version,scope:'public_html/index.html and assets only'},null,2)+'\n');
await writeFile(path.join(frontend,'build/local-integration.json'),JSON.stringify({version:info.version,backup,publicPublished:false,localIntegrated:true},null,2)+'\n');
console.log(`Local frontend ${info.version} integrated. Private rollback: ${backup}`);
