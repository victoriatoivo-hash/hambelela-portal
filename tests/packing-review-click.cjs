const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/packing-list.js'), 'utf8');
const start = source.indexOf("      if (reviewDistributionButton && reviewDistributionButton.type === 'button') {");
const end = source.indexOf('      if (resetAutoDistribution)', start);
assert(start > 0 && end > start);
const handler = source.slice(start, end);
function check(code) {
  let reviewed = 0, submissions = 0;
  const button = { type: 'button' };
  const context = vm.createContext({ reviewDistributionButton: button, openDistributionReview() { reviewed++; button.type = 'submit'; } });
  vm.runInContext(`function click(event) { ${code} }`, context);
  function activate() {
    const event = { defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, stopPropagation() {} };
    context.click(event);
    // Browser activation evaluates the button's type after click dispatch.
    if (!event.defaultPrevented && button.type === 'submit') submissions++;
  }
  activate();
  assert.equal(reviewed, 1);
  assert.equal(submissions, 0, 'Review must not submit');
  activate();
  assert.equal(submissions, 1, 'Separate Create click must submit');
}
assert.throws(() => check(handler.replace('event.preventDefault();', '')), /Review must not submit/);
check(handler);
console.log('PASS: reproduces original same-click submission; review now waits for a separate Create click.');
