<?php

function ensureEmploymentLetterTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS employment_letters (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_id INT UNSIGNED NOT NULL,
      letter_no VARCHAR(60) NOT NULL UNIQUE,
      issued_date DATE NOT NULL,
      title VARCHAR(200) NOT NULL DEFAULT 'Employment Confirmation Letter',
      body_html MEDIUMTEXT NOT NULL,
      status ENUM('draft','published') NOT NULL DEFAULT 'draft',
      download_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
      download_limit TINYINT UNSIGNED NOT NULL DEFAULT 2,
      created_by INT UNSIGNED NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      published_at TIMESTAMP NULL DEFAULT NULL,
      FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function employmentLetterDate($date): string {
    if (!$date) return 'N/A';
    $time = strtotime((string)$date);
    return $time ? date('d F Y', $time) : 'N/A';
}

function employmentLetterText($value, string $fallback = 'N/A'): string {
    $value = trim((string)$value);
    return $value !== '' ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $fallback;
}

function buildEmploymentLetterBody(array $employee, string $issuedDate, string $letterNo, string $notes = ''): string {
    $fullName = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
    $employmentType = str_replace('_', ' ', (string)($employee['employment_type'] ?? ''));
    $notesHtml = trim($notes) !== ''
        ? '<p><strong>Additional note:</strong> ' . nl2br(htmlspecialchars(trim($notes), ENT_QUOTES, 'UTF-8')) . '</p>'
        : '';

    return '
      <div class="letter-head">
        <h1>Hambelela Organic</h1>
        <p>Employment Confirmation Letter</p>
      </div>
      <div class="letter-meta">
        <div><strong>Date:</strong> ' . employmentLetterDate($issuedDate) . '</div>
        <div><strong>Reference:</strong> ' . htmlspecialchars($letterNo, ENT_QUOTES, 'UTF-8') . '</div>
      </div>
      <p>To whom it may concern,</p>
      <p>This letter serves to confirm that <strong>' . employmentLetterText($fullName) . '</strong>, ID number <strong>' . employmentLetterText($employee['id_number'] ?? '') . '</strong>, is employed by <strong>Hambelela Organic</strong>.</p>
      <table class="details">
        <tr><th>Employee Name</th><td>' . employmentLetterText($employee['first_name'] ?? '') . '</td></tr>
        <tr><th>Employee Surname</th><td>' . employmentLetterText($employee['last_name'] ?? '') . '</td></tr>
        <tr><th>ID Number</th><td>' . employmentLetterText($employee['id_number'] ?? '') . '</td></tr>
        <tr><th>Employee Number</th><td>' . employmentLetterText($employee['emp_number'] ?? '') . '</td></tr>
        <tr><th>Position</th><td>' . employmentLetterText($employee['job_title'] ?? '') . '</td></tr>
        <tr><th>Department</th><td>' . employmentLetterText($employee['department'] ?? '') . '</td></tr>
        <tr><th>Employment Type</th><td>' . employmentLetterText(ucwords($employmentType)) . '</td></tr>
        <tr><th>Employed Since</th><td>' . employmentLetterDate($employee['start_date'] ?? '') . '</td></tr>
      </table>
      <p>According to our records, the employee remains listed on the Hambelela Organic HR Portal as at the date of issue shown above.</p>
      ' . $notesHtml . '
      <p>This confirmation letter is generated from the Hambelela Organic HR Portal and is issued for employment confirmation purposes.</p>
      <div class="signature">
        <div class="line"></div>
        <strong>HR Administration</strong><br>
        Hambelela Organic
      </div>';
}

function renderEmploymentLetterHtml(array $letter): string {
    $title = htmlspecialchars($letter['title'] ?? 'Employment Confirmation Letter', ENT_QUOTES, 'UTF-8');
    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . $title . '</title>
<style>
  body{font-family:Arial,sans-serif;background:#f4f6f2;margin:0;padding:32px;color:#1f2933}
  .page{max-width:760px;margin:0 auto;background:#fff;padding:46px 54px;border:1px solid #d9e2d0;box-shadow:0 8px 24px rgba(0,0,0,.08)}
  .letter-head{text-align:center;border-bottom:3px solid #2d6a4f;padding-bottom:18px;margin-bottom:24px}
  .letter-head h1{margin:0;color:#2d6a4f;text-transform:uppercase;letter-spacing:.06em;font-size:24px}
  .letter-head p{margin:6px 0 0;color:#52635a;font-size:13px;text-transform:uppercase;letter-spacing:.08em}
  .letter-meta{display:flex;justify-content:space-between;gap:20px;margin:0 0 28px;font-size:13px;color:#445}
  p{font-size:14px;line-height:1.7}
  .details{width:100%;border-collapse:collapse;margin:22px 0 24px;font-size:13px}
  .details th{width:34%;text-align:left;background:#f0f7f2;color:#2d6a4f;padding:10px;border:1px solid #d9e2d0}
  .details td{padding:10px;border:1px solid #d9e2d0}
  .signature{margin-top:48px;font-size:14px}
  .line{width:220px;border-top:1px solid #334155;margin-bottom:10px}
  @media print{body{background:#fff;padding:0}.page{box-shadow:none;border:0}}
</style>
</head>
<body><div class="page">' . ($letter['body_html'] ?? '') . '</div></body>
</html>';
}

function notifyEmploymentLetterPublished(PDO $db, int $employeeId): void {
    $eu = $db->prepare("SELECT u.id, u.email AS user_email, u.name AS user_name, e.email AS employee_email, CONCAT(e.first_name,' ',e.last_name) AS employee_name FROM employees e LEFT JOIN users u ON u.employee_id=e.id WHERE e.id=? LIMIT 1");
    $eu->execute([$employeeId]);
    $eu = $eu->fetch();
    if (!$eu) return;

    if (!empty($eu['id'])) {
        $db->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,'info')")
           ->execute([$eu['id'], 'Employment Confirmation Letter', 'Your employment confirmation letter has been published and is available in My Documents.']);
    }

    $toEmail = trim((string)($eu['user_email'] ?: $eu['employee_email']));
    $toName = $eu['user_name'] ?: $eu['employee_name'];
    if ($toEmail !== '' && function_exists('emailDocumentUploaded')) {
        emailDocumentUploaded($toEmail, $toName, 'Employment Confirmation Letter');
    }
}
