<?php
// Shared completion state. Keep beside index.html; PHP needs write access here.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$dataFile = __DIR__ . '/done-markers.json';
$lock = @fopen(__DIR__ . '/done-markers.lock', 'c');
function cleanName($v): string {
    if (!is_string($v)) return '';
    $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '';
    $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    return implode('', array_slice(preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 24));
}
function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (!$lock || !flock($lock, LOCK_EX)) respond(500, ['error' => 'Cannot lock marker store; check directory permissions.']);
$stored = [];
if (is_file($dataFile)) {
    $raw = @file_get_contents($dataFile);
    $decoded = $raw === false ? null : json_decode($raw, true);
    if (is_array($decoded) && isset($decoded['done']) && is_array($decoded['done'])) {
        foreach ($decoded['done'] as $key => $value) {
            if (is_int($key) && is_string($value)) $stored[$value] = ['changed_at' => null, 'name' => null];
            elseif (is_string($key)) {
                if (is_array($value)) $stored[$key] = ['changed_at' => is_string($value['changed_at'] ?? null) ? $value['changed_at'] : null, 'name' => is_string($value['name'] ?? null) && $value['name'] !== '' ? $value['name'] : null];
                else $stored[$key] = ['changed_at' => is_string($value) ? $value : null, 'name' => null];
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    flock($lock, LOCK_UN); fclose($lock); respond(200, ['done' => (object)$stored]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST'); flock($lock, LOCK_UN); fclose($lock); respond(405, ['error' => 'Use GET or POST.']);
}
$input = json_decode(file_get_contents('php://input'), true);
$key = is_array($input) ? ($input['key'] ?? null) : null;
$done = is_array($input) ? ($input['done'] ?? null) : null;
if (!is_string($key) || $key === '' || strlen($key) > 1000 || !is_bool($done)) {
    flock($lock, LOCK_UN); fclose($lock); respond(400, ['error' => 'Expected a marker key and boolean done value.']);
}
if ($done) {
    $name = cleanName($input['name'] ?? '');
    $stored[$key] = ['changed_at' => gmdate('Y-m-d\TH:i:s\Z'), 'name' => $name !== '' ? $name : null];
}
else unset($stored[$key]);
$tmp = $dataFile . '.tmp';
$json = json_encode(['done' => (object)$stored], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || @file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $dataFile)) {
    @unlink($tmp); flock($lock, LOCK_UN); fclose($lock); respond(500, ['error' => 'Could not write marker data; check directory permissions.']);
}
flock($lock, LOCK_UN); fclose($lock); respond(200, ['ok' => true, 'done' => (object)$stored]);
