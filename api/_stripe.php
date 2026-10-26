<?php
// Peças comuns da Stripe (pagamentos e faturas). Não responde nada sozinho.
// A chave fica em stripe-config.json, na pasta de dados fora do site, e é salva pelo painel (aba Pagamentos).
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }
require_once __DIR__ . '/_base.php';

$stripeCfgFile = $dataDir . '/stripe-config.json';
$pagamentosFile = $dataDir . '/pagamentos.json';
const STRIPE_WEBHOOK_URL = 'https://mrgroup.com.br/api/stripe-webhook.php';
const STRIPE_EVENTOS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'invoice.paid'];

// Produtos vendidos pelo site: o preço vem da Stripe pelo lookup_key, a página de volta é a do produto
const PRODUTOS = [
    'curriculo'   => ['nome' => 'Currículo Sob Medida', 'voltar' => '/curriculo/', 'centavos' => 9700,
                      'descricao' => 'Currículo adaptado à vaga escolhida, carta de apresentação, ajustes no LinkedIn e nota antes e depois.'],
    'diagnostico' => ['nome' => 'Diagnóstico de eventos', 'voltar' => '/diagnostico/', 'centavos' => 14700,
                      'descricao' => 'Diagnóstico de eventos do setor de óleo, gás e energia para a sua empresa.'],
];

// Cria na Stripe os produtos que ainda não existem (a conta de produção começa vazia). O preço é achado pelo lookup_key.
function garantirProdutos($chave) {
    $criados = [];
    foreach (PRODUTOS as $k => $p) {
        $r = stripe('GET', 'prices', ['lookup_keys' => [$k], 'limit' => 1], $chave);
        if (!empty($r[1]['data'])) continue;
        $prod = stripe('POST', 'products', ['name' => $p['nome'], 'description' => $p['descricao'], 'metadata' => ['produto' => $k]], $chave);
        if (empty($prod[1]['id'])) continue;
        $preco = stripe('POST', 'prices', ['product' => $prod[1]['id'], 'currency' => 'brl', 'unit_amount' => $p['centavos'], 'lookup_key' => $k], $chave);
        if (!empty($preco[1]['id'])) $criados[] = $p['nome'];
    }
    return $criados;
}

function stripeCfg() { global $stripeCfgFile; return readJson($stripeCfgFile); }

// Chamada à API da Stripe. Devolve [código HTTP, resposta decodificada].
function stripe($metodo, $caminho, $params = [], $chave = null) {
    $chave = $chave ?? (stripeCfg()['chave'] ?? '');
    if ($chave === '') return [0, ['error' => ['message' => 'Stripe não configurada']]];
    $url = 'https://api.stripe.com/v1/' . $caminho;
    $corpo = http_build_query($params);
    if ($metodo === 'GET' && $corpo !== '') { $url .= '?' . $corpo; $corpo = ''; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_USERPWD => $chave . ':']);
    if ($metodo === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string)$r, true) ?: []];
}
function stripeErro($r) { return $r[1]['error']['message'] ?? 'Erro na Stripe'; }
function modoStripe($chave) { return strpos((string)$chave, '_live_') !== false ? 'produção' : 'teste'; }

// Guarda ou atualiza um pagamento recebido (sessão de checkout ou fatura), pelo id da Stripe.
// Devolve a situação anterior ('nova' se não existia) ou false se não conseguiu gravar.
function registrarPagamento($p) {
    global $pagamentosFile;
    return mexer($pagamentosFile, function (&$todos) use ($p) {
        foreach ($todos as $i => $t) if ($t['id'] === $p['id']) { $todos[$i] = array_merge($t, array_diff_key($p, ['data' => 1])); return $t['situacao'] ?? ''; }
        $todos[] = $p; return 'nova';
    });
}
// Avisa por e-mail só na primeira vez que o pagamento aparece como pago (a Stripe repete avisos)
function pagouAgora($anterior, $situacao) { return $anterior !== false && $anterior !== 'pago' && $situacao === 'pago'; }

// Abre o pagamento de um produto liberado no painel. Devolve [true, url, id da sessão] ou [false, erro]
function abrirCheckout($produto, $email = '', $ref = '') {
    $cfg = stripeCfg();
    if (!isset(PRODUTOS[$produto]) || !in_array($produto, $cfg['abertos'] ?? [], true)) return [false, 'Este produto ainda não está à venda'];
    $preco = stripe('GET', 'prices', ['lookup_keys' => [$produto], 'active' => 'true', 'limit' => 1]);
    $price = $preco[1]['data'][0]['id'] ?? '';
    if ($price === '') return [false, 'Preço não encontrado na Stripe'];
    $p = [
        'mode' => 'payment', 'locale' => 'pt-BR',
        'line_items' => [['price' => $price, 'quantity' => 1]],
        'success_url' => 'https://mrgroup.com.br/pagamento/obrigado/?s={CHECKOUT_SESSION_ID}',
        'cancel_url' => 'https://mrgroup.com.br' . PRODUTOS[$produto]['voltar'],
        'allow_promotion_codes' => 'true',
        'metadata' => ['produto' => $produto, 'ref' => $ref],
        'payment_intent_data' => ['metadata' => ['produto' => $produto, 'ref' => $ref]],
    ];
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) $p['customer_email'] = $email;
    $r = stripe('POST', 'checkout/sessions', $p);
    return empty($r[1]['url']) ? [false, stripeErro($r)] : [true, $r[1]['url'], $r[1]['id']];
}
