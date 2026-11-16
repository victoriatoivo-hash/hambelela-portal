// Standalone browser regression: no network, login, or employee records.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '..');
const assets = process.env.NOTICE_ASSETS || path.join(root,'assets');
const file = name => fs.readFileSync(path.join(assets, name), 'utf8');
(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROME_BIN || undefined});
  try {
    for (const width of [320, 390, 768, 1440]) {
      const page = await browser.newPage({viewport:{width,height:800}});
      const actions = [];
      const pending = [1];
      await page.clock.install();
      await page.addInitScript(()=>sessionStorage.setItem('ack-popup:fixture','shown'));
      const styles = [file('css/portal.css'), file('css/acknowledgments.css'),
        fs.readFileSync(process.env.NOTICE_BASE_CSS || path.join(assets,'css/portal-notices.css'),'utf8'),
        file('css/ack-communication.css')].join('\n');
      await page.route('**/*', async route => {
        const u = new URL(route.request().url());
        if (u.pathname === '/') return route.fulfill({contentType:'text/html', body:'<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/style"><script>window.PortalNoticeGate={acquire:()=>true,release:()=>{}}</script><script defer src="/ack-communication.js"></script><script defer src="/acknowledgments-popup.js"></script>'});
        if (u.pathname === '/style') return route.fulfill({contentType:'text/css',body:styles});
        if (u.pathname.endsWith('.js')) return route.fulfill({contentType:'text/javascript',body:file('js'+u.pathname)});
        if (u.pathname.endsWith('api.php')) {
          const body=route.request().postDataJSON()||{}, action=u.searchParams.get('action');
          if(action==='respond'){
            actions.push(body.action);
            if(body.action==='acknowledge')pending.splice(pending.indexOf(body.recipient_id),1);
          }
          const eligible=pending.filter(id=>!(body.snoozed||[]).includes(id));
          const id=eligible.includes(body.prefer)?body.prefer:eligible[0];
          return route.fulfill({json:{csrf:'fixture',session:'fixture',notice:id?{id,required:true,title:'Notice '+id,message_html:'Please review the attached instructions.',deadline:'2026-11-16 17:00',image_type:'png'}:null}});
        }
        if (u.pathname.endsWith('image.php')) return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="480" height="240"><rect width="480" height="240" fill="olive"/></svg>'});
        return route.abort();
      });
      await page.goto('https://notice.test/');
      const dlg = page.locator('.ack-company-notice[open]');
      await dlg.waitFor();
      pending.unshift(3); // A newer notice arrives while the employee is reading notice 1.
      await page.reload();
      await dlg.getByRole('heading',{name:'Notice 1'}).waitFor();
      assert.deepEqual(actions,[],'Refreshing an open notice must not submit any response');
      pending.shift();
      assert(await dlg.evaluate(d => {
        const box = d.querySelector('.ack-check input'), label = d.querySelector('.ack-check span');
        const brand = d.querySelector('.ack-notice-brand'), range = new Range();
        const row = d.querySelector('.ack-attachment-row');
        const [icon,title,button] = Array.from(row.children,x=>x.getBoundingClientRect());
        range.selectNodeContents(brand.lastChild);
        const aligned = (a,b) => a.top < b.bottom && b.top < a.bottom && a.right <= b.left + 1;
        return d.scrollWidth <= d.clientWidth + 1 && aligned(box.getBoundingClientRect(),label.getBoundingClientRect())
          && aligned(brand.firstElementChild.getBoundingClientRect(),range.getBoundingClientRect())
          && aligned(icon,title) && aligned(title,button) && button.right <= row.getBoundingClientRect().right+1
          && getComputedStyle(box).accentColor === 'rgb(80, 108, 62)';
      }), 'Alignment, green checkbox and no horizontal overflow at '+width);
      const submit = dlg.getByRole('button',{name:'Acknowledge',exact:true});
      assert(await submit.isDisabled());
      assert.deepEqual(actions,[]);
      await dlg.getByRole('button',{name:'View image'}).click();
      const viewer = page.locator('.ack-notice-viewer');
      await viewer.getByText('Image opened.',{exact:false}).waitFor();
      assert.deepEqual(actions,['image_opened']);
      await viewer.getByRole('button',{name:'Close image'}).click();
      await dlg.locator('.ack-check span').click();
      assert(await submit.isEnabled());
      if (width === 1440) {
        await submit.click(); await dlg.waitFor({state:'detached'});
        assert.deepEqual(actions,['image_opened','acknowledge']);
      } else {
        pending.push(2);
        await dlg.locator('[data-clarify]').click();
        assert(await dlg.locator('.ack-check').isHidden());
        await dlg.locator('[data-later]').click();
        await dlg.getByRole('heading',{name:'Notice 2'}).waitFor();
        await dlg.locator('.ack-check span').click();await submit.click();
        await dlg.waitFor({state:'detached'});
        await Promise.all([page.waitForResponse(r=>r.url().includes('action=popup_alternative')),page.reload()]);
        assert.equal(await dlg.count(),0,'refresh should respect defer');
        await Promise.all([page.waitForResponse(r=>r.url().includes('action=popup_alternative')),page.clock.fastForward(14*60*1000)]);
        assert.equal(await dlg.count(),0,'must not interrupt before 15 minutes');
        await page.clock.fastForward(61*1000);
        await dlg.getByRole('heading',{name:'Notice 1'}).waitFor();
        assert.deepEqual(actions,['image_opened','acknowledge']);
      }
      await page.close(); console.log(width+'px: passed');
    }
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
