<?php
// Alerta de vagas por e-mail (envio pela Resend).
// POST {action:"inscrever", email, funcoes, regime, iniciante}   inscreve e manda o e-mail de confirmação (público)
// GET  ?c=TOKEN                                                  confirma a inscrição (link do e-mail)
// GET  ?sair=TOKEN                                               cancela a inscrição (link de todo e-mail)
// POST {action:"painel", senha}                                  inscritos, configuração e resumo para o WhatsApp (protegido)
// POST {action:"config", senha, chave, remetente}                guarda a chave da Resend (protegido)
// POST {action:"enviar", senha, teste?}                          dispara o alerta da semana agora (protegido)
// POST {action:"remover", senha, email}                          remove um inscrito (protegido)
// O envio semanal também roda sozinho: api/cliques.php chama alertaSemanal() quando chega a hora.
require_once __DIR__ . '/_base.php';
$alertasFile = $dataDir . '/alertas.json';
$alertasCfg = $dataDir . '/alertas-config.json';
const ALERTA_SITE = 'https://mrgroup.com.br';
const ENTRADA_RX = '/trainee|est[aá]gi|aprendiz|apprentice|\bintern(ship)?\b|jovem|cadet|ajudante|homem de [aá]rea|sem experi|auxiliar de (plataforma|opera)/iu';

