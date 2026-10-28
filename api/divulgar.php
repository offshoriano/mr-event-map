<?php
// Post do dia no canal do Telegram e no canal do WhatsApp.
// O robô diário grava vagas/post-do-dia.json. Aqui o post sai no Telegram (sozinho) e vai para o e-mail
// do Matheus com o botão que abre o WhatsApp com o texto pronto (o WhatsApp não aceita postagem automática).
// POST {action:"publicar"}                          publica o post de hoje, uma vez por dia (público; o robô chama depois de atualizar)
// POST {action:"painel", senha}                     post de hoje e situação do Telegram (protegido)
// POST {action:"config", senha, token?, canal?}     guarda o bot e o canal do Telegram (protegido)
// POST {action:"testar", senha}                     manda uma mensagem de teste no canal (protegido)
// POST {action:"reenviar", senha}                   publica de novo o post de hoje (protegido)
require_once __DIR__ . '/_base.php';
$divulgarCfg = $dataDir . '/divulgar-config.json';
const AVISO_DIVULGAR = 'matheus.rangel@mrgroup.com.br';
const CANAL_WHATSAPP = 'https://whatsapp.com/channel/0029VbEcI5T6hENgxY9hl10L';

function postDoDia() { return json_decode((string)@file_get_contents(dirname(__DIR__) . '/vagas/post-do-dia.json'), true) ?: []; }

// Chamada à API do Telegram. Devolve [ok, resposta ou erro]
function telegram($token, $metodo, $params = []) {
    if ($token === '' || !function_exists('curl_init')) return [false, 'Telegram não configurado'];
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $metodo);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE)]);
    $r = json_decode((string)curl_exec($ch), true) ?: []; curl_close($ch);
    return !empty($r['ok']) ? [true, $r['result']] : [false, $r['description'] ?? 'Sem resposta do Telegram'];
}

function emailWhatsApp($post) {
    $html = '<div style="background:#0D1117;padding:24px 12px;font-family:Arial,sans-serif"><div style="max-width:600px;margin:0 auto;background:#111820;border:1px solid #1C2733;border-radius:6px;padding:24px;color:#C9D3DD;font-size:15px;line-height:1.6">'
        . '<p>O post de hoje está pronto. Segure o dedo no texto abaixo, copie, toque em "Abrir o canal" e cole como nova atualização.</p>'
        . '<p style="margin:20px 0"><a href="' . CANAL_WHATSAPP . '" style="background:#25D366;color:#08130c;text-decoration:none;font-weight:bold;padding:13px 22px;border-radius:3px;display:inline-block">Abrir o canal</a></p>'
        . '<pre style="white-space:pre-wrap;font-family:Arial,sans-serif;font-size:14px;background:#0D1117;border:1px solid #1C2733;padding:14px;color:#EEE9E2">' . htmlspecialchars($post['whatsapp']) . '</pre></div></div>';
    $assunto = 'Post do dia ' . date('d/m') . ($post['vagas'] ? " · {$post['vagas']} vagas novas" : '');
    return resendEnviar(AVISO_DIVULGAR, $assunto, $html, "Post do dia pronto. Copie o texto e cole no canal: " . CANAL_WHATSAPP . "\n\n{$post['whatsapp']}\n")
        || avisar(AVISO_DIVULGAR, $assunto, "Post do dia pronto. Copie o texto e cole no canal: " . CANAL_WHATSAPP . "\n\n{$post['whatsapp']}\n");
}

