<?php
// Peças comuns das APIs (contatos e vagas enviadas). Não responde nada sozinho.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Hash bcrypt da senha do painel /leads/. Para trocar a senha, gere um novo hash
// com password_hash() e substitua aqui.
const SENHA_HASH = '$2y$11$sE2KfOD0fhR1wef/HfvVMOVb/kZU.oLX5XoyKeWho0nvzSPguD74G';

// Guarda fora da pasta pública (public_html), onde as publicações do Hostinger
// não mexem e a internet não alcança. Se não der para escrever lá, usa data/,
// que também é bloqueada para acesso externo pelo .htaccess.
$dataDir = dirname(__DIR__, 2) . '/mr-dados';
if (!is_dir($dataDir)) @mkdir($dataDir, 0750, true);
if (!is_dir($dataDir) || !is_writable($dataDir)) {
    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
}

function out($code, $body) { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; }
function clean($v, $max = 200) { return mb_substr(trim(strip_tags((string)($v ?? ''))), 0, $max); }
function readJson($f, $padrao = []) {
    if (!file_exists($f)) return $padrao;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : $padrao;
}
function writeJson($f, $d) {
    $tmp = $f . '.tmp.' . uniqid('', true);
    if (file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) return false;
    return rename($tmp, $f);
}
// Lê, altera e grava um arquivo com trava, para dois envios ao mesmo tempo não se atropelarem
function mexer($f, callable $fn, $padrao = []) {
    $fp = fopen($f . '.lock', 'c');
    flock($fp, LOCK_EX);
    $d = readJson($f, $padrao);
    $r = $fn($d);
    $ok = writeJson($f, $d);
    flock($fp, LOCK_UN);
    return $ok ? ($r ?? true) : false;
}
// Limite de envios por IP numa janela de tempo, contra robôs que enchem formulário
function limitar($nome, $max = 10, $janela = 3600) {
    global $dataDir;
    $f = $dataDir . '/limite-' . preg_replace('/[^a-z0-9-]/', '', $nome) . '.json';
    $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . $nome);
    $agora = time();
    $fp = fopen($f . '.lock', 'c'); flock($fp, LOCK_EX);
    $d = readJson($f);
    foreach ($d as $k => $l) { $d[$k] = array_values(array_filter((array)$l, fn($t) => $agora - $t < $janela)); if (!$d[$k]) unset($d[$k]); }
    $estourou = count($d[$ip] ?? []) >= $max;
    if (!$estourou) { $d[$ip][] = $agora; writeJson($f, $d); }
    flock($fp, LOCK_UN);
    if ($estourou) out(429, ['error' => 'Muitos envios seguidos. Tente de novo mais tarde.']);
}
function auth($in) {
    if (!password_verify((string)($in['senha'] ?? ''), SENHA_HASH)) {
        // Cada senha errada conta: 10 erros em 15 minutos bloqueiam o IP por um tempo (contra quem tenta adivinhar)
        limitar('login', 10, 900);
        sleep(1); out(401, ['error' => 'Senha incorreta']);
    }
}
function entrada() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['error' => 'Use POST']);
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) out(400, ['error' => 'JSON inválido']);
    return $in;
}
