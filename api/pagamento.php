<?php
// Pagamentos pela Stripe.
// POST {action:"checkout", produto, email?, ref?}          abre o pagamento de um produto liberado no painel (público)
// POST {action:"status", s}                                situação de um pagamento, para a página de obrigado (público)
// POST {action:"painel", senha}                            configuração, pagamentos e faturas (protegido)
// POST {action:"config", senha, chave?, abertos?}          guarda a chave, cria o webhook e libera produtos (protegido)
// POST {action:"fatura", senha, nome, email, documento?, descricao, valor, dias}   cria e envia uma fatura (protegido)
// POST {action:"cancelar", senha, id}                      cancela uma fatura em aberto (protegido)
require __DIR__ . '/_stripe.php';

$in = entrada();
$action = $in['action'] ?? '';

if ($action === 'checkout') {
    limitar('checkout', 20);
    $r = abrirCheckout((string)($in['produto'] ?? ''), clean($in['email'] ?? '', 160), clean($in['ref'] ?? '', 120));
    out($r[0] ? 200 : 404, $r[0] ? ['url' => $r[1]] : ['error' => $r[1]]);
}

if ($action === 'status') {
    limitar('status-pagamento', 60);
    $s = (string)($in['s'] ?? '');
    if (!preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/', $s)) out(400, ['error' => 'Pagamento inválido']);
    $r = stripe('GET', 'checkout/sessions/' . $s);
    if ($r[0] !== 200) out(404, ['error' => 'Pagamento não encontrado']);
    $produto = $r[1]['metadata']['produto'] ?? '';
    out(200, ['situacao' => $r[1]['payment_status'] ?? '', 'produto' => PRODUTOS[$produto]['nome'] ?? '',
        'nome' => explode(' ', trim($r[1]['customer_details']['name'] ?? ''))[0]]);
}

auth($in);

if ($action === 'painel') {
    $cfg = stripeCfg();
    $res = ['configurada' => !empty($cfg['chave']), 'abertos' => $cfg['abertos'] ?? [], 'produtos' => array_map(fn($p) => $p['nome'], PRODUTOS),
        'pagamentos' => array_reverse(readJson($pagamentosFile)), 'faturas' => []];
    if (!empty($cfg['chave'])) {
        $res += ['conta' => $cfg['conta'] ?? '', 'modo' => modoStripe($cfg['chave']), 'final' => substr($cfg['chave'], -4), 'webhook' => !empty($cfg['webhook'])];
        $f = stripe('GET', 'invoices', ['limit' => 30]);
        foreach ($f[1]['data'] ?? [] as $i) $res['faturas'][] = ['id' => $i['id'], 'numero' => $i['number'] ?? '', 'cliente' => $i['customer_name'] ?: ($i['customer_email'] ?? ''),
            'email' => $i['customer_email'] ?? '', 'valor' => $i['amount_due'] / 100, 'situacao' => $i['status'], 'criada' => date('c', $i['created']),
            'vence' => !empty($i['due_date']) ? date('c', $i['due_date']) : '', 'link' => $i['hosted_invoice_url'] ?? '', 'descricao' => $i['lines']['data'][0]['description'] ?? ''];
    }
    out(200, $res);
}

if ($action === 'config') {
    $cfg = stripeCfg();
    $chave = trim((string)($in['chave'] ?? ''));
    if ($chave !== '') {
        if (!preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $chave)) out(422, ['error' => 'Essa não parece uma chave secreta da Stripe (começa com sk_ ou rk_)']);
        $conta = stripe('GET', 'account', [], $chave);
        if ($conta[0] !== 200) out(422, ['error' => 'A Stripe recusou a chave: ' . stripeErro($conta)]);
        // O segredo do webhook só aparece na criação, então apaga o antigo deste site e cria de novo
        $lista = stripe('GET', 'webhook_endpoints', ['limit' => 100], $chave);
        foreach ($lista[1]['data'] ?? [] as $w) if ($w['url'] === STRIPE_WEBHOOK_URL) stripe('DELETE', 'webhook_endpoints/' . $w['id'], [], $chave);
        $w = stripe('POST', 'webhook_endpoints', ['url' => STRIPE_WEBHOOK_URL, 'enabled_events' => STRIPE_EVENTOS, 'description' => 'Site mrgroup.com.br'], $chave);
        $produtosCriados = garantirProdutos($chave);
        $cfg = array_merge($cfg, ['chave' => $chave, 'conta' => $conta[1]['business_profile']['name'] ?? ($conta[1]['settings']['dashboard']['display_name'] ?? ''),
            'webhook' => $w[1]['secret'] ?? '', 'webhook_erro' => empty($w[1]['secret']) ? stripeErro($w) : '']);
    }
    if (isset($in['abertos']) && is_array($in['abertos'])) $cfg['abertos'] = array_values(array_intersect(array_keys(PRODUTOS), $in['abertos']));
    $ok = mexer($stripeCfgFile, function (&$c) use ($cfg) { $c = $cfg; });
    out($ok ? 200 : 500, ['ok' => (bool)$ok, 'webhook' => !empty($cfg['webhook']), 'aviso' => $cfg['webhook_erro'] ?? '', 'produtos_criados' => $produtosCriados ?? []]);
}

