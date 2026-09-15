<?php
// Exercise the actual response serializer without a database or creating accounts.
$source = file_get_contents(__DIR__ . '/../apps/operations/my-account.php');
$start = strpos($source, '        $field = null;', strpos($source, 'if ($isCreateEmployeeAjax)'));
$end = strpos($source, '        exit;', $start);
$block = substr($source, $start, $end - $start);
$block = preg_replace('/^\s*header\([^\n]+\);\s*$/m', '', $block);
if (strpos($block, 'str_contains(') !== false) throw new RuntimeException('PHP 8-only function in response');
$createdEmployeeId = 42;
$createdRoleKey = 'marketing_sales';
$cases = [
    ['success', 'Marketing & Sales Assistant account created successfully.', null],
    ['error', 'An account already exists with this email address.', 'email'],
    ['error', 'The access code is already in use.', 'login_code'],
    ['error', 'Confirm your access code.', 'confirm_login_code'],
    ['error', 'Full name is required.', 'full_name'],
    ['error', 'Choose a valid role.', 'role'],
];
foreach ($cases as $case) {
    list($messageType, $message, $expectedField) = $case;
    ob_start();
    eval($block);
    $data = json_decode(ob_get_clean(), true);
    if (!is_array($data) || $data['success'] !== ($messageType === 'success') || $data['field'] !== $expectedField) {
        throw new RuntimeException('Invalid account response');
    }
    if (http_response_code() !== ($messageType === 'success' ? 201 : 422)) throw new RuntimeException('Wrong HTTP status');
}
echo "PASS: creation success and validation failures return valid JSON on this PHP runtime.\n";
