<?php

declare(strict_types=1);

function uploaded_pdf_info(string $field, string $folder): array
{
    if (!isset($_FILES[$field]) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        return ['ok' => false, 'error' => 'No PDF was uploaded.'];
    }

    $file = $_FILES[$field];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($extension !== 'pdf') {
        return ['ok' => false, 'error' => 'Please upload a PDF file.'];
    }

    $uploadDir = BASE_PATH . '/uploads/' . trim($folder, '/');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', pathinfo($file['name'], PATHINFO_FILENAME));
    $target = $uploadDir . '/' . date('Ymd-His') . '-' . $safeName . '.pdf';

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'error' => 'The PDF could not be saved on the server.'];
    }

    return [
        'ok' => true,
        'path' => $target,
        'name' => $file['name'],
    ];
}

function extract_pdf_text(string $path): array
{
    $escapedPath = escapeshellarg($path);
    $command = 'pdftotext -layout ' . $escapedPath . ' - 2>&1';
    $output = function_exists('shell_exec') ? shell_exec($command) : null;

    if (!is_string($output) || trim($output) === '' || stripos($output, 'not recognized') !== false || stripos($output, 'not found') !== false) {
        return [
            'available' => false,
            'text' => '',
            'message' => 'PDF text extraction needs Poppler pdftotext installed on the server, or an OCR/API extractor connected.',
        ];
    }

    return [
        'available' => true,
        'text' => trim($output),
        'message' => 'PDF text was extracted and parsed.',
    ];
}

function first_match(string $text, array $patterns): ?string
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            return trim($matches[1]);
        }
    }

    return null;
}

function money_to_float(?string $value): ?float
{
    if ($value === null) {
        return null;
    }

    $clean = preg_replace('/[^0-9.]/', '', str_replace(',', '', $value));
    return $clean === '' ? null : (float) $clean;
}

function parse_transport_text(string $text): array
{
    $invoiceDate = first_match($text, [
        '/(?:invoice\s*date|date)\s*[:#-]?\s*(\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4})/i',
        '/(?:invoice\s*date|date)\s*[:#-]?\s*(\d{4}[\/.-]\d{1,2}[\/.-]\d{1,2})/i',
    ]);

    $invoiceNumber = first_match($text, [
        '/(?:invoice\s*(?:no|number|#)|inv\s*(?:no|#))\s*[:#-]?\s*([A-Z0-9\/._-]+)/i',
    ]);

    $waybill = first_match($text, [
        '/(?:waybill|way\s*bill|awb)\s*(?:no|number|#)?\s*[:#-]?\s*([A-Z0-9\/._-]+)/i',
    ]);

    $consignment = first_match($text, [
        '/(?:consignment|shipment)\s*(?:no|number|#)?\s*[:#-]?\s*([A-Z0-9\/._-]+)/i',
    ]);

    $pieces = first_match($text, [
        '/(?:pieces|pcs|packages|parcels)\s*[:#-]?\s*([0-9]+(?:\.[0-9]+)?)/i',
    ]);

    $actualWeight = first_match($text, [
        '/(?:actual\s*weight|gross\s*weight|weight)\s*[:#-]?\s*([0-9]+(?:\.[0-9]+)?)\s*(?:kg|kgs|kilograms)?/i',
    ]);

    $chargeableWeight = first_match($text, [
        '/(?:chargeable\s*weight|volumetric\s*weight|billing\s*weight)\s*[:#-]?\s*([0-9]+(?:\.[0-9]+)?)\s*(?:kg|kgs|kilograms)?/i',
    ]);

    $vat = money_to_float(first_match($text, [
        '/(?:vat|tax)\s*[:#-]?\s*(?:N\$|NAD|ZAR|R)?\s*([0-9,]+(?:\.[0-9]{2})?)/i',
    ]));

    $total = money_to_float(first_match($text, [
        '/(?:grand\s*total|total\s*due|amount\s*due|total)\s*[:#-]?\s*(?:N\$|NAD|ZAR|R)?\s*([0-9,]+(?:\.[0-9]{2})?)/i',
    ]));

    $route = first_match($text, [
        '/(?:route|from\s*\/\s*to|origin\s*\/\s*destination)\s*[:#-]?\s*([^\r\n]+)/i',
    ]);

    return [
        'invoice_date' => $invoiceDate,
        'invoice_number' => $invoiceNumber,
        'waybill_number' => $waybill,
        'consignment_number' => $consignment,
        'route' => $route,
        'pieces' => $pieces !== null ? (float) $pieces : null,
        'actual_weight_kg' => $actualWeight !== null ? (float) $actualWeight : null,
        'chargeable_weight_kg' => $chargeableWeight !== null ? (float) $chargeableWeight : null,
        'vat_amount' => $vat,
        'total_cost' => $total,
        'consignments' => [],
    ];
}

function parse_supplier_invoice_text(string $text): array
{
    return [
        'invoice_date' => first_match($text, [
            '/(?:invoice\s*date|date)\s*[:#-]?\s*(\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4})/i',
            '/(?:invoice\s*date|date)\s*[:#-]?\s*(\d{4}[\/.-]\d{1,2}[\/.-]\d{1,2})/i',
        ]),
        'invoice_number' => first_match($text, [
            '/(?:invoice\s*(?:no|number|#)|inv\s*(?:no|#))\s*[:#-]?\s*([A-Z0-9\/._-]+)/i',
        ]),
        'total' => money_to_float(first_match($text, [
            '/(?:grand\s*total|total\s*due|amount\s*due|total)\s*[:#-]?\s*(?:N\$|NAD|ZAR|R)?\s*([0-9,]+(?:\.[0-9]{2})?)/i',
        ])),
    ];
}
