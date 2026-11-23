const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
const output=path.resolve('.run-artifacts/epi-package-test-'+Date.now());
const normalize=b=>b.toString('utf8').replace(/\r\n/g,'\n'),hash=b=>crypto.createHash('sha256').update(normalize(b)).digest('hex');
cp.execFileSync(process.execPath,['scripts/epi-data-link-package.cjs',output]);
const manifest=JSON.parse(fs.readFileSync(path.join(output,'manifest.json')));
assert.equal(manifest.baseline,'54cfa319a1241a67b5969f93444b114455bf80f9');
assert.equal(Object.keys(manifest.files).length,9);
for(const [name,entry] of Object.entries(manifest.files)){
 assert.equal(hash(fs.readFileSync(path.join(output,'runtime',name))),entry.after);
 assert.equal(hash(fs.readFileSync(name)),entry.after);
 let baseline=null;try{baseline=hash(cp.execFileSync('git',['show',`${manifest.baseline}:${name}`],{stdio:['ignore','pipe','ignore']}));}catch{}
 assert.ok(entry.before.includes(baseline),`Approved baseline retained after commit: ${name}`);
}
assert.equal(Object.keys(manifest.dependencies).length,5);
assert.throws(()=>cp.execFileSync(process.execPath,['scripts/epi-data-link-package.cjs',output],{stdio:'ignore'}),'Cannot overwrite previous package');
assert.throws(()=>cp.execFileSync(process.execPath,['scripts/epi-data-link-package.cjs','../outside-release'],{stdio:'ignore'}),'Cannot write outside artifacts');
const names=cp.execFileSync('tar',['-tf',output+'.zip'],{encoding:'utf8'}).trim().split(/\r?\n/);
const expected=new Set(['./manifest.json','./release.php',...Object.keys(manifest.files).map(n=>'./runtime/'+n)]);
assert.equal(new Set(names).size,names.length);
for(const name of names.filter(n=>!n.endsWith('/')))assert.ok(expected.has(name),`Unexpected archive entry: ${name}`);
console.log('PASS: pinned baseline, nine source hashes, dependency guards, no private data, no package overwrite or path escape');
