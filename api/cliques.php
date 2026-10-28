<?php
// Contagem de cliques nas vagas, por mês, para o ranking do painel.
// POST {action:"clique", vaga, titulo, empresa, tipo}   registra um clique (público)
// POST {action:"ranking", senha, mes:"2026-10"}           ranking do mês e lista de meses (protegido)
// Guarda só números por vaga e empresa, nada que identifique quem clicou.
require __DIR__ . '/_base.php';
$arquivo = $dataDir . '/cliques.json';
$vistosArquivo = $dataDir . '/cliques-vistos.json';
const TIPOS = ['candidatar', 'preparar', 'video', 'compartilhar'];

$in = entrada();
$action = $in['action'] ?? '';

if ($action === 'clique') {
    // Segunda a partir das 9h: o primeiro clique da semana dispara o alerta de vagas por e-mail, depois de responder
    if (date('N') == 1 && (int)date('G') >= 9) {
        require_once __DIR__ . '/alertas.php';
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            ignore_user_abort(true); set_time_limit(300);
            alertaSemanal();
        });
    }
    // Reserva do post do dia: se o robô não conseguiu avisar, o primeiro clique depois das 9h publica (uma vez por dia)
    if ((int)date('G') >= 9) {
        require_once __DIR__ . '/divulgar.php';
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            ignore_user_abort(true); set_time_limit(120);
            publicarDia();
        });
    }
    $tipo = in_array($in['tipo'] ?? '', TIPOS, true) ? $in['tipo'] : 'candidatar';
    $vaga = clean($in['vaga'] ?? '', 120);
    $empresa = clean($in['empresa'] ?? '', 100);
    if ($vaga === '' || $empresa === '') out(422, ['error' => 'Faltou a vaga']);
    // A mesma pessoa clicando várias vezes na mesma vaga no mesmo dia conta uma vez só
    $chave = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? '') . date('Y-m-d') . $vaga . $tipo);
    $novo = mexer($vistosArquivo, function (&$v) use ($chave) {
        $hoje = date('Y-m-d');
        if (($v['dia'] ?? '') !== $hoje) $v = ['dia' => $hoje, 'chaves' => []];
        if (isset($v['chaves'][$chave])) return false;
        $v['chaves'][$chave] = 1;
        return true;
    });
    if ($novo !== true) out(200, ['ok' => true]);
    $mes = date('Y-m');
    $titulo = clean($in['titulo'] ?? '', 160);
    mexer($arquivo, function (&$d) use ($mes, $vaga, $titulo, $empresa, $tipo) {
        $m = &$d[$mes];
        $m['vagas'][$vaga]['titulo'] = $titulo;
        $m['vagas'][$vaga]['empresa'] = $empresa;
        $m['vagas'][$vaga][$tipo] = ($m['vagas'][$vaga][$tipo] ?? 0) + 1;
        $m['empresas'][$empresa][$tipo] = ($m['empresas'][$empresa][$tipo] ?? 0) + 1;
        $m['total'][$tipo] = ($m['total'][$tipo] ?? 0) + 1;
    });
    out(200, ['ok' => true]);
}

if ($action === 'ranking') {
    auth($in);
    $d = readJson($arquivo);
    $meses = array_keys($d);
    rsort($meses);
    $mes = preg_match('/^\d{4}-\d{2}$/', $in['mes'] ?? '') ? $in['mes'] : ($meses[0] ?? date('Y-m'));
    out(200, ['mes' => $mes, 'meses' => $meses, 'dados' => $d[$mes] ?? ['vagas' => [], 'empresas' => [], 'total' => []]]);
}

out(400, ['error' => 'Ação desconhecida']);
