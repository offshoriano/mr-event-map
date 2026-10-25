<?php
// Pedidos do Currículo Sob Medida: do upload à entrega.
// Situações: aguardando_pagamento → na_fila → gerando → revisar → entregue (ou erro). Pedidos de teste do painel começam em na_fila.
// Os arquivos ficam em <dados>/curriculo-pedidos/<id>/ e são apagados 30 dias depois da entrega (promessa da página e da política de privacidade).
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }
require_once __DIR__ . '/_claude.php';
require_once __DIR__ . '/_docs.php';

$pedidosFile = $dataDir . '/curriculo-pedidos.json';
$pedidosDir = $dataDir . '/curriculo-pedidos';
const AVISO_CURRICULO_PEDIDO = 'mateus.rangel@mrgroup.com.br';

function pastaPedido($id) { global $pedidosDir; return $pedidosDir . '/' . preg_replace('/[^a-f0-9]/', '', $id); }
function pedido($id) { global $pedidosFile; foreach (readJson($pedidosFile) as $p) if ($p['id'] === $id) return $p; return null; }
function pedidoPorToken($t) { global $pedidosFile; foreach (readJson($pedidosFile) as $p) if (hash_equals($p['token'], (string)$t)) return $p; return null; }
function atualizarPedido($id, $campos) {
    global $pedidosFile;
    return mexer($pedidosFile, function (&$todos) use ($id, $campos) {
        foreach ($todos as $i => $p) if ($p['id'] === $id) { $todos[$i] = array_merge($p, $campos, ['atualizado' => date('c')]); return true; }
        return false;
    });
}
function resultadoPedido($id) { return readJson(pastaPedido($id) . '/resultado.json', null); }

// Recebe os arquivos enviados (currículo obrigatório, LinkedIn opcional) e cria o pedido
function criarPedido($dados, $situacao) {
    global $pedidosFile, $pedidosDir;
    $id = bin2hex(random_bytes(6));
    $dir = pastaPedido($id);
    if (!is_dir($pedidosDir)) @mkdir($pedidosDir, 0750, true);
    @mkdir($dir, 0750, true);
    $arquivos = [];
    foreach (['cv' => true, 'linkedin' => false] as $campo => $obrigatorio) {
        $f = $_FILES[$campo] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { if ($obrigatorio) return [false, 'Envie o seu currículo em PDF ou Word (.docx)']; continue; }
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 8 * 1024 * 1024) return [false, 'Arquivo grande demais ou com erro (máximo 8 MB)'];
        $cab = (string)file_get_contents($f['tmp_name'], false, null, 0, 5);
        $ext = strpos($cab, '%PDF') === 0 ? 'pdf' : (strpos($cab, "PK\x03\x04") === 0 ? 'docx' : '');
        if ($ext === '' || ($campo === 'linkedin' && $ext !== 'pdf')) return [false, $campo === 'cv' ? 'O currículo precisa ser PDF ou Word (.docx)' : 'O LinkedIn precisa ser o PDF exportado do perfil'];
        if (!move_uploaded_file($f['tmp_name'], "$dir/$campo.$ext")) return [false, 'Não foi possível salvar o arquivo'];
        $arquivos[$campo] = "$campo.$ext";
    }
    $p = array_merge($dados, ['id' => $id, 'token' => bin2hex(random_bytes(16)), 'data' => date('c'), 'atualizado' => date('c'),
        'situacao' => $situacao, 'arquivos' => $arquivos]);
    $ok = mexer($pedidosFile, function (&$todos) use ($p) { $todos[] = $p; });
    return $ok ? [true, $p] : [false, 'Falha ao salvar o pedido'];
}

// Gera o currículo de um pedido e deixa pronto para revisão no painel
function processarPedido($id) {
    @set_time_limit(900); ignore_user_abort(true);
    $p = pedido($id);
    if (!$p || !in_array($p['situacao'], ['na_fila', 'erro', 'revisar'], true)) return;
    atualizarPedido($id, ['situacao' => 'gerando', 'erro' => '']);
    $dir = pastaPedido($id);
    $arqs = ['CURRÍCULO ATUAL' => isset($p['arquivos']['cv']) ? "$dir/{$p['arquivos']['cv']}" : null,
             'PERFIL DO LINKEDIN (PDF)' => isset($p['arquivos']['linkedin']) ? "$dir/{$p['arquivos']['linkedin']}" : null];
    $extras = 'Já trabalhou embarcado: ' . ($p['experiencia'] === 'sim' ? 'sim' : 'não')
        . ($p['obs'] ?? '' ? "\nObservações do cliente: {$p['obs']}" : '')
        . ($p['nota_revisor'] ?? '' ? "\nPedido do revisor para esta nova versão: {$p['nota_revisor']}" : '');
    $r = gerarCurriculo($arqs, $p['vaga'], $extras);
    if (!$r['ok']) { atualizarPedido($id, ['situacao' => 'erro', 'erro' => $r['erro']]); return; }
    writeJson("$dir/resultado.json", $r['resultado']);
    docxCurriculo($r['resultado'], "$dir/curriculo.docx");
    pdfCurriculo($r['resultado'], "$dir/curriculo.pdf");
    atualizarPedido($id, ['situacao' => 'revisar', 'uso' => $r['uso']]);
    avisar(AVISO_CURRICULO_PEDIDO, "Currículo para revisar: {$p['nome']}", "O currículo de {$p['nome']} para a vaga abaixo está pronto para revisão.\n\n"
        . mb_substr($p['vaga'], 0, 300) . "\n\nRevise e aprove no painel: https://mrgroup.com.br/leads/#curriculo\n");
}

