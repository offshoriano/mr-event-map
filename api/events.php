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

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $name     = trim(strip_tags($input['name']     ?? ''));
        $month    = trim(strip_tags($input['month']    ?? ''));
        $location = trim(strip_tags($input['location'] ?? ''));

        if ($name === '' || $month === '' || $location === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Missing required fields: name, month, location']);
            exit;
        }

        $conf = [
            'id'       => trim(strip_tags($input['id']      ?? ('user_' . time()))),
            'name'     => $name,
            'dates'    => trim(strip_tags($input['dates']   ?? 'A confirmar')),
            'month'    => $month,
            'location' => $location,
            'access'   => trim(strip_tags($input['access']  ?? 'public')),
            'link'     => trim(strip_tags($input['link']    ?? '')),
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
        $confId = trim(strip_tags($input['confId'] ?? ''));
        $name   = trim(strip_tags($input['name']   ?? ''));

        if ($confId === '' || $name === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Missing required fields: confId, name']);
            exit;
        }

        $parallel = [
            'confId'    => $confId,
            'id'        => trim(strip_tags($input['id']      ?? ('p_' . (time() * 1000)))),
            'dateIso'   => trim(strip_tags($input['dateIso'] ?? '')),
            'timeVal'   => trim(strip_tags($input['timeVal'] ?? '')),
            'day'       => trim(strip_tags($input['day']     ?? 'A confirmar')),
            'name'      => $name,
            'desc'      => trim(strip_tags($input['desc']    ?? '')),
            'access'    => trim(strip_tags($input['access']  ?? 'convite')),
            'attendees' => [],
        ];

        if (!empty($input['attendees']) && is_array($input['attendees'])) {
            foreach ($input['attendees'] as $a) {
                $parallel['attendees'][] = trim(strip_tags($a));
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
        $confId    = trim(strip_tags($input['confId']    ?? ''));
        $eventKey  = trim(strip_tags($input['eventKey'] ?? ''));
        $eventType = trim(strip_tags($input['eventType'] ?? ''));
        $name      = trim(strip_tags($input['name']     ?? ''));

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
            'company'   => trim(strip_tags($input['company'] ?? '')),
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
