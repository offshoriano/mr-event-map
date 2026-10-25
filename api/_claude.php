<?php
// Gerador do Currículo Sob Medida: manda o currículo, o LinkedIn e a vaga para a API da Anthropic
// e recebe o currículo reescrito em JSON, no formato fixo abaixo. Chamada direta por HTTP (curl),
// como as outras integrações do site, porque a hospedagem não instala bibliotecas.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }
require_once __DIR__ . '/_base.php';

$curriculoCfgFile = $dataDir . '/curriculo-config.json';
const CLAUDE_MODELO = 'claude-opus-5-5';

function curriculoCfg() { global $curriculoCfgFile; return readJson($curriculoCfgFile); }

// Formato da resposta. Toda chave é obrigatória e nada além delas é aceito (exigência das saídas estruturadas).
function esquemaCurriculo() {
    $txt = ['type' => 'string']; $lista = ['type' => 'array', 'items' => ['type' => 'string']];
    $obj = fn($props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];
    return $obj([
        'dados' => $obj(['nome' => $txt, 'cidade' => $txt, 'telefone' => $txt, 'email' => $txt, 'linkedin' => $txt]),
        'objetivo' => $txt,
        'resumo' => $txt,
        'idiomas' => $lista,
        'formacao' => $lista,
        'experiencias' => ['type' => 'array', 'items' => $obj(['periodo' => $txt, 'empresa' => $txt, 'local' => $txt, 'posicao' => $txt, 'atividades' => $txt])],
        'qualificacoes' => $lista,
        'outras' => $lista,
        'carta' => $txt,
        'linkedin' => $obj(['titulo' => $txt, 'sobre' => $txt, 'competencias' => $lista]),
        'nota_antes' => ['type' => 'integer'],
        'nota_depois' => ['type' => 'integer'],
        'faltando' => $lista,
        'avisos_revisao' => $lista,
    ]);
}

function promptSistema() {
    $regras = @file_get_contents(__DIR__ . '/../scripts/curriculo/regras.md') ?: '';
    return "Você trabalha no Currículo Sob Medida da MR Group, empresa de Matheus Rangel, profissional com 14 anos de offshore que já reformulou mais de 300 currículos do setor de óleo e gás.\n\n"
        . "Seu trabalho: receber o currículo atual de uma pessoa (e às vezes o PDF do LinkedIn dela) e o anúncio de uma vaga, e devolver o currículo reescrito para aquela vaga, seguindo à risca as regras abaixo. Quem lê o resultado primeiro é um revisor humano da MR Group, que confere tudo antes de entregar ao cliente.\n\n"
        . "Pontos que não podem falhar:\n"
        . "- Nada inventado. Cada experiência, curso, certificado, data, empresa, unidade, idioma e nível sai do material enviado. Se a vaga pede algo que a pessoa não tem, isso vai para \"faltando\", nunca para o currículo.\n"
        . "- Validade de curso só entra se constar no material. Se não constar, escreva o curso sem validade e registre em \"avisos_revisao\" para o revisor pedir a data ao cliente.\n"
        . "- Dados pessoais só com nome, cidade e estado, telefone, e-mail e LinkedIn. Campo sem informação fica como texto vazio.\n"
        . "- Escreva em português do Brasil, em primeira pessoa no resumo e nas atividades, com as palavras que a vaga usa. Se a vaga estiver em inglês, escreva o currículo, a carta e o LinkedIn em inglês.\n"
        . "- Use \"T-HUET\", nunca só \"HUET\".\n"
        . "- Não use travessão longo nem emoji. Use o traço curto (–) só como separador nos períodos e nas qualificações.\n"
        . "- \"nota_antes\" e \"nota_depois\" vão de 0 a 100 e medem o quanto o currículo combina com a vaga, com honestidade: a nota depois só sobe pelo que foi reescrito, não pelo que a pessoa não tem.\n"
        . "- \"avisos_revisao\" é só para o revisor: dados inconsistentes, datas que se sobrepõem, curso sem validade, vaga que exige experiência que a pessoa não tem, texto da vaga incompleto. Lista vazia se não houver nada.\n"
        . "- \"carta\" é a carta de apresentação para o corpo do e-mail ou o campo de mensagem da candidatura: até 150 palavras, cita a vaga e a empresa, sem bajulação.\n"
        . "- \"linkedin\": título de até 220 caracteres, \"sobre\" de até 1.500 caracteres e de 5 a 10 competências que recrutador do setor pesquisa.\n\n"
        . "=== REGRAS DO CURRÍCULO (método do Matheus Rangel) ===\n\n" . $regras;
}

