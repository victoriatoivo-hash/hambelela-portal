// Builds a source-only private upload package. No production records/credentials.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
process.chdir(path.resolve(__dirname,'..'));
const artifacts=path.resolve('.run-artifacts');
const output=path.resolve(process.argv[2]||'.run-artifacts/epi-data-link-20261027-r2');
if(!output.startsWith(artifacts+path.sep))throw new Error('Package must remain inside private artifacts');
if(fs.existsSync(output))throw new Error('Refusing to overwrite package');
const files=['shared/epi/PerformanceHistory.php','shared/epi/EmployeePerformanceService.php','apps/operations/kpi-employee-data.php','assets/js/employee-performance-workforce.js','assets/js/employee-performance.js','apps/operations/performance-workforce-response.php','apps/operations/performance-workspace.php','apps/operations/reports-data.php','assets/js/performance-workspace.js'];
const normalized=b=>b.toString('utf8').replace(/\r\n/g,'\n'),hash=b=>crypto.createHash('sha256').update(normalized(b)).digest('hex');
// Pin the approved live baseline. HEAD changes when a release is committed.
const baseline='54cfa319a1241a67b5969f93444b114455bf80f9';
const manifest={baseline,files:{},dependencies:{},schema_changes:false};
for(const name of files){
 const before=[baseline,'9b9ef2b8'].map(ref=>{try{return hash(cp.execFileSync('git',['show',`${ref}:${name}`],{stdio:['ignore','pipe','ignore']}));}catch{return null;}});
 const data=normalized(fs.readFileSync(name));manifest.files[name]={before:[...new Set(before)],after:hash(data)};
 const dest=path.join(output,'runtime',name);fs.mkdirSync(path.dirname(dest),{recursive:true});fs.writeFileSync(dest,data);
}
// These existing routes/styles must already be compatible; never install a partial dashboard.
for(const name of ['apps/operations/reports.php','apps/operations/reports-performance-reports-data.php','assets/css/performance-workspace.css','shared/epi/V2PerformanceQuery.php','shared/epi/OwnershipPeriodEngine.php']){
 manifest.dependencies[name]=hash(cp.execFileSync('git',['show',`${baseline}:${name}`]));
}
fs.writeFileSync(path.join(output,'manifest.json'),JSON.stringify(manifest,null,2));
fs.copyFileSync('scripts/epi-data-link-release.php',path.join(output,'release.php'));
cp.execFileSync('tar',['-a','-cf',output+'.zip','-C',output,'.']);
console.log(JSON.stringify({archive:output+'.zip',sha256:crypto.createHash('sha256').update(fs.readFileSync(output+'.zip')).digest('hex'),files:files.length}));
