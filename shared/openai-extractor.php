<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

function openai_invoice_schema(string $type): string
{
    if ($type === 'transport') {
        return '{
  "supplier_name": null,
  "transport_provider": null,
  "invoice_number": null,
  "invoice_date": null,
  "waybill_number": null,
  "consignment_number": null,
  "route": null,
  "pieces": null,
  "actual_weight_kg": null,
  "chargeable_weight_kg": null,
  "subtotal": null,
  "vat_amount": null,
  "total_cost": null,
  "currency": null,
  "consignments": [
    {
      "supplier_name": null,
      "waybill_number": null,
      "consignment_number": null,
      "description": null,
      "route": null,
      "pieces": null,
      "actual_weight_kg": null,
      "chargeable_weight_kg": null,
      "line_amount": null
    }
  ],
  "confidence": "low|medium|high",
  "needs_review": [],
  "notes": null
}';
    }

    return '{
  "supplier_name": null,
  "invoice_number": null,
  "invoice_date": null,
  "subtotal": null,
  "vat_amount": null,
  "total": null,
  "currency": null,
  "raw_materials": [
    {"name": null, "quantity": null, "unit": null, "unit_price": null, "line_total": null}
  ],
  "packaging": [
    {"name": null, "quantity": null, "unit": null, "unit_price": null, "line_total": null}
  ],
  "transport_lines": [
    {"description": null, "total_cost": null}
  ],
  "confidence": "low|medium|high",
  "needs_review": [],
  "notes": null
}';
}

function openai_extract_pdf(string $path, string $filename, string $type, string $supplierName = ''): array
{
    if (OPENAI_API_KEY === '') {
        return [
            'ok' => false,
            'source' => 'openai',
            'message' => 'OpenAI extraction is not configured. Add OPENAI_API_KEY on the server.',
            'data' => [],
            'raw' => '',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'source' => 'openai',
            'message' => 'PHP cURL is not enabled on this server.',
            'data' => [],
            'raw' => '',
        ];
    }

    $bytes = file_get_contents($path);
    if ($bytes === false) {
        return [
            'ok' => false,
            'source' => 'openai',
            'message' => 'The uploaded PDF could not be read.',
            'data' => [],
            'raw' => '',
        ];
    }

    $documentLabel = $type === 'transport' ? 'transport/courier/freight invoice' : 'supplier invoice';
    $schema = openai_invoice_schema($type);
    $prompt = "Extract structured information from this {$documentLabel}. "
        . "The user-entered supplier name is: " . ($supplierName !== '' ? $supplierName : 'not provided') . ". "
        . "Use the PDF visually and textually. Return only valid JSON matching this shape: {$schema}. "
        . "Use numbers for money, quantities, pieces, and weights. Use ISO date format YYYY-MM-DD when possible. "
        . "For supplier invoice line items, extract each product line. If the invoice shows a quantity like 25.0 and the product is a raw material sold by weight, infer unit kg only when the invoice context supports it; otherwise leave unit null and add it to needs_review. "
        . "Classify bottles, jars, lids, caps, labels, pouches, boxes, and containers as packaging. Classify oils, butters, powders, herbs, waxes, extracts, and ingredients as raw_materials. "
        . "For transport invoices, extract every waybill or consignment line separately in consignments. If one invoice contains multiple suppliers, keep the invoice total at the header level and put each supplier, waybill, description, route, weight, and line amount into its own consignment row. "
        . "If a field is missing or uncertain, use null and add the field name to needs_review. "
        . "Do not invent values.";

    $payload = [
        'model' => OPENAI_MODEL,
        'input' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_file',
                        'filename' => $filename,
                        'file_data' => 'data:application/pdf;base64,' . base64_encode($bytes),
                    ],
                    [
                        'type' => 'input_text',
                        'text' => $prompt,
                    ],
                ],
            ],
        ],
        'max_output_tokens' => 1800,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $body === '') {
        return [
            'ok' => false,
            'source' => 'openai',
            'message' => 'OpenAI request failed: ' . ($error ?: 'empty response'),
            'data' => [],
            'raw' => '',
        ];
    }

    $response = json_decode($body, true);
    if (!is_array($response) || $status >= 400) {
        $apiMessage = is_array($response) ? ($response['error']['message'] ?? $body) : $body;
        return [
            'ok' => false,
            'source' => 'openai',
            'message' => 'OpenAI extraction failed: ' . $apiMessage,
            'data' => [],
            'raw' => $body,
        ];
    }

    $text = openai_response_text($response);
    $jsonText = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
    $data = json_decode($jsonText, true);

    if (!is_array($data)) {
        return [
            'ok' => false,
            'source' => 'openai',
            'message' => 'OpenAI responded, but the result was not valid JSON.',
            'data' => [],
            'raw' => $text,
        ];
    }

    return [
        'ok' => true,
        'source' => 'openai',
        'message' => 'OpenAI extracted the invoice fields. Review before saving.',
        'data' => $data,
        'raw' => $text,
    ];
}

function openai_response_text(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return $response['output_text'];
    }

    $parts = [];
    foreach (($response['output'] ?? []) as $item) {
        foreach (($item['content'] ?? []) as $content) {
            if (isset($content['text']) && is_string($content['text'])) {
                $parts[] = $content['text'];
            } elseif (isset($content['output_text']) && is_string($content['output_text'])) {
                $parts[] = $content['output_text'];
            }
        }
    }

    return trim(implode("\n", $parts));
}
