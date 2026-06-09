<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email.php';
requireLogin();
$user = currentUser();
$db   = db();

// Handle admin uploading a policy
if ($user['role'] === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'')==='upload_policy') {
    $docName = clean($_POST['doc_name'] ?? '');
    $docType = clean($_POST['doc_type'] ?? 'Policy');

    if ($docName && isset($_FILES['policy_file']) && $_FILES['policy_file']['error']===0) {
        $ext     = strtolower(pathinfo($_FILES['policy_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','doc','docx'];
        if (in_array($ext, $allowed)) {
            $dir  = __DIR__ . '/uploads/documents/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $file = 'policy_'.time().'.'.$ext;
            move_uploaded_file($_FILES['policy_file']['tmp_name'], $dir.$file);
            $db->prepare("INSERT INTO documents (employee_id,doc_type,doc_name,file_path,uploaded_by) VALUES (NULL,?,?,?,?)")
               ->execute([$docType,$docName,'uploads/documents/'.$file,$user['id']]);

            // Notify all employees
            $employees = $db->query("SELECT u.id AS user_id, u.email AS user_email, u.name AS user_name, e.email AS employee_email, CONCAT(e.first_name,' ',e.last_name) AS employee_name FROM employees e LEFT JOIN users u ON u.employee_id=e.id WHERE e.status='active'")->fetchAll();
            foreach ($employees as $e) {
                if ($e['user_id']) {
                    $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'info')")
                       ->execute([$e['user_id'],'New Company Policy', "A new policy has been uploaded: $docName. Please read it at your earliest convenience."]);
                }
                $toEmail = trim((string)($e['user_email'] ?: $e['employee_email']));
                $toName = $e['user_name'] ?: $e['employee_name'];
                if ($toEmail !== '') emailDocumentUploaded($toEmail, $toName, $docName);
            }
        }
    }
    header('Location: company-policies.php?msg=uploaded'); exit;
}

// Get all company policies (employee_id IS NULL = company-wide)
$policies = $db->query("SELECT * FROM documents WHERE employee_id IS NULL ORDER BY created_at DESC")->fetchAll();
$currentPage = 'company-policies.php';
$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Company Policies — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
</head>
<body>
<?php if ($user['role']==='employee'): ?>
  <?php include __DIR__ . '/includes/emp-sidebar.php'; ?>
<?php else: ?>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
<?php endif ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">Company Policies</div>
    <?php if ($user['role']==='admin'): ?>
    <button class="btn btn-primary" onclick="openModal('uploadModal')"><i class="fa-solid fa-upload"></i> Upload Policy</button>
    <?php endif ?>
  </div>
  <div class="content">
    <?php if ($msg==='uploaded'): ?><div class="toast"><i class="fa-solid fa-check"></i> Policy uploaded and employees notified.</div><?php endif ?>

    <div class="section-title" style="margin-bottom:6px">Company Policies &amp; Documents</div>
    <div class="section-sub" style="margin-bottom:22px">Official Hambelela Organic policies for all employees</div>

    <?php if (empty($policies)): ?>
    <!-- Default policy cards when no documents uploaded yet -->
    <div class="grid-2">
      <?php
      $defaultPolicies = [
        ['icon'=>'fa-solid fa-calendar-check','color'=>'green','title'=>'Leave Policy','desc'=>'Annual leave entitlements, application process, carry-over rules and leave without pay conditions.'],
        ['icon'=>'fa-solid fa-briefcase-medical','color'=>'amber','title'=>'Sick Leave Rules','desc'=>'Sick leave entitlement, medical certificate requirements, and procedures for extended sick leave.'],
        ['icon'=>'fa-solid fa-scale-balanced','color'=>'blue','title'=>'Code of Conduct','desc'=>'Expected standards of behaviour, workplace ethics, disciplinary procedures and company values.'],
        ['icon'=>'fa-regular fa-clock','color'=>'teal','title'=>'Overtime Policy','desc'=>'Overtime approval process, applicable rates, public holiday pay and overtime payment procedures.'],
      ];
      foreach ($defaultPolicies as $p):
      ?>
      <div class="card" style="overflow:visible">
        <div style="padding:20px">
          <div style="display:flex;align-items:flex-start;gap:14px">
            <div class="stat-icon <?=$p['color']?>" style="flex-shrink:0"><i class="<?=$p['icon']?>"></i></div>
            <div>
              <div style="font-size:15px;font-weight:700;margin-bottom:6px"><?=$p['title']?></div>
              <div style="font-size:13px;color:var(--text-mid);line-height:1.6"><?=$p['desc']?></div>
              <?php if ($user['role']==='admin'): ?>
              <div style="margin-top:12px">
                <button class="btn btn-secondary btn-sm" onclick="prefillUpload('<?=$p['title']?>')"><i class="fa-solid fa-upload"></i> Upload Document</button>
              </div>
              <?php else: ?>
              <div style="margin-top:10px;font-size:11px;color:var(--text-light)"><i class="fa-solid fa-circle-info"></i> Document not yet uploaded. Contact your manager.</div>
              <?php endif ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach ?>
    </div>

    <?php else: ?>
    <!-- Uploaded policy documents -->
    <div class="card">
      <table>
        <thead><tr><th>Document</th><th>Type</th><th>Uploaded</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($policies as $doc):
          $ext = strtolower(pathinfo($doc['file_path'],PATHINFO_EXTENSION));
          $icon = $ext==='pdf' ? 'fa-solid fa-file-pdf' : 'fa-solid fa-file-word';
          $icol = $ext==='pdf' ? 'var(--red)' : 'var(--blue)';
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <i class="<?=$icon?>" style="font-size:20px;color:<?=$icol?>"></i>
              <span style="font-weight:600"><?=htmlspecialchars($doc['doc_name'])?></span>
            </div>
          </td>
          <td><span class="badge badge-blue"><?=htmlspecialchars($doc['doc_type'])?></span></td>
          <td style="font-size:12px;color:var(--text-mid)"><?=date('d M Y',strtotime($doc['created_at']))?></td>
          <td>
            <a href="<?=htmlspecialchars($doc['file_path'])?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fa-solid fa-eye"></i> View</a>
            <a href="<?=htmlspecialchars($doc['file_path'])?>" download class="btn btn-secondary btn-sm"><i class="fa-solid fa-download"></i></a>
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php endif ?>
  </div>
</div>

<?php if ($user['role']==='admin'): ?>
<!-- UPLOAD MODAL -->
<div class="overlay" id="uploadModal">
  <div class="modal" style="max-width:460px">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-upload"></i> Upload Policy Document</div><button class="modal-close" onclick="closeModal('uploadModal')"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_policy">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full">
            <label class="form-label">Document Name</label>
            <input class="form-input" name="doc_name" id="docNameField" placeholder="e.g. Leave Policy 2026" required>
          </div>
          <div class="form-group full">
            <label class="form-label">Document Type</label>
            <select class="form-select" name="doc_type">
              <option>Leave Policy</option>
              <option>Sick Leave Rules</option>
              <option>Code of Conduct</option>
              <option>Overtime Policy</option>
              <option>Employment Contract</option>
              <option>HR Policy</option>
              <option>Other</option>
            </select>
          </div>
          <div class="form-group full">
            <label class="form-label">File (PDF, DOC, DOCX)</label>
            <input class="form-input" type="file" name="policy_file" accept=".pdf,.doc,.docx" required>
          </div>
        </div>
        <div style="margin-top:12px;padding:10px 14px;background:var(--green-pale);border-radius:8px;font-size:12px;color:var(--green)">
          <i class="fa-solid fa-bell"></i> All employees will be notified automatically when you upload this document.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('uploadModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload"></i> Upload &amp; Notify</button>
      </div>
    </form>
  </div>
</div>
<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function prefillUpload(name) { document.getElementById('docNameField').value = name; openModal('uploadModal'); }
document.querySelectorAll('.overlay').forEach(o=>{ o.addEventListener('click',e=>{if(e.target===o)o.classList.remove('open');}); });
</script>
<?php endif ?>
</body>
</html>
