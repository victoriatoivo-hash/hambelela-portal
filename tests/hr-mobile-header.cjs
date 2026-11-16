// Run with a Chromium-based browser: HR_TEST_BROWSER=/path/to/browser node tests/hr-mobile-header.cjs
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const {execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const root = path.join(__dirname, '../apps/hr-portal');
const css = fs.readFileSync(path.join(root, 'includes/styles.css'), 'utf8');
const source = fs.readFileSync(path.join(root, 'employees.php'), 'utf8');
assert.match(source, /viewport-fit=cover/);
const bar = source.match(/<div class="topbar">[\s\S]+?<\/button>\s*<\/div>/)[0];
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'hr-header-'));
const browser = process.env.HR_TEST_BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
try {
  const cases = [[390,844,59,0],[844,390,0,47],[320,568,0,0],[1280,800,0,0]];
  // Headless browsers have no notch: simulate the environment inputs, not the computed layout.
  const frames = cases.map(([w,h,top,side], i) => {
    const inset = css.replaceAll('env(safe-area-inset-top,0px)',top+'px')
      .replaceAll('env(safe-area-inset-left,0px)',side+'px').replaceAll('env(safe-area-inset-right,0px)',side+'px');
    fs.writeFileSync(path.join(tmp,i+'.html'), '<!doctype html><style>'+inset+'</style><button class="hr-mobile-menu-toggle">Menu</button><div class="main">'+bar+'</div>');
    return '<iframe src="'+i+'.html" width="'+w+'" height="'+h+'"></iframe>';
  });
  const html = '<!doctype html>'+frames.join('')+'<output></output><script>onload=()=>{const results='+JSON.stringify(cases)+`.map(([w,h,top,side],i)=>{
    const d=frames[i].document, menu=d.querySelector('.hr-mobile-menu-toggle'), add=d.querySelector('.btn');
    const rect=e=>e.getBoundingClientRect(), hit=e=>{const r=rect(e);return e.contains(d.elementFromPoint(r.x+r.width/2,r.y+r.height/2))};
    if(w>=1024)return rect(menu).width===0 && rect(d.querySelector('.topbar')).height===58;
    return [menu,add].every(e=>rect(e).y>=top && rect(e).x>=side && rect(e).right<=w-side && rect(e).height>=44 && hit(e))
      && rect(menu).right<=rect(d.querySelector('.topbar-title')).left;
  });document.querySelector('output').textContent=results.join(',')};</script>`;
  const file = path.join(tmp, 'test.html');
  fs.writeFileSync(file, html);
  const output = execFileSync(browser,['--headless','--disable-gpu','--no-first-run','--allow-file-access-from-files','--user-data-dir='+path.join(tmp,'profile'),'--dump-dom',pathToFileURL(file).href],{encoding:'utf8',timeout:30000,stdio:['ignore','pipe','pipe']});
  assert.match(output, /<output>true,true,true,true<\/output>/);
  console.log('HR header: portrait notch, landscape notch, small phone and desktop passed.');
} finally { fs.rmSync(tmp,{recursive:true,force:true,maxRetries:3}); }
