<?php
// Monta o currículo em Word (.docx) e em PDF a partir do resultado do gerador, sem bibliotecas externas.
// Formato simples de propósito (uma coluna, uma fonte, sem tabela nem imagem): é o que os sistemas de triagem leem.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

// O currículo como lista de blocos: [tipo, texto]. Tipos: nome, contato, titulo, texto, item, cargo
function blocosCurriculo($r) {
    $b = [['nome', $r['dados']['nome'] ?? '']];
    $contato = array_filter([$r['dados']['cidade'] ?? '', $r['dados']['telefone'] ?? '', $r['dados']['email'] ?? '']);
    if ($contato) $b[] = ['contato', implode(' · ', $contato)];
    if (!empty($r['dados']['linkedin'])) $b[] = ['contato', $r['dados']['linkedin']];
    $secao = function ($titulo, $itens, $tipo = 'item') use (&$b) {
        $itens = array_values(array_filter((array)$itens, fn($x) => trim((string)$x) !== ''));
        if (!$itens) return;
        $b[] = ['titulo', $titulo];
        foreach ($itens as $i) $b[] = [$tipo, $i];
    };
    $secao('OBJETIVO', [$r['objetivo'] ?? ''], 'texto');
    $secao('RESUMO PROFISSIONAL', [$r['resumo'] ?? ''], 'texto');
    $secao('IDIOMAS', $r['idiomas'] ?? []);
    $secao('FORMAÇÃO', $r['formacao'] ?? []);
    if (!empty($r['experiencias'])) {
        $b[] = ['titulo', 'EXPERIÊNCIA PROFISSIONAL'];
        foreach ($r['experiencias'] as $e) {
            $b[] = ['cargo', trim(implode(' – ', array_filter([$e['periodo'] ?? '', $e['empresa'] ?? '', $e['local'] ?? ''])))];
            if (!empty($e['posicao'])) $b[] = ['texto', 'Posição: ' . $e['posicao']];
            if (!empty($e['atividades'])) $b[] = ['texto', 'Principais Atividades: ' . $e['atividades']];
        }
    }
    $secao('QUALIFICAÇÕES', $r['qualificacoes'] ?? []);
    $secao('OUTRAS INFORMAÇÕES', $r['outras'] ?? []);
    return $b;
}

function docxCurriculo($r, $destino) {
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $run = fn($t, $neg = false, $tam = 22, $cor = '000000') => '<w:r><w:rPr>' . ($neg ? '<w:b/>' : '') . '<w:color w:val="' . $cor . '"/><w:sz w:val="' . $tam . '"/></w:rPr><w:t xml:space="preserve">' . $x($t) . '</w:t></w:r>';
    $par = fn($conteudo, $antes = 0, $depois = 80, $alin = 'left', $extra = '') => '<w:p><w:pPr>' . $extra . '<w:spacing w:before="' . $antes . '" w:after="' . $depois . '"/><w:jc w:val="' . $alin . '"/></w:pPr>' . $conteudo . '</w:p>';
    $corpo = '';
    foreach (blocosCurriculo($r) as [$tipo, $t]) {
        if ($tipo === 'nome') $corpo .= $par($run($t, true, 32), 0, 40, 'center');
        elseif ($tipo === 'contato') $corpo .= $par($run($t, false, 20, '333333'), 0, 20, 'center');
        elseif ($tipo === 'titulo') $corpo .= $par($run($t, true, 22), 240, 80, 'left', '<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="999999"/></w:pBdr>');
        elseif ($tipo === 'cargo') $corpo .= $par($run($t, true), 120, 40);
        elseif ($tipo === 'item') $corpo .= $par($run('• ' . $t), 0, 40, 'left', '<w:ind w:left="284" w:hanging="284"/>');
        else $corpo .= $par($run($t), 0, 80, 'both');
    }
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $corpo
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="0" w:footer="0" w:gutter="0"/></w:sectPr></w:body></w:document>';
    $estilos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri" w:eastAsia="Calibri"/><w:sz w:val="22"/><w:lang w:val="pt-BR"/></w:rPr></w:rPrDefault></w:docDefaults></w:styles>';
    $z = new ZipArchive();
    if ($z->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $z->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('word/document.xml', $doc);
    $z->addFromString('word/styles.xml', $estilos);
    return $z->close();
}

// Larguras da Helvetica (padrão AFM, em milésimos do tamanho da fonte) para quebrar as linhas no lugar certo
function larguraHelvetica($s, $tam, $negrito = false) {
    static $w = null, $wb = null;
    if ($w === null) {
        $w = array_combine(range(32, 126), [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
            1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,
            556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584]);
        $wb = array_combine(range(32, 126), [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
            975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,
            556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584]);
    }
    $t = $negrito ? $wb : $w; $soma = 0;
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $s); if ($ascii === false) $ascii = $s;
    foreach (str_split($ascii) as $c) $soma += $t[ord($c)] ?? 556;
    return $soma * $tam / 1000;
}

