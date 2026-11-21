const fs=require('node:fs'),cp=require('node:child_process'),assert=require('node:assert/strict');
const php=process.env.PHP74_BIN||'php';
const version=cp.spawnSync(php,['-n','-r','echo PHP_VERSION_ID;'],{encoding:'utf8'});
assert.equal(version.status,0,version.stderr);
assert.ok(Number(version.stdout)>=70400&&Number(version.stdout)<80000,'Run this gate with the actual PHP 7.4 executable.');
const tracked=cp.spawnSync('git',['ls-files','shared/delivery','apps/delivery','index.php','notifications.php'],{encoding:'utf8'});
assert.equal(tracked.status,0,tracked.stderr);
const files=tracked.stdout.trim().split(/\r?\n/).filter(p=>p.endsWith('.php'));
for(const file of files){
  const code=fs.readFileSync(file,'utf8');
  // Do not allow test polyfills to conceal a production-only undefined function.
  assert.doesNotMatch(code,/\b(?:str_contains|str_starts_with|str_ends_with|array_is_list|get_debug_type|fdiv|preg_last_error_msg)\s*\(/,file+' calls a PHP 8-only function');
  assert.doesNotMatch(code,/\bmixed\s+\$/,file+' uses a PHP 8-only parameter type');
  const lint=cp.spawnSync(php,['-n','-l',file],{encoding:'utf8'});
  assert.equal(lint.status,0,file+'\n'+lint.stdout+'\n'+lint.stderr);
}
console.log('PASS PHP 7.4 runtime lint and function compatibility: '+files.length+' files');
