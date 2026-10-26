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
const documents=['index.html','install.html'];
for(const file of documents)await copyFile(path.join(staging,file),path.join(prepared,file));
await mkdir(publicRoot,{recursive:true});
const oldDocuments=new Map(await Promise.all(documents.map(async file=>[file,await access(path.join(publicRoot,file)).then(()=>true,()=>false)])));
const oldAssets=await access(path.join(publicRoot,'assets')).then(()=>true,()=>false);
for(const [file,exists]of oldDocuments)if(exists)await copyFile(path.join(publicRoot,file),path.join(backup,file));
const info=JSON.parse(await readFile(path.join(staging,'build-info.json'),'utf8'));
let movedOld=false,movedNew=false;
try{
  if(oldAssets){await rename(path.join(publicRoot,'assets'),path.join(backup,'assets'));movedOld=true;}
  await rename(path.join(prepared,'assets'),path.join(publicRoot,'assets'));movedNew=true;
  for(const file of documents)await rename(path.join(prepared,file),path.join(publicRoot,file));
}catch(error){
  if(movedNew)await rm(path.join(publicRoot,'assets'),{recursive:true,force:true});
  if(movedOld)await rename(path.join(backup,'assets'),path.join(publicRoot,'assets'));
  for(const [file,exists]of oldDocuments){if(exists)await copyFile(path.join(backup,file),path.join(publicRoot,file));else await rm(path.join(publicRoot,file),{force:true});}
  throw error;
}finally{await rm(prepared,{recursive:true,force:true});}
await writeFile(path.join(backup,'metadata.json'),JSON.stringify({replacedBy:info.version,scope:'public_html/index.html, install.html and assets only'},null,2)+'\n');
await writeFile(path.join(frontend,'build/local-integration.json'),JSON.stringify({version:info.version,backup,publicPublished:false,localIntegrated:true},null,2)+'\n');
console.log(`Local frontend ${info.version} integrated. Private rollback: ${backup}`);
