import assert from 'node:assert/strict';
import {readdir, readFile} from 'node:fs/promises';

const root = new URL('../', import.meta.url);
const names = (await readdir(new URL('apps/cost-manager/', root))).filter(name => name.endsWith('.php'));
const protectedWrappers = new Set([
  'index.php', 'inventory-profit.php', 'pricing-manager.php', 'product-database.php', 'products.php',
  'profit-calculator.php', 'settings.php', 'system-dashboard.php', 'workbook.php', 'purchases.php',
  'invoice-review.php', 'shipments.php', 'landed-costs.php', 'product-matching.php', 'profitability.php', 'cogs-publishing.php',
  'size-conversions.php',
]);

for (const name of names) {
  const source = await readFile(new URL(`apps/cost-manager/${name}`, root), 'utf8');
  if (protectedWrappers.has(name)) {
    assert.match(source, /(?:workbook-page|workbook|module-placeholder|recipes|landing-cost-engine)\.php/, `${name} must delegate only to a protected route`);
    continue;
  }
  assert.match(source, /require_role\('owner_admin'\)/, `${name} must require owner_admin before financial processing`);
  assert.doesNotMatch(source, /require_role\('owner_admin',\s*'supervisor_manager'\)/, `${name} must not permit supervisors`);
  assert.doesNotMatch(source, /require_login\(\)/, `${name} must not rely on login-only access`);
}

const dashboard = await readFile(new URL('index.php', root), 'utf8');
assert.match(dashboard, /if \(\$roleKey === 'owner_admin'\)[\s\S]*Cost Workbook/, 'Cost Workbook dashboard card must be owner_admin-only');
const sidebar = await readFile(new URL('shared/sidebar.php', root), 'utf8');
assert.doesNotMatch(sidebar, /apps\/cost-manager\/(?:workbook|packaging-manager|historical-cost-records)\.php/, 'Financial Cost Workbook links must not be present in the shared sidebar');
const helper = await readFile(new URL('shared/cost-workbook.php', root), 'utf8');
assert.match(helper, /function cw_require_admin\(\)[\s\S]*user_has_role\('owner_admin'\)/, 'Cost Workbook mutation helper must be owner_admin-only');
assert.doesNotMatch(helper, /function cw_require_admin\(\)[\s\S]{0,180}supervisor_manager/, 'Mutation helper must not permit supervisors');
console.log(`Cost Workbook owner/admin access static assertions passed: ${names.length} routes audited.`);
