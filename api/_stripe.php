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
    'curriculo'   => ['nome' => 'Currículo Sob Medida', 'voltar' => '/curriculo/'],
    'diagnostico' => ['nome' => 'Diagnóstico de eventos', 'voltar' => '/diagnostico/'],
];

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
