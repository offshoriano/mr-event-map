<?php
// Currículo Sob Medida (/curriculo/).
// Lista de espera:
//   POST {action:"add", ...}                      grava a pessoa na lista e avisa por e-mail (público)
//   POST {action:"list", senha} / {action:"delete", senha, id}       lista e remove inscritos (protegido)
// Venda e entrega:
//   POST {action:"aberto"}                        se o produto está à venda (público)
//   POST multipart {action:"pedido", ...}         cria o pedido com os arquivos e abre o pagamento (público)
//   POST {action:"entrega", t}                    conteúdo da entrega para a página do cliente (público, pelo token)
//   GET  ?baixar=docx|pdf&t=TOKEN                 baixa o currículo entregue (público, pelo token)
// Painel:
//   POST {action:"config", senha, chave}          guarda e testa a chave da Anthropic
//   POST {action:"pedidos", senha}                pedidos, configuração e resultado de cada um
//   POST multipart {action:"teste", senha, ...}   gera um currículo sem pagamento, para testar e validar
//   POST {action:"gerar", senha, id, nota?}       gera de novo (com um pedido do revisor, se houver)
//   POST {action:"salvar", senha, id, resultado}  guarda a revisão feita no painel
//   POST {action:"aprovar", senha, id}            gera os arquivos finais e manda o link ao cliente
//   POST {action:"apagar", senha, id}             apaga o pedido e os arquivos
require __DIR__ . '/_stripe.php';
require __DIR__ . '/_pedidos.php';
$dataFile = $dataDir . '/curriculo.json';
const AVISO_CURRICULO = 'mateus.rangel@mrgroup.com.br';

