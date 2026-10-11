<?php
require_once __DIR__ . '/rp_config.php';
require_once __DIR__ . '/financeiro_core.php';

if (!function_exists('rp_pg_scheme')) {
    function rp_pg_scheme() {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }
}

if (!function_exists('rp_pg_host')) {
    function rp_pg_host() {
        return $_SERVER['HTTP_HOST'] ?? 'localhost';
    }
}

if (!function_exists('rp_pg_addon_url')) {
    function rp_pg_addon_url($path = '') {
        $base = rp_pg_scheme() . '://' . rp_pg_host() . '/admin/addons/cartao_credito/';
        return $base . ltrim($path, '/');
    }
}

if (!function_exists('rp_pg_admin_url')) {
    function rp_pg_admin_url($path = '') {
        $base = rp_pg_scheme() . '://' . rp_pg_host() . '/admin/';
        return $base . ltrim($path, '/');
    }
}

if (!function_exists('rp_pg_render_erro')) {
    function rp_pg_render_erro($titulo, $mensagem) {
        http_response_code(200);
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($titulo); ?></title>
    <style>
        body{margin:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#1f2d3d;padding:24px;}
        .box{max-width:560px;margin:8vh auto;background:#fff;border:1px solid #dfe7ef;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,.08);padding:26px;text-align:center;}
        .ico{width:56px;height:56px;margin:0 auto 12px;border-radius:16px;background:rgba(245,158,11,.13);color:#B45309;display:flex;align-items:center;justify-content:center;}
        h1{font-size:22px;margin:0 0 10px;}
        p{font-size:15px;line-height:1.5;color:#526174;margin:0 0 18px;}
        .hint{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px;text-align:left;font-size:13px;color:#475569;}
    </style>
</head>
<body>
    <div class="box">
        <div class="ico"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/></svg></div>
        <h1><?php echo htmlspecialchars($titulo); ?></h1>
        <p><?php echo htmlspecialchars($mensagem); ?></p>
        <div class="hint">Verifique se o link contém o parâmetro <strong>?id=</strong> com o código da fatura.</div>
    </div>
</body>
</html>
        <?php
        exit;
    }
}



function mp_status_detail_pt($detail) {
    $detail = trim((string)$detail);

    $map = [
        'cc_rejected_bad_filled_card_number' => 'Número do cartão inválido.',
        'cc_rejected_bad_filled_date' => 'Data de validade inválida.',
        'cc_rejected_bad_filled_other' => 'Dados do cartão inválidos.',
        'cc_rejected_bad_filled_security_code' => 'Código de segurança inválido.',
        'cc_rejected_blacklist' => 'Pagamento recusado por segurança.',
        'cc_rejected_call_for_authorize' => 'O banco pediu autorização prévia. Entre em contato com a administradora do cartão.',
        'cc_rejected_card_disabled' => 'Cartão desativado. Use outro cartão.',
        'cc_rejected_card_error' => 'O cartão não pôde ser processado. Tente novamente ou use outro cartão.',
        'cc_rejected_duplicated_payment' => 'Pagamento duplicado. Já existe uma tentativa semelhante.',
        'cc_rejected_high_risk' => 'Pagamento recusado por análise de risco.',
        'cc_rejected_insufficient_amount' => 'Saldo ou limite insuficiente.',
        'cc_rejected_invalid_installments' => 'Quantidade de parcelas inválida para este cartão.',
        'cc_rejected_max_attempts' => 'Número máximo de tentativas excedido.',
        'cc_rejected_other_reason' => 'Pagamento recusado pela administradora do cartão.',
        'pending_contingency' => 'Pagamento em análise pelo Mercado Pago.',
        'pending_review_manual' => 'Pagamento em revisão manual pelo Mercado Pago.',
        'accredited' => 'Pagamento aprovado.',
    ];

    return $map[$detail] ?? ($detail !== '' ? $detail : 'Pagamento recusado pela administradora do cartão.');
}

/**
 * Pagamento via Cartao de Credito - Mercado Pago
 * Pagina publica acessada pelo cliente para pagar faturas
 * URL: /admin/addons/cartao_credito/pagamento.php?id=FATURA_ID
 */

error_reporting(0);
date_default_timezone_set('America/Sao_Paulo');

// Conexao com banco
$conn = rp_db_connect_procedural();
mysqli_select_db($conn,'mkradius');
$conn->set_charset("utf8mb4");

// Buscar configuracoes do Mercado Pago
$cfg_mp = mysqli_query($conn, "SELECT * FROM integrar_cartao_mp LIMIT 1");
$mp = mysqli_fetch_assoc($cfg_mp);
$access_token = $mp['access_token'] ?? '';
$public_key = $mp['public_key'] ?? '';
$acrescimo = floatval($mp['acrescimo'] ?? 0);
$msg_processando = $mp['solicitacao_pagamento'] ?? 'Pagamento em processamento...';
$msg_confirmado = $mp['confirmacao_pagamento'] ?? 'Pagamento confirmado!';
$msg_recusado = $mp['pagamento_recusado'] ?? 'Pagamento recusado.';

// Buscar configuracoes de layout
$cfg_layout = mysqli_query($conn, "SELECT * FROM personaliza_pg_pag LIMIT 1");
$layout = mysqli_fetch_assoc($cfg_layout);
$cor_botao = $layout['cor_botao_pg'] ?? '#dc3545';
$cor_navbar = $layout['navbar'] ?? '#3373ad';
$caminho_logo = $layout['caminho_logo'] ?? '/mkfiles/logo.jpg';
$exibir_cartao = ($layout['exibir_cartao'] ?? 'sim') == 'sim';
$exibir_pix = ($layout['exibir_pix'] ?? 'sim') == 'sim';
// 03/10/2026: aba PIX reativada — mostra o Pix EMBUTIDO no boleto Efí (Bolix) da fatura (rp_bolix.php).
// (Estava desligada desde 07/2026 porque o Pix da Evotrix só existia no WhatsApp.) Se a fatura não tiver
// boleto Efí, a aba fica só com o "Receber PIX no WhatsApp" de antes.
$exibir_boleto = ($layout['exibir_boleto'] ?? 'sim') == 'sim';

// Buscar dados da fatura (aceita uuid_lanc ou id numerico)
$fatura_param = $_GET['id'] ?? ($_GET['uuid'] ?? ($_GET['uuid_lanc'] ?? ''));
if ($fatura_param === '' && isset($_GET['cartao']) && $_GET['cartao'] !== '') {
    $fatura_param = $_GET['cartao'];
}
$fatura_id = 0;
$fatura = null;

if (!empty($fatura_param)) {
    if (preg_match('/^[a-f0-9-]{36}$/i', $fatura_param)) {
        // ATENCAO: c.* primeiro e l.* depois, para que as colunas de sis_lanc
        // (id, login, valor, uuid_lanc...) tenham PRIORIDADE e nao sejam
        // sobrescritas por colunas de mesmo nome em sis_cliente (id, nome, login...).
        // Alem disso, l.id AS id garante o id da FATURA mesmo apos o c.*.
        $stmt = mysqli_prepare($conn, "SELECT c.*, l.*, l.id AS id, l.login AS login, l.valor AS valor, l.uuid_lanc AS uuid_lanc, c.nome AS cliente_nome FROM sis_lanc l LEFT JOIN sis_cliente c ON l.login = c.login WHERE l.uuid_lanc = ? AND l.deltitulo = 0 LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $fatura_param);
    } elseif (preg_match('/^[0-9]+$/', $fatura_param)) {
        $fatura_id_num = intval($fatura_param);
        // c.* primeiro, l.* depois + alias explicito do id da fatura (evita colisao com sis_cliente.id)
        $stmt = mysqli_prepare($conn, "SELECT c.*, l.*, l.id AS id, l.login AS login, l.valor AS valor, l.uuid_lanc AS uuid_lanc, c.nome AS cliente_nome FROM sis_lanc l LEFT JOIN sis_cliente c ON l.login = c.login WHERE l.id = ? AND l.deltitulo = 0 LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $fatura_id_num);
    } else {
        $stmt = false;
    }

    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $q = mysqli_stmt_get_result($stmt);
    } else {
        $q = false;
    }
    if ($q && mysqli_num_rows($q) > 0) {
        $fatura = mysqli_fetch_assoc($q);
        $fatura_id = intval($fatura['id']);
        $valor_original = floatval($fatura['valor']);

        // === CORRECAO BUG #1 (auditoria) ===
        // Usa a MESMA funcao que webhook_mp.php usa, garantindo que
        // o valor cobrado bate com o valor validado pelo webhook.
        // Antes: cobrava valor_base * 1.05 (sem multa/juros) -> dava divergencia
        $dados_fatura = rp_buscar_dados_fatura_pagamento($conn, $fatura);
        $taxas        = rp_buscar_taxas_cartao($conn);
        $taxa_1x_pct  = $taxas['taxa_1x_percent'];
        $acrescimo_pct = $acrescimo > 0 ? $acrescimo : $taxas['acrescimo_percent'];

        $params_base = [
            'valor_base'           => $dados_fatura['valor_base'],
            'multa_percent'        => $dados_fatura['multa_percent'],
            'juros_percent_mensal' => $dados_fatura['juros_percent_mensal'],
            'dias_atraso'          => $dados_fatura['dias_atraso'],
            'taxa_1x_percent'      => $taxa_1x_pct,
            'acrescimo_percent'    => $acrescimo_pct,
        ];
        $calc_1x = rp_calcular_valor_fatura(array_merge($params_base, ['parcelas' => 1]));
        $calc_2x = rp_calcular_valor_fatura(array_merge($params_base, ['parcelas' => 2]));
        $calc_3x = rp_calcular_valor_fatura(array_merge($params_base, ['parcelas' => 3]));

        $valor_1x       = $calc_1x['valor_final'];
        $valor_2x_total = $calc_2x['valor_final'];
        $valor_3x_total = $calc_3x['valor_final'];

        // Para exibir na tela: dias de atraso e multa/juros
        $dias_atraso_display = $dados_fatura['dias_atraso'];
        $multa_juros_display = $calc_1x['multa_juros'];
    }
}

function detectarBandeira($numero) {
    $numero = preg_replace('/\D+/', '', $numero);
    if (preg_match('/^4/', $numero)) return 'visa';
    if (preg_match('/^(5[1-5]|2[2-7])/', $numero)) return 'master';
    if (preg_match('/^3[47]/', $numero)) return 'amex';
    if (preg_match('/^6(?:011|5)/', $numero)) return 'elo';
    return 'visa';
}

// Processar pagamento via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // 03/10/2026: o botão chamava api_enviar_whatsapp.php direto do navegador e o cliente (sem login no
    // MK-Auth) levava 401. Agora passa por aqui: só envia ESTA fatura, ao dono dela, no máx. 1 vez a cada 10 min.
    if ($_POST['action'] === 'enviar_pix_whats') {
        if (!is_file(__DIR__ . '/../Resumo_Pagamentos/api_enviar_whatsapp.php')) { echo json_encode(['ok' => false, 'erro' => 'Envio pelo WhatsApp indisponível. Use o cartão ou o boleto.']); exit; }
        if (!$fatura) { echo json_encode(['ok' => false, 'erro' => 'Fatura não encontrada.']); exit; }
        if (($fatura['status'] ?? '') === 'pago') { echo json_encode(['ok' => false, 'erro' => 'Esta fatura já foi paga.']); exit; }
        $trava = sys_get_temp_dir() . '/rp_pixwhats_' . (int)$fatura['id'];
        if (is_file($trava) && time() - filemtime($trava) < 600) {
            echo json_encode(['ok' => true, 'repetido' => true]); exit;
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url = 'https://' . $host . '/admin/addons/Resumo_Pagamentos/api_enviar_whatsapp.php?action=enviar_fatura&login=' . urlencode($fatura['login']) . '&fatura_id=' . (int)$fatura['id'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RESOLVE => [$host . ':443:127.0.0.1'], CURLOPT_HTTPHEADER => ['X-API-Key: ' . rp_get_effective_api_key()]]);
        $resp = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (!empty($resp['ok'])) @touch($trava);
        echo json_encode(['ok' => !empty($resp['ok']), 'erro' => $resp['erro'] ?? 'Falha ao enviar. Tente o cartão ou o boleto.']);
        exit;
    }

    if ($_POST['action'] === 'processar_pagamento') {
        $token = $_POST['token'] ?? '';
        $parcelas = intval($_POST['installments'] ?? 1);
        $email = $_POST['email'] ?? '';
        $doc_tipo = $_POST['doc_type'] ?? 'CPF';
        $doc_number = preg_replace('/\D+/', '', $_POST['doc_number'] ?? '');
        $numero_cartao = preg_replace('/\D+/', '', $_POST['card_number'] ?? '');
        $metodo = $_POST['payment_method_id'] ?? detectarBandeira($numero_cartao);

        if (!$fatura) {
            echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Fatura não encontrada.']);
            exit;
        }

        // 03/10/2026: nunca cobrar de novo uma fatura que já está paga (o link continua aberto depois do pagamento)
        if (($fatura['status'] ?? '') === 'pago') {
            echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Esta fatura já foi paga' . (!empty($fatura['datapag']) ? ' em ' . date('d/m/Y', strtotime($fatura['datapag'])) : '') . '. Nenhuma cobrança foi feita.']);
            exit;
        }

        if ($parcelas < 1 || $parcelas > 3) {
            echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Parcelamento inválido.']);
            exit;
        }

        // Regra solicitada:
        // 1x à vista com 5% de juros
        // 2x com juros
        // 3x com juros maior
        if ($parcelas === 1) {
            $valor = $valor_1x;
        } elseif ($parcelas === 2) {
            $valor = $valor_2x_total;
        } else {
            $valor = $valor_3x_total;
        }

        $payment_data = array(
            'transaction_amount' => round($valor, 2),
            'token' => $token,
            'description' => 'Fatura #' . ($fatura['numconta'] ?? $fatura_id),
            'installments' => $parcelas,
            'payment_method_id' => $metodo,
            'payer' => array(
                'email' => $email,
                'identification' => array('type' => $doc_tipo, 'number' => $doc_number)
            ),
            'external_reference' => ($fatura['uuid_lanc'] ?? strval($fatura_id)),
            'notification_url' => 'https://' . cc_dominio($conn) . '/cartao/webhook_mp.php'   // 11/10/2026: domínio do provedor (era rxmaxspeed.com.br fixo)
        );

        $ch = curl_init('https://api.mercadopago.com/v1/payments');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $access_token,
            'X-Idempotency-Key: ' . uniqid('pay_' . $fatura_id . '_')
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if (!$result || !isset($result['status'])) {
            $logs_dir = __DIR__ . '/logs';
            rp_safe_log($logs_dir . '/erro_pagamento.log', 'ERRO MP: HTTP ' . $http_code . ' | Body omitido por seguranca');
        }

        if ($result && isset($result['status'])) {
            $status = $result['status'];
            $status_detail = $result['status_detail'] ?? '';

            if ($status === 'approved') {
                $data_pagamento = date("Y-m-d H:i:s");
                $id_titulo_safe = mysqli_real_escape_string($conn, $fatura['id']);
                $valor_pago_cartao = number_format($valor, 2, '.', '');
                if ($parcelas === 2) {
                    $juros_cartao = number_format(max(0, $valor_2x_total - $valor_original), 2, '.', '');
                } elseif ($parcelas === 3) {
                    $juros_cartao = number_format(max(0, $valor_3x_total - $valor_original), 2, '.', '');
                } else {
                    $juros_cartao = number_format(0, 2, '.', '');
                }

                $chave_gnet2 = mysqli_real_escape_string($conn, $fatura['chave_gnet2'] ?? '');
                $chave_juno = mysqli_real_escape_string($conn, $fatura['chave_juno'] ?? '');
                $id_empresa = mysqli_real_escape_string($conn, $fatura['id_empresa'] ?? '');

                mysqli_query($conn, "INSERT INTO sis_gnetdels (titulo, chave_gnet2, chave_juno, remover) VALUES('$id_empresa', '$chave_gnet2', '$chave_juno', '1')");

                // PREPARED STATEMENTS (item #18 da auditoria) - usa parametros tipados
                $fatura_id_int = (int)$fatura['id'];
                $valor_pago_float = (float)$valor_pago_cartao;
                $juros_float = (float)$juros_cartao;

                $stmt = $conn->prepare("UPDATE sis_lanc SET status='pago', valorpag=?, coletor='API CARTAO', formapag='CARTAO DE CREDITO', datapag=? WHERE id=? AND status != 'pago'");
                if ($stmt) {
                    $stmt->bind_param('dsi', $valor_pago_float, $data_pagamento, $fatura_id_int);
                    $stmt->execute();
                    $stmt->close();
                }

                $hist_principal = "pagamento do titulo $fatura_id_int via CARTAO";
                $stmt = $conn->prepare("INSERT INTO sis_caixa (usuario, data, historico, entrada, tipomov, planodecontas) VALUES('mkbot', ?, ?, ?, 'aut', 'Outros')");
                if ($stmt) {
                    $stmt->bind_param('ssd', $data_pagamento, $hist_principal, $valor_pago_float);
                    $stmt->execute();
                    $stmt->close();
                }

                if ($juros_float > 0) {
                    $hist_juros = "juros do titulo $fatura_id_int via CARTAO {$installments}x";
                    $stmt = $conn->prepare("INSERT INTO sis_caixa (usuario, data, historico, saida, tipomov, planodecontas) VALUES('mkbot', ?, ?, ?, 'aut', 'Outros')");
                    if ($stmt) {
                        $stmt->bind_param('ssd', $data_pagamento, $hist_juros, $juros_float);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                // ========================================================
                // AUTOPAY: se cliente marcou para ativar debito automatico,
                // salva o cartao tokenizado e cria o registro
                // ========================================================
                $autopay_ativar = !empty($_POST['autopay_ativar']);
                $autopay_meses = max(0, min(99, intval($_POST['autopay_meses'] ?? 0)));
                $autopay_resultado = null;

                if ($autopay_ativar) {
                    // Carrega helper
                    $hlp = __DIR__ . '/autopay_helper.php';
                    if (is_file($hlp)) require_once $hlp;

                    if (function_exists('autopay_criar_customer') && !empty($result['payer']['email'])) {
                        $email = $result['payer']['email'];
                        $first_name = $result['payer']['first_name'] ?? ($fatura['nome'] ?? 'Cliente');
                        $last_name = $result['payer']['last_name'] ?? '';

                        // 1. Cria/busca customer
                        $customer_id = autopay_criar_customer($conn, $email, $first_name, $last_name, $fatura['login'] ?? '');

                        if ($customer_id) {
                            // 2. Salva o cartao usado nesse pagamento
                            $card_token = $_POST['card_token'] ?? '';
                            $card_result = $card_token ? autopay_salvar_cartao($conn, $customer_id, $card_token) : null;

                            if ($card_result && !empty($card_result['card_id'])) {
                                // 3. Cria registro autopay
                                $login_ap = $fatura['login'] ?? '';
                                $token_gerencia = autopay_gerar_token_gerencia();

                                // Calcula proxima cobranca: 5 dias antes do PROXIMO vencimento
                                $r_prox = $conn->query("SELECT datavenc FROM sis_lanc WHERE login='" . mysqli_real_escape_string($conn, $login_ap) . "' AND deltitulo=0 AND status != 'pago' ORDER BY datavenc ASC LIMIT 1");
                                $prox_cobranca = null;
                                if ($r_prox && $r_prox->num_rows > 0) {
                                    $venc = $r_prox->fetch_assoc()['datavenc'];
                                    $prox_cobranca = date('Y-m-d', strtotime($venc . ' -5 days'));
                                } else {
                                    // Sem fatura aberta: estima proximo mes
                                    $prox_cobranca = date('Y-m-d', strtotime('+25 days'));
                                }

                                $stmt = $conn->prepare("INSERT INTO addon_autopay_clientes
                                    (login, mp_customer_id, mp_card_id, cartao_final, cartao_bandeira, cartao_validade, email_pagador, meses_total, proxima_cobranca, token_gerencia, status)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ativo')
                                    ON DUPLICATE KEY UPDATE
                                        mp_customer_id=VALUES(mp_customer_id),
                                        mp_card_id=VALUES(mp_card_id),
                                        cartao_final=VALUES(cartao_final),
                                        cartao_bandeira=VALUES(cartao_bandeira),
                                        cartao_validade=VALUES(cartao_validade),
                                        email_pagador=VALUES(email_pagador),
                                        meses_total=VALUES(meses_total),
                                        meses_executados=0,
                                        proxima_cobranca=VALUES(proxima_cobranca),
                                        falhas_consecutivas=0,
                                        status='ativo',
                                        cancelado_em=NULL,
                                        cancelado_motivo=NULL");
                                if ($stmt) {
                                    $stmt->bind_param('sssssssiss',   // 02/10/2026: meses=i, proxima_cobranca=s (antes ia como int -> 0000-00-00)
                                        $login_ap, $customer_id, $card_result['card_id'],
                                        $card_result['last_four'], $card_result['bandeira'], $card_result['validade'],
                                        $email, $autopay_meses, $prox_cobranca, $token_gerencia);
                                    $stmt->execute();
                                    $autopay_resultado = ['ok' => true, 'token' => $token_gerencia, 'final' => $card_result['last_four']];
                                    $stmt->close();
                                }
                            } else {
                                $autopay_resultado = ['ok' => false, 'erro' => 'falha ao salvar cartao no MP'];
                            }
                        } else {
                            $autopay_resultado = ['ok' => false, 'erro' => 'falha ao criar customer no MP'];
                        }
                    } else {
                        $autopay_resultado = ['ok' => false, 'erro' => 'email do pagador nao informado'];
                    }
                }

                echo json_encode(array(
                    'success' => true,
                    'status' => 'approved',
                    'message' => $msg_confirmado,
                    'payment_id' => $result['id'],
                    'installments' => $parcelas,
                    'autopay' => $autopay_resultado,
                ));
            } else {
                $motivo_recusa = mp_status_detail_pt($status_detail);
                $msg_final_recusado = str_replace(
                    ['%motivo_recusa%', '{motivo_recusa}', '%status_detail%', '{status_detail}'],
                    [$motivo_recusa, $motivo_recusa, $status_detail, $status_detail],
                    $msg_recusado
                );
                if (strpos($msg_final_recusado, '%motivo_recusa%') !== false || trim($msg_final_recusado) === '') {
                    $msg_final_recusado = 'Ops! Seu pagamento foi recusado. Motivo: ' . $motivo_recusa . ' Entre em contato com sua administradora do cartão.';
                }

                $logs_dir = __DIR__ . '/logs';
                if (!is_dir($logs_dir)) @mkdir($logs_dir, 0777, true);
                @file_put_contents(
                    $logs_dir . '/erro_pagamento.log',
                    date('Y-m-d H:i:s') . " | PAGAMENTO RECUSADO MP | status=$status | detail=$status_detail | motivo=$motivo_recusa | resp=" . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                    FILE_APPEND
                );

                echo json_encode(array('success' => false, 'status' => $status, 'message' => $msg_final_recusado, 'detail' => $status_detail, 'motivo' => $motivo_recusa));
            }
        } else {
            $error_msg = $result['message'] ?? 'Erro desconhecido';
            if ($http_code == 401) $error_msg = "Credenciais inválidas (Access Token incorreto)";
            echo json_encode(array('success' => false, 'status' => 'error', 'message' => 'Erro técnico: ' . $error_msg, 'http_code' => $http_code));
        }
        exit;
    }
}

if (!$fatura) {
    rp_pg_render_erro('Fatura não encontrada', 'Não foi possível localizar a fatura para pagamento com cartão.');
}

// 03/10/2026: fatura já paga -> mostra que está paga (antes a página seguia cobrando, com "X dias em atraso")
if (($fatura['status'] ?? '') === 'pago') {
    $pg_prox = null;
    $rq = mysqli_query($conn, "SELECT id, uuid_lanc, datavenc, valor FROM sis_lanc WHERE login='" . mysqli_real_escape_string($conn, (string)$fatura['login']) . "' AND deltitulo=0 AND status<>'pago' ORDER BY datavenc ASC LIMIT 1");
    if ($rq) $pg_prox = mysqli_fetch_assoc($rq);
    $pg_nome = trim((string)($fatura['cliente_nome'] ?? '')) ?: 'Cliente';
    $pg_quando = !empty($fatura['datapag']) ? date('d/m/Y \à\s H:i', strtotime($fatura['datapag'])) : '';
    ?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f172a"><title>Fatura #<?php echo $fatura_id; ?> paga</title>
<style>
body{margin:0;background:#f1f5f9;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#0f172a;padding:20px}
.box{max-width:440px;margin:7vh auto;background:#fff;border-radius:20px;box-shadow:0 12px 34px rgba(15,23,42,.10);padding:30px 24px;text-align:center}
.ok{width:64px;height:64px;margin:0 auto 14px;border-radius:50%;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center}
h1{font-size:22px;margin:0 0 6px}.sub{color:#64748b;font-size:15px;margin:0 0 20px;line-height:1.5}
.lin{display:flex;justify-content:space-between;padding:10px 0;border-top:1px solid #eef2f6;font-size:14px}.lin span{color:#64748b}
.btn{display:block;margin-top:20px;background:#0f172a;color:#fff;text-decoration:none;padding:14px;border-radius:12px;font-weight:600}
.nada{margin-top:18px;color:#16a34a;font-size:14px;font-weight:600}
</style></head><body><div class="box">
<div class="ok"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
<h1>Esta fatura já está paga</h1>
<p class="sub">Olá, <?php echo htmlspecialchars($pg_nome); ?>. Recebemos o pagamento, não precisa pagar de novo.</p>
<div class="lin"><span>Fatura</span><strong>#<?php echo $fatura_id; ?></strong></div>
<div class="lin"><span>Vencimento</span><strong><?php echo !empty($fatura['datavenc']) ? date('d/m/Y', strtotime($fatura['datavenc'])) : '—'; ?></strong></div>
<?php if ($pg_quando): ?><div class="lin"><span>Pago em</span><strong><?php echo $pg_quando; ?></strong></div><?php endif; ?>
<?php if ($pg_prox): ?>
<a class="btn" href="?id=<?php echo urlencode($pg_prox['uuid_lanc'] ?: $pg_prox['id']); ?>">Pagar a próxima fatura (venc. <?php echo date('d/m/Y', strtotime($pg_prox['datavenc'])); ?>)</a>
<?php else: ?><div class="nada">Você não tem outras faturas em aberto.</div><?php endif; ?>
</div></body></html><?php
    exit;
}

// 03/10/2026: Pix embutido no boleto Efí (Bolix) para a aba PIX
$pg_bolix = [];
if ($exibir_pix && ($fatura['status'] ?? '') !== 'pago') {
    @include_once __DIR__ . '/rp_bolix.php';
    if (function_exists('rp_bolix_pix')) { try { $pg_bolix = rp_bolix_pix($conn, $fatura); } catch (\Throwable $e) { $pg_bolix = []; } }
}
/* 11/10/2026: "Receber PIX no WhatsApp" usa o envio do Resumo de Pagamentos. Sem o Resumo e sem Pix no boleto
   (Efí), a aba PIX não teria nada que funcione → some, e a página fica com cartão e boleto. */
$tem_pix_whats = is_file(__DIR__ . '/../Resumo_Pagamentos/api_enviar_whatsapp.php');
if ($exibir_pix && empty($pg_bolix['payload']) && !$tem_pix_whats) $exibir_pix = false;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="theme-color" content="#0f172a">
    <title>Pagamento • Fatura #<?php echo $fatura_id; ?></title>
    <script src="https://sdk.mercadopago.com/js/v2"></script>
    <link rel="stylesheet" href="assets/ds/ds.css?v=20260922">
    <link rel="stylesheet" href="assets/ds/pages/pagamento.css?v=20260922">
    <link rel="stylesheet" href="assets/ds/pages/pagamento_4a.css?v=20261002">
    <script src="assets/vendor/lucide.min.js"></script>
</head>
<body class="pg-body pg4a">
    <div class="tn-scope pg-public">
    <div class="pg-shell">

        <!-- CABEÇALHO PÚBLICO -->
        <header class="pg-topbar ds-anim-up">
            <div class="pg-brand">
                <?php if (!empty($caminho_logo)): ?>
                <img class="pg-brand-logo" src="<?php echo htmlspecialchars($caminho_logo); ?>" alt="Logo do provedor" onerror="this.style.display='none'">
                <?php else: ?>
                <div class="pg-brand-fallback"><i data-lucide="credit-card"></i></div>
                <?php endif; ?>
                <div class="pg-brand-text">
                    <div class="pg-brand-name"><?php echo htmlspecialchars(cc_provedor_nome($conn)); ?></div>
                    <div class="pg-brand-host">Fatura #<?php echo $fatura_id; ?></div>
                </div>
            </div>
            <div class="pg-secure-badge">
                <i data-lucide="shield-check"></i>
                <span>Pagamento seguro</span>
            </div>
        </header>

        <div class="pg-layout">

        <!-- RESUMO DA FATURA (coluna lateral no desktop / topo no mobile) -->
        <aside class="pg-aside ds-anim-up">
        <div class="pg-fatura-sum">
            <div class="pg-fatura-row">
                <div>
                    <?php $pg_nome_cli = trim((string)($fatura['cliente_nome'] ?? '')) ?: trim((string)($fatura['nome'] ?? '')); /* 02/10: sis_lanc.nome (vazio) sobrescrevia o nome do cliente */ ?>
                    <div class="pg-fatura-label">Olá,</div>
                    <div class="pg-fatura-cliente"><?php echo htmlspecialchars($pg_nome_cli !== '' ? $pg_nome_cli : 'Cliente'); ?></div>
                    <div class="pg-fatura-id">Fatura #<?php echo $fatura_id; ?></div>
                </div>
                <div>
                    <div class="pg-fatura-label" style="text-align:right">Valor</div>
                    <?php $pg_sem_taxa = round((float)($dados_fatura['valor_base'] ?? $valor_original) + (float)($multa_juros_display ?? 0), 2); ?>
                    <div class="pg-fatura-valor" id="pgTopoValor" data-cartao="R$ <?php echo number_format($valor_1x, 2, ',', '.'); ?>" data-sem-taxa="R$ <?php echo number_format($pg_sem_taxa, 2, ',', '.'); ?>">R$ <?php echo number_format($valor_1x, 2, ',', '.'); ?></div>
                </div>
            </div>
            <div class="pg-fatura-venc">
                <span>Vencimento: <strong><?php echo !empty($fatura['datavenc']) ? date('d/m/Y', strtotime($fatura['datavenc'])) : '—'; ?></strong></span>
                <?php if ($dias_atraso_display > 0): ?>
                    <span class="pg-fatura-chip late"><i data-lucide="alert-circle"></i><?php echo $dias_atraso_display; ?> dia<?php echo $dias_atraso_display > 1 ? 's' : ''; ?> em atraso</span>
                <?php else: ?>
                    <span class="pg-fatura-chip"><i data-lucide="check"></i>Em dia</span>
                <?php endif; ?>
            </div>
        </div>
        </aside>

        <main class="pg-main ds-anim-up">
        <!-- TABS -->
        <div class="pg-tabs">
            <?php if ($exibir_cartao): ?>
            <button type="button" class="pg-tab active" data-tab="cartao">
                <span class="icon"><i data-lucide="credit-card"></i></span>
                <span class="label">Cartão</span>
            </button>
            <?php endif; ?>
            <?php if ($exibir_boleto): ?>
            <button type="button" class="pg-tab" data-tab="boleto">
                <span class="icon"><i data-lucide="file-text"></i></span>
                <span class="label">Boleto</span>
            </button>
            <?php endif; ?>
            <?php if ($exibir_pix): ?>
            <button type="button" class="pg-tab" data-tab="pix">
                <span class="icon"><svg class="pg-pix-logo" width="20" height="20" viewBox="0 0 24 24" fill="#32BCAD" aria-label="Pix"><path d="M5.283 18.36a3.505 3.505 0 0 0 2.493-1.032l3.6-3.6a.684.684 0 0 1 .946 0l3.613 3.613a3.504 3.504 0 0 0 2.493 1.032h.71l-4.56 4.56a3.647 3.647 0 0 1-5.156 0L4.85 18.36ZM18.428 5.627a3.505 3.505 0 0 0-2.493 1.032l-3.613 3.614a.67.67 0 0 1-.946 0l-3.6-3.6A3.505 3.505 0 0 0 5.283 5.64h-.434l4.573-4.572a3.646 3.646 0 0 1 5.156 0l4.559 4.559ZM1.068 9.422 3.79 6.699h1.492a2.483 2.483 0 0 1 1.744.722l3.6 3.6a1.73 1.73 0 0 0 2.443 0l3.614-3.613a2.482 2.482 0 0 1 1.744-.723h1.767l2.737 2.737a3.646 3.646 0 0 1 0 5.156l-2.736 2.736h-1.768a2.482 2.482 0 0 1-1.744-.722l-3.613-3.613a1.77 1.77 0 0 0-2.444 0l-3.6 3.6a2.483 2.483 0 0 1-1.744.722H3.791l-2.723-2.723a3.646 3.646 0 0 1 0-5.156"/></svg></span>
                <span class="label">PIX</span>
            </button>
            <?php endif; ?>
        </div>

        <!-- PANEL -->
        <div class="pg-panel">

            <!-- TAB: PIX -->
            <?php if ($exibir_pix): ?>
            <div class="pg-tab-content" data-tab-content="pix">
                <?php
                $tem_celular = !empty(trim($fatura['celular'] ?? ''));
                $celular_clean = preg_replace('/\D/', '', $fatura['celular'] ?? '');
                $celular_fmt = '';
                if (strlen($celular_clean) >= 10) {
                    $ddd = substr($celular_clean, 0, 2);
                    $resto = substr($celular_clean, 2);
                    if (strlen($resto) === 9) {
                        $celular_fmt = "($ddd) " . substr($resto, 0, 5) . '-' . substr($resto, 5);
                    } else {
                        $celular_fmt = "($ddd) " . substr($resto, 0, 4) . '-' . substr($resto, 4);
                    }
                    // Mascara meio do celular
                    $celular_mask = '(' . $ddd . ') *****-' . substr($resto, -4);
                } else {
                    $celular_mask = '—';
                }
                ?>

                <?php if (!empty($pg_bolix['payload'])): ?>
                <div class="pg-pixqr">
                    <div style="display:flex;justify-content:center;margin-bottom:6px"><svg class="pg-pix-logo" width="36" height="36" viewBox="0 0 24 24" fill="#32BCAD" aria-label="Pix"><path d="M5.283 18.36a3.505 3.505 0 0 0 2.493-1.032l3.6-3.6a.684.684 0 0 1 .946 0l3.613 3.613a3.504 3.504 0 0 0 2.493 1.032h.71l-4.56 4.56a3.647 3.647 0 0 1-5.156 0L4.85 18.36ZM18.428 5.627a3.505 3.505 0 0 0-2.493 1.032l-3.613 3.614a.67.67 0 0 1-.946 0l-3.6-3.6A3.505 3.505 0 0 0 5.283 5.64h-.434l4.573-4.572a3.646 3.646 0 0 1 5.156 0l4.559 4.559ZM1.068 9.422 3.79 6.699h1.492a2.483 2.483 0 0 1 1.744.722l3.6 3.6a1.73 1.73 0 0 0 2.443 0l3.614-3.613a2.482 2.482 0 0 1 1.744-.723h1.767l2.737 2.737a3.646 3.646 0 0 1 0 5.156l-2.736 2.736h-1.768a2.482 2.482 0 0 1-1.744-.722l-3.613-3.613a1.77 1.77 0 0 0-2.444 0l-3.6 3.6a2.483 2.483 0 0 1-1.744.722H3.791l-2.723-2.723a3.646 3.646 0 0 1 0-5.156"/></svg></div>
                    <h2 class="pg-pix-hero-title">Pague com Pix</h2>
                    <p class="pg-pix-hero-sub">Abra o app do banco, escolha <strong>Pix → Ler QR code</strong> ou use o <strong>copia e cola</strong>. A baixa é automática.</p>
                    <?php if (strpos((string)$pg_bolix['img'], 'data:image/') === 0): ?><img class="pg-pixqr-img" alt="QR code Pix" src="<?php echo htmlspecialchars($pg_bolix['img']); ?>"><?php endif; ?>
                    <div class="pg-pixqr-cc"><input id="pgPixCC" readonly value="<?php echo htmlspecialchars($pg_bolix['payload']); ?>"><button type="button" onclick="var e=document.getElementById('pgPixCC');e.select();try{document.execCommand('copy')}catch(x){}if(navigator.clipboard)navigator.clipboard.writeText(e.value);this.textContent='Copiado!';var b=this;setTimeout(function(){b.textContent='Copiar'},2000)">Copiar</button></div>
                    <p class="pg-pix-note">Valor com multa e juros calculado pelo banco na hora do pagamento.</p>
                </div>
                <?php else: ?>
                <div class="pg-pix-hero">
                    <div class="pg-pix-hero-icon">
                        <svg class="pg-pix-logo" width="30" height="30" viewBox="0 0 24 24" fill="#fff" aria-label="Pix"><path d="M5.283 18.36a3.505 3.505 0 0 0 2.493-1.032l3.6-3.6a.684.684 0 0 1 .946 0l3.613 3.613a3.504 3.504 0 0 0 2.493 1.032h.71l-4.56 4.56a3.647 3.647 0 0 1-5.156 0L4.85 18.36ZM18.428 5.627a3.505 3.505 0 0 0-2.493 1.032l-3.613 3.614a.67.67 0 0 1-.946 0l-3.6-3.6A3.505 3.505 0 0 0 5.283 5.64h-.434l4.573-4.572a3.646 3.646 0 0 1 5.156 0l4.559 4.559ZM1.068 9.422 3.79 6.699h1.492a2.483 2.483 0 0 1 1.744.722l3.6 3.6a1.73 1.73 0 0 0 2.443 0l3.614-3.613a2.482 2.482 0 0 1 1.744-.723h1.767l2.737 2.737a3.646 3.646 0 0 1 0 5.156l-2.736 2.736h-1.768a2.482 2.482 0 0 1-1.744-.722l-3.613-3.613a1.77 1.77 0 0 0-2.444 0l-3.6 3.6a2.483 2.483 0 0 1-1.744.722H3.791l-2.723-2.723a3.646 3.646 0 0 1 0-5.156"/></svg>
                    </div>
                    <h2 class="pg-pix-hero-title">PIX disponível no WhatsApp</h2>
                    <p class="pg-pix-hero-sub">
                        Pagamento aprovado em <strong>segundos</strong>.<br>
                        Receba o link PIX direto no seu WhatsApp.
                    </p>
                </div>

                <?php if ($tem_celular): ?>
                <div class="pg-wa-box">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="#25D366" stroke="none">
                        <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.247-.694.247-1.289.173-1.413z"/>
                    </svg>
                    <div style="flex:1;min-width:0">
                        <div class="pg-wa-box-label">Enviar para</div>
                        <div class="pg-wa-box-num"><?php echo htmlspecialchars($celular_mask); ?></div>
                    </div>
                </div>

                <button type="button" class="pg-pix-copy-btn" id="pgBtnEnviarPix" data-id="<?php echo $fatura_id; ?>" data-login="<?php echo htmlspecialchars($fatura['login'] ?? ''); ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                    </svg>
                    <span>Receber PIX no WhatsApp</span>
                </button>

                <p class="pg-pix-note">
                    Já recebeu o PIX antes? Verifique sua conversa do WhatsApp.<br>
                    O link tem validade até o vencimento da fatura.
                </p>
                <?php else: ?>
                <div class="pg-alert info show">
                    <span class="pg-alert-icon"><i data-lucide="phone"></i></span>
                    <span>Não há celular cadastrado para esta fatura. Use o cartão de crédito ou boleto, ou entre em contato com o suporte para receber o PIX.</span>
                </div>
                <?php endif; ?>

                <?php endif; /* fim do else do Bolix */ ?>
                <div id="pgPixResultado" class="pg-alert"></div>
            </div>
            <?php endif; ?>

            <!-- TAB: CARTAO -->
            <?php if ($exibir_cartao): ?>
            <div class="pg-tab-content active" data-tab-content="cartao">

                <!-- Cartao 3D Preview -->
                <div class="pg-card-stage">
                    <div class="pg-card-preview" id="pgCardPreview">
                        <div class="pg-card-face pg-card-front" id="pgCardFront" data-brand="visa">
                            <div class="pg-card-chip"></div>
                            <div class="pg-card-brand" id="pgCardBrand">VISA</div>
                            <div class="pg-card-num" id="pgCardNum">•••• •••• •••• ••••</div>
                            <div class="pg-card-bottom">
                                <div style="flex:1;min-width:0">
                                    <div class="pg-card-field-label">Titular</div>
                                    <div class="pg-card-name" id="pgCardName">SEU NOME AQUI</div>
                                </div>
                                <div style="text-align:right">
                                    <div class="pg-card-field-label">Validade</div>
                                    <div class="pg-card-exp" id="pgCardExp">MM/AA</div>
                                </div>
                            </div>
                        </div>
                        <div class="pg-card-face pg-card-back">
                            <div class="pg-card-strip"></div>
                            <div class="pg-card-cvv-row">
                                <div class="pg-card-signature"></div>
                                <div class="pg-card-cvv-box" id="pgCardCvv">CVV</div>
                            </div>
                            <div class="pg-card-back-label">Código de segurança · 3 dígitos no verso</div>
                        </div>
                    </div>
                </div>

                <form id="pgFormCartao" onsubmit="return pgProcessarPagamento(event)">

                    <div class="pg-field">
                        <label class="pg-field-label" for="pgInputNum">Número do Cartão</label>
                        <div class="pg-input-with-icon">
                            <input type="text" class="pg-input" id="pgInputNum" placeholder="0000 0000 0000 0000" maxlength="19" autocomplete="cc-number" inputmode="numeric">
                            <span class="pg-input-icon" id="pgBrandIcon"></span>
                        </div>
                    </div>

                    <div class="pg-field">
                        <label class="pg-field-label" for="pgInputName">Nome no Cartão</label>
                        <input type="text" class="pg-input" id="pgInputName" placeholder="Como impresso no cartão" autocomplete="cc-name">
                    </div>

                    <div class="pg-row" style="margin-bottom:12px">
                        <div class="pg-field" style="margin:0">
                            <label class="pg-field-label" for="pgInputExp">Validade</label>
                            <input type="text" class="pg-input" id="pgInputExp" placeholder="MM/AA" maxlength="5" autocomplete="cc-exp" inputmode="numeric">
                        </div>
                        <div class="pg-field" style="margin:0">
                            <label class="pg-field-label" for="pgInputCvv">CVV</label>
                            <input type="text" class="pg-input" id="pgInputCvv" placeholder="000" maxlength="4" autocomplete="cc-csc" inputmode="numeric">
                        </div>
                    </div>

                    <div class="pg-row-3" style="margin-bottom:12px">
                        <div class="pg-field" style="margin:0">
                            <label class="pg-field-label" for="pgDocType">Tipo</label>
                            <select class="pg-select" id="pgDocType">
                                <option value="CPF">CPF</option>
                                <option value="CNPJ">CNPJ</option>
                            </select>
                        </div>
                        <div class="pg-field" style="margin:0">
                            <label class="pg-field-label" for="pgDocNum">Documento</label>
                            <input type="text" class="pg-input" id="pgDocNum" placeholder="000.000.000-00" value="<?php echo htmlspecialchars($fatura['cpf_cnpj'] ?? ''); ?>" inputmode="numeric">
                        </div>
                    </div>

                    <div class="pg-field">
                        <label class="pg-field-label" for="pgEmail">E-mail</label>
                        <input type="email" class="pg-input" id="pgEmail" placeholder="email@exemplo.com" value="<?php echo htmlspecialchars($fatura['email'] ?? ''); ?>" autocomplete="email">
                    </div>

                    <div class="pg-field">
                        <label class="pg-field-label">Parcelas</label>
                        <div class="pg-parcelas-grid">
                            <div class="pg-parcela-pill selected" data-parcela="1" data-total="<?php echo number_format($valor_1x, 2, '.', ''); ?>">
                                <div class="pg-parcela-num">1x</div>
                                <div class="pg-parcela-valor">R$ <?php echo number_format($valor_1x, 2, ',', '.'); ?></div>
                                <span class="pg-parcela-tag success">À vista</span>
                            </div>
                            <div class="pg-parcela-pill" data-parcela="2" data-total="<?php echo number_format($valor_2x_total, 2, '.', ''); ?>">
                                <div class="pg-parcela-num">2x</div>
                                <div class="pg-parcela-valor">R$ <?php echo number_format($valor_2x_total / 2, 2, ',', '.'); ?></div>
                                <span class="pg-parcela-tag warn">Com juros</span>
                            </div>
                            <div class="pg-parcela-pill" data-parcela="3" data-total="<?php echo number_format($valor_3x_total, 2, '.', ''); ?>">
                                <div class="pg-parcela-num">3x</div>
                                <div class="pg-parcela-valor">R$ <?php echo number_format($valor_3x_total / 3, 2, ',', '.'); ?></div>
                                <span class="pg-parcela-tag warn">Com juros</span>
                            </div>
                        </div>
                        <input type="hidden" id="pgParcelas" value="1">
                    </div>

                    <div class="pg-summary-box">
                        <div class="pg-summary-row">
                            <span>Valor da fatura</span>
                            <span>R$ <?php echo number_format($dados_fatura['valor_base'] ?? $valor_original, 2, ',', '.'); ?></span>
                        </div>
                        <?php if (($multa_juros_display ?? 0) > 0): ?>
                        <div class="pg-summary-row">
                            <span>Multa + Juros</span>
                            <span>R$ <?php echo number_format($multa_juros_display, 2, ',', '.'); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php $pg_base_mj = round((float)($dados_fatura['valor_base'] ?? $valor_original) + (float)($multa_juros_display ?? 0), 2); $pg_taxa_1x = round($valor_1x - $pg_base_mj, 2); ?>
                        <?php if ($pg_taxa_1x > 0.004): /* 02/10: a taxa do cartão não aparecia (89 + 2,50 mas total 96,07) */ ?>
                        <div class="pg-summary-row" data-base-mj="<?php echo number_format($pg_base_mj, 2, '.', ''); ?>">
                            <span id="pgResumoTaxaLbl">Taxa do cartão</span>
                            <span id="pgResumoTaxa">R$ <?php echo number_format($pg_taxa_1x, 2, ',', '.'); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="pg-summary-row total">
                            <span>Total</span>
                            <strong id="pgResumoParcela">R$ <?php echo number_format($valor_1x, 2, ',', '.'); ?></strong>
                        </div>
                    </div>

                    <!-- ============ AUTOPAY: Ativar Débito Automático ============ -->
                    <div class="pg-autopay-box">
                        <label class="pg-autopay-label">
                            <input type="checkbox" name="autopay_ativar" id="autopayCheck" value="1" onchange="autopayToggle(this)">
                            <span>
                                <strong class="pg-autopay-title"><i data-lucide="zap"></i>Ativar pagamento automático</strong>
                                <span class="pg-autopay-desc">
                                    Suas próximas faturas serão pagas <strong>automaticamente</strong> com este mesmo cartão.<br>
                                    Cobrança 5 dias antes do vencimento. Cancele quando quiser pelo WhatsApp.
                                </span>
                            </span>
                        </label>

                        <div id="autopayDuracao" class="pg-autopay-duracao" style="display:none">
                            <label class="pg-autopay-duracao-label">
                                Por quantos meses você quer manter ativo?
                            </label>
                            <div class="pg-autopay-grid">
                                <label class="pg-autopay-pill">
                                    <input type="radio" name="autopay_meses" value="3"> <span>3 meses</span>
                                </label>
                                <label class="pg-autopay-pill">
                                    <input type="radio" name="autopay_meses" value="6"> <span>6 meses</span>
                                </label>
                                <label class="pg-autopay-pill">
                                    <input type="radio" name="autopay_meses" value="12" checked> <span>1 ano</span>
                                </label>
                                <label class="pg-autopay-pill">
                                    <input type="radio" name="autopay_meses" value="0"> <i data-lucide="infinity"></i><span>Sempre</span>
                                </label>
                            </div>
                            <small class="pg-autopay-hint">
                                <i data-lucide="lightbulb"></i>
                                <span>Você receberá um WhatsApp toda vez que cobrar, e poderá cancelar a qualquer momento.</span>
                            </small>
                        </div>
                    </div>

                    <button type="submit" class="pg-btn-pagar" id="pgBtnPagar">
                        <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <span class="text">Pagar R$ <span id="pgBtnValor"><?php echo number_format($valor_1x, 2, ',', '.'); ?></span></span>
                        <span class="spinner"></span>
                    </button>
                </form>

                <div id="pgResultado" class="pg-alert"></div>
            </div>
            <?php endif; ?>

            <!-- TAB: BOLETO -->
            <?php if ($exibir_boleto): ?>
            <div class="pg-tab-content" data-tab-content="boleto">
                <?php
                $linha_dig = $fatura['linhadig'] ?? '';
                $url_boleto = !empty($fatura['url_boleto']) ? $fatura['url_boleto'] : '';
                if (empty($url_boleto)) {
                    $srv_boleto = preg_replace('#^https?://#i', '', rp_pg_host());
                    $url_boleto = 'https://' . $srv_boleto . '/central/boleto.php?id=' . $fatura_id;
                }
                ?>
                <div class="pg-boleto-card">
                    <div class="pg-boleto-icon"><i data-lucide="file-text"></i></div>
                    <div class="pg-boleto-title">Pagar com Boleto</div>
                    <div class="pg-boleto-desc">
                        Boleto bancário com vencimento prorrogado. <br>
                        <strong>Compensação:</strong> 1 a 3 dias úteis.
                    </div>
                </div>

                <?php if (!empty($linha_dig)): ?>
                <div class="pg-boleto-linha">
                    <div class="pg-boleto-linha-label">Linha digitável</div>
                    <div class="pg-boleto-linha-num" id="pgBoletoLinha"><?php echo htmlspecialchars($linha_dig); ?></div>
                </div>
                <button type="button" class="pg-btn-secondary" id="pgBtnCopyBoleto" data-text="<?php echo htmlspecialchars(preg_replace('/\D/', '', $linha_dig)); ?>" style="margin-bottom:10px">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                    <span>Copiar Linha Digitável</span>
                </button>
                <?php endif; ?>

                <a href="<?php echo htmlspecialchars($url_boleto); ?>" target="_blank" rel="noopener" class="pg-btn-pagar" style="text-decoration:none">
                    <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span class="text">Baixar Boleto em PDF</span>
                </a>
            </div>
            <?php endif; ?>

        </div>
        </main>

        <!-- SELOS DE CONFIANÇA -->
        <div class="pg-trust ds-anim-up">
            <div class="pg-trust-item">
                <i data-lucide="lock"></i>
                <span>SSL 256-bit</span>
            </div>
            <div class="pg-trust-item">
                <i data-lucide="shield-check"></i>
                <span>PCI Compliant</span>
            </div>
            <div class="pg-trust-item">
                <i data-lucide="badge-check"></i>
                <span>Mercado Pago</span>
            </div>
        </div>

        </div><!-- /.pg-layout -->

        <div class="pg-footer-mp">Processado pelo Mercado Pago · <?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? ''); ?></div>

    </div><!-- /.pg-shell -->
    </div><!-- /.tn-scope -->

    <script src="assets/ds/js/ds.js?v=20260922"></script>
    <script src="assets/ds/js/toast.js?v=20260922"></script>
    <script>
        const mp = new MercadoPago('<?php echo $public_key; ?>');
        const btn = document.getElementById('pgBtnPagar');
        const btnValor = document.getElementById('pgBtnValor');
        const resultado = document.getElementById('pgResultado');
        const parcelasInput = document.getElementById('pgParcelas');
        const resumoParcela = document.getElementById('pgResumoParcela');
        const cardPreview = document.getElementById('pgCardPreview');
        const cardFront = document.getElementById('pgCardFront');
        const brandIcon = document.getElementById('pgBrandIcon');

        function moneyBR(v) {
            return Number(v).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
        }
        function formatCardNumber(value) {
            return value.replace(/\D/g, '').replace(/(.{4})/g, '$1 ').trim().substring(0, 19);
        }
        function detectarBandeira(numero) {
            const n = (numero || '').replace(/\D/g, '');
            if (/^4/.test(n)) return 'visa';
            if (/^(5[1-5]|2[2-7])/.test(n)) return 'master';
            if (/^3[47]/.test(n)) return 'amex';
            if (/^6(?:011|5)/.test(n)) return 'elo';
            return '';
        }
        const BRAND_LABELS = { visa: 'VISA', master: 'MASTER', amex: 'AMEX', elo: 'ELO' };

        // ========= TABS =========
        document.querySelectorAll('.pg-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.pg-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.pg-tab-content').forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                const tname = tab.getAttribute('data-tab');
                const content = document.querySelector('[data-tab-content="' + tname + '"]');
                if (content) content.classList.add('active');
            });
        });

        // ========= PARCELAS =========
        document.querySelectorAll('.pg-parcela-pill').forEach(pill => {
            pill.addEventListener('click', () => {
                document.querySelectorAll('.pg-parcela-pill').forEach(p => p.classList.remove('selected'));
                pill.classList.add('selected');
                parcelasInput.value = pill.getAttribute('data-parcela');
                const total = parseFloat(pill.getAttribute('data-total') || '0');
                if (resumoParcela) resumoParcela.textContent = 'R$ ' + moneyBR(total);
                if (btnValor) btnValor.textContent = moneyBR(total);
            });
        });

        // ========= CARD PREVIEW =========
        function updatePreview() {
            const numEl = document.getElementById('pgInputNum');
            const nameEl = document.getElementById('pgInputName');
            const expEl = document.getElementById('pgInputExp');
            const cvvEl = document.getElementById('pgInputCvv');
            if (!numEl) return;

            const num = numEl.value || '';
            const name = nameEl.value || 'SEU NOME AQUI';
            const exp = expEl.value || 'MM/AA';
            const cvv = cvvEl.value || '';

            const cleanNum = num.replace(/\D/g, '');
            const formatted = cleanNum ? formatCardNumber(cleanNum) : '•••• •••• •••• ••••';
            const display = cleanNum.length > 0
                ? formatted.padEnd(19, '•').replace(/^(.{0,4}).*/, function(_, vis) {
                    return formatted; // mantém visivel pq cliente esta digitando
                })
                : '•••• •••• •••• ••••';

            document.getElementById('pgCardNum').textContent = display;
            document.getElementById('pgCardName').textContent = (name || 'SEU NOME AQUI').toUpperCase();
            document.getElementById('pgCardExp').textContent = exp || 'MM/AA';
            document.getElementById('pgCardCvv').textContent = cvv ? '•'.repeat(cvv.length) : 'CVV';

            const brand = detectarBandeira(cleanNum);
            const brandLabel = BRAND_LABELS[brand] || 'CARTÃO';
            document.getElementById('pgCardBrand').textContent = brandLabel;
            cardFront.setAttribute('data-brand', brand || 'visa');

            // Atualiza ícone no input
            if (brand) {
                brandIcon.textContent = brandLabel;
                brandIcon.setAttribute('data-brand', brand);
                brandIcon.classList.add('show');
            } else {
                brandIcon.classList.remove('show');
            }
        }

        const inputNum = document.getElementById('pgInputNum');
        if (inputNum) {
            inputNum.addEventListener('input', function() {
                this.value = formatCardNumber(this.value);
                updatePreview();
            });
        }
        const inputName = document.getElementById('pgInputName');
        if (inputName) inputName.addEventListener('input', updatePreview);

        const inputExp = document.getElementById('pgInputExp');
        if (inputExp) {
            inputExp.addEventListener('input', function() {
                let v = this.value.replace(/\D/g, '').substring(0, 4);
                if (v.length >= 3) v = v.substring(0, 2) + '/' + v.substring(2);
                this.value = v;
                updatePreview();
            });
        }

        const inputCvv = document.getElementById('pgInputCvv');
        if (inputCvv) {
            inputCvv.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').substring(0, 4);
                updatePreview();
            });
            inputCvv.addEventListener('focus', function() { cardPreview.classList.add('is-flipped'); });
            inputCvv.addEventListener('blur', function() { cardPreview.classList.remove('is-flipped'); });
        }

        // CPF/CNPJ mask
        const docNum = document.getElementById('pgDocNum');
        if (docNum) {
            docNum.addEventListener('input', function() {
                let v = this.value.replace(/\D/g, '');
                const tipo = document.getElementById('pgDocType').value;
                if (tipo === 'CPF') {
                    v = v.substring(0, 11);
                    if (v.length > 9) v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{0,2}).*/, '$1.$2.$3-$4');
                    else if (v.length > 6) v = v.replace(/^(\d{3})(\d{3})(\d{0,3}).*/, '$1.$2.$3');
                    else if (v.length > 3) v = v.replace(/^(\d{3})(\d{0,3}).*/, '$1.$2');
                } else {
                    v = v.substring(0, 14);
                    if (v.length > 12) v = v.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2}).*/, '$1.$2.$3/$4-$5');
                    else if (v.length > 8) v = v.replace(/^(\d{2})(\d{3})(\d{3})(\d{0,4}).*/, '$1.$2.$3/$4');
                    else if (v.length > 5) v = v.replace(/^(\d{2})(\d{3})(\d{0,3}).*/, '$1.$2.$3');
                    else if (v.length > 2) v = v.replace(/^(\d{2})(\d{0,3}).*/, '$1.$2');
                }
                this.value = v;
            });
        }

        updatePreview();

        // ========= ENVIAR PIX VIA WHATSAPP =========
        const btnEnviarPix = document.getElementById('pgBtnEnviarPix');
        const pixResultado = document.getElementById('pgPixResultado');
        if (btnEnviarPix) {
            btnEnviarPix.addEventListener('click', async function() {
                if (this.disabled) return;
                const login = this.getAttribute('data-login');
                if (!login) {
                    showPixAlert('err', 'Login do cliente nao identificado.');
                    return;
                }

                // UI: loading
                this.disabled = true;
                const originalHtml = this.innerHTML;
                this.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" style="animation:pgSpin .8s linear infinite" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" stroke-opacity=".25"/><path d="M12 2 a10 10 0 0 1 10 10" stroke-linecap="round"/></svg><span>Enviando...</span>';

                try {
                    const fd = new FormData(); fd.append('action', 'enviar_pix_whats');
                    const response = await fetch(window.location.href, { method: 'POST', body: fd, credentials: 'include' });
                    const data = await response.json();
                    if (data.ok) {
                        showPixAlert('ok', '✓ PIX enviado para seu WhatsApp! Verifique sua conversa.');
                        this.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span>PIX Enviado</span>';
                        this.style.background = 'linear-gradient(135deg, #059669, #047857)';
                        setTimeout(() => {
                            this.disabled = false;
                            this.innerHTML = originalHtml;
                            this.style.background = '';
                        }, 8000);
                    } else {
                        showPixAlert('err', 'Erro: ' + (data.erro || 'Falha ao enviar PIX'));
                        this.disabled = false;
                        this.innerHTML = originalHtml;
                    }
                } catch (err) {
                    showPixAlert('err', 'Erro de conexao. Tente novamente em alguns segundos.');
                    this.disabled = false;
                    this.innerHTML = originalHtml;
                }
            });
        }
        function showPixAlert(type, message) {
            if (!pixResultado) return;
            pixResultado.className = 'pg-alert show ' + type;
            const icon = type === 'ok' ? '✓' : (type === 'err' ? '✕' : 'ℹ');
            pixResultado.innerHTML = '<span class="pg-alert-icon">' + icon + '</span><span>' + message + '</span>';
            // Auto hide depois de 8s
            setTimeout(() => {
                if (pixResultado.classList.contains('show')) {
                    pixResultado.classList.remove('show');
                }
            }, 8000);
        }

        // ========= COPY BOLETO =========
        const btnCopyBoleto = document.getElementById('pgBtnCopyBoleto');
        if (btnCopyBoleto) {
            btnCopyBoleto.addEventListener('click', async function() {
                const text = this.getAttribute('data-text') || '';
                try {
                    await navigator.clipboard.writeText(text);
                    const span = this.querySelector('span');
                    const old = span.textContent;
                    span.textContent = '✓ Copiado!';
                    if (window.dsToast) dsToast.success('Linha digitável copiada!');
                    setTimeout(() => { span.textContent = old; }, 2200);
                } catch(e) {
                    if (window.dsToast) dsToast.warning('Copie manualmente: ' + text, { duration: 0 });
                    else alert('Copie manualmente: ' + text);
                }
            });
        }

        // ========= SHOW ALERT =========
        function showAlert(type, message) {
            if (!resultado) return;
            resultado.className = 'pg-alert show ' + type;
            const icon = type === 'ok' ? '✓' : (type === 'err' ? '✕' : 'ℹ');
            resultado.innerHTML = '<span class="pg-alert-icon">' + icon + '</span><span>' + message + '</span>';
        }

        // ========= PROCESSAR PAGAMENTO =========
        async function pgProcessarPagamento(e) {
            e.preventDefault();
            btn.disabled = true;
            btn.classList.add('loading');
            if (resultado) { resultado.className = 'pg-alert'; }

            try {
                const exp = document.getElementById('pgInputExp').value.split('/');
                if (exp.length !== 2 || !exp[0] || !exp[1]) throw new Error('Data de validade inválida.');

                const cardNumber = document.getElementById('pgInputNum').value.replace(/\s/g, '');
                if (cardNumber.length < 13) throw new Error('Número do cartão inválido.');

                const cvv = document.getElementById('pgInputCvv').value;
                if (cvv.length < 3) throw new Error('CVV inválido.');

                const docNumVal = document.getElementById('pgDocNum').value.replace(/\D/g, '');
                if (docNumVal.length < 11) throw new Error('Documento (CPF/CNPJ) inválido.');

                const tokenResult = await mp.createCardToken({
                    cardNumber: cardNumber,
                    cardholderName: document.getElementById('pgInputName').value,
                    cardExpirationMonth: exp[0],
                    cardExpirationYear: '20' + exp[1],
                    securityCode: cvv,
                    identificationType: document.getElementById('pgDocType').value,
                    identificationNumber: docNumVal
                });

                const selectedPill = document.querySelector('.pg-parcela-pill.selected');
                const total = selectedPill ? selectedPill.getAttribute('data-total') : '<?php echo number_format($valor_1x, 2, '.', ''); ?>';
                const installments = parcelasInput.value;

                const formData = new FormData();
                formData.append('action', 'processar_pagamento');
                formData.append('token', tokenResult.id);
                formData.append('card_token', tokenResult.id);  // duplicado pra autopay
                formData.append('installments', installments);
                formData.append('payment_method_id', detectarBandeira(cardNumber) || 'visa');
                formData.append('email', document.getElementById('pgEmail').value);
                formData.append('doc_type', document.getElementById('pgDocType').value);
                formData.append('doc_number', docNumVal);
                formData.append('amount', total);
                formData.append('card_number', cardNumber);

                // Autopay
                const autopayCheck = document.getElementById('autopayCheck');
                if (autopayCheck && autopayCheck.checked) {
                    formData.append('autopay_ativar', '1');
                    const mesesSel = document.querySelector('input[name="autopay_meses"]:checked');
                    formData.append('autopay_meses', mesesSel ? mesesSel.value : '12');
                }

                const response = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await response.json();

                if (data.success) {
                    showAlert('ok', data.message || 'Pagamento aprovado!');
                    btn.classList.remove('loading');
                    btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span>Pagamento Confirmado</span>';
                    btn.style.background = 'linear-gradient(135deg, #059669 0%, #047857 100%)';
                    setTimeout(() => { location.reload(); }, 1800);
                } else {
                    showAlert('err', data.message + (data.http_code ? ' (HTTP ' + data.http_code + ')' : ''));
                    btn.disabled = false;
                    btn.classList.remove('loading');
                }
            } catch (err) {
                showAlert('err', 'Erro: ' + err.message);
                btn.disabled = false;
                btn.classList.remove('loading');
            }
            return false;
        }
    </script>
<script src="assets/ds/js/pg4a.js?v=20261002"></script>
</body>
</html>

<script>
function autopayToggle(cb) {
    document.getElementById('autopayDuracao').style.display = cb.checked ? 'block' : 'none';
}
</script>