// Monta o conteúdo da mensagem: os arquivos (PDF vai como documento; Word vira texto) e a vaga
function conteudoPedido($arquivos, $vaga, $extras) {
    $c = [];
    foreach ($arquivos as $rotulo => $caminho) {
        if (!$caminho || !is_file($caminho)) continue;
        $ext = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $c[] = ['type' => 'text', 'text' => "Arquivo a seguir: $rotulo"];
            $c[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode(file_get_contents($caminho))], 'title' => $rotulo];
        } elseif ($ext === 'docx') {
            $c[] = ['type' => 'text', 'text' => "=== $rotulo ===\n" . textoDocx($caminho)];
        }
    }
    $c[] = ['type' => 'text', 'text' => "=== ANÚNCIO DA VAGA ===\n" . $vaga . "\n\n=== INFORMAÇÕES DO PEDIDO ===\n" . $extras
        . "\n\nReescreva o currículo para esta vaga seguindo as regras, e preencha todos os campos do formato pedido."];
    return $c;
}
function textoDocx($caminho) {
    $z = new ZipArchive();
    if ($z->open($caminho) !== true) return '';
    $x = (string)$z->getFromName('word/document.xml'); $z->close();
    $x = preg_replace(['#</w:p>#', '#<w:tab/>#', '#<w:br/>#'], ["\n", "\t", "\n"], $x);
    return trim(html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

// Chama a API. Devolve ['ok' => true, 'resultado' => [...], 'uso' => [...]] ou ['ok' => false, 'erro' => '...']
function gerarCurriculo($arquivos, $vaga, $extras) {
    $chave = curriculoCfg()['chave'] ?? '';
    if ($chave === '') return ['ok' => false, 'erro' => 'Chave da Anthropic não configurada no painel'];
    $corpo = [
        'model' => CLAUDE_MODELO,
        'max_tokens' => 16000,
        'system' => promptSistema(),
        'output_config' => ['effort' => 'high', 'format' => ['type' => 'json_schema', 'schema' => esquemaCurriculo()]],
        // Se o filtro de segurança recusar por engano, a própria API refaz o pedido no modelo recomendado
        'fallbacks' => 'default',
        'messages' => [['role' => 'user', 'content' => conteudoPedido($arquivos, $vaga, $extras)]],
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $chave, 'anthropic-version: 2026-10-21', 'anthropic-beta: server-side-fallback-2026-07-01'],
        CURLOPT_POSTFIELDS => json_encode($corpo, JSON_UNESCAPED_UNICODE)]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $erroRede = curl_error($ch); curl_close($ch);
    if ($r === false) return ['ok' => false, 'erro' => 'Sem resposta da Anthropic: ' . $erroRede];
    $d = json_decode((string)$r, true) ?: [];
    if ($code !== 200) return ['ok' => false, 'erro' => 'Anthropic ' . $code . ': ' . ($d['error']['message'] ?? substr((string)$r, 0, 300))];
    if (($d['stop_reason'] ?? '') === 'refusal') return ['ok' => false, 'erro' => 'A IA recusou o pedido (' . ($d['stop_details']['category'] ?? 'sem categoria') . '). Faça este manualmente.'];
    if (($d['stop_reason'] ?? '') === 'max_tokens') return ['ok' => false, 'erro' => 'A resposta passou do tamanho máximo. Tente de novo ou reduza o material.'];
    $texto = '';
    foreach ($d['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $texto .= $b['text'];
    $res = json_decode($texto, true);
    if (!is_array($res) || empty($res['objetivo'])) return ['ok' => false, 'erro' => 'Resposta fora do formato esperado'];
    return ['ok' => true, 'resultado' => $res, 'uso' => ['modelo' => $d['model'] ?? CLAUDE_MODELO, 'entrada' => $d['usage']['input_tokens'] ?? 0, 'saida' => $d['usage']['output_tokens'] ?? 0]];
}