function pdfCurriculo($r, $destino) {
    $L = 595; $A = 842; $marg = 56; $util = $L - 2 * $marg;
    $paginas = []; $ops = ''; $y = $A - $marg;
    $cp = fn($s) => @iconv('UTF-8', 'CP1252//TRANSLIT', (string)$s) ?: '';
    $esc = fn($s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    $novaPagina = function () use (&$paginas, &$ops, &$y, $A, $marg) { $paginas[] = $ops; $ops = ''; $y = $A - $marg; };
    $linha = function ($texto, $tam, $neg, $x, $alin = 'left') use (&$ops, &$y, $cp, $esc, $L, $novaPagina, $marg) {
        if ($y < $marg + $tam) $novaPagina();
        if ($alin === 'center') $x = ($L - larguraHelvetica($texto, $tam, $neg)) / 2;
        $ops .= 'BT /' . ($neg ? 'F2' : 'F1') . ' ' . $tam . ' Tf ' . round($x, 2) . ' ' . round($y, 2) . ' Td (' . $esc($cp($texto)) . ") Tj ET\n";
        $y -= $tam * 1.38;
    };
    $paragrafo = function ($texto, $tam, $neg, $recuo = 0, $alin = 'left', $marcador = '') use ($linha, $util, $marg) {
        $palavras = preg_split('/\s+/', trim((string)$texto)); $atual = '';
        $primeira = true;
        foreach ($palavras as $p) {
            $teste = $atual === '' ? $p : "$atual $p";
            if (larguraHelvetica(($primeira ? $marcador : '') . $teste, $tam, $neg) > $util - $recuo && $atual !== '') {
                $linha(($primeira ? $marcador : '') . $atual, $tam, $neg, $marg + ($primeira || !$marcador ? 0 : $recuo), $alin);
                $atual = $p; $primeira = false;
            } else $atual = $teste;
        }
        if ($atual !== '') $linha(($primeira ? $marcador : '') . $atual, $tam, $neg, $marg + ($primeira || !$marcador ? 0 : $recuo), $alin);
    };
    foreach (blocosCurriculo($r) as [$tipo, $t]) {
        if ($tipo === 'nome') $paragrafo($t, 16, true, 0, 'center');
        elseif ($tipo === 'contato') $paragrafo($t, 9.5, false, 0, 'center');
        elseif ($tipo === 'titulo') {
            $y -= 9; if ($y < $marg + 40) $novaPagina();
            $paragrafo($t, 11, true);
            $ops .= '0.6 G 0.6 w ' . $marg . ' ' . round($y + 9, 2) . ' m ' . ($L - $marg) . ' ' . round($y + 9, 2) . " l S 0 G\n"; $y -= 3;
        }
        elseif ($tipo === 'cargo') { $y -= 4; $paragrafo($t, 10.5, true); }
        elseif ($tipo === 'item') $paragrafo($t, 10.5, false, 12, 'left', '•  ');
        else { $paragrafo($t, 10.5, false); $y -= 2; }
    }
    $paginas[] = $ops;
    // Objetos: 1 catálogo, 2 páginas, 3 e 4 fontes, depois conteúdo e página alternados
    $obj = [];
    $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = [];
    $fonte = fn($nome) => '<< /Type /Font /Subtype /Type1 /BaseFont /' . $nome . ' /Encoding /WinAnsiEncoding >>';
    $obj[3] = $fonte('Helvetica'); $obj[4] = $fonte('Helvetica-Bold');
    $n = 5;
    foreach ($paginas as $conteudo) {
        $obj[$n] = '<< /Length ' . strlen($conteudo) . " >>\nstream\n" . $conteudo . "endstream";
        $obj[$n + 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $L . ' ' . $A . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $n . ' 0 R >>';
        $kids[] = ($n + 1) . ' 0 R'; $n += 2;
    }
    $obj[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($obj);
    $pdf = "%PDF-1.4\n"; $pos = [];
    foreach ($obj as $i => $o) { $pos[$i] = strlen($pdf); $pdf .= "$i 0 obj\n$o\nendobj\n"; }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($obj) + 1) . "\n0000000000 65535 f \n";
    foreach ($obj as $i => $o) $pdf .= sprintf("%010d 00000 n \n", $pos[$i]);
    $pdf .= "trailer\n<< /Size " . (count($obj) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    return file_put_contents($destino, $pdf) !== false;
}
