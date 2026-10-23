<?php
// Diagnósticos de eventos feitos em /diagnostico/.
// POST {action:"add", ...}            grava um diagnóstico (público)
// POST {action:"list", senha}         lista os diagnósticos (protegido)
// POST {action:"delete", senha, id}   remove um diagnóstico (pedido de remoção, LGPD)
require __DIR__ . '/_base.php';
$dataFile = $dataDir . '/diagnosticos.json';

$in = entrada();
$action = $in['action'] ?? 'add';

if ($action === 'add') {
    if (!empty($in['site'])) out(200, ['ok' => true]); // campo isca contra robôs
    $r = is_array($in['respostas'] ?? null) ? $in['respostas'] : [];
    $lista = fn($v) => array_slice(array_map(fn($x) => clean($x, 30), is_array($v) ? $v : []), 0, 10);
    $d = [
        'id'        => bin2hex(random_bytes(6)),
        'data'      => date('c'),
        'nome'      => clean($in['nome'] ?? '', 120),
        'empresa'   => clean($in['empresa'] ?? '', 120),
        'cargo'     => clean($in['cargo'] ?? '', 120),
        'email'     => clean($in['email'] ?? '', 160),
        'whats'     => clean($in['whats'] ?? '', 40),
        'respostas' => [
            'mercados'     => $lista($r['mercados'] ?? []),
            'comprador'    => clean($r['comprador'] ?? '', 30),
            'regioes'      => $lista($r['regioes'] ?? []),
            'objetivo'     => clean($r['objetivo'] ?? '', 30),
            'participacao' => clean($r['participacao'] ?? '', 30),
            'eventos_ano'  => clean($r['eventos_ano'] ?? '', 5),
            'orcamento_mil'=> clean($r['orcamento_mil'] ?? '', 6),
        ],
        'top'       => array_slice(array_map(fn($x) => clean($x, 120), is_array($in['top'] ?? null) ? $in['top'] : []), 0, 5),
    ];
    if ($d['nome'] === '' || $d['empresa'] === '' || !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        out(422, ['error' => 'Preencha nome, empresa e e-mail válido']);
    }
    $ok = mexer($dataFile, function (&$todos) use ($d) { $todos[] = $d; });
    out($ok ? 200 : 500, $ok ? ['ok' => true] : ['error' => 'Falha ao salvar']);
}

if ($action === 'list') {
    auth($in);
    out(200, ['diagnosticos' => array_reverse(readJson($dataFile))]);
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
