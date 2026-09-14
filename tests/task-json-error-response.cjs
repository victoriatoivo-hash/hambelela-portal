const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const source = fs.readFileSync(path.join(__dirname, '../apps/operations/checklists.php'), 'utf8');
const start = source.indexOf('        $validationPayload = json_decode($e->getMessage(), true);');
const end = source.indexOf("        $message = $errorMessage;", start);
assert(start >= 0 && end > start);
const handler = source.slice(start, end);
const php = process.argv[2];
assert(php, 'Supply a PHP executable');
for (const headers of [
  "$_SERVER['HTTP_ACCEPT']='application/json';",
  "$_SERVER['HTTP_X_REQUESTED_WITH']='XMLHttpRequest';",
  "$_SERVER['HTTP_ACCEPT']='text/html';",
]) {
  const output = execFileSync(php, ['-r', headers + `
    $action='update_task_progress';
    $e=new RuntimeException(json_encode(['valid'=>false,'code'=>'proof_required','message'=>'Upload evidence before completing this task.','incomplete_items'=>[]]));
    ${handler}
    echo 'HTML fallback';
  `], {encoding:'utf8'});
  if (headers.includes('text/html')) assert.equal(output, 'HTML fallback');
  else {
    const result = JSON.parse(output);
    assert.equal(result.success, false);
    assert.equal(result.code, 'proof_required');
    assert.equal(result.message, 'Upload evidence before completing this task.');
    assert.deepEqual(result.incomplete_items, []);
  }
}
console.log('PASS: Accept-only and XHR errors preserve JSON validation; regular forms retain HTML.');
