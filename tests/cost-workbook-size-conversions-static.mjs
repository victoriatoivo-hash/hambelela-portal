import assert from 'node:assert/strict';
import fs from 'node:fs';

const shell = fs.readFileSync('shared/cost-workbook-page-shell.php', 'utf8');
const sections = fs.readFileSync('shared/cost-workbook-sections.php', 'utf8');
const data = fs.readFileSync('shared/cost-workbook-size-conversions.php', 'utf8');
const page = fs.readFileSync('apps/cost-manager/size-conversions.php', 'utf8');
const workbook = fs.readFileSync('apps/cost-manager/workbook-page.php', 'utf8');
const css = fs.readFileSync('assets/css/cost-workbook-landing.css', 'utf8');

assert.match(workbook, /require_role\('owner_admin'\)/, 'Cost Workbook remains server-side owner protected');
assert.match(page, /\$cwPageKey='size-conversions'/);
assert.match(shell, /accounts-dashboard\.css/, 'Accounts card system is reused');
assert.match(shell, /Back to Cost Workbook/);
assert.match(sections, /Size Conversions/);
assert.match(sections, /Available/);
assert.match(sections, /Owner only/);
assert.match(sections, /Liquid sizes are converted to litres/);
assert.match(sections, /Weight sizes are converted to kilograms/);
assert.match(sections, /<th scope="col">Size<\/th>/);
assert.match(css, /\.cost-workbook-app/, 'new styles are Cost Workbook scoped');
assert.match(css, /prefers-reduced-motion/);
assert.match(css, /overflow-x:auto/);

const expected = ['10ml','20ml','50ml','100ml','250ml','500ml','1L','5L','50g','100g','200g','250g','500g','750g','1kg','1.5kg','5kg','10kg'];
for (const label of expected) assert.match(data, new RegExp(`'key'=>'${label.replace('.', '\\.')}'`), `missing ${label}`);
assert.equal((data.match(/'key'=>'/g) || []).length, 18, 'exactly 18 canonical conversions');
assert.equal((data.match(/'measurement_type'=>'volume'/g) || []).length, 8);
assert.equal((data.match(/'measurement_type'=>'weight'/g) || []).length, 10);
assert.doesNotMatch(data, /measurement_type'=>'volume'[^\n]+base_unit'=>'kg'/);
assert.doesNotMatch(data, /measurement_type'=>'weight'[^\n]+base_unit'=>'L'/);

console.log('Cost Workbook Size Conversions static checks passed');
