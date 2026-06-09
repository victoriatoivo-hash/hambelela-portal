<?php

function ensureEmploymentLetterTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS employment_letters (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_id INT UNSIGNED NOT NULL,
      letter_no VARCHAR(60) NOT NULL UNIQUE,
      issued_date DATE NOT NULL,
      title VARCHAR(200) NOT NULL DEFAULT 'Employment Confirmation Letter',
      body_html MEDIUMTEXT NOT NULL,
      responsibilities TEXT NULL,
      status ENUM('draft','published') NOT NULL DEFAULT 'draft',
      download_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
      download_limit TINYINT UNSIGNED NOT NULL DEFAULT 2,
      created_by INT UNSIGNED NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      published_at TIMESTAMP NULL DEFAULT NULL,
      FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    employmentLetterEnsureColumn($db, 'responsibilities', "ALTER TABLE employment_letters ADD COLUMN responsibilities TEXT NULL AFTER body_html");
}

function employmentLetterEnsureColumn(PDO $db, string $column, string $ddl): void {
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM employment_letters LIKE ?");
        $stmt->execute([$column]);
        if (!$stmt->fetch()) {
            $db->exec($ddl);
        }
    } catch (Throwable $e) {
        // The base table still works if the host does not allow metadata checks.
    }
}

function employmentLetterDefaults(): array {
    return [
        'letter_company_legal_name' => 'Neaco Trading CC',
        'letter_company_trading_name' => 'Hambelela Organic',
        'letter_company_reg' => 'cc/2023/03878',
        'letter_physical_address' => 'Office 3, floor one, Lazarette house, Erf 7173, corner of Julius Nyerere Street and John Muundjua Street, Ausspannplatz, Windhoek, Namibia',
        'letter_email' => 'info@hambelelaorganic.com',
        'letter_phone' => '0856628598',
        'letter_website' => 'www.hambelelaorganic.com',
        'letter_signatory_name' => 'Ms. Victoria Toivo',
        'letter_default_responsibilities' => 'packaging products, packing customer orders, preparing orders for courier, delivery or collection, handling inventory with care, maintaining dispatch records, and ensuring a clean and organised workspace',
    ];
}

function employmentLetterSettings(?PDO $db = null): array {
    $settings = employmentLetterDefaults();
    if (!$db) return $settings;

    $keys = array_keys($settings);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    try {
        $stmt = $db->prepare("SELECT setting_key, setting_val FROM settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        foreach ($stmt->fetchAll() as $row) {
            if (array_key_exists($row['setting_key'], $settings) && trim((string)$row['setting_val']) !== '') {
                $settings[$row['setting_key']] = $row['setting_val'];
            }
        }
    } catch (Throwable $e) {
        return $settings;
    }

    $settings['letter_physical_address'] = str_replace(
        ['Juluis Nyerere street', 'John Muundjua street'],
        ['Julius Nyerere Street', 'John Muundjua Street'],
        $settings['letter_physical_address']
    );

    return $settings;
}

function employmentLetterDate($date): string {
    if (!$date) return 'N/A';
    $time = strtotime((string)$date);
    return $time ? date('d F Y', $time) : 'N/A';
}

function employmentLetterPlain($value, string $fallback = 'N/A'): string {
    $value = trim((string)$value);
    return $value !== '' ? $value : $fallback;
}

function employmentLetterEsc($value, string $fallback = 'N/A'): string {
    return htmlspecialchars(employmentLetterPlain($value, $fallback), ENT_QUOTES, 'UTF-8');
}

function employmentLetterEmployeeFullName(array $employee): string {
    if (!empty($employee['emp_name'])) return trim((string)$employee['emp_name']);
    return trim((string)($employee['first_name'] ?? '') . ' ' . (string)($employee['last_name'] ?? ''));
}

function employmentLetterResponsibilities(array $letter, array $settings): string {
    $responsibilities = trim((string)($letter['responsibilities'] ?? ''));
    if ($responsibilities === '') {
        $responsibilities = trim((string)($letter['letter_responsibilities'] ?? ''));
    }
    if ($responsibilities === '') {
        $responsibilities = $settings['letter_default_responsibilities'];
    }
    return $responsibilities;
}

