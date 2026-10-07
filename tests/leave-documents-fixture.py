"""Build a localhost-only preview and test fixture with synthetic documents."""
from pathlib import Path
import base64
import shutil
from PIL import Image
root = Path(__file__).resolve().parents[1]
fixture = root / 'verification/http-fixture'
hr = fixture / 'apps/hr-portal'
(hr / 'includes').mkdir(parents=True, exist_ok=True)
(hr / 'uploads/certificates').mkdir(parents=True, exist_ok=True)
for path in ('download-certificate.php','view-my-certificate.php','includes/leave-documents.php','includes/leave-documents.css','includes/hr-responsive.js'):
    shutil.copy2(root / 'apps/hr-portal' / path, hr / path)
shutil.copy2(root / 'verification/live-hr-styles.css', hr / 'includes/styles.css')
shutil.copy2(root / 'verification/live-hr-sidebar-theme.css', hr / 'includes/hr-sidebar-theme.css')
(fixture / 'assets/fonts').mkdir(parents=True, exist_ok=True)
shutil.copy2(root.parent / 'assets/fonts/jost-variable.woff2', fixture / 'assets/fonts/jost-variable.woff2')
(hr / 'config.php').write_text("""<?php
session_name('leave_document_fixture');session_start();
function db():PDO {static $db;return $db??($db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=leave_documents_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]));}
function currentUser():?array{return $_SESSION['user']??null;}
function requireLogin():void{if(!currentUser()){header('Location: /login-required');exit;}}
""")
(fixture / 'login-test.php').write_text("""<?php
require __DIR__.'/apps/hr-portal/config.php';$q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([(int)($_GET['id']??1)]);$u=$q->fetch();
$_SESSION['user']=['id'=>(int)$u['id'],'role'=>$u['role'],'emp_id'=>(int)$u['employee_id']];echo 'Synthetic test session established';
""")
pdf=b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n'
for name in ('cert_1_example.pdf','legacy-note.pdf','unreferenced.pdf','cert_1_forged.pdf'):
    (hr / 'uploads/certificates' / name).write_bytes(pdf)
(hr / 'uploads/certificates/cert_2_example.png').write_bytes(base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4ioAAAAASUVORK5CYII='))
(hr / 'uploads/certificates/cert_1_spoof.pdf').write_text('<?php echo "not a medical PDF";')
Image.new('RGB', (8, 8), '#f2f4ec').save(hr / 'uploads/certificates/cert_1_example.jpg')
source=(root / 'apps/hr-portal/leave.php').read_text(encoding='utf-8')
start=source.index('    <!-- All Leave History -->')
end=source.index('\n  </div>\n</div>',start)
history=source[start:end]
preview="""<?php
require __DIR__.'/config.php';require_once __DIR__.'/includes/leave-documents.php';
$all=db()->query("SELECT *,CASE employee_id WHEN 1 THEN 'Example Employee One' ELSE 'Example Employee Two' END AS emp_name,'' AS reviewer_name,NULL AS approved_at,'' AS reject_reason FROM leave_requests ORDER BY id")->fetchAll();$leaveCsrfToken='fixture';
?><!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Leave document review preview</title>
<link rel="stylesheet" href="includes/styles.css"><link rel="stylesheet" href="includes/hr-sidebar-theme.css"><link rel="stylesheet" href="includes/leave-documents.css">
<style>@font-face{font-family:Jost;src:url('/assets/fonts/jost-variable.woff2')}body{font-family:Jost,sans-serif}.review-container{max-width:1200px;margin:auto;padding:20px}.review-notice{padding:12px 16px;background:#f2f4ec;border:1px solid #d3dac6;border-radius:8px;margin:0 0 16px;color:#59694a;font-size:13px}</style></head><body><main class="review-container"><h1>Leave History</h1><p class="review-notice">Local review preview — synthetic documents and accounts. Production records are unchanged.</p>
"""+history+"""</main><script src="includes/hr-responsive.js"></script></body></html>"""
(hr / 'history-preview.php').write_text(preview, encoding='utf-8')
print('Synthetic local leave-document fixture and production history renderer prepared.')