// Depois de revisado: refaz os arquivos com o texto final e manda o link de entrega ao cliente
function entregarPedido($id) {
    $p = pedido($id); $r = resultadoPedido($id);
    if (!$p || !$r) return [false, 'Pedido sem currículo gerado'];
    $dir = pastaPedido($id);
    docxCurriculo($r, "$dir/curriculo.docx"); pdfCurriculo($r, "$dir/curriculo.pdf");
    $link = 'https://mrgroup.com.br/curriculo/entrega/?t=' . $p['token'];
    $nome = explode(' ', trim($p['nome']))[0];
    $enviado = false;
    if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
        $html = '<div style="background:#0D1117;padding:24px 12px;font-family:Arial,sans-serif"><div style="max-width:600px;margin:0 auto;background:#111820;border:1px solid #1C2733;border-radius:6px;padding:28px;color:#C9D3DD;font-size:16px;line-height:1.65">'
            . '<p>Oi, ' . htmlspecialchars($nome) . '.</p><p>O seu currículo para a vaga está pronto. No link abaixo você baixa o arquivo em Word e em PDF, copia a carta de apresentação e os ajustes do LinkedIn, e vê o que ainda falta para essa vaga.</p>'
            . '<p style="margin:24px 0"><a href="' . $link . '" style="background:#C9933A;color:#090C10;text-decoration:none;font-weight:bold;padding:13px 22px;border-radius:3px;display:inline-block">Ver o meu currículo</a></p>'
            . '<p>Uma dica antes de mandar: se for por e-mail, coloque o nome da vaga no assunto e a carta no corpo da mensagem. Em plataforma como a Gupy, suba o arquivo em Word.</p>'
            . '<p>Boa sorte. Se precisar de ajuste, é só responder este e-mail.</p><p>Matheus Rangel<br><span style="color:#7A8FA6">MR Group</span></p>'
            . '<p style="color:#7A8FA6;font-size:12px;border-top:1px solid #1C2733;padding-top:14px">O link fica no ar por 30 dias. Depois disso apagamos os seus arquivos, como prometido na nossa política de privacidade.</p></div></div>';
        $enviado = resendEnviar($p['email'], "$nome, o seu currículo está pronto", $html)
            || avisar($p['email'], "$nome, o seu currículo está pronto", "Oi, $nome.\n\nO seu currículo para a vaga está pronto. Baixe em Word e PDF, copie a carta de apresentação e os ajustes do LinkedIn neste link:\n\n$link\n\nO link fica no ar por 30 dias.\n\nMatheus Rangel\nMR Group\n");
    }
    atualizarPedido($id, ['situacao' => 'entregue', 'entregue_em' => date('c'), 'email_enviado' => $enviado]);
    return [true, ['link' => $link, 'enviado' => $enviado]];
}

function apagarPedido($id) {
    global $pedidosFile;
    $dir = pastaPedido($id);
    foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
    @rmdir($dir);
    return mexer($pedidosFile, function (&$todos) use ($id) { $todos = array_values(array_filter($todos, fn($x) => $x['id'] !== $id)); });
}

// Apaga os arquivos de pedidos entregues há mais de 30 dias e de pedidos nunca pagos há mais de 7
function limparPedidosAntigos() {
    global $pedidosFile;
    $agora = time();
    mexer($pedidosFile, function (&$todos) use ($agora) {
        foreach ($todos as $i => $p) {
            $velhoEntregue = $p['situacao'] === 'entregue' && $agora - strtotime($p['entregue_em'] ?? $p['atualizado']) > 30 * 86400;
            $naoPago = $p['situacao'] === 'aguardando_pagamento' && $agora - strtotime($p['data']) > 7 * 86400;
            if (!$velhoEntregue && !$naoPago) continue;
            $dir = pastaPedido($p['id']);
            foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
            @rmdir($dir);
            if ($naoPago) { unset($todos[$i]); continue; }
            $todos[$i] = ['id' => $p['id'], 'token' => $p['token'], 'data' => $p['data'], 'atualizado' => date('c'), 'situacao' => 'arquivado',
                'nome' => $p['nome'], 'email' => $p['email'], 'origem' => $p['origem'] ?? '', 'vaga' => mb_substr($p['vaga'], 0, 120), 'arquivos' => []];
        }
        $todos = array_values($todos);
    });
}

// Responde já e continua trabalhando depois (a geração leva alguns minutos)
function responderEContinuar($corpo, callable $depois) {
    http_response_code(200); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { @ob_end_flush(); @flush(); }
    $depois();
    exit;
}
