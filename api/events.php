<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dataDir  = __DIR__ . '/../data';
$dataFile = $dataDir . '/events.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

function readData($dataFile) {
    if (!file_exists($dataFile)) {
        return ['conferences' => [], 'parallels' => [], 'goers' => []];
    }
    $raw = file_get_contents($dataFile);
    if ($raw === false) {
        return ['conferences' => [], 'parallels' => [], 'goers' => []];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['conferences' => [], 'parallels' => [], 'goers' => []];
    }
    if (!isset($data['conferences'])) $data['conferences'] = [];
    if (!isset($data['parallels']))   $data['parallels']   = [];
    if (!isset($data['goers']))       $data['goers']       = [];
    return $data;
}

function writeData($dataFile, $data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $tmp  = $dataFile . '.tmp.' . uniqid('', true);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $dataFile);
}

// ── GET ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = readData($dataFile);
    echo json_encode($data);
    exit;
}

// Texto curto e sem tags; id só com letras, números, _ e -; link só http(s)
function txt($v, $max = 200) { return mb_substr(trim(strip_tags((string)($v ?? ''))), 0, $max); }
function idok($v, $padrao) { $v = (string)($v ?? ''); return preg_match('/^[A-Za-z0-9_-]{1,80}$/', $v) ? $v : $padrao; }
function linkok($v) { $v = trim((string)($v ?? '')); return preg_match('#^https?://[^\s"\'<>]+$#i', $v) ? mb_substr($v, 0, 300) : ''; }

// Limite por IP: até 20 envios por hora, para ninguém encher a agenda com lixo
function limite($dataDir) {
    $f = $dataDir . '/limite-eventos.json';
    $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'mr');
    $agora = time();
    $d = file_exists($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $d = array_filter($d, fn($l) => is_array($l) && $agora - end($l) < 3600);
    $lista = array_values(array_filter($d[$ip] ?? [], fn($t) => $agora - $t < 3600));
    if (count($lista) >= 20) { http_response_code(429); echo json_encode(['error' => 'Muitos envios. Tente mais tarde.']); exit; }
    $lista[] = $agora; $d[$ip] = $lista;
    file_put_contents($f, json_encode($d), LOCK_EX);
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) { http_response_code(413); echo json_encode(['error' => 'Envio grande demais']); exit; }
    limite($dataDir);
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input) || empty($input['type'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing type field']);
        exit;
    }

    $type = $input['type'];
    $data = readData($dataFile);

    // ── Conference ────────────────────────────────────────────────────────────
    if ($type === 'conference') {
        $name     = txt($input['name'] ?? '', 160);
        $month    = txt($input['month'] ?? '', 20);
        $location = txt($input['location'] ?? '', 160);

        if ($name === '' || $month === '' || $location === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Missing required fields: name, month, location']);
            exit;
        }

        $conf = [
            'id'       => idok($input['id'] ?? '', 'user_' . time()),
            'name'     => $name,
            'dates'    => txt($input['dates'] ?? 'A confirmar', 40),
            'month'    => $month,
            'location' => $location,
            'access'   => in_array($input['access'] ?? '', ['public', 'convite', 'restrito'], true) ? $input['access'] : 'public',
            'link'     => linkok($input['link'] ?? ''),
        ];

        // De-duplicate by id
        $exists = false;
        foreach ($data['conferences'] as $c) {
            if ($c['id'] === $conf['id']) { $exists = true; break; }
        }
        if (!$exists) {
            $data['conferences'][] = $conf;
            writeData($dataFile, $data);
        }

        echo json_encode(['success' => true, 'event' => $conf]);
        exit;
    }

    // ── Parallel ──────────────────────────────────────────────────────────────
    if ($type === 'parallel') {
        $confId = idok($input['confId'] ?? '', '');
        $name   = txt($input['name'] ?? '', 160);

        if ($confId === '' || $name === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Missing required fields: confId, name']);
            exit;
        }

        $parallel = [
            'confId'    => $confId,
            'id'        => idok($input['id'] ?? '', 'p_' . (time() * 1000)),
            'dateIso'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($input['dateIso'] ?? '')) ? $input['dateIso'] : '',
            'timeVal'   => preg_match('/^\d{2}:\d{2}$/', (string)($input['timeVal'] ?? '')) ? $input['timeVal'] : '',
            'day'       => txt($input['day'] ?? 'A confirmar', 60),
            'name'      => $name,
            'desc'      => txt($input['desc'] ?? '', 400),
            'access'    => in_array($input['access'] ?? '', ['convite', 'aberto', 'restrito'], true) ? $input['access'] : 'convite',
            'attendees' => [],
        ];

        if (!empty($input['attendees']) && is_array($input['attendees'])) {
            foreach (array_slice($input['attendees'], 0, 30) as $a) {
                $parallel['attendees'][] = txt(is_string($a) ? $a : '', 80);
            }
            $parallel['attendees'] = array_values(array_filter($parallel['attendees']));
        }

        // De-duplicate: same confId + name + day
        $exists = false;
        foreach ($data['parallels'] as $p) {
            if ($p['confId'] === $parallel['confId'] &&
                $p['name']   === $parallel['name']   &&
                $p['day']    === $parallel['day']) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $data['parallels'][] = $parallel;
            writeData($dataFile, $data);
        }

        echo json_encode(['success' => true, 'event' => $parallel]);
        exit;
    }

    // ── Goer (Eu vou!) ────────────────────────────────────────────────────────
    if ($type === 'goer') {
        $confId    = idok($input['confId'] ?? '', '');
        $eventKey  = idok($input['eventKey'] ?? '', '');
        $eventType = ($input['eventType'] ?? '') === 'parallel' ? 'parallel' : 'conference';
        $name      = txt($input['name'] ?? '', 80);

        if ($confId === '' || $name === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Missing required fields: confId, name']);
            exit;
        }

        $goer = [
            'eventType' => $eventType,
            'confId'    => $confId,
            'eventKey'  => $eventKey,
            'name'      => $name,
            'company'   => txt($input['company'] ?? '', 80),
            'ts'        => time() * 1000,
        ];

        if (!isset($data['goers'])) $data['goers'] = [];
        $data['goers'][] = $goer;
        writeData($dataFile, $data);

        echo json_encode(['success' => true, 'goer' => $goer]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown type']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
