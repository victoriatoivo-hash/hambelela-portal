// Private release packaging: generated artifacts and production snapshots are never committed.
const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');
const root=path.resolve(__dirname,'..');process.chdir(root);
const snapshot=path.resolve(process.argv[2]||'.run-artifacts/epi-live-20261008');
const output=path.resolve('.run-artifacts/epi-existing-performance-release');
const changed=cp.execSync('git diff --name-only',{encoding:'utf8'}).trim().split(/\r?\n/);
const added=cp.execSync('git ls-files --others --exclude-standard',{encoding:'utf8'}).trim().split(/\r?\n/);
const files=[...new Set([...changed,...added])].filter(p=>/^(apps\/operations\/|shared\/epi\/|assets\/(css|js)\/employee-performance)/.test(p));
files.sort((a,b)=>Number(a.startsWith('apps/'))-Number(b.startsWith('apps/'))||a.localeCompare(b));
const hash=b=>crypto.createHash('sha256').update(b).digest('hex');
const manifest={files:{},financial_use_allowed:false};
function copy(src,dest){fs.mkdirSync(path.dirname(dest),{recursive:true});fs.copyFileSync(src,dest);}
for(const p of files){const current=fs.readFileSync(p);const oldPath=path.join(snapshot,p);const old=fs.existsSync(oldPath)?fs.readFileSync(oldPath):null;manifest.files[p]={before:old===null?null:hash(old),after:hash(current)};copy(p,path.join(output,'runtime',p));if(old!==null)copy(oldPath,path.join(output,'before',p));}
const migration='operations-epi-performance-service-migration.sql';manifest.migration_sha256=hash(fs.readFileSync(migration));copy(migration,path.join(output,migration));
copy('scripts/epi-existing-performance-release.php',path.join(output,'scripts/epi-existing-performance-release.php'));
fs.writeFileSync(path.join(output,'release-manifest.json'),JSON.stringify(manifest,null,2));
cp.execFileSync('tar',['-a','-cf',output+'.zip','-C',output,'.']);
console.log(JSON.stringify({files:files.length,archive:output+'.zip',sha256:hash(fs.readFileSync(output+'.zip'))}));