function resend($cfg, $payload) {
    if (empty($cfg['chave'])) return false;
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['chave'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code >= 200 && $code < 300;
}
function remetente($cfg) { return $cfg['remetente'] ?? 'MR Group Vagas <alertas@mrgroup.com.br>'; }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function moldura($titulo, $miolo, $sair = '') {
    return '<div style="background:#0D1117;padding:24px 12px;font-family:Arial,sans-serif"><div style="max-width:600px;margin:0 auto;background:#111820;border:1px solid #1C2733;border-radius:6px;padding:28px">'
        . '<div style="color:#C9933A;font-size:12px;letter-spacing:3px;text-transform:uppercase;font-weight:bold">MR Group · Alerta de vagas</div>'
        . '<h1 style="color:#EEE9E2;font-size:24px;margin:10px 0 18px">' . h($titulo) . '</h1>' . $miolo
        . '<p style="color:#7A8FA6;font-size:12px;line-height:1.6;margin-top:28px;border-top:1px solid #1C2733;padding-top:16px">Você recebe este e-mail porque se inscreveu no alerta de vagas do mrgroup.com.br.'
        . ($sair ? ' <a href="' . h($sair) . '" style="color:#E8B86D">Cancelar a inscrição</a>.' : '') . '</p></div></div>';
}

// Vagas que entram no alerta de um inscrito: novas na semana e que batem com o que ele pediu
// Sem acento e em minúsculas, para "mecânico" achar "Mecânica" e "Mecanico"
function norm($s) { $s = mb_strtolower((string)$s); $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s); return $t !== false ? preg_replace('/[^a-z0-9 ]/', '', $t) : $s; }
function vagasPara($inscrito, $vagas, $desde) {
    // Cada função vira um radical: tira o final (o, a, os, as, es) das palavras longas
    $fun = array_filter(array_map(fn($f) => preg_replace('/(os|as|es|o|a)$/', '', trim(norm($f))), explode(',', $inscrito['funcoes'] ?? '')), fn($f) => mb_strlen($f) >= 2);
    return array_values(array_filter($vagas, function ($v) use ($inscrito, $fun, $desde) {
        if (($v['vistaEm'] ?? '') < $desde) return false;
        if (($inscrito['regime'] ?? '') && $inscrito['regime'] !== 'Ambos' && ($v['regime'] ?? '') !== $inscrito['regime']) return false;
        $t = norm($v['titulo'] . ' ' . ($v['area'] ?? ''));
        if (!empty($inscrito['iniciante']) && preg_match(ENTRADA_RX, $t)) return true;
        if (!$fun) return empty($inscrito['iniciante']);
        foreach ($fun as $f) if ($f !== '' && mb_strpos($t, $f) !== false) return true;
        return false;
    }));
}
function emailAlerta($inscrito, $lista) {
    $sair = ALERTA_SITE . '/api/alertas.php?sair=' . $inscrito['token'];
    $itens = '';
    foreach (array_slice($lista, 0, 25) as $v) {
        $itens .= '<a href="' . ALERTA_SITE . '/vagas/#' . h($v['id']) . '" style="display:block;text-decoration:none;background:#0D1117;border:1px solid #1C2733;border-radius:4px;padding:12px 14px;margin-bottom:8px">'
            . '<b style="color:#EEE9E2;font-size:15px">' . h($v['titulo']) . '</b><br><span style="color:#A0B2C4;font-size:13px">' . h($v['empresa']) . ' · ' . h($v['local'])
            . ' · ' . (($v['regime'] ?? '') === 'Offshore' ? 'Embarcado' : 'Em terra') . '</span></a>';
    }
    $mais = count($lista) > 25 ? '<p style="color:#A0B2C4">E mais ' . (count($lista) - 25) . ' vagas no site.</p>' : '';
    $miolo = '<p style="color:#A0B2C4;font-size:15px;line-height:1.6">Estas são as vagas novas da semana que combinam com o que você pediu. Cada uma tem vídeo de preparação no site.</p>'
        . $itens . $mais . '<a href="' . ALERTA_SITE . '/vagas/" style="display:inline-block;margin-top:14px;background:#C9933A;color:#090C10;font-weight:bold;text-decoration:none;padding:13px 20px;border-radius:3px">Ver todas as vagas</a>';
    return moldura(count($lista) . ' vagas novas para você', $miolo, $sair);
}
function alertaSemanal($forcar = false) {
    global $alertasFile, $alertasCfg, $dataDir;
    $cfg = readJson($alertasCfg);
    if (empty($cfg['chave'])) return ['erro' => 'Falta a chave da Resend'];
    $semana = date('o-W');
    // Toda segunda a partir das 9h, uma vez por semana
    if (!$forcar && (date('N') != 1 || (int)date('G') < 9 || ($cfg['ultima_semana'] ?? '') === $semana)) return ['pulou' => true];
    $fp = fopen($alertasCfg . '.envio.lock', 'c');
    if (!flock($fp, LOCK_EX | LOCK_NB)) return ['ocupado' => true];
    $cfg = readJson($alertasCfg);
    if (!$forcar && ($cfg['ultima_semana'] ?? '') === $semana) { flock($fp, LOCK_UN); return ['pulou' => true]; }
    mexer($alertasCfg, function (&$c) use ($semana) { $c['ultima_semana'] = $semana; });
    $vagas = json_decode((string)@file_get_contents(dirname(__DIR__) . '/vagas/automaticas.json'), true)['vagas'] ?? [];
    $desde = date('Y-m-d', strtotime('-7 days'));
    $env = 0;
    foreach (readJson($alertasFile) as $i) {
        if (($i['status'] ?? '') !== 'ativo') continue;
        $lista = vagasPara($i, $vagas, $desde);
        if (!$lista) continue;
        if (resend($cfg, ['from' => remetente($cfg), 'to' => [$i['email']], 'subject' => count($lista) . ' vagas novas para você esta semana',
            'html' => emailAlerta($i, $lista), 'headers' => ['List-Unsubscribe' => '<' . ALERTA_SITE . '/api/alertas.php?sair=' . $i['token'] . '>']])) $env++;
        usleep(250000); // a Resend aceita poucos envios por segundo
    }
    mexer($alertasCfg, function (&$c) use ($env) { $c['ultimo_envio'] = date('c'); $c['ultimo_total'] = $env; });
    flock($fp, LOCK_UN);
    return ['enviados' => $env];
}
// Resumo das vagas novas de ontem e hoje, pronto para colar no canal do WhatsApp
function resumoWhatsApp() {
    $vagas = json_decode((string)@file_get_contents(dirname(__DIR__) . '/vagas/automaticas.json'), true)['vagas'] ?? [];
    $desde = date('Y-m-d', strtotime('-1 day'));
    $novas = array_values(array_filter($vagas, fn($v) => ($v['vistaEm'] ?? '') >= $desde));
    usort($novas, fn($a, $b) => (($b['regime'] ?? '') === 'Offshore') <=> (($a['regime'] ?? '') === 'Offshore'));
    $txt = "*Vagas novas no setor de óleo e gás*\n" . date('d/m') . "\n\n";
    foreach (array_slice($novas, 0, 12) as $v) {
        $txt .= "*{$v['titulo']}*\n{$v['empresa']} · {$v['local']} · " . ($v['regime'] === 'Offshore' ? 'Embarcado' : 'Em terra') . "\n" . ALERTA_SITE . "/vagas/#{$v['id']}\n\n";
    }
    $txt .= count($novas) > 12 ? 'E mais ' . (count($novas) - 12) . " vagas novas em mrgroup.com.br/vagas\n" : "Todas as vagas em mrgroup.com.br/vagas\n";
    return ['texto' => $txt, 'total' => count($novas)];
}

// Quando incluído por outro arquivo (cliques.php), só disponibiliza as funções
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'alertas.php') return;

