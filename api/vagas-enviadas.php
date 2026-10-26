<?php
// Vagas enviadas por empresas e recrutadores pelo formulário /vagas/divulgar/.
// Ficam pendentes até serem aprovadas no painel /leads/.
// POST {action:"enviar", ...}                 nova vaga (público)
// POST {action:"publicas"}                    vagas aprovadas e no prazo (público)
// POST {action:"listar", senha}               todas as vagas e o e-mail de aviso (protegido)
// POST {action:"aprovar", senha, id, vaga}    publica, com as correções feitas no painel (protegido)
// POST {action:"recusar"|"apagar", senha, id} (protegido)
// POST {action:"aviso", senha, email}         e-mail que recebe o aviso de vaga nova (protegido)
// GET  ?id=&acao=aprovar|recusar&t=           página de confirmação aberta pelo botão do e-mail
require __DIR__ . '/_base.php';
$vagasFile = $dataDir . '/vagas-enviadas.json';
$configFile = $dataDir . '/config.json';
const SITE = 'https://mrgroup.com.br';
// Recebe o aviso enquanto nenhum outro e-mail for salvo no painel
const AVISO_PADRAO = 'matheus.rangel@mrgroup.com.br';

function linkCandidatura($v) {
    $v = trim((string)$v);
    if (filter_var($v, FILTER_VALIDATE_EMAIL)) return 'mailto:' . $v;
    if (preg_match('#^https?://#i', $v) && filter_var($v, FILTER_VALIDATE_URL)) return mb_substr($v, 0, 500);
    if (preg_match('#^[\w.-]+\.[a-z]{2,}(/\S*)?$#i', $v)) return 'https://' . mb_substr($v, 0, 490);
    return '';
}
function campos($in) {
    $regimes = ['Embarcado', 'Em terra', 'Híbrido'];
    $ate = (string)($in['inscricoesAte'] ?? '');
    return [
        'titulo'        => clean($in['titulo'] ?? '', 140),
        'empresa'       => clean($in['empresa'] ?? '', 100),
        'local'         => clean($in['local'] ?? '', 100),
        'regime'        => in_array($in['regime'] ?? '', $regimes, true) ? $in['regime'] : 'Embarcado',
        'contrato'      => clean($in['contrato'] ?? '', 60),
        'descricao'     => mb_substr(trim(strip_tags((string)($in['descricao'] ?? ''))), 0, 3000),
        'link'          => linkCandidatura($in['link'] ?? ''),
        'inscricoesAte' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate) ? $ate : date('Y-m-d', strtotime('+30 days')),
    ];
}
// Chave secreta guardada só no servidor. Assina os botões do e-mail para ninguém aprovar vaga sem ele.
function segredo() {
    global $configFile;
    $c = readJson($configFile);
    if (!empty($c['segredo'])) return $c['segredo'];
    $novo = bin2hex(random_bytes(32));
    return mexer($configFile, function (&$c) use ($novo) {
        if (empty($c['segredo'])) $c['segredo'] = $novo;
        return $c['segredo'];
    });
}
function assinatura($id, $acao) { return hash_hmac('sha256', "$id|$acao", segredo()); }
function linkEmail($id, $acao) {
    return SITE . '/api/vagas-enviadas.php?' . http_build_query(['id' => $id, 'acao' => $acao, 't' => assinatura($id, $acao)]);
}
// Área sugerida pelo título, com as mesmas regras do robô de vagas
function temaPorTitulo($titulo) {
    $cfg = readJson(__DIR__ . '/../scripts/vagas/videos.json');
    foreach ($cfg['regras'] ?? [] as [$tema, $termos]) {
        if (@preg_match('/' . $termos . '/iu', $titulo)) return [$tema, $cfg['nomes'][$tema] ?? 'Outras'];
    }
    return ['geral', 'Outras'];
}
function decidir($id, $acao, $edit = []) {
    global $vagasFile;
    $temas = ['seguranca', 'eletrica', 'mecanica', 'pocos', 'maritimo', 'hotelaria', 'operacao', 'engenharia', 'trainee', 'geral'];
    $achada = mexer($vagasFile, function (&$vagas) use ($id, $acao, $edit, $temas) {
        foreach ($vagas as &$v) {
            if ($v['id'] !== $id) continue;
            if ($v['status'] !== 'pendente' && empty($edit)) return ['jaDecidida' => $v['status']] + $v;
            if ($acao === 'aprovar') {
                $novo = campos($edit + $v);
                if ($novo['link'] === '') $novo['link'] = $v['link'];
                $v = array_merge($v, $novo);
                if (in_array($edit['tema'] ?? '', $temas, true)) {
                    $v['tema'] = $edit['tema'];
                    $v['area'] = clean($edit['area'] ?? 'Outras', 60);
                } else {
                    [$v['tema'], $v['area']] = temaPorTitulo($v['titulo']);
                }
                $v['publicada'] = $v['publicada'] ?? date('Y-m-d');
            }
            $v['status'] = $acao === 'aprovar' ? 'aprovada' : 'recusada';
            $v['decidida'] = date('c');
            return $v;
        }
        return null;
    });
    if ($achada && empty($achada['jaDecidida']) && $acao === 'aprovar') {
        avisar($achada['email'], "Sua vaga foi publicada: {$achada['titulo']}",
            "Olá, {$achada['contato']}.\n\nA vaga {$achada['titulo']} já está no ar em " . SITE . "/vagas/#{$achada['id']}\n\n"
            . "Ela sai do site sozinha depois de {$achada['inscricoesAte']}.\n\nObrigado por divulgar com a gente.\nMatheus Rangel · MR Group\n");
    }
    return $achada;
}
function pagina($titulo, $corpo) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . htmlspecialchars($titulo) . '</title><style>body{font-family:system-ui,sans-serif;background:#0D1117;color:#EEE9E2;margin:0;padding:24px 16px}'
        . '.c{max-width:560px;margin:6vh auto;background:#111820;border:1px solid #243040;border-radius:6px;padding:26px}h1{font-size:21px;margin:0 0 6px}'
        . '.m{color:#A0B2C4;font-size:14px;line-height:1.6}p.d{white-space:pre-line;font-size:14px;line-height:1.6;background:#141D27;padding:14px;border-radius:4px}'
        . 'button,a.b{display:block;width:100%;text-align:center;box-sizing:border-box;font:700 14px system-ui;padding:14px;border-radius:4px;border:none;cursor:pointer;margin-top:10px;text-decoration:none}'
        . '.ok{background:#C9933A;color:#090C10}.no{background:#2a1a1c;color:#ef9a9a;border:1px solid #5a2a2e}.g{background:transparent;color:#A0B2C4;border:1px solid #243040}</style></head>'
        . '<body><div class="c">' . $corpo . '</div></body></html>';
    exit;
}