if ($action === 'fatura') {
    $nome = clean($in['nome'] ?? '', 120); $email = clean($in['email'] ?? '', 160);
    $descricao = clean($in['descricao'] ?? '', 300); $valor = (int)round((float)str_replace(',', '.', (string)($in['valor'] ?? 0)) * 100);
    $dias = max(1, min(60, (int)($in['dias'] ?? 7)));
    $doc = preg_replace('/\D/', '', (string)($in['documento'] ?? ''));
    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $descricao === '' || $valor < 100) out(422, ['error' => 'Preencha cliente, e-mail, descrição e um valor a partir de R$ 1,00']);
    // Reaproveita o cliente se o e-mail já existe na Stripe
    $busca = stripe('GET', 'customers', ['email' => $email, 'limit' => 1]);
    $cliente = $busca[1]['data'][0]['id'] ?? '';
    if ($cliente === '') {
        $c = ['name' => $nome, 'email' => $email, 'preferred_locales' => ['pt-BR']];
        if (strlen($doc) === 14) $c['tax_id_data'] = [['type' => 'br_cnpj', 'value' => $doc]];
        if (strlen($doc) === 11) $c['tax_id_data'] = [['type' => 'br_cpf', 'value' => $doc]];
        $r = stripe('POST', 'customers', $c);
        $cliente = $r[1]['id'] ?? '';
        if ($cliente === '') out(502, ['error' => stripeErro($r)]);
    }
    $f = stripe('POST', 'invoices', ['customer' => $cliente, 'collection_method' => 'send_invoice', 'days_until_due' => $dias, 'currency' => 'brl',
        'metadata' => ['origem' => 'painel']]);
    if (empty($f[1]['id'])) out(502, ['error' => stripeErro($f)]);
    $id = $f[1]['id'];
    $it = stripe('POST', 'invoiceitems', ['customer' => $cliente, 'invoice' => $id, 'amount' => $valor, 'currency' => 'brl', 'description' => $descricao]);
    if (empty($it[1]['id'])) { stripe('DELETE', 'invoices/' . $id); out(502, ['error' => stripeErro($it)]); }
    $fin = stripe('POST', 'invoices/' . $id . '/finalize');
    if (empty($fin[1]['hosted_invoice_url'])) out(502, ['error' => stripeErro($fin)]);
    $env = stripe('POST', 'invoices/' . $id . '/send');
    out(200, ['ok' => true, 'link' => $fin[1]['hosted_invoice_url'], 'numero' => $fin[1]['number'] ?? '', 'enviada' => $env[0] === 200]);
}

if ($action === 'cancelar') {
    $id = (string)($in['id'] ?? '');
    if (!preg_match('/^in_[A-Za-z0-9]+$/', $id)) out(400, ['error' => 'Fatura inválida']);
    $r = stripe('POST', 'invoices/' . $id . '/void');
    out($r[0] === 200 ? 200 : 502, $r[0] === 200 ? ['ok' => true] : ['error' => stripeErro($r)]);
}

out(400, ['error' => 'Ação desconhecida']);
