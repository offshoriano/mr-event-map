<?php
// Lista de espera do Currículo Sob Medida (/curriculo/).
// POST {action:"add", ...}            grava a pessoa na lista e avisa por e-mail (público)
// POST {action:"list", senha}         lista os inscritos (protegido)
// POST {action:"delete", senha, id}   remove um inscrito (pedido de remoção, LGPD)
require __DIR__ . '/_base.php';
$dataFile = $dataDir . '/curriculo.json';
const AVISO_CURRICULO = 'mateus.rangel@mrgroup.com.br';

$in = entrada();
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

if ($action === 'list') {
    auth($in);
    out(200, ['inscritos' => array_reverse(readJson($dataFile))]);
}

if ($action === 'delete') {
    auth($in);
    $id = (string)($in['id'] ?? '');
    $ok = mexer($dataFile, function (&$todos) use ($id) {
        $todos = array_values(array_filter($todos, fn($x) => ($x['id'] ?? '') !== $id));
    });
    out($ok ? 200 : 500, ['ok' => $ok]);
}

out(400, ['error' => 'Ação desconhecida']);
