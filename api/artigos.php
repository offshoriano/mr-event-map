<?php
// Curtidas e comentários dos artigos (/artigos/<slug>/).
// POST {action:"info", slug}                         curtidas e comentários aprovados do artigo (público)
// POST {action:"curtir", slug}                       soma uma curtida, uma por pessoa a cada 24h (público)
// POST {action:"comentar", slug, nome, texto}        recebe um comentário; só aparece depois de aprovado no painel (público)
// POST {action:"painel", senha}                      comentários de todos os artigos (protegido)
// POST {action:"aprovar", senha, id} / {action:"apagar", senha, id}   modera um comentário (protegido)
require __DIR__ . '/_base.php';
$curtidasFile = $dataDir . '/artigos-curtidas.json';
$comentariosFile = $dataDir . '/artigos-comentarios.json';
const AVISO_COMENTARIO = 'matheus.rangel@mrgroup.com.br';

function slugValido($s) {
    $s = (string)$s;
    return preg_match('/^[a-z0-9-]{3,120}$/', $s) && is_dir(dirname(__DIR__) . '/artigos/' . $s) ? $s : '';
}
function publico($c) { return ['id' => $c['id'], 'nome' => $c['nome'], 'texto' => $c['texto'], 'data' => $c['data']]; }

$in = entrada();
$action = $in['action'] ?? '';

if ($action === 'info') {
    $slug = slugValido($in['slug'] ?? '');
    if (!$slug) out(404, ['error' => 'Artigo não encontrado']);
    $aprovados = array_values(array_filter(readJson($comentariosFile), fn($c) => $c['slug'] === $slug && $c['situacao'] === 'aprovado'));
    out(200, ['curtidas' => (int)(readJson($curtidasFile)[$slug] ?? 0), 'comentarios' => array_map('publico', $aprovados)]);
}

if ($action === 'curtir') {
    $slug = slugValido($in['slug'] ?? '');
    if (!$slug) out(404, ['error' => 'Artigo não encontrado']);
    limitar('curtir-' . $slug, 1, 86400);
    $total = mexer($curtidasFile, function (&$d) use ($slug) { $d[$slug] = (int)($d[$slug] ?? 0) + 1; return $d[$slug]; });
    out(200, ['curtidas' => (int)$total]);
}

if ($action === 'comentar') {
    if (!empty($in['site'])) out(200, ['ok' => true]); // campo isca contra robôs
    $slug = slugValido($in['slug'] ?? '');
    if (!$slug) out(404, ['error' => 'Artigo não encontrado']);
    $nome = clean($in['nome'] ?? '', 80);
    $texto = trim(mb_substr(strip_tags((string)($in['texto'] ?? '')), 0, 1500));
    if ($nome === '' || mb_strlen($texto) < 3) out(422, ['error' => 'Escreva seu nome e o comentário']);
    if (preg_match_all('~https?://~i', $texto) > 1) out(422, ['error' => 'O comentário tem links demais']);
    limitar('comentar', 5);
    $c = ['id' => bin2hex(random_bytes(6)), 'slug' => $slug, 'nome' => $nome, 'texto' => $texto, 'data' => date('c'), 'situacao' => 'pendente'];
    if (!mexer($comentariosFile, function (&$d) use ($c) { $d[] = $c; })) out(500, ['error' => 'Não foi possível salvar']);
    avisar(AVISO_COMENTARIO, "Comentário novo no artigo: $nome", "Novo comentário esperando aprovação\n\nArtigo: https://mrgroup.com.br/artigos/$slug/\nNome: $nome\n\n$texto\n\nAprove ou apague no painel: https://mrgroup.com.br/leads/#comentarios\n");
    out(200, ['ok' => true]);
}

auth($in);

if ($action === 'painel') {
    out(200, ['comentarios' => array_reverse(readJson($comentariosFile)), 'curtidas' => readJson($curtidasFile)]);
}

if ($action === 'aprovar' || $action === 'apagar') {
    $id = (string)($in['id'] ?? '');
    $ok = mexer($comentariosFile, function (&$d) use ($id, $action) {
        foreach ($d as $i => $c) if ($c['id'] === $id) {
            if ($action === 'apagar') { array_splice($d, $i, 1); } else { $d[$i]['situacao'] = 'aprovado'; }
            return true;
        }
        return false;
    });
    out($ok ? 200 : 404, ['ok' => (bool)$ok]);
}

out(400, ['error' => 'Ação desconhecida']);
