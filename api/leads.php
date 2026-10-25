<?php
// Contatos deixados no pop-up de candidatura das vagas.
// POST {action:"add", ...}            grava um contato (público)
// POST {action:"list", senha}         lista os contatos (protegido)
// POST {action:"delete", senha, id}   remove um contato (pedido de remoção, LGPD)
require __DIR__ . '/_base.php';
$dataFile = $dataDir . '/leads.json';

// E-mail depois da candidatura: um texto para quem ainda não embarcou e outro para quem já embarcou
function emailCandidatura($l) {
    $e = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $nome = explode(' ', trim($l['nome']))[0];
    $vaga = $l['vaga'] !== '' ? $l['vaga'] . ($l['empresa'] !== '' ? ' na ' . $l['empresa'] : '') : 'que você escolheu';
    $site = 'https://mrgroup.com.br';
    $btn = fn($href, $txt) => '<p style="margin:22px 0"><a href="' . $href . '" style="background:#C9933A;color:#090C10;text-decoration:none;font-weight:bold;padding:13px 22px;border-radius:3px;display:inline-block">' . $txt . '</a></p>';
    $p = fn($t) => '<p style="color:#C9D3DD;font-size:16px;line-height:1.65;margin:0 0 14px">' . $t . '</p>';
    if ($l['experiencia'] === 'sim') {
        $assunto = "$nome, sobre a sua candidatura para $vaga";
        $miolo = $p('Oi, ' . $e($nome) . '.')
            . $p('Vi que você foi atrás da vaga de ' . $e($vaga) . '. Como você já embarcou, sabe que no offshore a candidatura é só a porta. O que abre ou fecha é o currículo.')
            . $p('Uma coisa que eu vejo direto em currículo de quem tem experiência: a estrada está lá, mas escondida. Unidade sem nome, sistema sem detalhe, certificação sem validade. O sistema de triagem das empresas procura exatamente isso e, quando não acha, passa para o próximo.')
            . $p('Por isso a gente está lançando o <b style="color:#EEE9E2">Currículo Sob Medida</b>. Você escolhe a vaga, manda o seu currículo e recebe em até 24 horas a versão adaptada, a carta de apresentação e ajustes no LinkedIn. Sem inventar nada.')
            . $btn($site . '/curriculo/?origem=email-com-exp', 'Entrar na lista de espera')
            . $p('Enquanto isso, as vagas para quem tem experiência saem todo dia em <a href="' . $site . '/vagas/#setor" style="color:#E8B86D">mrgroup.com.br/vagas</a>. Se preferir receber por e-mail, ative o <a href="' . $site . '/vagas/#alerta" style="color:#E8B86D">alerta de vagas</a>.');
    } else {
        $assunto = "$nome, um passo antes da próxima vaga";
        $miolo = $p('Oi, ' . $e($nome) . '.')
            . $p('Vi que você foi atrás da vaga de ' . $e($vaga) . '. Boa. Quem se candidata toda semana chega lá antes de quem espera a vaga perfeita aparecer.')
            . $p('Para quem ainda não embarcou, duas coisas pesam mais que qualquer outra:')
            . $p('<b style="color:#EEE9E2">1. Os cursos obrigatórios.</b> Sem CBSP e T-HUET, a maioria das empresas nem chama para a entrevista. <a href="' . $site . '/artigos/cbsp-e-huet/" style="color:#E8B86D">Expliquei os dois aqui</a>.')
            . $p('<b style="color:#EEE9E2">2. O currículo.</b> Ele passa por um sistema de triagem antes de chegar em alguém do RH. Se a vaga pede NR-35 e o seu currículo diz "cursos de segurança", você fica pelo caminho sem ninguém ler.')
            . $p('Para o segundo ponto a gente está lançando o <b style="color:#EEE9E2">Currículo Sob Medida</b>: o seu currículo adaptado para cada vaga, com o seu curso técnico e a sua experiência em terra contados do jeito que o setor lê. Sem inventar nada.')
            . $btn($site . '/curriculo/?origem=email-sem-exp', 'Entrar na lista de espera')
            . $p('E se você quer o caminho completo do zero, está no <a href="' . $site . '/guia/?origem=email-candidatura" style="color:#E8B86D">Guia 7 Passos</a>. Tem também uma seção de <a href="' . $site . '/vagas/#comecando" style="color:#E8B86D">vagas para quem está começando</a>, atualizada todo dia.');
    }
    $miolo .= $p('Matheus Rangel<br><span style="color:#7A8FA6">MR Group</span>');
    $html = '<div style="background:#0D1117;padding:24px 12px;font-family:Arial,sans-serif"><div style="max-width:600px;margin:0 auto;background:#111820;border:1px solid #1C2733;border-radius:6px;padding:28px">'
        . $miolo . '<p style="color:#7A8FA6;font-size:12px;line-height:1.6;margin-top:24px;border-top:1px solid #1C2733;padding-top:14px">Você recebeu este e-mail porque deixou seus dados ao se candidatar a uma vaga no mrgroup.com.br. Se não quiser receber mais nada, é só responder com a palavra SAIR.</p></div></div>';
    resendEnviar($l['email'], $assunto, $html);
}

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
    $primeira = true;
    $ok = mexer($dataFile, function (&$leads) use ($lead, &$primeira) {
        foreach ($leads as $l) if (strcasecmp($l['email'] ?? '', $lead['email']) === 0) { $primeira = false; break; }
        $leads[] = $lead;
    });
    if (!$ok) out(500, ['error' => 'Falha ao salvar']);
    // Só a primeira candidatura de cada e-mail recebe o e-mail de boas-vindas, depois da resposta para não atrasar a pessoa
    if ($primeira) {
        http_response_code(200); echo json_encode(['ok' => true]);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        emailCandidatura($lead);
        exit;
    }
    out(200, ['ok' => true]);
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
