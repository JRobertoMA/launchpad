<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');

$file = __DIR__ . '/../services.json';

// GET — public: serves the services list
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo file_exists($file) ? file_get_contents($file) : '[]';
    exit;
}

// POST — requires admin session
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (empty($_SESSION['admin_ok'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$request_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $request_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request token']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

function safeUrl($raw) {
    $url = filter_var(trim($raw), FILTER_VALIDATE_URL);
    return ($url && preg_match('/^https?:\/\//i', $url)) ? $url : '';
}

$clean = [];
foreach (array_values($input) as $svc) {
    if (empty($svc['name'])) continue;

    $name  = trim(strip_tags($svc['name']));
    $id    = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($svc['id'] ?? '')));
    if (!$id) $id = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));

    $clean[] = [
        'id'     => $id,
        'name'   => $name,
        'tag'    => trim(strip_tags($svc['tag']   ?? '')),
        'glyph'  => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $svc['glyph'] ?? ''), 0, 5)),
        'local'  => safeUrl($svc['local']  ?? ''),
        'global' => safeUrl($svc['global'] ?? ''),
    ];
}

if (file_put_contents($file, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Write failed — check file permissions']);
    exit;
}

echo json_encode(['ok' => true, 'count' => count($clean)]);
