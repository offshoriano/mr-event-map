<?php
// Avisos da Stripe (webhook): pagamento confirmado, Pix ou boleto compensado, fatura paga.
// A Stripe assina cada aviso; sem a assinatura certa, nada é gravado.
require __DIR__ . '/_stripe.php';
const AVISO_PAGAMENTO = 'matheus.rangel@mrgroup.com.br';

$corpo = (string)file_get_contents('php://input');
$segredo = stripeCfg()['webhook'] ?? '';
$assinatura = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
if ($segredo === '' || !preg_match('/t=(\d+)/', $assinatura, $t)) out(400, ['error' => 'Sem assinatura']);
preg_match_all('/v1=([a-f0-9]+)/', $assinatura, $v1);
$esperada = hash_hmac('sha256', $t[1] . '.' . $corpo, $segredo);
$valida = false;
foreach ($v1[1] as $s) if (hash_equals($esperada, $s)) $valida = true;
if (!$valida || abs(time() - (int)$t[1]) > 600) out(400, ['error' => 'Assinatura inválida']);

$ev = json_decode($corpo, true) ?: [];
$o = $ev['data']['object'] ?? [];
$tipo = $ev['type'] ?? '';

if (strpos($tipo, 'checkout.session.') === 0) {
    $situacao = ['checkout.session.async_payment_failed' => 'falhou', 'checkout.session.async_payment_succeeded' => 'pago'][$tipo]
        ?? (($o['payment_status'] ?? '') === 'paid' ? 'pago' : 'aguardando');
    $produto = $o['metadata']['produto'] ?? '';
    $p = ['id' => $o['id'], 'tipo' => 'produto', 'produto' => $produto, 'descricao' => PRODUTOS[$produto]['nome'] ?? $produto,
        'nome' => clean($o['customer_details']['name'] ?? '', 120), 'email' => clean($o['customer_details']['email'] ?? '', 160),
        'valor' => ($o['amount_total'] ?? 0) / 100, 'situacao' => $situacao, 'ref' => $o['metadata']['ref'] ?? '',
        'modo' => !empty($o['livemode']) ? 'produção' : 'teste', 'atualizado' => date('c')];
    if (pagouAgora(registrarPagamento($p + ['data' => date('c', $o['created'] ?? time())]), $situacao)) {
        avisar(AVISO_PAGAMENTO, "Pagamento recebido: {$p['descricao']}", "Pagamento confirmado pela Stripe (" . $p['modo'] . ")\n\n"
            . "Produto: {$p['descricao']}\nValor: R$ " . number_format($p['valor'], 2, ',', '.') . "\nCliente: {$p['nome']}\nE-mail: {$p['email']}\n\nPainel: https://mrgroup.com.br/leads/#pagamentos\n");
    }
}

// Currículo pago: o pedido entra na fila e é gerado logo depois de responder à Stripe
if (in_array($tipo, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)
    && ($o['metadata']['produto'] ?? '') === 'curriculo' && ($o['payment_status'] ?? '') === 'paid') {
    require_once __DIR__ . '/_pedidos.php';
    $ped = pedido((string)($o['metadata']['ref'] ?? ''));
    if ($ped && $ped['situacao'] === 'aguardando_pagamento') {
        atualizarPedido($ped['id'], ['situacao' => 'na_fila', 'pago_em' => date('c')]);
        responderEContinuar(['ok' => true], fn() => processarPedido($ped['id']));
    }
}

if ($tipo === 'invoice.paid') {
    $p = ['id' => $o['id'], 'tipo' => 'fatura', 'descricao' => clean($o['lines']['data'][0]['description'] ?? 'Fatura', 200),
        'nome' => clean($o['customer_name'] ?? '', 120), 'email' => clean($o['customer_email'] ?? '', 160), 'valor' => ($o['amount_paid'] ?? 0) / 100,
        'situacao' => 'pago', 'numero' => $o['number'] ?? '', 'modo' => !empty($o['livemode']) ? 'produção' : 'teste', 'atualizado' => date('c'), 'data' => date('c', $o['created'] ?? time())];
    if (pagouAgora(registrarPagamento($p), 'pago')) {
        avisar(AVISO_PAGAMENTO, "Fatura paga: {$p['nome']}", "A fatura {$p['numero']} foi paga (" . $p['modo'] . ")\n\nCliente: {$p['nome']}\nValor: R$ "
            . number_format($p['valor'], 2, ',', '.') . "\nDescrição: {$p['descricao']}\n\nPainel: https://mrgroup.com.br/leads/#pagamentos\n");
    }
}

out(200, ['ok' => true]);