// Publica o post de hoje uma vez só. Sem post de hoje (o robô ainda não rodou) ou sem nada novo, não faz nada.
function publicarDia($forcar = false) {
    global $divulgarCfg;
    $post = postDoDia();
    if (($post['data'] ?? '') !== date('Y-m-d')) return ['situacao' => 'aguardando', 'motivo' => 'O post de hoje ainda não chegou ao site'];
    if (!empty($post['vazio'])) return ['situacao' => 'vazio', 'motivo' => 'Nada novo hoje'];
    $fp = fopen($divulgarCfg . '.publicar.lock', 'c');
    if (!flock($fp, LOCK_EX | LOCK_NB)) return ['situacao' => 'ocupado'];
    $cfg = readJson($divulgarCfg);
    if (!$forcar && ($cfg['ultimo'] ?? '') === $post['data']) { flock($fp, LOCK_UN); return ['situacao' => 'ja_publicado']; }
    mexer($divulgarCfg, function (&$c) use ($post) { $c['ultimo'] = $post['data']; });
    $tg = 'desligado';
    if (!empty($cfg['token']) && !empty($cfg['canal'])) {
        [$ok, $r] = telegram($cfg['token'], 'sendMessage', ['chat_id' => $cfg['canal'], 'text' => $post['telegram'], 'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true]]);
        $tg = $ok ? 'publicado' : 'erro: ' . $r;
    }
    $email = emailWhatsApp($post);
    mexer($divulgarCfg, function (&$c) use ($tg, $email) { $c['resultado'] = ['quando' => date('c'), 'telegram' => $tg, 'email' => $email]; });
    flock($fp, LOCK_UN);
    return ['situacao' => 'publicado', 'telegram' => $tg, 'email' => $email];
}

// Quando incluído por outro arquivo, só disponibiliza as funções
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'divulgar.php') return;

$in = entrada();
$action = $in['action'] ?? '';

if ($action === 'publicar') {
    limitar('divulgar', 60);
    out(200, publicarDia());
}

auth($in);

if ($action === 'painel') {
    $cfg = readJson($divulgarCfg); $post = postDoDia();
    out(200, ['post' => $post, 'hoje' => date('Y-m-d'), 'telegram' => !empty($cfg['token']) && !empty($cfg['canal']),
        'canal' => $cfg['canal'] ?? '', 'nome_canal' => $cfg['nome_canal'] ?? '', 'bot' => $cfg['bot'] ?? '',
        'ultimo' => $cfg['ultimo'] ?? '', 'resultado' => $cfg['resultado'] ?? null,
        'canal_whatsapp' => CANAL_WHATSAPP]);
}

if ($action === 'config') {
    $cfg = readJson($divulgarCfg);
    $token = preg_replace('/\s+/', '', (string)($in['token'] ?? ''));
    $canal = trim((string)($in['canal'] ?? ''));
    if (preg_match('~(?:t\.me/|telegram\.me/)([A-Za-z0-9_]{5,})~', $canal, $m)) $canal = '@' . $m[1];
    elseif ($canal !== '' && $canal[0] !== '@' && !preg_match('/^-?\d+$/', $canal)) $canal = '@' . $canal;
    if ($token !== '') {
        if (!preg_match('/^\d{6,}:[A-Za-z0-9_-]{30,}$/', $token)) out(422, ['error' => 'Essa não parece a chave de um bot do Telegram (número, dois pontos e letras, como o BotFather entrega)']);
        [$ok, $r] = telegram($token, 'getMe');
        if (!$ok) out(422, ['error' => 'O Telegram recusou a chave: ' . $r]);
        $cfg['token'] = $token; $cfg['bot'] = '@' . ($r['username'] ?? '');
    }
    if ($canal !== '') {
        if (empty($cfg['token'])) out(422, ['error' => 'Salve primeiro a chave do bot']);
        [$ok, $r] = telegram($cfg['token'], 'getChat', ['chat_id' => $canal]);
        if (!$ok) out(422, ['error' => "Não achei o canal $canal. Confira o endereço e se o bot foi adicionado como administrador. (" . $r . ')']);
        $cfg['canal'] = $canal; $cfg['nome_canal'] = $r['title'] ?? $canal;
    }
    $ok = mexer($divulgarCfg, function (&$c) use ($cfg) { $c = $cfg; });
    out($ok ? 200 : 500, ['ok' => (bool)$ok, 'bot' => $cfg['bot'] ?? '', 'canal' => $cfg['nome_canal'] ?? '']);
}

if ($action === 'testar') {
    $cfg = readJson($divulgarCfg);
    [$ok, $r] = telegram($cfg['token'] ?? '', 'sendMessage', ['chat_id' => $cfg['canal'] ?? '', 'text' => 'Canal ligado ao site da MR Group. As vagas novas, os artigos e os eventos do setor aparecem aqui todo dia.']);
    out(200, $ok ? ['ok' => true] : ['ok' => false, 'erro' => $r]);
}

if ($action === 'reenviar') out(200, publicarDia(true));

out(400, ['error' => 'Ação desconhecida']);
