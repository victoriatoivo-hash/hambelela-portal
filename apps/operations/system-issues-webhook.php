<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
    'ok'=>false,
    'error'=>'Automated System Issues worker callbacks are deferred. Use the owner-controlled manual Codex handoff workflow.',
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
