// Mobile usability visual harness: screenshots + overflow metrics for Orders, Bookkeeping, Packing, Tasks.
import {chromium} from 'playwright';
import {mkdir, writeFile} from 'node:fs/promises';

const out = process.env.MOBILE_SCREENSHOT_DIR || 'artifacts/mobile';
await mkdir(out, {recursive: true});
const base = process.env.MOBILE_BASE || 'http://127.0.0.1:8821';
const pages = {
  orders: '/apps/operations/orders-board.php',
  bookkeeping: '/apps/operations/bookkeeping.php',
  packing: '/apps/operations/consignments.php',
  tasks: '/apps/operations/checklists.php',
};
const sizes = [[390, 844, true], [430, 932, true], [844, 390, true], [768, 1024, true], [1440, 900, false]];
const browser = await chromium.launch({headless: true});
const summary = [];

for (const [name, path] of Object.entries(pages)) {
  for (const [width, height, mobile] of sizes) {
    const context = await browser.newContext({viewport: {width, height}, isMobile: mobile, hasTouch: mobile, deviceScaleFactor: mobile ? 2 : 1});
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push('pageerror: ' + error.message));
    page.on('console', (message) => { if (message.type() === 'error') errors.push('console: ' + message.text()); });
    page.on('response', (response) => { if (response.status() >= 400) errors.push(response.status() + ' ' + response.url()); });
    await page.goto(base + path, {waitUntil: 'networkidle'});
    await page.waitForTimeout(900);
    const metrics = await page.evaluate(() => {
      const vw = document.documentElement.clientWidth;
      const offenders = [];
      for (const el of document.querySelectorAll('body *')) {
        const style = getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden' || style.position === 'fixed') continue;
        const rect = el.getBoundingClientRect();
        if (rect.width && rect.right > vw + 1 && !el.closest('[class*="scroll"]')) {
          offenders.push(`${el.tagName.toLowerCase()}.${[...el.classList].slice(0, 3).join('.')} right=${Math.round(rect.right)} w=${Math.round(rect.width)}`);
        }
      }
      return {vw, docScroll: document.documentElement.scrollWidth, bodyScroll: document.body.scrollWidth,
        title: document.title, h1: document.querySelector('h1')?.textContent?.trim() || '', offenders: offenders.slice(0, 12)};
    });
    const file = `${out}/${name}-${width}x${height}.png`;
    await page.screenshot({path: file, fullPage: width < 1000});
    const pageOverflow = metrics.docScroll > metrics.vw + 1;
    summary.push({name, width, height, pageOverflow, ...metrics, errors: errors.slice(0, 8)});
    console.log(`${name} ${width}x${height} overflow=${pageOverflow} (${metrics.docScroll}/${metrics.vw}) h1="${metrics.h1}" errors=${errors.length}`);
    for (const offender of metrics.offenders.slice(0, 5)) console.log('   offender ' + offender);
    for (const error of errors.slice(0, 4)) console.log('   ' + error);
    await context.close();
  }
}
await writeFile(`${out}/summary.json`, JSON.stringify(summary, null, 2));
await browser.close();
