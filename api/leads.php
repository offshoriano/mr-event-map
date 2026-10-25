<?php
// Contatos deixados no pop-up de candidatura das vagas.
// POST {action:"add", ...}            grava um contato (público)
// POST {action:"list", senha}         lista os contatos (protegido)
// POST {action:"delete", senha, id}   remove um contato (pedido de remoção, LGPD)
require __DIR__ . '/_base.php';
$dataFile = $dataDir . '/leads.json';

$in = entrada();
$action = $in['action'] ?? 'add';

if ($action === 'add') {
    if (!empty($in['site'])) out(200, ['ok' => true]); // campo isca contra robôs
    limitar('leads', 20);
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
    $ok = mexer($dataFile, function (&$leads) use ($lead) { $leads[] = $lead; });
    out($ok ? 200 : 500, $ok ? ['ok' => true] : ['error' => 'Falha ao salvar']);
}

if ($action === 'list') {
    auth($in);
    out(200, ['leads' => array_reverse(readJson($dataFile))]);
}

if ($action === 'delete') {
    auth($in);
    $id = (string)($in['id'] ?? '');
    $ok = mexer($dataFile, function (&$leads) use ($id) {
        $leads = array_values(array_filter($leads, fn($l) => ($l['id'] ?? '') !== $id));
    });
    out($ok ? 200 : 500, ['ok' => $ok]);
}

out(400, ['error' => 'Ação desconhecida']);
