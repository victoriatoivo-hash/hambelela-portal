// Three-file presentation-only delta; uses the established private EPI backup/rollback runner.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
process.chdir(path.resolve(__dirname,'..'));
const baseline='c1ed030502eca9b47474a15cc0521722dd534774';
const output=path.resolve('.run-artifacts/epi-profile-ui-20261029');
if(fs.existsSync(output))throw new Error('Refusing to overwrite a release package');
const files=['assets/css/employee-performance.css','assets/js/employee-performance.js','apps/operations/kpi-employee.php'];
const normalized=b=>b.toString('utf8').replace(/\r\n/g,'\n');
const hash=b=>crypto.createHash('sha256').update(normalized(b)).digest('hex');
const manifest={baseline,files:{},dependencies:{},schema_changes:false};
for(const name of files){
 const data=normalized(fs.readFileSync(name));
 manifest.files[name]={before:[hash(cp.execFileSync('git',['show',`${baseline}:${name}`]))],after:hash(data)};
 const target=path.join(output,'runtime',name);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,data);
}
fs.writeFileSync(path.join(output,'manifest.json'),JSON.stringify(manifest,null,2));
fs.copyFileSync('scripts/epi-data-link-release.php',path.join(output,'release.php'));
cp.execFileSync('tar',['-a','-cf',output+'.zip','-C',output,'.']);
console.log(JSON.stringify({archive:output+'.zip',files,sha256:crypto.createHash('sha256').update(fs.readFileSync(output+'.zip')).digest('hex')}));
