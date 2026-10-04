<?php
// Shared generic markings placed by viewers, plus the custom marking types in the toolbar.
// Keep beside index.html; PHP needs write access here.
// GET  markers.php?since=<rev>  -> full state, or {"unchanged":true} when <rev> is current.
// POST {op: add|move|done|rename|delete|addType|deleteType, ...} -> applies the change, returns full state.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$dataFile = __DIR__ . '/markers.json';
$lock = @fopen(__DIR__ . '/markers.lock', 'c');
const MAX_MARKERS = 3000;
const MAX_TYPES = 24;

function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function done($lock, int $status, array $body): void {
    flock($lock, LOCK_UN); fclose($lock); respond($status, $body);
}
function cleanText($v, int $maxChars): ?string {
    if (!is_string($v)) return null;
    $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '');
    if (strlen($v) > $maxChars * 8) return null;
    if (preg_match_all('/./us', $v) > $maxChars) return null;
    return $v;
}
function cleanName($v): string {
    if (!is_string($v)) return '';
    $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '';
    $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    return implode('', array_slice(preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 24));
}
function cleanSymbol($v): ?string {
    $v = cleanText($v, 32);
    if ($v === null || $v === '' || strlen($v) > 40) return null;
    return preg_match_all('/\X/u', $v) <= 3 ? $v : null;
}
// Optional named page icon (e.g. "unlock"); lets a marking reuse a built-in map icon instead of a text symbol.
function cleanIcon($v): ?string {
    if ($v === null || $v === '') return '';
    return is_string($v) && preg_match('/^[a-z0-9_-]{1,32}$/', $v) ? $v : null;
}
function cleanColor($v): ?string {
    return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null;
}
function cleanFrac($v): ?float {
    return (is_int($v) || is_float($v)) && $v >= 0 && $v <= 1 ? (float)$v : null;
}
function save(string $file, array $state): bool {
    $tmp = $file . '.tmp';
    $json = json_encode([
        'rev' => $state['rev'],
        'markers' => (object)$state['markers'],
        'types' => (object)$state['types'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}
function publicState(array $state, array $extra = []): array {
    return $extra + [
        'rev' => $state['rev'],
        'markers' => array_values($state['markers']),
        'types' => array_values($state['types']),
    ];
}

if (!$lock || !flock($lock, LOCK_EX)) respond(500, ['error' => 'Cannot lock marker store; check directory permissions.']);

$state = ['rev' => 0, 'markers' => [], 'types' => []];
if (is_file($dataFile)) {
    $raw = @file_get_contents($dataFile);
    $decoded = $raw === false ? null : json_decode($raw, true);
    if (is_array($decoded)) {
        $state['rev'] = (int)($decoded['rev'] ?? 0);
        foreach (['markers', 'types'] as $k) {
            if (isset($decoded[$k]) && is_array($decoded[$k])) {
                foreach ($decoded[$k] as $id => $item) if (is_array($item)) $state[$k][(string)$id] = $item;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $since = isset($_GET['since']) ? (int)$_GET['since'] : -1;
    if ($since === $state['rev']) done($lock, 200, ['unchanged' => true, 'rev' => $state['rev']]);
    done($lock, 200, publicState($state));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    done($lock, 405, ['error' => 'Use GET or POST.']);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || !is_string($in['op'] ?? null)) done($lock, 400, ['error' => 'Expected an op.']);
$op = $in['op'];
$now = gmdate('Y-m-d\TH:i:s\Z');
$extra = [];
$bad = fn(string $m) => done($lock, 400, ['error' => $m]);
$id = isset($in['id']) && is_string($in['id']) ? $in['id'] : '';

switch ($op) {
    case 'add':
        $icon = cleanIcon($in['icon'] ?? '');
        $symbol = $icon !== null && $icon !== '' ? '' : cleanSymbol($in['symbol'] ?? null);
        $color = cleanColor($in['color'] ?? null);
        $name = cleanText($in['name'] ?? '', 80);
        $x = cleanFrac($in['x'] ?? null);
        $y = cleanFrac($in['y'] ?? null);
        if ($symbol === null || $icon === null || $color === null || $name === null || $x === null || $y === null) $bad('Invalid marking.');
        if (count($state['markers']) >= MAX_MARKERS) $bad('Too many markings.');
        $newId = bin2hex(random_bytes(6));
        $state['markers'][$newId] = ['id' => $newId, 'symbol' => $symbol, 'icon' => $icon, 'color' => $color, 'name' => $name,
            'x' => $x, 'y' => $y, 'done' => false, 'done_at' => null, 'done_by' => null, 'created_by' => ($by0 = cleanName($in['by'] ?? '')) !== '' ? $by0 : null, 'created_at' => $now, 'updated_at' => $now];
        $extra['id'] = $newId;
        break;
    case 'move':
        $x = cleanFrac($in['x'] ?? null); $y = cleanFrac($in['y'] ?? null);
        if (!isset($state['markers'][$id]) || $x === null || $y === null) $bad('Invalid move.');
        $state['markers'][$id]['x'] = $x; $state['markers'][$id]['y'] = $y; $state['markers'][$id]['updated_at'] = $now;
        break;
    case 'done':
        if (!isset($state['markers'][$id]) || !is_bool($in['done'] ?? null)) $bad('Invalid done change.');
        $state['markers'][$id]['done'] = $in['done'];
        $state['markers'][$id]['done_at'] = $in['done'] ? $now : null;
        $by = cleanName($in['by'] ?? '');
        $state['markers'][$id]['done_by'] = $in['done'] && $by !== '' ? $by : null;
        $state['markers'][$id]['updated_at'] = $now;
        break;
    case 'rename':
        $name = cleanText($in['name'] ?? null, 80);
        if (!isset($state['markers'][$id]) || $name === null) $bad('Invalid name.');
        $state['markers'][$id]['name'] = $name; $state['markers'][$id]['updated_at'] = $now;
        break;
    case 'delete':
        if (!isset($state['markers'][$id])) $bad('Unknown marking.');
        unset($state['markers'][$id]);
        break;
    case 'addType':
        $symbol = cleanSymbol($in['symbol'] ?? null);
        $color = cleanColor($in['color'] ?? null);
        $label = cleanText($in['label'] ?? null, 30);
        if ($symbol === null || $color === null || $label === null || $label === '') $bad('Invalid marking type.');
        if (count($state['types']) >= MAX_TYPES) $bad('Too many custom types.');
        $newId = bin2hex(random_bytes(4));
        $state['types'][$newId] = ['id' => $newId, 'symbol' => $symbol, 'label' => $label, 'color' => $color];
        $extra['id'] = $newId;
        break;
    case 'deleteType':
        if (!isset($state['types'][$id])) $bad('Unknown type.');
        unset($state['types'][$id]);
        break;
    default:
        $bad('Unknown op.');
}

$state['rev']++;
if (!save($dataFile, $state)) done($lock, 500, ['error' => 'Could not write marker data; check directory permissions.']);
done($lock, 200, publicState($state, $extra));
