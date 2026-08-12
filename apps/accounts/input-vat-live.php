<?php
declare(strict_types=1);

// This entry point deliberately reads the implementation from disk on every
// request. The production PHP opcode cache can retain input-vat.php after an
// FTP replacement, which previously caused the old workspace to reappear even
// while the deployed source and versioned assets were current.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$implementationPath = __DIR__ . '/input-vat.php';
$implementation = file_get_contents($implementationPath);
if ($implementation === false) {
    http_response_code(500);
    exit('Input VAT workspace is temporarily unavailable.');
}

eval('?>' . $implementation);
