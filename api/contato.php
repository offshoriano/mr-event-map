<?php
// Mensagens do formulário "Fale com a gente" (/contato/).
// POST {action:"add", ...}            grava a mensagem e avisa por e-mail (público)
// POST {action:"list", senha}         lista as mensagens (protegido)
// POST {action:"delete", senha, id}   remove uma mensagem (protegido)
require __DIR__ . '/_base.php';
$dataFile = $dataDir . '/contato.json';
const AVISO_CONTATO = 'mateus.rangel@mrgroup.com.br';

$in = entrada();
$action = $in['action'] ?? 'add';

if ($action === 'add') {
    if (!empty($in['site'])) out(200, ['ok' => true]); // campo isca contra robôs
    limitar('contato', 5);
    $m = [
        'id'       => bin2hex(random_bytes(6)),
        'data'     => date('c'),
        'nome'     => clean($in['nome'] ?? '', 120),
        'email'    => clean($in['email'] ?? '', 160),
        'telefone' => clean($in['telefone'] ?? '', 40),
        'assunto'  => clean($in['assunto'] ?? '', 140),
        'mensagem' => mb_substr(trim(strip_tags((string)($in['mensagem'] ?? ''))), 0, 3000),
    ];
    if ($m['nome'] === '' || !filter_var($m['email'], FILTER_VALIDATE_EMAIL) || $m['assunto'] === '' || $m['mensagem'] === '') {
        out(422, ['error' => 'Preencha nome, e-mail válido, assunto e mensagem']);
    }
    $ok = mexer($dataFile, function (&$todos) use ($m) { $todos[] = $m; });
    if (!$ok) out(500, ['error' => 'Falha ao salvar']);
    $cfg = readJson($dataDir . '/config.json');
    avisar(($cfg['aviso'] ?? '') ?: AVISO_CONTATO, "Fale com a gente: {$m['assunto']}",
        "Nova mensagem pelo site mrgroup.com.br\n\nNome: {$m['nome']}\nE-mail: {$m['email']}\nTelefone: " . ($m['telefone'] ?: 'não informado')
        . "\nAssunto: {$m['assunto']}\n\n{$m['mensagem']}\n\nResponda direto para {$m['email']}. Todas as mensagens ficam no painel: https://mrgroup.com.br/leads/#mensagens\n");
    out(200, ['ok' => true]);
}

if ($action === 'list') {
    auth($in);
    out(200, ['mensagens' => array_reverse(readJson($dataFile))]);
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