function buildEmploymentLetterBody(array $employee, string $issuedDate, string $letterNo, string $responsibilities = '', ?array $settings = null): string {
    $settings = $settings ?: employmentLetterDefaults();
    $fullName = employmentLetterEmployeeFullName($employee);
    $jobTitle = employmentLetterPlain($employee['job_title'] ?? '');
    $idNumber = employmentLetterPlain($employee['id_number'] ?? '');
    $startDate = employmentLetterDate($employee['start_date'] ?? '');
    $responsibilities = trim($responsibilities) !== '' ? trim($responsibilities) : $settings['letter_default_responsibilities'];
    $company = $settings['letter_company_legal_name'] . ' T/A ' . $settings['letter_company_trading_name'];

    return '
      <div class="letter-brand">
        <img src="assets/letter/hambelela-logo.jpg" alt="Hambelela Organic">
      </div>

      <p class="letter-date">' . employmentLetterDate($issuedDate) . '</p>
      <p>To whom it may concern,</p>
      <p><strong>RE: EMPLOYMENT CONFIRMATION LETTER</strong></p>
      <p>This letter serves to confirm that ' . employmentLetterEsc($fullName) . ' (ID: ' . employmentLetterEsc($idNumber) . ') is currently employed at ' . employmentLetterEsc($company) . ' as a ' . employmentLetterEsc($jobTitle) . '. She has been employed with the company since ' . employmentLetterEsc($startDate) . '.</p>
      <p>Her responsibilities include, ' . nl2br(employmentLetterEsc($responsibilities)) . '.</p>
      <p>Should you require any further information, please do not hesitate to contact us.</p>
      <p>Yours Sincerely,</p>
      <div class="letter-signature">
        <img src="assets/letter/victoria-signature.jpg" alt="Signature">
        <div>' . employmentLetterEsc($settings['letter_signatory_name']) . '</div>
      </div>
      <div class="letter-footer">
        <div>' . employmentLetterEsc($settings['letter_company_legal_name']) . ' | Registration no: ' . employmentLetterEsc($settings['letter_company_reg']) . '</div>
        <div>Physical Address: ' . employmentLetterEsc($settings['letter_physical_address']) . '</div>
        <div>Email: ' . employmentLetterEsc($settings['letter_email']) . ' | Tel: ' . employmentLetterEsc($settings['letter_phone']) . ' | ' . employmentLetterEsc($settings['letter_website']) . '</div>
      </div>';
}

