// Render the real app markup, stylesheet and JavaScript; test viewport geometry.
const fs = require('node:fs');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
(async () => {
  const browser = await chromium.launch(process.env.MIV_TEST_BROWSER ? {executablePath: process.env.MIV_TEST_BROWSER} : {});
  try {
    const bundle = JSON.parse(fs.readFileSync('standalone/miv-bundle.json', 'utf8'));
    const source = name => bundle['standalone/miv/' + name];
    const html = source('index.php').replace(/<\?(?:php|=)[\s\S]*?\?>/g, '').replace(/<script[\s\S]*?<\/script>/g, '').replace(/<link[^>]*>/g, '');
    for (const [width, height] of [[320,568], [375,667], [640,360], [768,1024], [1280,800]]) {
      const page = await browser.newPage({viewport: {width, height}});
      await page.route('**/*', route => route.abort());
      await page.setContent(html);
      await page.addStyleTag({content: source('assets/css/miv-shipping.css')});
      await page.evaluate(() => { window.fetch = async () => ({ok:true, json: async () => ({ok:true,orders:[],payments:[]})}); });
      await page.addScriptTag({content: source('assets/js/miv-shipping.js')});
      await page.waitForTimeout(300);
      const bar = page.locator('.miv-live');
      assert.equal(await bar.evaluate(el => getComputedStyle(el).position), 'fixed');
      const initial = await bar.boundingBox();
      await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
      const middle = await bar.boundingBox();
      await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
      const end = await bar.boundingBox();
      for (const rect of [initial,middle,end]) {
        assert(rect && rect.x >= -1 && rect.x + rect.width <= width + 1, `bar horizontal fit ${width}`);
        assert(rect.y >= 0 && rect.y + rect.height <= height, `bar vertical fit ${width}`);
        assert(Math.abs(rect.y - initial.y) <= 1, `bar moved when scrolling ${width}`);
      }
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `page overflow ${width}`);
      console.log(`PASS: fixed totals bar and no horizontal overflow at ${width}x${height}`);
      await page.evaluate(() => { if (!crypto.randomUUID) crypto.randomUUID = () => 'budget_layout_test_000001'; });
      await page.locator('[data-tab="budgets"]').click();
      await page.locator('#budgetNew').click();
      await page.locator('#budgetName').fill('November household budget');
      await page.locator('#budgetAvailable').fill('23000');
      await page.locator('.b-name').fill('Groceries and household shopping');
      await page.locator('.b-planned').fill('2500');
      await page.locator('.b-actual').fill('1000');
      await page.locator('#budgetAddCategory').click();
      await page.locator('.miv-budget-category:last-child .b-name').fill('Transport');
      await page.locator('.miv-budget-category:last-child .b-planned').fill('1500');
      await page.locator('.miv-budget-category:last-child .b-actual').fill('500');
      assert.equal(await page.locator('#budgetActual').innerText(), 'N$1,500.00');
      assert.equal(await page.locator('#budgetRemaining').innerText(), 'N$21,500.00');
      assert.equal(await bar.isVisible(), false, 'shipping bar hidden while budgeting');
      assert(await page.locator('#budgetPie').evaluate(el => getComputedStyle(el).backgroundImage.includes('conic-gradient')), 'pie chart rendered');
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `budget page overflow ${width}`);
      for (const selector of ['#budgetPie','#budgetCategories','#budgetSave']) {
        const rect = await page.locator(selector).boundingBox();
        assert(rect && rect.x >= -1 && rect.x + rect.width <= width + 1, `budget control fit ${selector} ${width}`);
      }
      console.log(`PASS: budgeting controls, chart and balances fit at ${width}x${height}`);
      await page.close();
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