// Download do currículo entregue
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['baixar'])) {
    $p = pedidoPorToken($_GET['t'] ?? '');
    $tipo = $_GET['baixar'] === 'pdf' ? 'pdf' : 'docx';
    $arq = $p ? pastaPedido($p['id']) . "/curriculo.$tipo" : '';
    if (!$p || $p['situacao'] !== 'entregue' || !is_file($arq)) out(404, ['error' => 'Arquivo não encontrado ou expirado']);
    $nome = preg_replace('/[^A-Za-z0-9]+/', '-', @iconv('UTF-8', 'ASCII//TRANSLIT', $p['nome']) ?: 'curriculo');
    header('Content-Type: ' . ($tipo === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    header('Content-Disposition: attachment; filename="Curriculo-' . trim($nome, '-') . ".$tipo\"");
    header('Content-Length: ' . filesize($arq));
    readfile($arq); exit;
}

// Pedido e teste chegam como formulário com arquivo; o resto, como JSON
$multipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0;
$in = $multipart ? $_POST : entrada();
$action = $in['action'] ?? 'add';

if ($action === 'add') {
    if (!empty($in['site'])) out(200, ['ok' => true]); // campo isca contra robôs
    limitar('curriculo', 8);
    $p = [
        'id'          => bin2hex(random_bytes(6)),
        'data'        => date('c'),
        'nome'        => clean($in['nome'] ?? '', 120),
        'email'       => clean($in['email'] ?? '', 160),
        'whats'       => clean($in['whats'] ?? '', 40),
        'funcao'      => clean($in['funcao'] ?? '', 120),
        'experiencia' => ($in['experiencia'] ?? '') === 'sim' ? 'sim' : 'nao',
        'origem'      => clean($in['origem'] ?? '', 60),
    ];
    if ($p['nome'] === '' || !filter_var($p['email'], FILTER_VALIDATE_EMAIL) || $p['funcao'] === '') {
        out(422, ['error' => 'Preencha nome, e-mail válido e a função que você busca']);
    }
    $novo = mexer($dataFile, function (&$todos) use ($p) {
        foreach ($todos as $i => $t) if (strcasecmp($t['email'], $p['email']) === 0) { $todos[$i] = array_merge($t, $p, ['id' => $t['id']]); return 'repetido'; }
        $todos[] = $p; return 'novo';
    });
    if (!$novo) out(500, ['error' => 'Falha ao salvar']);
    if ($novo === 'novo') {
        $cfg = readJson($dataDir . '/config.json');
        avisar(($cfg['aviso'] ?? '') ?: AVISO_CURRICULO, "Currículo Sob Medida: {$p['nome']} entrou na lista",
            "Nova pessoa na lista de espera do Currículo Sob Medida\n\nNome: {$p['nome']}\nE-mail: {$p['email']}\nWhatsApp: " . ($p['whats'] ?: 'não informado')
            . "\nFunção: {$p['funcao']}\nJá embarcou: {$p['experiencia']}\n\nLista completa no painel: https://mrgroup.com.br/leads/#curriculo\n");
    }
    out(200, ['ok' => true]);
}

if ($action === 'aberto') {
    out(200, ['aberto' => in_array('curriculo', stripeCfg()['abertos'] ?? [], true) && !empty(curriculoCfg()['chave'])]);
}

// Dados do pedido vindos do formulário (cliente ou painel)
function dadosPedido($in, $origem) {
    $vaga = mb_substr(trim(strip_tags((string)($in['vaga'] ?? ''))), 0, 20000);
    return ['nome' => clean($in['nome'] ?? '', 120), 'email' => clean($in['email'] ?? '', 160), 'whats' => clean($in['whats'] ?? '', 40),
        'experiencia' => ($in['experiencia'] ?? '') === 'sim' ? 'sim' : 'nao', 'vaga' => $vaga, 'obs' => clean($in['obs'] ?? '', 1000), 'origem' => $origem];
}

if ($action === 'pedido') {
    if (!empty($in['site'])) out(200, ['ok' => true]);
    limitar('curriculo-pedido', 6);
    $d = dadosPedido($in, 'site');
    if ($d['nome'] === '' || !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) out(422, ['error' => 'Preencha nome e e-mail válido']);
    if (mb_strlen($d['vaga']) < 120) out(422, ['error' => 'Cole o texto completo da vaga (atividades e requisitos), não só o link']);
    if (($in['aceite'] ?? '') !== 'sim') out(422, ['error' => 'Marque que você concorda com o uso dos seus dados para fazer o currículo']);
    if (!in_array('curriculo', stripeCfg()['abertos'] ?? [], true)) out(404, ['error' => 'As vendas ainda não abriram']);
    [$ok, $p] = criarPedido($d, 'aguardando_pagamento');
    if (!$ok) out(422, ['error' => $p]);
    $c = abrirCheckout('curriculo', $d['email'], $p['id']);
    if (!$c[0]) { apagarPedido($p['id']); out(502, ['error' => 'Não foi possível abrir o pagamento agora. Tente de novo em instantes.']); }
    atualizarPedido($p['id'], ['sessao' => $c[2]]);
    out(200, ['url' => $c[1]]);
}

if ($action === 'entrega') {
    limitar('curriculo-entrega', 60);
    $p = pedidoPorToken($in['t'] ?? '');
    if (!$p) out(404, ['error' => 'Link inválido']);
    if ($p['situacao'] === 'arquivado') out(410, ['error' => 'Este link expirou e os arquivos foram apagados depois de 30 dias']);
    if ($p['situacao'] !== 'entregue') out(202, ['pronto' => false]);
    $r = resultadoPedido($p['id']) ?: [];
    out(200, ['pronto' => true, 'nome' => explode(' ', trim($p['nome']))[0], 'objetivo' => $r['objetivo'] ?? '', 'carta' => $r['carta'] ?? '',
        'linkedin' => $r['linkedin'] ?? [], 'nota_antes' => $r['nota_antes'] ?? null, 'nota_depois' => $r['nota_depois'] ?? null,
        'faltando' => $r['faltando'] ?? [], 'expira' => date('c', strtotime($p['entregue_em']) + 30 * 86400)]);
}

auth($in);

if ($action === 'list') out(200, ['inscritos' => array_reverse(readJson($dataFile))]);

if ($action === 'delete') {
    $id = (string)($in['id'] ?? '');
    $ok = mexer($dataFile, function (&$todos) use ($id) {
        $todos = array_values(array_filter($todos, fn($x) => ($x['id'] ?? '') !== $id));
    });
    out($ok ? 200 : 500, ['ok' => $ok]);
}

if ($action === 'config') {
    $chave = trim((string)($in['chave'] ?? ''));
    if (!preg_match('/^sk-ant-[A-Za-z0-9_-]+$/', $chave)) out(422, ['error' => 'Essa não parece uma chave da Anthropic (começa com sk-ant-)']);
    $ch = curl_init('https://api.anthropic.com/v1/models/' . CLAUDE_MODELO);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['x-api-key: ' . $chave, 'anthropic-version: 2026-10-21']]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) out(422, ['error' => 'A Anthropic recusou a chave (' . $code . '): ' . (json_decode((string)$r, true)['error']['message'] ?? 'sem detalhe')]);
    $ok = mexer($curriculoCfgFile, function (&$c) use ($chave) { $c['chave'] = $chave; $c['salva_em'] = date('c'); });
    out($ok ? 200 : 500, ['ok' => (bool)$ok]);
}