// Links dos e-mails (confirmar e sair) abrem no navegador
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $tok = (string)($_GET['c'] ?? $_GET['sair'] ?? '');
    $msg = 'Link inválido ou expirado.';
    if (preg_match('/^[a-f0-9]{32}$/', $tok)) {
        $novo = isset($_GET['c']) ? 'ativo' : 'cancelado';
        $achou = mexer($alertasFile, function (&$t) use ($tok, $novo) {
            foreach ($t as &$i) if (($i['token'] ?? '') === $tok) { $i['status'] = $novo; $i['atualizado'] = date('c'); return true; }
            return false;
        });
        if ($achou === true) $msg = $novo === 'ativo' ? 'Inscrição confirmada. Toda segunda você recebe as vagas novas que combinam com você.' : 'Pronto, você não vai mais receber o alerta de vagas.';
    }
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Alerta de vagas · MR Group</title></head>'
        . '<body style="margin:0;background:#0D1117;color:#EEE9E2;font-family:Arial,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center">'
        . '<div style="max-width:480px;padding:30px;text-align:center"><p style="color:#C9933A;letter-spacing:3px;font-size:12px;text-transform:uppercase">MR Group · Alerta de vagas</p>'
        . '<h1 style="font-size:24px;line-height:1.35">' . h($msg) . '</h1><a href="/vagas/" style="display:inline-block;margin-top:16px;background:#C9933A;color:#090C10;font-weight:bold;text-decoration:none;padding:13px 20px;border-radius:3px">Ver as vagas</a></div></body></html>';
    exit;
}

$in = entrada();
$action = $in['action'] ?? '';

if ($action === 'inscrever') {
    if (!empty($in['site'])) out(200, ['ok' => true]); // campo isca contra robôs
    limitar('alertas', 5);
    $email = mb_strtolower(clean($in['email'] ?? '', 160));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(422, ['error' => 'E-mail inválido']);
    $regime = in_array($in['regime'] ?? '', ['Offshore', 'Onshore', 'Ambos'], true) ? $in['regime'] : 'Ambos';
    $dados = ['funcoes' => clean($in['funcoes'] ?? '', 120), 'regime' => $regime, 'iniciante' => !empty($in['iniciante'])];
    $token = bin2hex(random_bytes(16));
    $ativo = false;
    mexer($alertasFile, function (&$t) use ($email, $dados, &$token, &$ativo) {
        foreach ($t as &$i) if ($i['email'] === $email) {
            $i = array_merge($i, $dados, ['atualizado' => date('c')]);
            $token = $i['token']; $ativo = ($i['status'] ?? '') === 'ativo'; return;
        }
        $t[] = ['email' => $email, 'token' => $token, 'status' => 'pendente', 'data' => date('c')] + $dados;
    });
    $cfg = readJson($alertasCfg);
    if (!$ativo) {
        resend($cfg, ['from' => remetente($cfg), 'to' => [$email], 'subject' => 'Confirme o seu alerta de vagas',
            'html' => moldura('Falta só confirmar', '<p style="color:#A0B2C4;font-size:15px;line-height:1.6">Clique no botão para começar a receber, toda segunda, as vagas novas que combinam com você.</p>'
                . '<a href="' . ALERTA_SITE . '/api/alertas.php?c=' . $token . '" style="display:inline-block;margin-top:10px;background:#C9933A;color:#090C10;font-weight:bold;text-decoration:none;padding:13px 20px;border-radius:3px">Confirmar inscrição</a>'
                . '<p style="color:#7A8FA6;font-size:13px;margin-top:16px">Se não foi você, é só ignorar este e-mail.</p>')]);
    }
    out(200, ['ok' => true, 'ativo' => $ativo]);
}

auth($in);

if ($action === 'painel') {
    $t = readJson($alertasFile); $cfg = readJson($alertasCfg);
    out(200, ['inscritos' => array_reverse(array_map(fn($i) => array_diff_key($i, ['token' => 1]), $t)),
        'config' => ['tem_chave' => !empty($cfg['chave']), 'remetente' => remetente($cfg), 'ultimo_envio' => $cfg['ultimo_envio'] ?? null, 'ultimo_total' => $cfg['ultimo_total'] ?? null],
        'whatsapp' => resumoWhatsApp()]);
}
if ($action === 'config') {
    $chave = trim((string)($in['chave'] ?? ''));
    $rem = clean($in['remetente'] ?? '', 120);
    mexer($alertasCfg, function (&$c) use ($chave, $rem) { if ($chave !== '') $c['chave'] = $chave; if ($rem !== '') $c['remetente'] = $rem; });
    out(200, ['ok' => true]);
}
if ($action === 'enviar') {
    out(200, alertaSemanal(true));
}
if ($action === 'remover') {
    $email = mb_strtolower(clean($in['email'] ?? '', 160));
    $ok = mexer($alertasFile, function (&$t) use ($email) { $t = array_values(array_filter($t, fn($i) => $i['email'] !== $email)); });
    out($ok ? 200 : 500, ['ok' => (bool)$ok]);
}
out(400, ['error' => 'Ação desconhecida']);
