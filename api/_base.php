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
function auth($in) {
    if (!password_verify((string)($in['senha'] ?? ''), SENHA_HASH)) { sleep(1); out(401, ['error' => 'Senha incorreta']); }
}
function entrada() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['error' => 'Use POST']);
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) out(400, ['error' => 'JSON inválido']);
    return $in;
}
