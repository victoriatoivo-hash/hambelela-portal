import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const shared = read('shared/marketing-phase3.php');
const view = read('apps/marketing/phase3-view.php');
const page = read('apps/marketing/index.php');
const data = read('apps/marketing/analytics-data.php');
const evidence = read('apps/marketing/metric-file.php');
const js = read('assets/js/marketing.js');
const dashboard = read('index.php');
const trackedLink = read('apps/marketing/track-link.php');

for (const table of ['marketing_campaigns','marketing_ads','marketing_metric_snapshots','marketing_attributions','marketing_monthly_reviews','marketing_audit_log','marketing_tracked_links']) {
  assert.match(shared, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`));
}

assert.match(page, /'analytics'=>'Analytics'/);
assert.match(view, /Marketing Analytics/);
assert.match(view, /Today.*Last 7 Days.*Last 30 Days.*This Month.*Last Month.*Custom Range/s);
assert.match(view, /CONTENT PUBLISHED|Content Published/);
assert.match(view, /ORDERS ATTRIBUTED|Orders Attributed/);
assert.match(view, /REVENUE ATTRIBUTED|Revenue Attributed/);
assert.match(view, /marketing-source-badge/);
assert.match(view, /WEBSITE PERFORMANCE/);
assert.match(view, /Products Most Promoted/);
assert.match(view, /Products in Attributed Orders/);
assert.match(view, /Per-product attributed revenue is not shown/);

assert.match(shared, /Engagement|engagements/i);
assert.match(view, /Eng\. Rate/);
assert.match(view, /Clicks.*Impressions.*100/s);
assert.match(view, /Spend.*Clicks/s);
assert.match(view, /Spend.*Impressions.*1000/s);
assert.match(view, /metricRevenue.*spend/s);
assert.doesNotMatch(view, /name="(?:ctr|cpc|cpm|roas)"/i);

assert.match(shared, /corrected_from_id/);
assert.match(shared, /UPDATE marketing_metric_snapshots SET is_valid=0/);
assert.match(view, /Correct prior snapshot/);
assert.match(view, /Correction reason/);
assert.match(view, /Corrected history/);
assert.match(view, /entered_by_name/);
assert.match(view, /entered_at/);
assert.match(evidence, /realpath/);
assert.match(evidence, /Content-Disposition: inline/);

assert.match(view, /Submit for Approval/);
assert.match(view, /Request Changes/);
assert.match(view, /Reject/);
assert.match(shared, /ad_approved/);
assert.match(shared, /campaign_approved/);
assert.match(page, /quality_revision_count/);
assert.match(page, /creative_direction_change/);
assert.match(page, /business_change/);

assert.match(shared, /JOIN ops_orders o ON o\.id=ma\.order_id/);
assert.match(shared, /array_sum\(array_map\(fn\(\$r\)=>\(float\)\$r\['total_amount'\]/);
assert.match(view, /It never changes payment or accounting values/);
assert.match(view, /UTM Link Builder/);
assert.match(trackedLink, /tracked_link_generated/);
assert.match(trackedLink, /marketing_verify\(\)/);
assert.match(view, /ROAS is not profit/);

assert.match(data, /application\/json/);
assert.match(js, /updated without reloading the page/);
assert.match(js, /data-top-content/);
assert.match(dashboard, /owner-marketing-widget/);
assert.match(dashboard, /Attributed Sales/);
assert.match(view, /Results do not change bonus weights/);
assert.match(view, /Phase 3 does not alter the existing bonus formula/);

console.log('Marketing Phase 3 static contracts passed.');
