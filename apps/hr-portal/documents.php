<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/employment-letter.php';
requireAdmin();
$user = currentUser();
$db   = db();

// Ensure tables exist
$db->exec("CREATE TABLE IF NOT EXISTS company_documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  category ENUM('Policy','HR Guideline','Training','SOP','Contract','Other') DEFAULT 'Policy',
  file_path VARCHAR(500) NOT NULL,
  file_type VARCHAR(20) DEFAULT 'pdf',
  assign_to ENUM('all','specific') DEFAULT 'all',
  uploaded_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS document_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  employee_id INT UNSIGNED NOT NULL,
  UNIQUE KEY doc_emp (document_id,employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS employee_documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  category ENUM('ID Copy','Contract','Agreement','Certificate','Other') DEFAULT 'Other',
  file_path VARCHAR(500) NOT NULL,
  file_type VARCHAR(20) DEFAULT 'pdf',
  uploaded_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

ensureEmploymentLetterTable($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_company_doc') {
        $title     = clean($_POST['doc_title'] ?? '');
        $category  = $_POST['doc_category'] ?? 'Policy';
        $assign_to = $_POST['assign_to'] ?? 'all';
        $empIds    = $_POST['employee_ids'] ?? [];

        if ($title && isset($_FILES['doc_file']) && $_FILES['doc_file']['error']===0) {
            $ext = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf','jpg','jpeg','png','doc','docx'])) {
                $dir = __DIR__.'/uploads/company-docs/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'doc_'.time().'_'.preg_replace('/[^a-z0-9]/','',$title).'.'.$ext;
                move_uploaded_file($_FILES['doc_file']['tmp_name'], $dir.$fname);
                $path = 'uploads/company-docs/'.$fname;

                $db->prepare("INSERT INTO company_documents (title,category,file_path,file_type,assign_to,uploaded_by) VALUES (?,?,?,?,?,?)")
                   ->execute([$title,$category,$path,$ext,$assign_to,$user['id']]);
                $docId = (int)$db->lastInsertId();

                if ($assign_to === 'specific' && !empty($empIds)) {
                    foreach ($empIds as $eid) {
                        $db->prepare("INSERT IGNORE INTO document_assignments (document_id,employee_id) VALUES (?,?)")->execute([$docId,(int)$eid]);
                    }
                    $notifyEmps = $empIds;
                } else {
                    $notifyEmps = array_column($db->query("SELECT id FROM employees WHERE status='active'")->fetchAll(), 'id');
                }
                foreach ($notifyEmps as $eid) {
                    $eu = $db->prepare("SELECT u.id, u.email AS user_email, u.name AS user_name, e.email AS employee_email, CONCAT(e.first_name,' ',e.last_name) AS employee_name FROM employees e LEFT JOIN users u ON u.employee_id=e.id WHERE e.id=? LIMIT 1"); $eu->execute([$eid]); $eu = $eu->fetch();
                    if ($eu) {
                        if ($eu['id']) {
                            $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'info')")
                               ->execute([$eu['id'],'New Document Available','"'.$title.'" has been uploaded and is available in your documents portal.']);
                        }
                        $toEmail = trim((string)($eu['user_email'] ?: $eu['employee_email']));
                        $toName = $eu['user_name'] ?: $eu['employee_name'];
                        if ($toEmail !== '') emailDocumentUploaded($toEmail, $toName, $title);
                    }
                }
            }
        }
        header('Location: documents.php?msg=uploaded'); exit;
    }

    if ($action === 'upload_emp_doc') {
        $emp_id   = (int)($_POST['emp_doc_employee'] ?? 0);
        $title    = clean($_POST['emp_doc_title'] ?? '');
        $category = $_POST['emp_doc_category'] ?? 'Other';

        if ($emp_id && $title && isset($_FILES['emp_doc_file']) && $_FILES['emp_doc_file']['error']===0) {
            $ext = strtolower(pathinfo($_FILES['emp_doc_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf','jpg','jpeg','png'])) {
                $dir = __DIR__.'/uploads/employee-docs/'.$emp_id.'/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = time().'_'.preg_replace('/[^a-z0-9]/','',$title).'.'.$ext;
                move_uploaded_file($_FILES['emp_doc_file']['tmp_name'], $dir.$fname);
                $path = 'uploads/employee-docs/'.$emp_id.'/'.$fname;

                $db->prepare("INSERT INTO employee_documents (employee_id,title,category,file_path,file_type,uploaded_by) VALUES (?,?,?,?,?,?)")
                   ->execute([$emp_id,$title,$category,$path,$ext,$user['id']]);

                $eu = $db->prepare("SELECT u.id, u.email AS user_email, u.name AS user_name, e.email AS employee_email, CONCAT(e.first_name,' ',e.last_name) AS employee_name FROM employees e LEFT JOIN users u ON u.employee_id=e.id WHERE e.id=? LIMIT 1"); $eu->execute([$emp_id]); $eu = $eu->fetch();
                if ($eu) {
                    if ($eu['id']) {
                        $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'info')")
                           ->execute([$eu['id'],'Document Uploaded','A new document "'.$title.'" has been added to your profile.']);
                    }
                    $toEmail = trim((string)($eu['user_email'] ?: $eu['employee_email']));
                    $toName = $eu['user_name'] ?: $eu['employee_name'];
                    if ($toEmail !== '') emailDocumentUploaded($toEmail, $toName, $title);
                }
            }
        }
        header('Location: documents.php?msg=emp_uploaded'); exit;
    }

    if ($action === 'create_employment_letter') {
        $emp_id = (int)($_POST['letter_employee'] ?? 0);
        $issuedDate = $_POST['issued_date'] ?? date('Y-m-d');
        $notes = trim((string)($_POST['letter_notes'] ?? ''));
        $status = !empty($_POST['publish_now']) ? 'published' : 'draft';

        if ($emp_id) {
            $stmt = $db->prepare("SELECT * FROM employees WHERE id=? LIMIT 1");
            $stmt->execute([$emp_id]);
            $employee = $stmt->fetch();

            if ($employee) {
                $letterNo = 'ECL-' . date('Ymd', strtotime($issuedDate ?: date('Y-m-d'))) . '-' . preg_replace('/[^A-Za-z0-9]/', '', (string)$employee['emp_number']) . '-' . str_pad((string)$emp_id, 4, '0', STR_PAD_LEFT);
                $baseLetterNo = $letterNo;
                $suffix = 1;
                while (true) {
                    $exists = $db->prepare("SELECT id FROM employment_letters WHERE letter_no=? LIMIT 1");
                    $exists->execute([$letterNo]);
                    if (!$exists->fetch()) break;
                    $suffix++;
                    $letterNo = $baseLetterNo . '-' . $suffix;
                }

                $bodyHtml = buildEmploymentLetterBody($employee, $issuedDate, $letterNo, $notes);
                $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;
                $db->prepare("INSERT INTO employment_letters (employee_id,letter_no,issued_date,title,body_html,status,download_limit,created_by,published_at) VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([$emp_id,$letterNo,$issuedDate,'Employment Confirmation Letter',$bodyHtml,$status,2,$user['id'],$publishedAt]);

                if ($status === 'published') {
                    notifyEmploymentLetterPublished($db, $emp_id);
                }
            }
        }
        header('Location: documents.php?tab=letters&msg=letter_created'); exit;
    }

    if ($action === 'publish_employment_letter') {
        $id = (int)($_POST['letter_id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare("SELECT employee_id,status FROM employment_letters WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $letter = $stmt->fetch();
            if ($letter && $letter['status'] !== 'published') {
                $db->prepare("UPDATE employment_letters SET status='published', published_at=NOW() WHERE id=?")->execute([$id]);
                notifyEmploymentLetterPublished($db, (int)$letter['employee_id']);
            }
        }
        header('Location: documents.php?tab=letters&msg=letter_published'); exit;
    }

    if ($action === 'delete_employment_letter') {
        $id = (int)($_POST['letter_id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM employment_letters WHERE id=?")->execute([$id]);
        }
        header('Location: documents.php?tab=letters&msg=letter_deleted'); exit;
    }

    if ($action === 'delete_doc') {
        $id   = (int)($_POST['doc_id'] ?? 0);
        $type = $_POST['doc_type'] ?? 'company';
        if ($id) {
            if ($type === 'company') {
                $doc = $db->prepare("SELECT file_path FROM company_documents WHERE id=?"); $doc->execute([$id]); $doc = $doc->fetch();
                if ($doc && file_exists(__DIR__.'/'.$doc['file_path'])) unlink(__DIR__.'/'.$doc['file_path']);
                $db->prepare("DELETE FROM document_assignments WHERE document_id=?")->execute([$id]);
                $db->prepare("DELETE FROM company_documents WHERE id=?")->execute([$id]);
            } else {
                $doc = $db->prepare("SELECT file_path FROM employee_documents WHERE id=?"); $doc->execute([$id]); $doc = $doc->fetch();
                if ($doc && file_exists(__DIR__.'/'.$doc['file_path'])) unlink(__DIR__.'/'.$doc['file_path']);
                $db->prepare("DELETE FROM employee_documents WHERE id=?")->execute([$id]);
            }
        }
        header('Location: documents.php?msg=deleted'); exit;
    }
}

$companyDocs = $db->query("SELECT cd.*, u.name as uploader FROM company_documents cd LEFT JOIN users u ON u.id=cd.uploaded_by ORDER BY cd.created_at DESC")->fetchAll();
$empDocs     = $db->query("SELECT ed.*, CONCAT(e.first_name,' ',e.last_name) as emp_name, e.emp_number FROM employee_documents ed JOIN employees e ON e.id=ed.employee_id ORDER BY ed.created_at DESC")->fetchAll();
$letters     = $db->query("SELECT el.*, CONCAT(e.first_name,' ',e.last_name) as emp_name, e.emp_number FROM employment_letters el JOIN employees e ON e.id=el.employee_id ORDER BY el.created_at DESC")->fetchAll();
$employees   = $db->query("SELECT id, CONCAT(first_name,' ',last_name) as name, emp_number FROM employees WHERE status='active' ORDER BY first_name")->fetchAll();

$categories    = ['Policy','HR Guideline','Training','SOP','Contract','Other'];
$empCategories = ['ID Copy','Contract','Agreement','Certificate','Other'];
$msg = $_GET['msg'] ?? '';
$tab = $_GET['tab'] ?? 'company';
$currentPage = 'documents.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Documents — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="topbar-title">Documents</div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-secondary" onclick="openModal('letterModal')"><i class="fa-solid fa-file-signature"></i> Create Confirmation Letter</button>
      <button class="btn btn-secondary" onclick="openModal('empDocModal')"><i class="fa-solid fa-user"></i> Upload to Employee</button>
      <button class="btn btn-primary" onclick="openModal('companyDocModal')"><i class="fa-solid fa-upload"></i> Upload Company Document</button>
    </div>
  </div>
  <div class="content">

    <?php if ($msg==='uploaded'): ?><div class="toast"><i class="fa-solid fa-check"></i> Document uploaded.</div>
    <?php elseif ($msg==='emp_uploaded'): ?><div class="toast"><i class="fa-solid fa-check"></i> Employee document uploaded.</div>
    <?php elseif ($msg==='letter_created'): ?><div class="toast"><i class="fa-solid fa-check"></i> Employment confirmation letter created.</div>
    <?php elseif ($msg==='letter_published'): ?><div class="toast"><i class="fa-solid fa-check"></i> Employment confirmation letter published.</div>
    <?php elseif ($msg==='letter_deleted'): ?><div class="toast error"><i class="fa-solid fa-trash"></i> Employment confirmation letter deleted.</div>
    <?php elseif ($msg==='deleted'): ?><div class="toast error"><i class="fa-solid fa-trash"></i> Document deleted.</div>
    <?php endif ?>

    <div style="display:flex;gap:4px;margin-bottom:20px;background:var(--sidebar-hover);padding:4px;border-radius:10px;width:fit-content">
      <a href="?tab=company" style="padding:8px 18px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;<?=$tab==='company'?'background:#fff;color:var(--green);box-shadow:0 1px 4px rgba(0,0,0,.1)':'color:var(--text-mid)'?>"><i class="fa-solid fa-building"></i> Company Documents</a>
      <a href="?tab=employee" style="padding:8px 18px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;<?=$tab==='employee'?'background:#fff;color:var(--green);box-shadow:0 1px 4px rgba(0,0,0,.1)':'color:var(--text-mid)'?>"><i class="fa-solid fa-user"></i> Employee Documents</a>
      <a href="?tab=letters" style="padding:8px 18px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;<?=$tab==='letters'?'background:#fff;color:var(--green);box-shadow:0 1px 4px rgba(0,0,0,.1)':'color:var(--text-mid)'?>"><i class="fa-solid fa-file-signature"></i> Employment Letters</a>
    </div>

    <?php if ($tab === 'company'): ?>
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-folder-open" style="color:var(--green)"></i> Company Documents (<?=count($companyDocs)?>)</div></div>
      <?php if (empty($companyDocs)): ?>
      <div class="empty-state"><i class="fa-solid fa-folder-open"></i><div>No company documents uploaded yet.</div></div>
      <?php else: ?>
      <table>
        <thead><tr><th>Document</th><th>Category</th><th>Assigned To</th><th>Uploaded</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($companyDocs as $d):
          $icon = in_array($d['file_type'],['jpg','jpeg','png']) ? 'fa-solid fa-file-image' : 'fa-solid fa-file-pdf';
          $icol = in_array($d['file_type'],['jpg','jpeg','png']) ? 'var(--blue)' : 'var(--red)';
        ?>
        <tr>
          <td><div style="display:flex;align-items:center;gap:10px"><i class="<?=$icon?>" style="font-size:20px;color:<?=$icol?>"></i><span style="font-weight:600"><?=htmlspecialchars($d['title'])?></span></div></td>
          <td><span class="badge badge-blue"><?=htmlspecialchars($d['category'])?></span></td>
          <td><?=$d['assign_to']==='all'?'<span class="badge badge-green">All Employees</span>':'<span class="badge badge-amber">Specific</span>'?></td>
          <td style="font-size:12px"><?=date('d M Y',strtotime($d['created_at']))?></td>
          <td style="white-space:nowrap">
            <a href="<?=htmlspecialchars($d['file_path'])?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fa-solid fa-eye"></i> View</a>
            <a href="<?=htmlspecialchars($d['file_path'])?>" download class="btn btn-secondary btn-sm"><i class="fa-solid fa-download"></i></a>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
              <input type="hidden" name="action" value="delete_doc">
              <input type="hidden" name="doc_id" value="<?=$d['id']?>">
              <input type="hidden" name="doc_type" value="company">
              <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </div>

    <?php elseif ($tab === 'employee'): ?>
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-user" style="color:var(--green)"></i> Employee Personal Documents (<?=count($empDocs)?>)</div></div>
      <?php if (empty($empDocs)): ?>
      <div class="empty-state"><i class="fa-solid fa-file-lines"></i><div>No employee documents uploaded yet.</div></div>
      <?php else: ?>
      <table>
        <thead><tr><th>Employee</th><th>Document</th><th>Category</th><th>Uploaded</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($empDocs as $d):
          $icon = in_array($d['file_type'],['jpg','jpeg','png']) ? 'fa-solid fa-file-image' : 'fa-solid fa-file-pdf';
          $icol = in_array($d['file_type'],['jpg','jpeg','png']) ? 'var(--blue)' : 'var(--red)';
        ?>
        <tr>
          <td><div style="font-weight:600"><?=htmlspecialchars($d['emp_name'])?></div><div style="font-size:11px;color:var(--text-mid)"><?=htmlspecialchars($d['emp_number'])?></div></td>
          <td><div style="display:flex;align-items:center;gap:8px"><i class="<?=$icon?>" style="font-size:18px;color:<?=$icol?>"></i><span style="font-weight:500"><?=htmlspecialchars($d['title'])?></span></div></td>
          <td><span class="badge badge-blue"><?=htmlspecialchars($d['category'])?></span></td>
          <td style="font-size:12px"><?=date('d M Y',strtotime($d['created_at']))?></td>
          <td style="white-space:nowrap">
            <a href="<?=htmlspecialchars($d['file_path'])?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fa-solid fa-eye"></i> View</a>
            <a href="<?=htmlspecialchars($d['file_path'])?>" download class="btn btn-secondary btn-sm"><i class="fa-solid fa-download"></i></a>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
              <input type="hidden" name="action" value="delete_doc">
              <input type="hidden" name="doc_id" value="<?=$d['id']?>">
              <input type="hidden" name="doc_type" value="employee">
              <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-file-signature" style="color:var(--green)"></i> Employment Confirmation Letters (<?=count($letters)?>)</div></div>
      <?php if (empty($letters)): ?>
      <div class="empty-state"><i class="fa-solid fa-file-signature"></i><div>No employment confirmation letters created yet.</div></div>
      <?php else: ?>
      <table>
        <thead><tr><th>Employee</th><th>Letter</th><th>Issued Date</th><th>Status</th><th>Downloads</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($letters as $l): ?>
        <tr>
          <td><div style="font-weight:600"><?=htmlspecialchars($l['emp_name'])?></div><div style="font-size:11px;color:var(--text-mid)"><?=htmlspecialchars($l['emp_number'])?></div></td>
          <td><div style="font-weight:600"><?=htmlspecialchars($l['title'])?></div><div style="font-size:11px;color:var(--text-mid)"><?=htmlspecialchars($l['letter_no'])?></div></td>
          <td style="font-size:12px"><?=date('d M Y',strtotime($l['issued_date']))?></td>
          <td><?=$l['status']==='published'?'<span class="badge badge-green">Published</span>':'<span class="badge badge-amber">Draft</span>'?></td>
          <td style="font-size:12px"><?=((int)$l['download_count'])?> / <?=((int)$l['download_limit'])?></td>
          <td style="white-space:nowrap">
            <a href="download-employment-letter.php?id=<?=$l['id']?>&preview=1" target="_blank" class="btn btn-secondary btn-sm"><i class="fa-solid fa-eye"></i> Preview</a>
            <a href="download-employment-letter.php?id=<?=$l['id']?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-download"></i></a>
            <?php if ($l['status'] !== 'published'): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Publish this letter to the employee?')">
              <input type="hidden" name="action" value="publish_employment_letter">
              <input type="hidden" name="letter_id" value="<?=$l['id']?>">
              <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-paper-plane"></i> Publish</button>
            </form>
            <?php endif ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this letter?')">
              <input type="hidden" name="action" value="delete_employment_letter">
              <input type="hidden" name="letter_id" value="<?=$l['id']?>">
              <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <?php endif ?>
    </div>
    <?php endif ?>
  </div>
</div>

<!-- UPLOAD COMPANY DOCUMENT MODAL -->
<div class="overlay" id="companyDocModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-upload"></i> Upload Company Document</div><button class="modal-close" onclick="closeModal('companyDocModal')"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_company_doc">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full"><label class="form-label">Document Title</label><input class="form-input" name="doc_title" placeholder="e.g. Leave Policy 2026" required></div>
          <div class="form-group">
            <label class="form-label">Category</label>
            <select class="form-select" name="doc_category">
              <?php foreach ($categories as $c): ?><option value="<?=$c?>"><?=$c?></option><?php endforeach ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Assign To</label>
            <select class="form-select" name="assign_to" id="assignTo" onchange="toggleSpecific()">
              <option value="all">All Employees</option>
              <option value="specific">Specific Employees</option>
            </select>
          </div>
          <div class="form-group full" id="specificEmps" style="display:none">
            <label class="form-label">Select Employees</label>
            <div style="border:1px solid var(--border);border-radius:8px;padding:10px;max-height:150px;overflow-y:auto">
              <?php foreach ($employees as $e): ?>
              <label style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;cursor:pointer">
                <input type="checkbox" name="employee_ids[]" value="<?=$e['id']?>">
                <?=htmlspecialchars($e['name'])?> (<?=htmlspecialchars($e['emp_number'])?>)
              </label>
              <?php endforeach ?>
            </div>
          </div>
          <div class="form-group full"><label class="form-label">File (PDF, JPG, PNG, DOC, DOCX)</label><input class="form-input" type="file" name="doc_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('companyDocModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload"></i> Upload</button>
      </div>
    </form>
  </div>
</div>

<!-- UPLOAD EMPLOYEE DOCUMENT MODAL -->
<div class="overlay" id="empDocModal">
  <div class="modal" style="max-width:460px">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-user"></i> Upload Employee Document</div><button class="modal-close" onclick="closeModal('empDocModal')"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_emp_doc">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full">
            <label class="form-label">Employee</label>
            <select class="form-select" name="emp_doc_employee" required>
              <option value="">Select employee...</option>
              <?php foreach ($employees as $e): ?><option value="<?=$e['id']?>"><?=htmlspecialchars($e['name'])?> (<?=htmlspecialchars($e['emp_number'])?>)</option><?php endforeach ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Document Title</label><input class="form-input" name="emp_doc_title" placeholder="e.g. Employment Contract" required></div>
          <div class="form-group">
            <label class="form-label">Category</label>
            <select class="form-select" name="emp_doc_category">
              <?php foreach ($empCategories as $c): ?><option value="<?=$c?>"><?=$c?></option><?php endforeach ?>
            </select>
          </div>
          <div class="form-group full"><label class="form-label">File (PDF, JPG, PNG)</label><input class="form-input" type="file" name="emp_doc_file" accept=".pdf,.jpg,.jpeg,.png" required></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('empDocModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload"></i> Upload</button>
      </div>
    </form>
  </div>
</div>

<!-- CREATE EMPLOYMENT CONFIRMATION LETTER MODAL -->
<div class="overlay" id="letterModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header"><div class="modal-title"><i class="fa-solid fa-file-signature"></i> Employment Confirmation Letter</div><button class="modal-close" onclick="closeModal('letterModal')"><i class="fa-solid fa-xmark"></i></button></div>
    <form method="POST">
      <input type="hidden" name="action" value="create_employment_letter">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full">
            <label class="form-label">Employee</label>
            <select class="form-select" name="letter_employee" required>
              <option value="">Select employee...</option>
              <?php foreach ($employees as $e): ?><option value="<?=$e['id']?>"><?=htmlspecialchars($e['name'])?> (<?=htmlspecialchars($e['emp_number'])?>)</option><?php endforeach ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Letter Date</label>
            <input class="form-input" type="date" name="issued_date" value="<?=date('Y-m-d')?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Employee Downloads</label>
            <input class="form-input" value="2 downloads allowed" disabled>
          </div>
          <div class="form-group full">
            <label class="form-label">Additional Note (Optional)</label>
            <textarea class="form-input" name="letter_notes" rows="3" placeholder="Optional note to include in the letter"></textarea>
          </div>
          <div class="form-group full">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
              <input type="checkbox" name="publish_now" value="1" checked>
              Publish to employee immediately
            </label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('letterModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-file-circle-plus"></i> Create Letter</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
function toggleSpecific() {
  document.getElementById('specificEmps').style.display = document.getElementById('assignTo').value==='specific' ? 'block' : 'none';
}
document.querySelectorAll('.overlay').forEach(function(o) {
  o.addEventListener('click', function(e) { if(e.target===o) o.classList.remove('open'); });
});
</script>
</body>
</html>
