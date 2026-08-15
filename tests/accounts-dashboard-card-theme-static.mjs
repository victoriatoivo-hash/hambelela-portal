import fs from 'node:fs';import assert from 'node:assert/strict';
const page=fs.readFileSync('apps/accounts/index.php','utf8'),css=fs.readFileSync('assets/css/accounts-dashboard.css','utf8');
for(const name of ['input-vat','output-vat','import-vat','vat-reconciliation','sage'])assert.match(page,new RegExp(`accounting-app-card--${name}`));
for(const [name,color] of [['input-vat','#a8ca19'],['output-vat','#ab3619'],['import-vat','#f07420'],['vat-reconciliation','#721b1a'],['sage','#6b4c3b']])assert.match(css,new RegExp(`accounting-app-card--${name}\\{--app-accent:${color}`));
assert.match(css,/\.accounting-app-card\{--app-accent:/);assert.match(css,/border-left:3px solid var\(--app-accent\)/);assert.match(css,/translateY\(-2px\)/);assert.match(css,/:focus-visible/);assert.match(css,/prefers-reduced-motion/);assert.match(css,/accounting-app-card--planned/);assert.match(css,/@media \(max-width:760px\)/);assert.match(page,/accounts-owner-label/);assert.match(page,/accounts-dashboard\.css/);
for(const route of ['input-vat.php','output-vat.php','import-vat.php','vat-reconciliation.php','sage-reconciliation.php'])assert.match(page,new RegExp(`href="${route.replace('.','\\.')}`));
console.log('Accounts dashboard card accent contracts passed.');