if ($action === 'pedidos') {
    limparPedidosAntigos();
    $cfg = curriculoCfg();
    $lista = array_map(function ($p) {
        $p['resultado'] = in_array($p['situacao'], ['revisar', 'entregue'], true) ? resultadoPedido($p['id']) : null;
        $p['link'] = 'https://mrgroup.com.br/curriculo/entrega/?t=' . $p['token'];
        unset($p['token']);
        return $p;
    }, array_reverse(readJson($pedidosFile)));
    out(200, ['configurada' => !empty($cfg['chave']), 'final' => substr($cfg['chave'] ?? '', -4), 'aberto' => in_array('curriculo', stripeCfg()['abertos'] ?? [], true), 'pedidos' => $lista]);
}

if ($action === 'teste') {
    $d = dadosPedido($in, 'painel');
    if ($d['nome'] === '') $d['nome'] = 'Teste';
    if (mb_strlen($d['vaga']) < 80) out(422, ['error' => 'Cole o texto da vaga']);
    [$ok, $p] = criarPedido($d, 'na_fila');
    if (!$ok) out(422, ['error' => $p]);
    responderEContinuar(['ok' => true, 'id' => $p['id']], fn() => processarPedido($p['id']));
}

$id = (string)($in['id'] ?? '');
$p = pedido($id);
if (!$p) out(404, ['error' => 'Pedido não encontrado']);

if ($action === 'gerar') {
    if (!in_array($p['situacao'], ['erro', 'revisar'], true)) out(409, ['error' => 'Este pedido não pode ser gerado agora']);
    atualizarPedido($id, ['situacao' => 'na_fila', 'nota_revisor' => clean($in['nota'] ?? '', 1000)]);
    responderEContinuar(['ok' => true], fn() => processarPedido($id));
}

if ($action === 'salvar') {
    $r = $in['resultado'] ?? null;
    if (!is_array($r) || empty($r['objetivo'])) out(422, ['error' => 'Revisão inválida']);
    $ok = writeJson(pastaPedido($id) . '/resultado.json', $r);
    if ($ok) { docxCurriculo($r, pastaPedido($id) . '/curriculo.docx'); pdfCurriculo($r, pastaPedido($id) . '/curriculo.pdf'); }
    out($ok ? 200 : 500, ['ok' => (bool)$ok]);
}

if ($action === 'aprovar') {
    if (!in_array($p['situacao'], ['revisar', 'entregue'], true)) out(409, ['error' => 'Só dá para aprovar um currículo gerado']);
    [$ok, $res] = entregarPedido($id);
    out($ok ? 200 : 500, $ok ? ['ok' => true] + $res : ['error' => $res]);
}

if ($action === 'apagar') {
    $ok = apagarPedido($id);
    out($ok ? 200 : 500, ['ok' => (bool)$ok]);
}

// Arquivo do pedido para o revisor conferir (protegido; chega por POST com a senha)
if ($action === 'arquivo') {
    $qual = in_array($in['qual'] ?? '', ['cv', 'linkedin', 'docx', 'pdf'], true) ? $in['qual'] : 'pdf';
    $nome = in_array($qual, ['docx', 'pdf'], true) ? "curriculo.$qual" : ($p['arquivos'][$qual] ?? '');
    $arq = pastaPedido($id) . '/' . $nome;
    if ($nome === '' || !is_file($arq)) out(404, ['error' => 'Arquivo não encontrado']);
    $mime = ['pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'][pathinfo($arq, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime); header('Content-Disposition: attachment; filename="' . $qual . '-' . $id . '.' . pathinfo($arq, PATHINFO_EXTENSION) . '"');
    readfile($arq); exit;
}

out(400, ['error' => 'Ação desconhecida']);
