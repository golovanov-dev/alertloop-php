<?php

// Router for `php -S`: stands in for the Telegram and MAX APIs in tests.
// Logs every request; answers with the status found as "status-NNN" in the URI
// or the Authorization header, 200 otherwise.

declare(strict_types=1);

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$headers = function_exists('getallheaders') ? getallheaders() : [];
file_put_contents(
    (string) getenv('ALERTLOOP_FAKE_API_LOG'),
    json_encode([
        'uri' => $uri,
        'headers' => $headers,
        'body' => file_get_contents('php://input'),
    ]) . "\n",
    FILE_APPEND | LOCK_EX,
);

$status = preg_match('/status-(\d{3})/', $uri . ' ' . ($headers['Authorization'] ?? ''), $m) === 1 ? (int) $m[1] : 200;
http_response_code($status);
header('Content-Type: application/json');
if ($status === 429) {
    header('Retry-After: 7');
    echo json_encode(['ok' => false, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 7]]);
} else {
    echo json_encode([
        'ok' => $status === 200,
        'description' => 'fake ' . $status,
        'message' => 'fake ' . $status,
        'result' => ['username' => 'fake_bot'],
    ]);
}