function renderEmploymentLetterHtml(PDO $db, array $letter): string {
    $settings = employmentLetterSettings($db);
    $body = buildEmploymentLetterBody($letter, $letter['issued_date'] ?? date('Y-m-d'), $letter['letter_no'] ?? '', employmentLetterResponsibilities($letter, $settings), $settings);
    $title = htmlspecialchars($letter['title'] ?? 'Employment Confirmation Letter', ENT_QUOTES, 'UTF-8');
    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . $title . '</title>
<style>
  body{font-family:Arial,sans-serif;background:#f4f6f2;margin:0;padding:32px;color:#111827}
  .page{width:210mm;min-height:297mm;box-sizing:border-box;position:relative;margin:0 auto;background:#fff;padding:42px 58px 136px;border:1px solid #d9e2d0;box-shadow:0 8px 24px rgba(0,0,0,.08)}
  .letter-brand{text-align:center;margin:0 0 52px}
  .letter-brand img{width:310px;max-width:70%;height:auto}
  p{font-size:14px;line-height:1.55;margin:0 0 15px}
  .letter-date{margin-bottom:32px}
  .letter-signature{margin-top:22px}
  .letter-signature img{display:block;width:110px;height:auto;margin:0 0 4px}
  .letter-signature div{font-size:14px}
  .letter-footer{position:absolute;left:40px;right:40px;bottom:22px;border-top:1px solid #777;text-align:center;font-size:11px;line-height:1.25;padding-top:8px;color:#111827}
  @media print{body{background:#fff;padding:0}.page{box-shadow:none;border:0}}
</style>
</head>
<body><div class="page">' . $body . '</div></body>
</html>';
}

function employmentLetterPdfText($text): string {
    $text = str_replace(["\r", "\n"], ' ', (string)$text);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        if ($converted !== false) $text = $converted;
    }
    return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text) . ')';
}

function employmentLetterPdfLine(string $text, float $x, float $y, int $size = 11, string $font = 'F1'): string {
    return "BT /$font $size Tf 1 0 0 1 " . round($x, 2) . ' ' . round($y, 2) . ' Tm ' . employmentLetterPdfText($text) . " Tj ET\n";
}

function employmentLetterPdfCenteredLine(string $text, float $y, int $size = 10, string $font = 'F1'): string {
    $estimated = strlen($text) * $size * 0.48;
    return employmentLetterPdfLine($text, max(40, (595.28 - $estimated) / 2), $y, $size, $font);
}

function employmentLetterWrap(string $text, int $limit = 92): array {
    $words = preg_split('/\s+/', trim($text));
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        if (strlen($candidate) > $limit && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $candidate;
        }
    }
    if ($line !== '') $lines[] = $line;
    return $lines;
}

function employmentLetterPdfParagraph(string $text, float $x, float &$y, int $size = 11, int $limit = 92): string {
    $out = '';
    foreach (employmentLetterWrap($text, $limit) as $line) {
        $out .= employmentLetterPdfLine($line, $x, $y, $size);
        $y -= 15;
    }
    $y -= 7;
    return $out;
}

function employmentLetterJpegObject(string $path): ?array {
    if (!is_file($path)) return null;
    $size = @getimagesize($path);
    if (!$size) return null;
    return [
        'width' => (int)$size[0],
        'height' => (int)$size[1],
        'data' => file_get_contents($path),
    ];
}

function renderEmploymentLetterPdf(PDO $db, array $letter): string {
    $settings = employmentLetterSettings($db);
    $fullName = employmentLetterEmployeeFullName($letter);
    $jobTitle = employmentLetterPlain($letter['job_title'] ?? '');
    $idNumber = employmentLetterPlain($letter['id_number'] ?? '');
    $startDate = employmentLetterDate($letter['start_date'] ?? '');
    $issuedDate = employmentLetterDate($letter['issued_date'] ?? date('Y-m-d'));
    $responsibilities = employmentLetterResponsibilities($letter, $settings);
    $company = $settings['letter_company_legal_name'] . ' T/A ' . $settings['letter_company_trading_name'];

    $logo = employmentLetterJpegObject(__DIR__ . '/../assets/letter/hambelela-logo.jpg');
    $signature = employmentLetterJpegObject(__DIR__ . '/../assets/letter/victoria-signature.jpg');

    $content = '';
    if ($logo) {
        $content .= "q 305 0 0 112 145 703 cm /Im1 Do Q\n";
    }

    $x = 72;
    $y = 585;
    $content .= employmentLetterPdfLine($issuedDate, $x, $y, 11);
    $y -= 42;
    $content .= employmentLetterPdfParagraph('To whom it may concern,', $x, $y, 11);
    $content .= employmentLetterPdfLine('RE: EMPLOYMENT CONFIRMATION LETTER', $x, $y, 11, 'F2');
    $y -= 30;
    $content .= employmentLetterPdfParagraph('This letter serves to confirm that ' . $fullName . ' (ID: ' . $idNumber . ') is currently employed at ' . $company . ' as a ' . $jobTitle . '. She has been employed with the company since ' . $startDate . '.', $x, $y, 11, 91);
    $content .= employmentLetterPdfParagraph('Her responsibilities include, ' . rtrim($responsibilities, '.') . '.', $x, $y, 11, 91);
    $content .= employmentLetterPdfParagraph('Should you require any further information, please do not hesitate to contact us.', $x, $y, 11, 91);
    $content .= employmentLetterPdfParagraph('Yours Sincerely,', $x, $y, 11, 91);
    if ($signature) {
        $content .= "q 95 0 0 38 72 " . round($y - 20, 2) . " cm /Im2 Do Q\n";
    }
    $content .= employmentLetterPdfLine($settings['letter_signatory_name'], $x, $y - 34, 11);
    $content .= "0.6 w 40 106 m 555 106 l S\n";
    $content .= employmentLetterPdfCenteredLine($settings['letter_company_legal_name'] . ' | Registration no: ' . $settings['letter_company_reg'], 91, 9);
    $addressLines = employmentLetterWrap('Physical Address: ' . $settings['letter_physical_address'], 103);
    $footerY = 78;
    foreach ($addressLines as $line) {
        $content .= employmentLetterPdfCenteredLine($line, $footerY, 9);
        $footerY -= 11;
    }
    $content .= employmentLetterPdfCenteredLine('Email: ' . $settings['letter_email'] . ' | Tel: ' . $settings['letter_phone'] . ' | ' . $settings['letter_website'], $footerY, 9);

    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $xobjects = [];
    if ($logo) $xobjects[] = '/Im1 7 0 R';
    if ($signature) $xobjects[] = '/Im2 8 0 R';
    $resources = "<< /Font << /F1 5 0 R /F2 6 0 R >>";
    if ($xobjects) $resources .= ' /XObject << ' . implode(' ', $xobjects) . ' >>';
    $resources .= ' >>';
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources $resources /Contents 4 0 R >>";
    $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
    if ($logo) {
        $objects[] = "<< /Type /XObject /Subtype /Image /Width {$logo['width']} /Height {$logo['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($logo['data']) . " >>\nstream\n{$logo['data']}\nendstream";
    }
    if ($signature) {
        $objects[] = "<< /Type /XObject /Subtype /Image /Width {$signature['width']} /Height {$signature['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($signature['data']) . " >>\nstream\n{$signature['data']}\nendstream";
    }

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[$i + 1] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n$object\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    return $pdf;
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
