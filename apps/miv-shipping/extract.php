<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/openai-extractor.php';
require_role('owner_admin');

header('Content-Type: application/json; charset=utf-8');

function miv_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    miv_json(405, ['ok' => false, 'message' => 'POST required.']);
}

$csrf = (string) ($_SERVER['HTTP_X_MIV_CSRF'] ?? '');
$expected = (string) ($_SESSION['miv_shipping_csrf'] ?? '');
if ($expected === '' || $csrf === '' || !hash_equals($expected, $csrf)) {
    miv_json(403, ['ok' => false, 'message' => 'Your session token expired. Refresh MIV Shipping and try again.']);
}

if (OPENAI_API_KEY === '') {
    miv_json(503, ['ok' => false, 'message' => 'Image extraction is not configured on the server yet.']);
}
if (!function_exists('curl_init')) {
    miv_json(503, ['ok' => false, 'message' => 'PHP cURL is unavailable on the server.']);
}
if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
    miv_json(400, ['ok' => false, 'message' => 'Choose a product screenshot first.']);
}
$file = $_FILES['image'];
if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    miv_json(400, ['ok' => false, 'message' => 'The screenshot upload failed.']);
}
if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 10 * 1024 * 1024) {
    miv_json(400, ['ok' => false, 'message' => 'Screenshots must be 10 MB or smaller.']);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file((string) $file['tmp_name']);
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
if (!in_array($mime, $allowed, true)) {
    miv_json(400, ['ok' => false, 'message' => 'Use a JPG, PNG, WEBP, HEIC or HEIF screenshot.']);
}
$bytes = file_get_contents((string) $file['tmp_name']);
if ($bytes === false) {
    miv_json(400, ['ok' => false, 'message' => 'The screenshot could not be read.']);
}

$schema = '{
  "product_name": null,
  "quantity": null,
  "price_cny": null,
  "price_type": "unit|total|unknown",
  "weight_kg": null,
  "weight_type": "unit|total|unknown",
  "weight_source": "listed|visual_estimate|unknown",
  "cargo_type": "normal|battery|unknown",
  "battery_detected": null,
  "dimensions": null,
  "confidence": "low|medium|high",
  "needs_review": [],
  "evidence": []
}';
$prompt = "Read this Chinese shopping/product screenshot for a consolidated China-to-Namibia shipping quote. "
    . "Return only valid JSON matching this exact shape: {$schema}. "
    . "Extract product name, selected quantity, the item price in Chinese yuan/CNY, whether the price is per unit or a total, and the product weight. "
    . "Recognize weight labels and units including kg, kgs, kilogram, g, gram, 克, 公斤, 千克, 净重, 毛重, 重量, and 斤 (1 Chinese 斤 = 0.5 kg). Convert grams and 斤 to kilograms. "
    . "If the screenshot explicitly shows a listed weight, set weight_source to listed. Determine whether the shown weight is per unit or total from the surrounding labels and quantity. "
    . "If no explicit weight is visible, do not invent an exact value. Only provide a visual_estimate when the image/specification gives enough evidence to make a reasonable estimate; otherwise set weight_kg to null. "
    . "Treat built-in batteries, rechargeable devices, power banks, electronics with batteries, LED devices with internal batteries, and battery wording as cargo_type battery. Otherwise use normal unless uncertain. "
    . "Do not multiply a total price or total weight by quantity. If uncertain, use unknown and add the field to needs_review. Include short evidence strings explaining visible text used.";

$payload = [
    'model' => OPENAI_MODEL,
    'input' => [[
        'role' => 'user',
        'content' => [
            [
                'type' => 'input_image',
                'image_url' => 'data:' . $mime . ';base64,' . base64_encode($bytes),
            ],
            [
                'type' => 'input_text',
                'text' => $prompt,
            ],
        ],
    ]],
    'max_output_tokens' => 1000,
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
    miv_json(502, ['ok' => false, 'message' => 'Image extraction failed: ' . ($error ?: 'empty response')]);
}
$response = json_decode($body, true);
if (!is_array($response) || $status >= 400) {
    $apiMessage = is_array($response) ? (string) ($response['error']['message'] ?? 'OpenAI request failed.') : 'OpenAI request failed.';
    miv_json(502, ['ok' => false, 'message' => $apiMessage]);
}
$text = openai_response_text($response);
$jsonText = trim((string) preg_replace('/^```(?:json)?|```$/m', '', $text));
$data = json_decode($jsonText, true);
if (!is_array($data)) {
    miv_json(502, ['ok' => false, 'message' => 'The screenshot was read, but the extracted result was not valid JSON.']);
}

miv_json(200, [
    'ok' => true,
    'message' => 'Product details extracted. Verify them before using the quote.',
    'data' => $data,
]);
