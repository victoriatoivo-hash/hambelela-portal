<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/employment-letter.php';
requireLogin();
$user = currentUser();
if ($user['role'] !== 'employee') { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$db    = db();
$empId = (int)($user['emp_id'] ?? 0);
ensureEmploymentLetterTable($db);

// Company documents assigned to this employee or all
$companyDocs = $db->prepare("
    SELECT cd.* FROM company_documents cd
    WHERE cd.assign_to='all'
    OR cd.id IN (SELECT document_id FROM document_assignments WHERE employee_id=?)
    ORDER BY cd.created_at DESC");
$companyDocs->execute([$empId]);
$companyDocs = $companyDocs->fetchAll();

// Personal documents
$myDocs = $db->prepare("SELECT * FROM employee_documents WHERE employee_id=? ORDER BY created_at DESC");
$myDocs->execute([$empId]);
$myDocs = $myDocs->fetchAll();

$letters = $db->prepare("SELECT * FROM employment_letters WHERE employee_id=? AND status='published' ORDER BY issued_date DESC, created_at DESC");
$letters->execute([$empId]);
$letters = $letters->fetchAll();

$tab = $_GET['tab'] ?? 'company';
$currentPage = 'my-documents.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Documents — Hambelela HR</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="includes/styles.css">
</head>
<body>
<?php include __DIR__ . '/includes/emp-sidebar.php'; ?>
<div class="main">
  <div class="topbar"><div class="topbar-title">My Documents</div></div>
  <div class="content">

    <!-- Tabs -->
    <div style="display:flex;gap:4px;margin-bottom:20px;background:var(--sidebar-hover);padding:4px;border-radius:10px;width:fit-content">
      <a href="?tab=company" style="padding:8px 18px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;<?=$tab==='company'?'background:#fff;color:var(--green);box-shadow:0 1px 4px rgba(0,0,0,.1)':'color:var(--text-mid)'?>"><i class="fa-solid fa-building"></i> Company Documents</a>
      <a href="?tab=personal" style="padding:8px 18px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;<?=$tab==='personal'?'background:#fff;color:var(--green);box-shadow:0 1px 4px rgba(0,0,0,.1)':'color:var(--text-mid)'?>"><i class="fa-solid fa-file-lines"></i> My Documents</a>
      <a href="?tab=letters" style="padding:8px 18px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;<?=$tab==='letters'?'background:#fff;color:var(--green);box-shadow:0 1px 4px rgba(0,0,0,.1)':'color:var(--text-mid)'?>"><i class="fa-solid fa-file-signature"></i> Employment Letters</a>
    </div>

    <?php if ($tab === 'company'): ?>
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-folder-open" style="color:var(--green)"></i> Company Policies &amp; Documents</div></div>
      <?php if (empty($companyDocs)): ?>
      <div class="empty-state"><i class="fa-solid fa-folder-open"></i><div>No company documents available yet.</div></div>
      <?php else: ?>
      <div style="padding:10px 20px">
        <?php foreach ($companyDocs as $d):
          $icon = in_array($d['file_type'],['jpg','jpeg','png']) ? 'fa-solid fa-file-image' : 'fa-solid fa-file-pdf';
          $icol = in_array($d['file_type'],['jpg','jpeg','png']) ? 'var(--blue)' : 'var(--red)';
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--border)">
          <div style="display:flex;align-items:center;gap:14px">
            <i class="<?=$icon?>" style="font-size:28px;color:<?=$icol?>"></i>
            <div>
              <div style="font-weight:600;font-size:14px"><?=htmlspecialchars($d['title'])?></div>
              <div style="font-size:11px;color:var(--text-mid);margin-top:2px">
                <span class="badge badge-blue" style="font-size:10px"><?=htmlspecialchars($d['category'])?></span>
                &nbsp;<?=date('d M Y',strtotime($d['created_at']))?>
              </div>
            </div>
          </div>
          <div style="display:flex;gap:8px">
            <a href="<?=htmlspecialchars($d['file_path'])?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fa-solid fa-eye"></i> View</a>
            <a href="<?=htmlspecialchars($d['file_path'])?>" download class="btn btn-secondary btn-sm"><i class="fa-solid fa-download"></i> Download</a>
          </div>
        </div>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </div>

    <?php elseif ($tab === 'personal'): ?>
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-file-lines" style="color:var(--green)"></i> My Personal Documents</div></div>
      <?php if (empty($myDocs)): ?>
      <div class="empty-state"><i class="fa-solid fa-file-lines"></i><div>No personal documents uploaded yet. Contact your manager to upload documents to your profile.</div></div>
      <?php else: ?>
      <div style="padding:10px 20px">
        <?php foreach ($myDocs as $d):
          $icon = in_array($d['file_type'],['jpg','jpeg','png']) ? 'fa-solid fa-file-image' : 'fa-solid fa-file-pdf';
          $icol = in_array($d['file_type'],['jpg','jpeg','png']) ? 'var(--blue)' : 'var(--red)';
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--border)">
          <div style="display:flex;align-items:center;gap:14px">
            <i class="<?=$icon?>" style="font-size:28px;color:<?=$icol?>"></i>
            <div>
              <div style="font-weight:600;font-size:14px"><?=htmlspecialchars($d['title'])?></div>
              <div style="font-size:11px;color:var(--text-mid);margin-top:2px">
                <span class="badge badge-blue" style="font-size:10px"><?=htmlspecialchars($d['category'])?></span>
                &nbsp;<?=date('d M Y',strtotime($d['created_at']))?>
              </div>
            </div>
          </div>
          <div style="display:flex;gap:8px">
            <a href="<?=htmlspecialchars($d['file_path'])?>" target="_blank" class="btn btn-secondary btn-sm"><i class="fa-solid fa-eye"></i> View</a>
            <a href="<?=htmlspecialchars($d['file_path'])?>" download class="btn btn-secondary btn-sm"><i class="fa-solid fa-download"></i> Download</a>
            <a href="<?=htmlspecialchars($d['file_path'])?>" target="_blank" onclick="window.print()" class="btn btn-secondary btn-sm"><i class="fa-solid fa-print"></i> Print</a>
          </div>
        </div>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fa-solid fa-file-signature" style="color:var(--green)"></i> Employment Confirmation Letters</div></div>
      <?php if (empty($letters)): ?>
      <div class="empty-state"><i class="fa-solid fa-file-signature"></i><div>No employment confirmation letters have been published to you yet.</div></div>
      <?php else: ?>
      <div style="padding:10px 20px">
        <?php foreach ($letters as $l):
          $remaining = max(0, (int)$l['download_limit'] - (int)$l['download_count']);
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--border)">
          <div style="display:flex;align-items:center;gap:14px">
            <i class="fa-solid fa-file-signature" style="font-size:28px;color:var(--green)"></i>
            <div>
              <div style="font-weight:600;font-size:14px"><?=htmlspecialchars($l['title'])?></div>
              <div style="font-size:11px;color:var(--text-mid);margin-top:2px">
                <?=htmlspecialchars($l['letter_no'])?> &nbsp;|&nbsp; Issued <?=date('d M Y',strtotime($l['issued_date']))?> &nbsp;|&nbsp; Downloads left: <?=$remaining?>
              </div>
            </div>
          </div>
          <div style="display:flex;gap:8px">
            <?php if ($remaining > 0): ?>
            <a href="download-employment-letter.php?id=<?=$l['id']?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-download"></i> Download</a>
            <?php else: ?>
            <button class="btn btn-secondary btn-sm" disabled><i class="fa-solid fa-lock"></i> Limit Reached</button>
            <?php endif ?>
          </div>
        </div>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </div>
    <?php endif ?>

  </div>
</div>
</body>
</html>
