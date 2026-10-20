<?php
// Vagas enviadas por empresas e recrutadores pelo formulário /vagas/divulgar/.
// Ficam pendentes até serem aprovadas no painel /leads/.
// POST {action:"enviar", ...}                 nova vaga (público)
// POST {action:"publicas"}                    vagas aprovadas e no prazo (público)
// POST {action:"listar", senha}               todas as vagas e o e-mail de aviso (protegido)
// POST {action:"aprovar", senha, id, vaga}    publica, com as correções feitas no painel (protegido)
// POST {action:"recusar"|"apagar", senha, id} (protegido)
// POST {action:"aviso", senha, email}         e-mail que recebe o aviso de vaga nova (protegido)
require __DIR__ . '/_base.php';
$vagasFile = $dataDir . '/vagas-enviadas.json';
$configFile = $dataDir . '/config.json';
const SITE = 'https://mrgroup.com.br';

$in = entrada();
$action = $in['action'] ?? '';

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
// mail() do Hostinger. O remetente precisa ser do próprio domínio para não cair no spam.
function avisar($para, $assunto, $texto) {
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) return false;
    $h = "From: MR Group <avisos@mrgroup.com.br>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return @mail($para, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $texto, $h);
}
function publica($v) {
    return array_intersect_key($v, array_flip(['id', 'titulo', 'empresa', 'local', 'regime', 'contrato', 'area', 'tema', 'descricao', 'link', 'inscricoesAte', 'publicada']));
}

if ($action === 'enviar') {
    if (!empty($in['site'])) out(200, ['ok' => true]); // campo isca contra robôs
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
    avisar($cfg['aviso'] ?? '', "Vaga para aprovar: {$v['titulo']} ({$v['empresa']})",
        "Chegou uma vaga nova pelo site.\n\n{$v['titulo']}\n{$v['empresa']} · {$v['local']} · {$v['regime']}\n"
        . "Enviada por {$v['contato']} ({$v['email']}" . ($v['whatsapp'] ? ", {$v['whatsapp']}" : '') . ")\n\n"
        . "Pendentes agora: $r\n\nPara aprovar ou recusar: " . SITE . "/leads/#vagas\n");
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
    out(200, ['vagas' => array_reverse(readJson($vagasFile)), 'aviso' => $cfg['aviso'] ?? '']);
}

if ($action === 'aviso') {
    $email = clean($in['email'] ?? '', 160);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) out(422, ['error' => 'E-mail inválido']);
    $ok = mexer($configFile, function (&$c) use ($email) { $c['aviso'] = $email; });
    if ($ok && !empty($in['teste'])) avisar($email, 'Teste de aviso do site MR Group', "Se este e-mail chegou, os avisos de vaga nova estão funcionando.\n");
    out($ok ? 200 : 500, ['ok' => (bool)$ok]);
}

if ($action === 'aprovar' || $action === 'recusar') {
    $temas = ['seguranca', 'eletrica', 'mecanica', 'pocos', 'maritimo', 'hotelaria', 'operacao', 'engenharia', 'trainee', 'geral'];
    $edit = is_array($in['vaga'] ?? null) ? $in['vaga'] : [];
    $achada = mexer($vagasFile, function (&$vagas) use ($id, $action, $edit, $temas) {
        foreach ($vagas as &$v) {
            if ($v['id'] !== $id) continue;
            if ($action === 'aprovar') {
                $novo = campos($edit + $v);
                if ($novo['link'] === '') $novo['link'] = $v['link'];
                $v = array_merge($v, $novo);
                $v['tema'] = in_array($edit['tema'] ?? '', $temas, true) ? $edit['tema'] : 'geral';
                $v['area'] = clean($edit['area'] ?? 'Outras', 60);
                $v['publicada'] = $v['publicada'] ?? date('Y-m-d');
            }
            $v['status'] = $action === 'aprovar' ? 'aprovada' : 'recusada';
            $v['decidida'] = date('c');
            return $v;
        }
        return null;
    });
    if (!$achada) out(404, ['error' => 'Vaga não encontrada']);
    if ($action === 'aprovar') {
        avisar($achada['email'], "Sua vaga foi publicada: {$achada['titulo']}",
            "Olá, {$achada['contato']}.\n\nA vaga {$achada['titulo']} já está no ar em " . SITE . "/vagas/#{$achada['id']}\n\n"
            . "Ela sai do site sozinha depois de {$achada['inscricoesAte']}.\n\nObrigado por divulgar com a gente.\nMatheus Rangel · MR Group\n");
    }
    out(200, ['ok' => true]);
}

if ($action === 'apagar') {
    $ok = mexer($vagasFile, function (&$vagas) use ($id) {
        $vagas = array_values(array_filter($vagas, fn($v) => $v['id'] !== $id));
    });
    out($ok ? 200 : 500, ['ok' => (bool)$ok]);
}

out(400, ['error' => 'Ação desconhecida']);
