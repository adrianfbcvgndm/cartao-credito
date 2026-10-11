<?php
/**
 * ================================================================
 * Autopay - Helpers para Mercado Pago
 * ================================================================
 *
 * Funcoes para criar customer, salvar cartao e cobrar.
 *
 * Endpoints usados:
 *   POST /v1/customers                     - cria/busca customer
 *   POST /v1/customers/{id}/cards          - salva cartao tokenizado
 *   GET  /v1/customers/{id}/cards/{cardid} - consulta cartao
 *   DELETE /v1/customers/{id}/cards/{cardid} - remove cartao
 *   POST /v1/payments                      - cobra cartao salvo
 * ================================================================
 */

if (!function_exists('autopay_get_token')) {
    function autopay_get_token($conn) {
        $r = @$conn->query("SELECT access_token FROM integrar_cartao_mp LIMIT 1");
        return $r ? ($r->fetch_assoc()['access_token'] ?? '') : '';
    }
}

if (!function_exists('autopay_mp_request')) {
    /**
     * Faz request para a API do MP.
     */
    function autopay_mp_request($method, $endpoint, $token, $body = null) {
        $url = 'https://api.mercadopago.com' . $endpoint;
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $data = json_decode($resp, true);
        return [
            'ok' => ($http >= 200 && $http < 300),
            'http' => $http,
            'data' => $data,
            'erro' => $err ?: ($data['message'] ?? null),
            'raw' => $resp,
        ];
    }
}

if (!function_exists('autopay_criar_customer')) {
    /**
     * Cria (ou busca) customer no MP a partir do email + dados do cliente.
     * Retorna customer_id ou null.
     */
    function autopay_criar_customer($conn, $email, $first_name = '', $last_name = '', $description = '') {
        $token = autopay_get_token($conn);
        if (!$token || !$email) return null;

        // 1. Tenta buscar customer existente por email
        $r = autopay_mp_request('GET', '/v1/customers/search?email=' . urlencode($email), $token);
        if ($r['ok'] && !empty($r['data']['results'][0]['id'])) {
            return $r['data']['results'][0]['id'];
        }

        // 2. Nao achou, cria novo
        $body = [
            'email' => $email,
            'first_name' => $first_name ?: 'Cliente',
            'last_name' => $last_name ?: '',
            'description' => $description ?: 'Cliente MK-AUTH',
        ];
        $r = autopay_mp_request('POST', '/v1/customers', $token, $body);
        if ($r['ok'] && !empty($r['data']['id'])) {
            return $r['data']['id'];
        }
        return null;
    }
}

if (!function_exists('autopay_salvar_cartao')) {
    /**
     * Salva o cartao (a partir do card_token gerado pelo MP) no customer.
     * Retorna array com card_id + dados ou null.
     */
    function autopay_salvar_cartao($conn, $customer_id, $card_token) {
        $token = autopay_get_token($conn);
        if (!$token || !$customer_id || !$card_token) return null;

        $r = autopay_mp_request('POST', '/v1/customers/' . $customer_id . '/cards', $token, [
            'token' => $card_token,
        ]);

        if (!$r['ok']) return ['erro' => $r['erro'] ?? 'Falha ao salvar cartao', 'http' => $r['http']];
        $d = $r['data'];

        return [
            'card_id' => $d['id'] ?? null,
            'last_four' => $d['last_four_digits'] ?? '',
            'bandeira' => $d['payment_method']['name'] ?? '',
            'validade' => sprintf('%02d/%d', $d['expiration_month'] ?? 0, $d['expiration_year'] ?? 0),
        ];
    }
}

if (!function_exists('autopay_cobrar_cartao')) {
    /**
     * Cobra um cartao salvo via customer + card_id.
     * Retorna array com payment_id, status e dados.
     */
    function autopay_cobrar_cartao($conn, $customer_id, $card_id, $valor, $external_reference, $description = '') {
        $token = autopay_get_token($conn);
        if (!$token) return ['ok' => false, 'erro' => 'Sem access_token'];

        $body = [
            'transaction_amount' => round(floatval($valor), 2),
            'payment_method_id' => null, // MP detecta do cartao
            'token' => null,             // nao usa - usa card_id
            'description' => $description ?: ('Pagamento automatico - ' . $external_reference),
            'external_reference' => $external_reference,
            'customer' => [
                'id' => $customer_id,
            ],
            'capture' => true,
            'binary_mode' => false,
            'installments' => 1,
            'statement_descriptor' => cc_descritor($conn),   // 11/10/2026: nome do provedor na fatura do cartão (era RXMAX SPEED fixo)
            // 02/10/2026: sem notification_url o MP não chama o webhook -> fatura não baixava (cobrava de novo no mês seguinte)
            'notification_url' => 'https://' . cc_dominio($conn) . '/cartao/webhook_mp.php',
        ];

        // MP API exige token de cartao mesmo pra cartao salvo
        // O fluxo correto eh: pegar card_id e gerar token a partir dele
        // Endpoint: POST /v1/card_tokens com card_id (validation only)
        $rTok = autopay_mp_request('POST', '/v1/card_tokens?public_key=' . urlencode(autopay_get_public_key($conn)), $token, [
            'card_id' => $card_id,
            'customer_id' => $customer_id,
        ]);

        if (!$rTok['ok'] || empty($rTok['data']['id'])) {
            return [
                'ok' => false,
                'erro' => 'Falha ao gerar token do cartao salvo: ' . ($rTok['erro'] ?? "HTTP {$rTok['http']}"),
                'http' => $rTok['http'],
            ];
        }
        $body['token'] = $rTok['data']['id'];

        // Agora cobra
        $r = autopay_mp_request('POST', '/v1/payments', $token, $body);
        if (!$r['ok']) {
            return [
                'ok' => false,
                'http' => $r['http'],
                'erro' => $r['erro'] ?? 'Falha ao cobrar',
                'data' => $r['data'],
            ];
        }

        $d = $r['data'];
        return [
            'ok' => true,
            'payment_id' => $d['id'] ?? null,
            'status' => $d['status'] ?? '',
            'status_detail' => $d['status_detail'] ?? '',
            'data' => $d,
        ];
    }
}

if (!function_exists('autopay_get_public_key')) {
    function autopay_get_public_key($conn) {
        $r = @$conn->query("SELECT public_key FROM integrar_cartao_mp LIMIT 1");
        return $r ? ($r->fetch_assoc()['public_key'] ?? '') : '';
    }
}

if (!function_exists('autopay_remover_cartao')) {
    function autopay_remover_cartao($conn, $customer_id, $card_id) {
        $token = autopay_get_token($conn);
        if (!$token) return false;
        $r = autopay_mp_request('DELETE', '/v1/customers/' . $customer_id . '/cards/' . $card_id, $token);
        return $r['ok'];
    }
}

if (!function_exists('autopay_gerar_token_gerencia')) {
    function autopay_gerar_token_gerencia() {
        return bin2hex(random_bytes(24)); // 48 chars
    }
}

if (!function_exists('autopay_url_gerenciar')) {
    function autopay_url_gerenciar($token) {
        // URL pública pra cliente gerenciar autopay
        // 02/10/2026: no cron (CLI) não há HTTP_HOST -> o link ia como http://localhost no WhatsApp
        $h = $_SERVER['HTTP_HOST'] ?? '';
        $base = function_exists('cc_dominio') ? cc_dominio() : rtrim($h, '/');   // 11/10/2026: no cron usa a URL do servidor da tela Credenciais
        $scheme = 'https';
        return "$scheme://$base/admin/addons/cartao_credito/autopay_publico.php?t=$token";
    }
}
