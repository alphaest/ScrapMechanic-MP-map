<?php
// Shared per-viewer map pins. Keep beside index.html; PHP needs write access here.
// GET  pins.php?id=<client id>  -> registers the client (assigns a unique color) and returns all pins.
// POST {id, x, y}               -> saves the client's pin (x/y are 0..1 fractions of the map; null/null clears it).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$dataFile = __DIR__ . '/pins.json';
$lock = @fopen(__DIR__ . '/pins.lock', 'c');
const PRUNE_AFTER = 60 * 60 * 24 * 120; // forget clients idle for 120 days
$activeFile = __DIR__ . '/active-players.json'; // {"players": {clientId: lastSeenUnix}}
const ACTIVE_WINDOW = 35;   // seconds a client counts as active after its last request
const ACTIVE_TOUCH_EVERY = 10; // only rewrite the file for a client every N seconds

function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function done($lock, int $status, array $body): void {
    // Every successful pin payload also carries the list of currently active, named players.
    global $clients, $active;
    if ($status === 200 && isset($body['pins']) && is_array($clients ?? null)) $body['active'] = activeList($clients, $active ?? []);
    flock($lock, LOCK_UN); fclose($lock); respond($status, $body);
}
function activeList(array $clients, array $active): array {
    // Everyone currently online (and named) plus everyone who has a pin placed, online or not.
    $out = [];
    $cutoff = time() - ACTIVE_WINDOW;
    foreach ($clients as $cid => $c) {
        $name = is_string($c['name'] ?? null) ? $c['name'] : '';
        $online = isset($active[$cid]) && (int)$active[$cid] >= $cutoff;
        $hasPin = isset($c['x'], $c['y']) && is_numeric($c['x']) && is_numeric($c['y']);
        if (!$hasPin && !($online && $name !== '')) continue;
        $out[] = ['id' => (string)$cid, 'name' => $name, 'color' => $c['color'], 'pin' => $hasPin, 'online' => $online];
    }
    usort($out, fn($a, $b) => ($b['online'] <=> $a['online']) ?: strcasecmp($a['name'] ?: "\u{10FFFF}", $b['name'] ?: "\u{10FFFF}"));
    return $out;
}
// Records that a client was just seen. The file is only rewritten when the entry is stale.
function touchActive(array &$active, string $id, string $file): void {
    $now = time();
    if (isset($active[$id]) && (int)$active[$id] >= $now - ACTIVE_TOUCH_EVERY) return;
    $active[$id] = $now;
    foreach ($active as $k => $ts) if ((int)$ts < $now - 3600) unset($active[$k]);
    $tmp = $file . '.tmp';
    $json = json_encode(['players' => (object)$active], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json !== false && @file_put_contents($tmp, $json . "\n", LOCK_EX) !== false) @rename($tmp, $file); else @unlink($tmp);
}
function hslToHex(float $h, float $s, float $l): string {
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    if ($h < 60) [$r, $g, $b] = [$c, $x, 0];
    elseif ($h < 120) [$r, $g, $b] = [$x, $c, 0];
    elseif ($h < 180) [$r, $g, $b] = [0, $c, $x];
    elseif ($h < 240) [$r, $g, $b] = [0, $x, $c];
    elseif ($h < 300) [$r, $g, $b] = [$x, 0, $c];
    else [$r, $g, $b] = [$c, 0, $x];
    return sprintf('#%02x%02x%02x', round(($r + $m) * 255), round(($g + $m) * 255), round(($b + $m) * 255));
}
// Picks the hue farthest from every hue already handed out, so colors stay distinguishable.
function newColor(array $clients, string $id): array {
    $used = [];
    foreach ($clients as $c) if (isset($c['hue'])) $used[] = (float)$c['hue'];
    $offset = hexdec(substr(md5($id), 0, 4)) % 3;
    if (!$used) {
        $hue = hexdec(substr(md5($id), 0, 6)) % 360;
    } else {
        $hue = 0; $bestDist = -1;
        for ($h = $offset; $h < 360; $h += 3) {
            $min = 360;
            foreach ($used as $u) { $d = abs($h - $u); $min = min($min, $d, 360 - $d); }
            if ($min > $bestDist) { $bestDist = $min; $hue = $h; }
        }
    }
    return ['hue' => $hue, 'color' => hslToHex($hue, 0.85, 0.58)];
}
function cleanName($v): string {
    if (!is_string($v)) return '';
    $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '';
    $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    return implode('', array_slice(preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 24));
}
function validId($id): bool {
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id) === 1;
}
function publicPins(array $clients): object {
    $out = [];
    foreach ($clients as $id => $c) {
        if (isset($c['x'], $c['y']) && is_numeric($c['x']) && is_numeric($c['y'])) {
            $out[$id] = ['color' => $c['color'], 'name' => is_string($c['name'] ?? null) ? $c['name'] : '', 'x' => (float)$c['x'], 'y' => (float)$c['y'], 'updated_at' => $c['updated_at'] ?? null, 'line' => (isset($c['line']) && is_array($c['line']) && count($c['line']) >= 4 && count($c['line']) % 2 === 0) ? array_map('floatval', array_values($c['line'])) : null];
        }
    }
    return (object)$out;
}
function save(string $file, array $clients): bool {
    $tmp = $file . '.tmp';
    $json = json_encode(['clients' => (object)$clients], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

if (!$lock || !flock($lock, LOCK_EX)) respond(500, ['error' => 'Cannot lock pin store; check directory permissions.']);

$clients = [];
if (is_file($dataFile)) {
    $raw = @file_get_contents($dataFile);
    $decoded = $raw === false ? null : json_decode($raw, true);
    if (is_array($decoded) && isset($decoded['clients']) && is_array($decoded['clients'])) {
        foreach ($decoded['clients'] as $id => $c) {
            if (validId((string)$id) && is_array($c) && isset($c['color'])) $clients[(string)$id] = $c;
        }
    }
}

$active = [];
if (is_file($activeFile)) {
    $rawActive = @file_get_contents($activeFile);
    $decodedActive = $rawActive === false ? null : json_decode($rawActive, true);
    if (is_array($decodedActive) && is_array($decodedActive['players'] ?? null)) {
        foreach ($decodedActive['players'] as $cid => $ts) if (is_numeric($ts)) $active[(string)$cid] = (int)$ts;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = $_GET['id'] ?? null;
    $you = null;
    if (validId($id)) {
        if (!isset($clients[$id])) {
            $clients[$id] = newColor($clients, $id) + ['x' => null, 'y' => null, 'updated_at' => null, 'seen' => time()];
            if (!save($dataFile, $clients)) done($lock, 500, ['error' => 'Could not write pin data; check directory permissions.']);
        }
        touchActive($active, $id, $activeFile);
        $you = ['id' => $id, 'color' => $clients[$id]['color'], 'name' => is_string($clients[$id]['name'] ?? null) ? $clients[$id]['name'] : ''];
    }
    done($lock, 200, ['you' => $you, 'pins' => publicPins($clients)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    done($lock, 405, ['error' => 'Use GET or POST.']);
}

$input = json_decode(file_get_contents('php://input'), true);

// Remove every viewer's pin (colors are kept), e.g. after browsers lost their client IDs.
if (is_array($input) && ($input['op'] ?? null) === 'clearAll') {
    foreach ($clients as $cid => $c) {
        $clients[$cid]['x'] = null;
        $clients[$cid]['y'] = null;
        $clients[$cid]['line'] = null;
        $clients[$cid]['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    }
    if (!save($dataFile, $clients)) done($lock, 500, ['error' => 'Could not write pin data; check directory permissions.']);
    done($lock, 200, ['ok' => true, 'you' => null, 'pins' => publicPins($clients)]);
}

// Set the player name shown on this client's pin.
if (is_array($input) && ($input['op'] ?? null) === 'setName') {
    $sid = $input['id'] ?? null;
    if (!validId($sid)) done($lock, 400, ['error' => 'Invalid client id.']);
    if (!isset($clients[$sid])) $clients[$sid] = newColor($clients, $sid) + ['x' => null, 'y' => null, 'updated_at' => null];
    $clients[$sid]['name'] = cleanName($input['name'] ?? '');
    $clients[$sid]['seen'] = time();
    touchActive($active, $sid, $activeFile);
    if (!save($dataFile, $clients)) done($lock, 500, ['error' => 'Could not write pin data; check directory permissions.']);
    done($lock, 200, ['ok' => true, 'you' => ['id' => $sid, 'color' => $clients[$sid]['color'], 'name' => $clients[$sid]['name']], 'pins' => publicPins($clients)]);
}
$id = is_array($input) ? ($input['id'] ?? null) : null;
$x = is_array($input) ? ($input['x'] ?? null) : null;
$y = is_array($input) ? ($input['y'] ?? null) : null;
$clearing = $x === null && $y === null;
$valid = $clearing || ((is_int($x) || is_float($x)) && (is_int($y) || is_float($y)) && $x >= 0 && $x <= 1 && $y >= 0 && $y <= 1);
if (!validId($id) || !$valid) done($lock, 400, ['error' => 'Expected a client id and either x/y between 0 and 1, or null/null to clear.']);

if (!isset($clients[$id])) $clients[$id] = newColor($clients, $id) + ['x' => null, 'y' => null, 'updated_at' => null];
// Optional planned-movement path: flat [x1, y1, x2, y2, ...] (2 to 64 points) as 0..1 map fractions, or null to remove it.
$lineVal = $clients[$id]['line'] ?? null;
if (is_array($input) && array_key_exists('line', $input)) {
    $ln = $input['line'];
    if ($ln === null) {
        $lineVal = null;
    } else {
        $okLine = is_array($ln) && count($ln) >= 4 && count($ln) <= 128 && count($ln) % 2 === 0;
        if ($okLine) foreach ($ln as $lv) if (!(is_int($lv) || is_float($lv)) || $lv < 0 || $lv > 1) $okLine = false;
        if (!$okLine) done($lock, 400, ['error' => 'Expected line as 4 to 128 numbers (x,y pairs) between 0 and 1, or null.']);
        $lineVal = array_map('floatval', array_values($ln));
    }
}
$clients[$id]['line'] = $clearing ? null : $lineVal;
$clients[$id]['x'] = $clearing ? null : (float)$x;
$clients[$id]['y'] = $clearing ? null : (float)$y;
$clients[$id]['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
$clients[$id]['seen'] = time();
touchActive($active, $id, $activeFile);

foreach ($clients as $cid => $c) {
    if ($cid !== $id && isset($c['seen']) && time() - (int)$c['seen'] > PRUNE_AFTER) unset($clients[$cid]);
}

if (!save($dataFile, $clients)) done($lock, 500, ['error' => 'Could not write pin data; check directory permissions.']);
done($lock, 200, ['ok' => true, 'you' => ['id' => $id, 'color' => $clients[$id]['color'], 'name' => is_string($clients[$id]['name'] ?? null) ? $clients[$id]['name'] : ''], 'pins' => publicPins($clients)]);