function publica($v) {
    return array_intersect_key($v, array_flip(['id', 'titulo', 'empresa', 'local', 'regime', 'contrato', 'area', 'tema', 'descricao', 'link', 'inscricoesAte', 'publicada']));
}

// Botões do e-mail: o link abre uma confirmação (GET) e só o clique no botão decide (POST).
// Assim o antivírus do e-mail, que abre links sozinho, não aprova nada por engano.
if (isset($_GET['id']) || isset($_POST['t'])) {
    $id = (string)($_POST['id'] ?? $_GET['id'] ?? '');
    $acao = (string)($_POST['acao'] ?? $_GET['acao'] ?? '');
    $t = (string)($_POST['t'] ?? $_GET['t'] ?? '');
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
    if (!in_array($acao, ['aprovar', 'recusar'], true) || !hash_equals(assinatura($id, $acao), $t)) {
        pagina('Link inválido', '<h1>Link inválido</h1><p class="m">Abra a vaga pelo painel: <a style="color:#E8B86D" href="' . SITE . '/leads/#vagas">mrgroup.com.br/leads</a></p>');
    }
    $v = null;
    foreach (readJson($vagasFile) as $x) if ($x['id'] === $id) $v = $x;
    if (!$v) pagina('Vaga não encontrada', '<h1>Vaga não encontrada</h1><p class="m">Ela pode ter sido apagada no painel.</p>');
    $painel = '<a class="b g" href="' . SITE . '/leads/#vagas">Abrir o painel</a>';
    if ($v['status'] !== 'pendente') {
        pagina('Vaga já decidida', '<h1>Essa vaga já foi ' . ($v['status'] === 'aprovada' ? 'aprovada' : 'recusada') . '</h1><p class="m">' . $h($v['titulo']) . ' · ' . $h($v['empresa']) . '</p>'
            . ($v['status'] === 'aprovada' ? '<a class="b ok" href="' . SITE . '/vagas/#' . $h($v['id']) . '">Ver no site</a>' : '') . $painel);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $r = decidir($id, $acao);
        if ($acao === 'aprovar') {
            pagina('Vaga publicada', '<h1>Vaga publicada</h1><p class="m">' . $h($r['titulo']) . ' · ' . $h($r['empresa']) . '<br>Área: ' . $h($r['area'])
                . '. A empresa recebeu um e-mail avisando.</p><a class="b ok" href="' . SITE . '/vagas/#' . $h($id) . '">Ver no site</a>' . $painel);
        }
        pagina('Vaga recusada', '<h1>Vaga recusada</h1><p class="m">' . $h($r['titulo']) . ' · ' . $h($r['empresa']) . ' não vai aparecer no site.</p>' . $painel);
    }
    $sim = $acao === 'aprovar';
    pagina($sim ? 'Aprovar vaga' : 'Recusar vaga', '<h1>' . ($sim ? 'Publicar esta vaga?' : 'Recusar esta vaga?') . '</h1>'
        . '<p class="m"><b>' . $h($v['titulo']) . '</b><br>' . $h($v['empresa']) . ' · ' . $h($v['local']) . ' · ' . $h($v['regime']) . '<br>Inscrições até ' . $h($v['inscricoesAte']) . '</p>'
        . '<p class="d">' . $h($v['descricao']) . '</p><p class="m">Candidatura: ' . $h(preg_replace('/^mailto:/', '', $v['link'])) . '</p>'
        . '<form method="post"><input type="hidden" name="id" value="' . $h($id) . '"><input type="hidden" name="acao" value="' . $acao . '"><input type="hidden" name="t" value="' . $h($t) . '">'
        . '<button class="' . ($sim ? 'ok' : 'no') . '">' . ($sim ? 'Sim, publicar' : 'Sim, recusar') . '</button></form>'
        . '<p class="m" style="margin-top:14px">Quer corrigir o texto ou a área antes? Faça pelo painel.</p>' . $painel);
}

