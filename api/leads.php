<?php
// Contatos deixados no pop-up de candidatura das vagas.
// POST {action:"add", ...}            grava um contato (público)
// POST {action:"list", senha}         lista os contatos (protegido)
// POST {action:"delete", senha, id}   remove um contato (pedido de remoção, LGPD)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Hash bcrypt da senha da página /leads/. Para trocar a senha, gere um novo hash
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
$dataFile = $dataDir . '/leads.json';

function out($code, $body) { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; }
function clean($v, $max = 200) { return mb_substr(trim(strip_tags((string)($v ?? ''))), 0, $max); }
function readLeads($f) {
    if (!file_exists($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function writeLeads($f, $d) {
    $tmp = $f . '.tmp.' . uniqid('', true);
    if (file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) return false;
    return rename($tmp, $f);
}
function auth($in) {
    if (!password_verify((string)($in['senha'] ?? ''), SENHA_HASH)) { sleep(1); out(401, ['error' => 'Senha incorreta']); }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['error' => 'Use POST']);
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) out(400, ['error' => 'JSON inválido']);
$action = $in['action'] ?? 'add';

if ($action === 'add') {
    if (!empty($in['site'])) out(200, ['ok' => true]); // campo isca contra robôs
    $lead = [
        'id'          => bin2hex(random_bytes(6)),
        'data'        => date('c'),
        'nome'        => clean($in['nome'] ?? '', 120),
        'email'       => clean($in['email'] ?? '', 160),
        'funcao'      => clean($in['funcao'] ?? '', 120),
        'experiencia' => ($in['experiencia'] ?? '') === 'sim' ? 'sim' : 'nao',
        'vaga'        => clean($in['vaga'] ?? '', 160),
        'empresa'     => clean($in['empresa'] ?? '', 120),
    ];
    if ($lead['nome'] === '' || !filter_var($lead['email'], FILTER_VALIDATE_EMAIL) || $lead['funcao'] === '') {
        out(422, ['error' => 'Preencha nome, e-mail válido e função']);
    }
    $fp = fopen($dataFile . '.lock', 'c');
    flock($fp, LOCK_EX);
    $leads = readLeads($dataFile);
    $leads[] = $lead;
    $ok = writeLeads($dataFile, $leads);
    flock($fp, LOCK_UN);
    out($ok ? 200 : 500, $ok ? ['ok' => true] : ['error' => 'Falha ao salvar']);
}

if ($action === 'list') {
    auth($in);
    out(200, ['leads' => array_reverse(readLeads($dataFile))]);
}

if ($action === 'delete') {
    auth($in);
    $id = (string)($in['id'] ?? '');
    $fp = fopen($dataFile . '.lock', 'c');
    flock($fp, LOCK_EX);
    $leads = array_values(array_filter(readLeads($dataFile), fn($l) => ($l['id'] ?? '') !== $id));
    $ok = writeLeads($dataFile, $leads);
    flock($fp, LOCK_UN);
    out($ok ? 200 : 500, ['ok' => $ok]);
}

out(400, ['error' => 'Ação desconhecida']);
