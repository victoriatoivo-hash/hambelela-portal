import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
const roots=['shared','apps/operations'];
const files=roots.flatMap(root=>fs.readdirSync(root,{withFileTypes:true}).filter(e=>e.isFile()&&/system-issue/i.test(e.name)&&e.name!=='system-issues-supervisor-owner-scope-static.mjs').map(e=>path.join(root,e.name)));
for(const file of files){
  const source=fs.readFileSync(file,'utf8');
  for(const line of source.split(/\r?\n/)){
    if(!line.includes('supervisor_manager'))continue;
    assert.doesNotMatch(line,/owner|system_issue_is_owner|system_issue_owner_ids|owner_admin/i,`${file} treats supervisor_manager as a System Issues owner`);
  }
}
console.log(`System Issues supervisor owner-scope scan passed across ${files.length} files.`);