$in = entrada();
$action = $in['action'] ?? '';

if ($action === 'enviar') {
    if (!empty($in['site'])) out(200, ['ok' => true]); // campo isca contra robôs
    limitar('vagas-enviadas', 10);
    $v = campos($in) + [
        'id'       => 'enviada-' . bin2hex(random_bytes(5)),
        'status'   => 'pendente',
        'enviada'  => date('c'),
        'contato'  => clean($in['contato'] ?? '', 120),
        'email'    => clean($in['email'] ?? '', 160),
        'whatsapp' => clean($in['whatsapp'] ?? '', 40),
        'ip'       => hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d')),
    ];
    if ($v['titulo'] === '' || $v['empresa'] === '' || $v['local'] === '' || $v['contato'] === ''
        || !filter_var($v['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['descricao']) < 30) {
        out(422, ['error' => 'Preencha empresa, cargo, local, seu nome, e-mail e uma descrição da vaga.']);
    }
    if ($v['link'] === '') out(422, ['error' => 'Informe o link ou o e-mail para candidatura.']);
    $r = mexer($vagasFile, function (&$vagas) use ($v) {
        // Freio contra robô: no máximo 5 envios por dia do mesmo endereço, e 200 pendentes no total
        $doIp = count(array_filter($vagas, fn($x) => ($x['ip'] ?? '') === $v['ip']));
        $pend = count(array_filter($vagas, fn($x) => $x['status'] === 'pendente'));
        if ($doIp >= 5 || $pend >= 200) return 'cheio';
        $vagas[] = $v;
        return $pend + 1;
    });
    if ($r === 'cheio') out(429, ['error' => 'Recebemos muitos envios agora. Tente de novo amanhã ou fale com a gente pelo Instagram.']);
    if ($r === false) out(500, ['error' => 'Falha ao salvar']);
    $cfg = readJson($configFile);
    avisar(($cfg['aviso'] ?? '') ?: AVISO_PADRAO, "Vaga para aprovar: {$v['titulo']} ({$v['empresa']})",
        "Chegou uma vaga nova pelo site.\n\n{$v['titulo']}\n{$v['empresa']} · {$v['local']} · {$v['regime']} · {$v['contrato']}\n"
        . "Inscrições até {$v['inscricoesAte']}\nCandidatura: " . preg_replace('/^mailto:/', '', $v['link']) . "\n\n{$v['descricao']}\n\n"
        . "Enviada por {$v['contato']} ({$v['email']}" . ($v['whatsapp'] ? ", {$v['whatsapp']}" : '') . ")\n\n"
        . "APROVAR E PUBLICAR:\n" . linkEmail($v['id'], 'aprovar') . "\n\nRECUSAR:\n" . linkEmail($v['id'], 'recusar') . "\n\n"
        . "Para corrigir o texto antes de publicar, use o painel: " . SITE . "/leads/#vagas\n"
        . "Vagas esperando aprovação agora: $r\n");
    out(200, ['ok' => true]);
}

if ($action === 'publicas') {
    $hoje = date('Y-m-d');
    $lista = array_values(array_map('publica', array_filter(readJson($vagasFile),
        fn($v) => $v['status'] === 'aprovada' && $v['inscricoesAte'] >= $hoje)));
    usort($lista, fn($a, $b) => strcmp($b['publicada'], $a['publicada']));
    out(200, ['vagas' => $lista]);
}

auth($in);
$id = (string)($in['id'] ?? '');

if ($action === 'listar') {
    $cfg = readJson($configFile);
    out(200, ['vagas' => array_reverse(readJson($vagasFile)), 'aviso' => ($cfg['aviso'] ?? '') ?: AVISO_PADRAO]);
}

if ($action === 'aviso') {
    $email = clean($in['email'] ?? '', 160);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) out(422, ['error' => 'E-mail inválido']);
    $ok = mexer($configFile, function (&$c) use ($email) { $c['aviso'] = $email; });
    if ($ok && !empty($in['teste'])) avisar($email, 'Teste de aviso do site MR Group', "Se este e-mail chegou, os avisos de vaga nova estão funcionando.\n");
    out($ok ? 200 : 500, ['ok' => (bool)$ok]);
}

if ($action === 'aprovar' || $action === 'recusar') {
    $edit = is_array($in['vaga'] ?? null) ? $in['vaga'] : [];
    if (!decidir($id, $action, $edit ?: ['_painel' => 1])) out(404, ['error' => 'Vaga não encontrada']);
    out(200, ['ok' => true]);
}

if ($action === 'apagar') {
    $ok = mexer($vagasFile, function (&$vagas) use ($id) {
        $vagas = array_values(array_filter($vagas, fn($v) => $v['id'] !== $id));
    });
    out($ok ? 200 : 500, ['ok' => (bool)$ok]);
}

out(400, ['error' => 'Ação desconhecida']);
