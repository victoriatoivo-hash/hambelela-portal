<?php
// ============================================================
//  Hambelela Organic HR Portal — Email Notifications
//  sendHREmail($to_email, $to_name, $subject, $body)
// ============================================================

function sendHREmail($to_email, $to_name, $subject, $body_html) {
    if (!$to_email) return false;

    $from_email = 'hr@hambelelaorganic.com';
    $from_name  = 'Hambelela Organic HR';

    $logo_url = 'https://hr.hambelelaorganic.com/uploads/logo_email.png';

    $full_html = '<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
  body{font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:0}
  .wrap{max-width:560px;margin:30px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.08)}
  .header{background:#2d6a4f;padding:28px 30px;text-align:center}
  .header h1{color:#fff;font-size:18px;margin:0;letter-spacing:.05em;text-transform:uppercase;font-family:Arial,sans-serif}
  .header p{color:rgba(255,255,255,.7);font-size:12px;margin:4px 0 0}
  .body{padding:28px 30px}
  .body h2{color:#2d6a4f;font-size:16px;margin-top:0}
  .body p{color:#444;font-size:14px;line-height:1.6}
  .highlight{background:#f0f9f4;border-left:4px solid #2d6a4f;padding:12px 16px;border-radius:0 8px 8px 0;margin:16px 0;font-size:14px;color:#2d6a4f;font-weight:600}
  .footer{background:#f9f9f9;padding:16px 30px;text-align:center;font-size:11px;color:#999;border-top:1px solid #eee}
  .btn{display:inline-block;padding:10px 24px;background:#2d6a4f;color:#fff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;margin-top:16px}
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>Hambelela Organic</h1>
    <p>HR Portal Notification</p>
  </div>
  <div class="body">
    <h2>Hello, ' . htmlspecialchars($to_name) . '</h2>
    ' . $body_html . '
  </div>
  <div class="footer">
    This is an automated notification from the Hambelela Organic HR Portal.<br>
    Please do not reply to this email. For queries, contact your HR administrator.
  </div>
</div>
</body></html>';

    if (sendHREmailViaSMTP($to_email, $to_name, $subject, $full_html, $from_email, $from_name)) {
        return true;
    }

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $from_name <$from_email>\r\n";
    $headers .= "Reply-To: $from_email\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    return mail($to_email, $subject, $full_html, $headers);
}

function getHRSMTPConfig() {
    $configFile = dirname(__DIR__) . '/smtp-config.php';
    if (!is_file($configFile)) return null;

    $config = require $configFile;
    if (!is_array($config) || empty($config['host'])) return null;

    return array_merge([
        'host' => '',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls',
        'from_email' => 'hr@hambelelaorganic.com',
        'from_name' => 'Hambelela Organic HR',
        'reply_to' => 'hr@hambelelaorganic.com',
        'timeout' => 20,
    ], $config);
}

function smtpRead($socket) {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    return $response;
}

function smtpCommand($socket, $command, array $expectedCodes) {
    if ($command !== '') {
        fwrite($socket, $command . "\r\n");
    }
    $response = smtpRead($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new Exception('SMTP command failed: ' . trim($response));
    }
    return $response;
}

function smtpHeaderEncode($text) {
    $text = (string)$text;
    if (preg_match('/[^\x20-\x7E]/', $text)) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    return str_replace(["\r", "\n"], '', $text);
}

function smtpAddress($email, $name = '') {
    $email = trim((string)$email);
    $name = trim((string)$name);
    if ($name === '') return '<' . $email . '>';
    return '"' . addcslashes($name, '\\"') . '" <' . $email . '>';
}

function sendHREmailViaSMTP($to_email, $to_name, $subject, $html, $fallback_from_email, $fallback_from_name) {
    $smtp = getHRSMTPConfig();
    if (!$smtp) return false;

    $fromEmail = trim((string)($smtp['from_email'] ?: $fallback_from_email));
    $fromName = trim((string)($smtp['from_name'] ?: $fallback_from_name));
    $replyTo = trim((string)($smtp['reply_to'] ?: $fromEmail));
    $encryption = strtolower(trim((string)$smtp['encryption']));
    $host = trim((string)$smtp['host']);
    $port = (int)$smtp['port'];
    $timeout = (int)$smtp['timeout'];
    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host;

    try {
        $socket = fsockopen($remote, $port, $errno, $errstr, $timeout);
        if (!$socket) return false;
        stream_set_timeout($socket, $timeout);

        smtpCommand($socket, '', [220]);
        smtpCommand($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);

        if ($encryption === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception('SMTP STARTTLS failed.');
            }
            smtpCommand($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
        }

        if (!empty($smtp['username'])) {
            smtpCommand($socket, 'AUTH LOGIN', [334]);
            smtpCommand($socket, base64_encode((string)$smtp['username']), [334]);
            smtpCommand($socket, base64_encode((string)$smtp['password']), [235]);
        }

        smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . trim((string)$to_email) . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $headers = [
            'Date: ' . date('r'),
            'From: ' . smtpAddress($fromEmail, $fromName),
            'Reply-To: ' . smtpAddress($replyTo, $fromName),
            'To: ' . smtpAddress($to_email, $to_name),
            'Subject: ' . smtpHeaderEncode($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $html);
        fwrite($socket, $message . "\r\n.\r\n");
        smtpCommand($socket, '', [250]);
        smtpCommand($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) fclose($socket);
        return false;
    }
}

function getHREmailAddress() {
    return 'victoriatoivo@gmail.com';
}

function emailHRNotice($subject, $body_html) {
    $to = getHREmailAddress();
    if (!$to) return false;
    return sendHREmail($to, 'HR Admin', $subject, $body_html);
}

// ── Specific notification functions ───────────────────────────

function emailLeaveApproved($to_email, $to_name, $leave_type, $days, $start, $end) {
    $subject = 'Leave Approved — ' . $leave_type;
    $body = '<p>Your leave request has been <strong style="color:#2d6a4f">approved</strong>.</p>
    <div class="highlight">
      ' . htmlspecialchars($leave_type) . ' — ' . number_format((float)$days,1) . ' day(s)<br>
      ' . date('d F Y',strtotime($start)) . ' to ' . date('d F Y',strtotime($end)) . '
    </div>
    <p>Please ensure your work is handed over before your leave begins.</p>
    <a href="' . SITE_URL . '/my-leave.php" class="btn">View My Leave</a>';
    return sendHREmail($to_email, $to_name, $subject, $body);
}

function emailLeaveRejected($to_email, $to_name, $leave_type, $reason) {
    $subject = 'Leave Request — Not Approved';
    $body = '<p>Your leave request has <strong style="color:#dc2626">not been approved</strong>.</p>
    <div class="highlight" style="background:#fef2f2;border-color:#dc2626;color:#dc2626">
      ' . htmlspecialchars($leave_type) . '
      ' . ($reason ? '<br>Reason: ' . htmlspecialchars($reason) : '') . '
    </div>
    <p>Please speak to your manager if you have any questions.</p>
    <a href="' . SITE_URL . '/my-leave.php" class="btn">View My Leave</a>';
    return sendHREmail($to_email, $to_name, $subject, $body);
}

function emailOvertimeApproved($to_email, $to_name, $date, $hours, $amount) {
    $subject = 'Overtime Approved';
    $body = '<p>Your overtime has been <strong style="color:#2d6a4f">approved</strong> and will be included in your next payslip.</p>
    <div class="highlight">
      Date: ' . date('d F Y',strtotime($date)) . '<br>
      Hours: ' . $hours . 'h<br>
      Amount: N$ ' . number_format((float)$amount,2) . '
    </div>
    <a href="' . SITE_URL . '/my-overtime.php" class="btn">View My Overtime</a>';
    return sendHREmail($to_email, $to_name, $subject, $body);
}

function emailOvertimeRejected($to_email, $to_name, $date, $hours) {
    $subject = 'Overtime Request - Not Approved';
    $body = '<p>Your overtime request has <strong style="color:#dc2626">not been approved</strong>.</p>
    <div class="highlight" style="background:#fef2f2;border-color:#dc2626;color:#dc2626">
      Date: ' . date('d F Y',strtotime($date)) . '<br>
      Hours: ' . $hours . 'h
    </div>
    <a href="' . SITE_URL . '/my-overtime.php" class="btn">View My Overtime</a>';
    return sendHREmail($to_email, $to_name, $subject, $body);
}

function emailPayslipReady($to_email, $to_name, $period, $net) {
    $subject = 'Your Payslip is Ready — ' . $period;
    $body = '<p>Your payslip for <strong>' . htmlspecialchars($period) . '</strong> is now available.</p>
    <div class="highlight">
      Net Pay: N$ ' . number_format((float)$net,2) . '
    </div>
    <p>You can view and download your payslip from the HR portal.</p>
    <a href="' . SITE_URL . '/my-payslips.php" class="btn">View My Payslip</a>';
    return sendHREmail($to_email, $to_name, $subject, $body);
}

function emailPayrollRunGenerated($period, $employee_count, $total_net, $total_ssf) {
    $subject = 'Payroll Generated - ' . $period;
    $body = '<p>A payroll run has been generated and payslip notifications were sent to employees with email addresses.</p>
    <div class="highlight">
      Period: ' . htmlspecialchars($period) . '<br>
      Employees: ' . (int)$employee_count . '<br>
      Total Net Payroll: N$ ' . number_format((float)$total_net,2) . '<br>
      Employee SSF Deductions: N$ ' . number_format((float)$total_ssf,2) . '
    </div>
    <a href="' . SITE_URL . '/payroll.php" class="btn">View Payroll</a>';
    return emailHRNotice($subject, $body);
}

function emailDocumentUploaded($to_email, $to_name, $doc_title) {
    $subject = 'New Document Available — ' . $doc_title;
    $body = '<p>A new document has been uploaded and is available in your HR portal.</p>
    <div class="highlight">' . htmlspecialchars($doc_title) . '</div>
    <p>Please read and acknowledge this document at your earliest convenience.</p>
    <a href="' . SITE_URL . '/my-documents.php" class="btn">View My Documents</a>';
    return sendHREmail($to_email, $to_name, $subject, $body);
}
