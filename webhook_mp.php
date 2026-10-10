<?php
require_once __DIR__ . '/rp_config.php';
/**
 * webhook_mp_reforcado.php
 *
 * Melhorias aplicadas:
 * - validação de valor
 * - identificação preferencial por uuid_lanc
 * - proteção contra baixa duplicada
 * - baixa + cancelamento EFi mais seguros
 * - suporte/validação de parcelamento até 3x
 *   * 1x e 2x sem juros
 *   * 3x com juros do campo "acrescimo" da integração MP
 *
 * OBSERVAÇÃO IMPORTANTE:
 * Este webhook NÃO cria o parcelamento no checkout.
 * Ele apenas valida e processa o pagamento recebido do Mercado Pago.
 * Para "oferecer até 3x" na tela de pagamento, o script que cria o pagamento/preferência
 * do Mercado Pago também precisa ser ajustado.
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: text/plain; charset=utf-8");
error_reporting(0);
date_default_timezone_set('America/Sao_Paulo');

function roundDown($decimal, $precision = 2) {
    $sign = $decimal > 0 ? 1 : -1;
    $base = pow(10, $precision);
    return floor(abs($decimal) * $base) / $base * $sign;
}

function mp_log($msg) {
    $log_file = __DIR__ . '/logs/webhook_mp.log';
    $dir = dirname($log_file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] $msg\n", FILE_APPEND);
}

function mp_respond_ok($msg = 'OK') {
    http_response_code(200);
    echo $msg;
    exit;
}
function mp_validate_signature_if_configured($raw) {
    // v8.1: secret OBRIGATORIO. Se nao tiver configurado, REJEITA.
    if (RP_MP_WEBHOOK_SECRET === "") {
        mp_log("ERRO CRITICO: RP_MP_WEBHOOK_SECRET nao configurado. Webhook rejeitado por seguranca. Configure em rp_config_local.php.");
        // Alerta o admin via Telegram (uma vez a cada 5min via dedupe)
        if (function_exists('rp_alertar_telegram')) {
            rp_alertar_telegram(
                "Webhook do Mercado Pago foi rejeitado porque `mp_webhook_secret` esta VAZIO em `rp_config_local.php`. Todo pagamento por cartao MP esta ficando orfao!",
                'critico',
                ['acao' => 'Configure o secret em rp_config_local.php']
            );
        }
        return false;
    }

    $signature = $_SERVER["HTTP_X_SIGNATURE"] ?? "";
    $request_id = $_SERVER["HTTP_X_REQUEST_ID"] ?? "";
    if ($signature === "") {
        mp_log("ERRO: webhook recebido sem cabecalho X-Signature");
        return false;
    }

    // =================================================================
    // Formato OFICIAL do Mercado Pago (documentacao):
    // X-Signature: ts=1704067200,v1=abc123def456...
    //
    // O hash deve ser calculado sobre o template:
    //   "id:DATA_ID;request-id:REQUEST_ID;ts:TIMESTAMP;"
    // onde DATA_ID e o id do recurso (data.id no body)
    //
    // Ref: https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks
    // =================================================================
    $ts = '';
    $hash_received = '';
    foreach (explode(',', $signature) as $part) {
        $part = trim($part);
        if (strpos($part, 'ts=') === 0) {
            $ts = substr($part, 3);
        } elseif (strpos($part, 'v1=') === 0) {
            $hash_received = substr($part, 3);
        }
    }

    // ========== Validacao formato OFICIAL (ts + v1) ==========
    if ($ts !== '' && $hash_received !== '') {
        // Anti-replay: rejeita timestamps muito antigos (>10 min) ou futuros
        $ts_int = (int)$ts;
        $age = time() - $ts_int;
        if ($age > 600 || $age < -120) {
            mp_log("ERRO: timestamp do webhook fora do limite aceitavel (idade=$age segundos)");
            return false;
        }

        // Extrai data.id do body (o MP envia "data": {"id": "123"})
        $json = json_decode($raw, true);
        $data_id = '';
        if (is_array($json) && isset($json['data']['id'])) {
            $data_id = (string)$json['data']['id'];
        } elseif (isset($_GET['data.id'])) {
            $data_id = (string)$_GET['data.id'];
        } elseif (isset($_GET['id'])) {
            $data_id = (string)$_GET['id'];
        }

        if ($data_id !== '') {
            $template = "id:{$data_id};request-id:{$request_id};ts:{$ts};";
            $expected = hash_hmac('sha256', $template, RP_MP_WEBHOOK_SECRET);
            if (hash_equals($expected, $hash_received)) {
                return true;
            }
            mp_log("ERRO: assinatura invalida (formato oficial ts+v1). Esperado != Recebido.");
        }
    }

    // ========== Fallback: validacao simples (hash do body inteiro) ==========
    // Mantido para compatibilidade com versoes antigas do webhook do MP
    $expected_simple = hash_hmac("sha256", $raw, RP_MP_WEBHOOK_SECRET);
    if ($hash_received !== '' && hash_equals($expected_simple, $hash_received)) {
        mp_log("AVISO: webhook validado pelo formato simples (fallback). Considere atualizar o webhook no painel do MP.");
        return true;
    }
    if (hash_equals($expected_simple, $signature)) {
        mp_log("AVISO: webhook validado pelo formato bruto (fallback). Considere atualizar o webhook no painel do MP.");
        return true;
    }

    mp_log("ERRO: assinatura invalida no webhook (todos os formatos testados)");
    return false;
}


function mp_clean_phone($phone) {
    return preg_replace('/\D+/', '', (string)$phone);
}

function mp_get_json_body() {
    $raw = file_get_contents('php://input');
    mp_log("Webhook recebido: body omitido por seguranca");
    $json = json_decode($raw, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        return [$raw, $json];
    }

    mp_log("ERRO JSON: " . json_last_error_msg());
    return [$raw, null];
}

function mp_extract_payment_id($raw, $conteudo) {
    $id = null;

    if (is_array($conteudo)) {
        $id = $conteudo['data']['id'] ??
              $conteudo['id'] ??
              $conteudo['resource'] ??
              null;

        if (empty($id) && !empty($conteudo['resource'])) {
            if (preg_match('/\/(\d+)$/', $conteudo['resource'], $matches)) {
                $id = $matches[1];
            }
        }
    }

    if (empty($id) && strpos((string)$raw, 'payment') !== false) {
        if (preg_match('/(\d{6,})/', (string)$raw, $matches)) {
            $id = $matches[1];
        }
    }

    return trim((string)$id);
}

function mp_fetch_payment_mp($payment_id, $access_token) {
    $ch = curl_init('https://api.mercadopago.com/v1/payments/' . urlencode($payment_id));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        mp_log("Erro cURL Mercado Pago: " . $curl_err);
    }

    if ($http_code !== 200) {
        mp_log("Erro ao consultar Mercado Pago. HTTP Code: $http_code | Body: $result");
        return null;
    }

    $retorno = json_decode($result, true);
    if (!is_array($retorno)) {
        mp_log("Erro ao decodificar resposta do MercadoPago");
        return null;
    }

    return $retorno;
}

function mp_send_evotrix($baseUrl, $apiKey, $channelId, $celular, $mensagem, $campaign='mercado-pago') {
    // Mesmo padrão usado no api_enviar_whatsapp.php que funcionava:
    // endpoint /whatsapp/notifications/text
    // recipient com 55 na frente quando o número vem só com DDD+número
    // payload com channel, recipient, body e campaign

    if (empty($baseUrl) || empty($apiKey) || empty($channelId) || empty($celular) || empty($mensagem)) {
        mp_log("Evotrix não enviado: configuração/número/mensagem vazia");
        return false;
    }

    $numero = preg_replace('/[^0-9]/', '', (string)$celular);

    if (strlen($numero) == 10 || strlen($numero) == 11) {
        $numero = '55' . $numero;
    } elseif (strlen($numero) < 10) {
        mp_log("Evotrix número inválido: " . $numero);
        return false;
    }

    $url = rtrim($baseUrl, '/') . '/whatsapp/notifications/text';

    $payload = json_encode([
        'channel'   => $channelId,
        'recipient' => $numero,
        'body'      => $mensagem,
        'campaign'  => $campaign
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => $payload
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    mp_log("Evotrix MP | HTTP $http_code | Para: $numero | Campaign: $campaign | Resp: $response | cURL: $curl_error");

    if ($http_code == 201 || $http_code == 200) {
        return true;
    }

    return false;
}

function mp_expected_amounts($valor_base, $multa_percent, $juros_percent_mensal, $dias_atraso, $acrescimo_cartao_percent, $parcelas) {
    $valor_base = (float)$valor_base;
    $multa_percent = (float)$multa_percent;
    $juros_percent_mensal = (float)$juros_percent_mensal;
    $dias_atraso = max(0, (int)$dias_atraso);
    $acrescimo_cartao_percent = (float)$acrescimo_cartao_percent;
    $parcelas = (int)$parcelas;

    if ($dias_atraso > 0) {
        $multa_total = ($valor_base / 100) * $multa_percent;
        $juros_diario_percent = $juros_percent_mensal * $dias_atraso;
        $juros_total = ($valor_base / 100) * $juros_diario_percent;
        $multa_juros_total = roundDown($multa_total + $juros_total, 2);
    } else {
        $multa_juros_total = 0.00;
    }

    $valor_sem_cartao = $valor_base + $multa_juros_total;

    // Regra solicitada:
    // 1x e 2x = sem juros
    // 3x = com juros baseado no campo acrescimo
    $juros_cartao = 0.00;
    if ($parcelas >= 3) {
        $juros_cartao = roundDown(($valor_sem_cartao / 100) * $acrescimo_cartao_percent, 2);
    }

    $valor_com_cartao = $valor_sem_cartao + $juros_cartao;

    return [
        'valor_base' => round((float)$valor_base, 2),
        'multa_juros' => round((float)$multa_juros_total, 2),
        'valor_sem_cartao' => round((float)$valor_sem_cartao, 2),
        'juros_cartao' => round((float)$juros_cartao, 2),
        'valor_com_cartao' => round((float)$valor_com_cartao, 2),
    ];
}

function mp_values_match($esperado, $recebido, $tolerancia = 0.05) {
    $esp = (float)$esperado;
    $rec = (float)$recebido;
    $delta = abs($esp - $rec);

    // 1. Tolerancia absoluta (default R$ 0,05)
    if ($delta <= (float)$tolerancia) return true;

    // 2. Tolerancia proporcional: ate 10% de diferenca aceita
    //    (cobre acrescimo de cartao, parcelamento, juros adicionais do MP)
    if ($esp > 0) {
        $delta_pct = ($delta / $esp) * 100;
        if ($delta_pct <= 10.0) return true;
    }

    // 3. Se recebeu MAIS do que esperado em ate 30%, aceita (cliente pagou a mais)
    //    Se recebeu MENOS de ate 10%, rejeita (suspeito)
    if ($rec > $esp && $rec <= $esp * 1.30) return true;

    return false;
}

function mp_fetch_titulo_by_reference($conn, $reference, $valor_mp = 0) {
    if (empty($reference)) return null;

    $ref = mysqli_real_escape_string($conn, $reference);

    // Funcao helper: confere se valor bate (tolera 30% pra mais ou 5% pra menos)
    $valida_valor = function($titulo) use ($valor_mp) {
        if ($valor_mp <= 0) return true;  // sem valor MP - aceita
        $valor_titulo = floatval($titulo['valor'] ?? 0);
        if ($valor_titulo <= 0) return true;
        $razao = $valor_mp / $valor_titulo;
        return ($razao >= 0.95 && $razao <= 1.30); // pago entre 95% e 130% do esperado
    };

    // TENTATIVA 1: uuid_lanc (formato padrao - UUID v7) - MAIS CONFIAVEL
    // sis_cliente.* primeiro, sis_lanc.* depois + alias do id, login, valor, uuid
    // para evitar que colunas homonimas de sis_cliente (id, nome, login...) sobrescrevam as da fatura.
    $sql = mysqli_query($conn, "
        SELECT sis_cliente.*, sis_lanc.*,
               sis_lanc.id AS id, sis_lanc.login AS login,
               sis_lanc.valor AS valor, sis_lanc.uuid_lanc AS uuid_lanc
        FROM sis_lanc
        INNER JOIN sis_cliente ON sis_lanc.login = sis_cliente.login
        WHERE sis_lanc.uuid_lanc = '$ref'
        LIMIT 1
    ");
    if ($sql && mysqli_num_rows($sql) > 0) {
        $t = mysqli_fetch_assoc($sql);
        mp_log("✓ Titulo achado por uuid_lanc: $reference (id={$t['id']}, valor={$t['valor']}, mp={$valor_mp})");
        // UUID e identificacao unica - confia mesmo com diferenca de valor
        return $t;
    }

    // TENTATIVA 2: id numerico direto - VALIDA VALOR
    if (ctype_digit((string)$reference)) {
        $sql = mysqli_query($conn, "
            SELECT sis_cliente.*, sis_lanc.*,
                   sis_lanc.id AS id, sis_lanc.login AS login,
                   sis_lanc.valor AS valor, sis_lanc.uuid_lanc AS uuid_lanc
            FROM sis_lanc
            INNER JOIN sis_cliente ON sis_lanc.login = sis_cliente.login
            WHERE sis_lanc.id = '$ref'
            LIMIT 1
        ");
        if ($sql && mysqli_num_rows($sql) > 0) {
            $t = mysqli_fetch_assoc($sql);
            // SEGURANCA: so aceita se o valor bate (evita baixar fatura aleatoria)
            if ($valida_valor($t)) {
                mp_log("✓ Titulo achado por id numerico: $reference (id={$t['id']}, valor={$t['valor']}, mp={$valor_mp})");
                return $t;
            } else {
                mp_log("⚠ Titulo #{$t['id']} achado por id mas VALOR NAO BATE (titulo R\$ {$t['valor']} vs MP R\$ $valor_mp). REJEITADO por seguranca.");
            }
        }
    }

    // TENTATIVA 3: extrai numero de strings tipo "fatura-19" ou "fatura#19" - VALIDA VALOR
    if (preg_match('/(?:fatura|titulo|tit|fat)[\s\-_#]*(\d+)/i', $reference, $m)) {
        $id_extraido = intval($m[1]);
        $sql = mysqli_query($conn, "
            SELECT sis_cliente.*, sis_lanc.*,
                   sis_lanc.id AS id, sis_lanc.login AS login,
                   sis_lanc.valor AS valor, sis_lanc.uuid_lanc AS uuid_lanc
            FROM sis_lanc
            INNER JOIN sis_cliente ON sis_lanc.login = sis_cliente.login
            WHERE sis_lanc.id = $id_extraido
            LIMIT 1
        ");
        if ($sql && mysqli_num_rows($sql) > 0) {
            $t = mysqli_fetch_assoc($sql);
            // SEGURANCA: so aceita se o valor bate
            if ($valida_valor($t)) {
                mp_log("✓ Titulo achado por regex (id=$id_extraido em '$reference', valor={$t['valor']}, mp={$valor_mp})");
                return $t;
            } else {
                mp_log("⚠ Titulo #{$t['id']} achado por regex mas VALOR NAO BATE. REJEITADO.");
            }
        }
    }

    mp_log("✗ Nenhum titulo encontrado por uuid_lanc/id/regex para reference='$reference'");
    return null;
}

function mp_ensure_audit_table($conn) {
    mysqli_query($conn, "
        CREATE TABLE IF NOT EXISTS addon_mp_webhook_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payment_id VARCHAR(50) NOT NULL,
            titulo_id INT NOT NULL,
            status_mp VARCHAR(50) DEFAULT '',
            transaction_amount DECIMAL(10,2) DEFAULT 0.00,
            installments INT DEFAULT 1,
            payload LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_payment_titulo (payment_id, titulo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function mp_already_processed($conn, $payment_id, $titulo_id) {
    $payment_id = mysqli_real_escape_string($conn, $payment_id);
    $titulo_id = (int)$titulo_id;
    $r = mysqli_query($conn, "SELECT id FROM addon_mp_webhook_audit WHERE payment_id = '$payment_id' AND titulo_id = $titulo_id LIMIT 1");
    return ($r && mysqli_num_rows($r) > 0);
}

function mp_register_audit($conn, $payment_id, $titulo_id, $status_mp, $transaction_amount, $installments, $payload) {
    $payment_id = mysqli_real_escape_string($conn, $payment_id);
    $titulo_id = (int)$titulo_id;
    $status_mp = mysqli_real_escape_string($conn, $status_mp);
    $transaction_amount = number_format((float)$transaction_amount, 2, '.', '');
    $installments = (int)$installments;
    $payload = mysqli_real_escape_string($conn, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    mysqli_query($conn, "
        INSERT IGNORE INTO addon_mp_webhook_audit (payment_id, titulo_id, status_mp, transaction_amount, installments, payload)
        VALUES ('$payment_id', $titulo_id, '$status_mp', '$transaction_amount', $installments, '$payload')
    ");
}

list($raw, $conteudo) = mp_get_json_body();
if (!mp_validate_signature_if_configured($raw)) {
    mp_log("Webhook recusado: assinatura invalida");
    http_response_code(401);
    exit("Invalid signature");
}
$payment_id = mp_extract_payment_id($raw, $conteudo);

if (empty($payment_id)) {
    mp_log("ID VAZIO - Encerrando");
    mp_respond_ok();
}

mp_log("Payment ID: $payment_id");

// Conexão
$conn = rp_db_connect_procedural();
if (!$conn) {
    mp_log("Erro de conexão com banco");
    http_response_code(500);
    exit;
}
mysqli_select_db($conn, 'mkradius');
$conn->set_charset("utf8mb4");

// Config MP
$consulta_mp = mysqli_query($conn, "SELECT * FROM integrar_cartao_mp LIMIT 1");
if (!$consulta_mp) {
    mp_log("ERRO SQL integrar_cartao_mp: " . mysqli_error($conn));
    http_response_code(500);
    exit;
}
$linha_mp = mysqli_fetch_assoc($consulta_mp);
if (!$linha_mp) {
    mp_log("Configuração do MercadoPago não encontrada");
    http_response_code(500);
    exit;
}

$access_token = $linha_mp['access_token'] ?? '';
$acrescimo = (float)($linha_mp['acrescimo'] ?? 0);
$confirmacao_pagamento = $linha_mp['confirmacao_pagamento'] ?? '';
$solicitacao_pagamento = $linha_mp['solicitacao_pagamento'] ?? '';
$pagamento_recusado = $linha_mp['pagamento_recusado'] ?? '';

if (empty($access_token)) {
    mp_log("Access token não configurado");
    http_response_code(500);
    exit;
}

$retorno = mp_fetch_payment_mp($payment_id, $access_token);
if (!$retorno) {
    mp_respond_ok();
}

$status = $retorno['status'] ?? '';
$status_detail = $retorno['status_detail'] ?? '';
$external_reference = trim((string)($retorno['external_reference'] ?? ''));
$transaction_amount = (float)($retorno['transaction_amount'] ?? 0);
$installments = (int)($retorno['installments'] ?? 1);
$payment_method = $retorno['payment_method_id'] ?? 'cartao';

mp_log("Status: $status | External reference: $external_reference | Detalhe: $status_detail | Parc: $installments | Valor MP: $transaction_amount");

if (empty($external_reference)) {
    mp_log("external_reference vazio");
    mp_respond_ok();
}

if ($installments < 1 || $installments > 3) {
    mp_log("Parcelamento inválido para processamento automático. Parc: $installments");
    mp_respond_ok();
}

$titulo = mp_fetch_titulo_by_reference($conn, $external_reference, $transaction_amount);
if (!$titulo) {
    mp_log("⚠ Título não encontrado por uuid_lanc/id/regex para: $external_reference");

    // ALERTA TELEGRAM: pagamento aprovado mas SEM fatura correspondente
    if ($status === 'approved' && function_exists('rp_alertar_telegram')) {
        $email_pagador = $retorno['payer']['email'] ?? '?';
        $payment_id_alerta = $retorno['id'] ?? '?';
        @rp_alertar_telegram(
            "🚨 Pagamento MP APROVADO mas SEM FATURA CORRESPONDENTE!\n\n" .
            "payment_id: $payment_id_alerta\n" .
            "external_reference: $external_reference\n" .
            "valor: R\$ $transaction_amount\n" .
            "email: $email_pagador\n\n" .
            "Use diag_pagamento.php para investigar e baixar manualmente.",
            'erro',
            ['payment_id' => $payment_id_alerta, 'external_reference' => $external_reference]
        );
    }

    mp_respond_ok();
}

$id_titulo = (int)($titulo['id'] ?? 0);
$uuid_titulo = $titulo['uuid_lanc'] ?? '';
$login = $titulo['login'] ?? '';
$celularbd = $titulo['celular'] ?? '';
$celularf = mp_clean_phone($celularbd);
$datavenc = $titulo['datavenc'] ?? date('Y-m-d');
$valor_titulo = (float)($titulo['valor'] ?? 0);
$numconta = $titulo['numconta'] ?? '';
$id_empresa = $titulo['id_empresa'] ?? '';
$chave_gnet2 = $titulo['chave_gnet2'] ?? '';
$chave_juno = $titulo['chave_juno'] ?? '';
$status_atual_titulo = $titulo['status'] ?? '';
$deltitulo = (string)($titulo['deltitulo'] ?? '0');

// Config Evotrix
$wa_config = mysqli_query($conn, "SELECT * FROM addon_msg_config WHERE chave IN ('evotrix_api_key','evotrix_channel','evotrix_base_url')");
$wa_settings = [];
if ($wa_config) {
    while ($row = mysqli_fetch_assoc($wa_config)) {
        $wa_settings[$row['chave']] = $row['valor'];
    }
}
$evotrix_api_key = $wa_settings['evotrix_api_key'] ?? '';
$evotrix_channel = $wa_settings['evotrix_channel'] ?? '';
$evotrix_base_url = $wa_settings['evotrix_base_url'] ?? 'https://api.evotrix.com.br/v1/services';

// Busca multa e juros
$numconta_safe = mysqli_real_escape_string($conn, (string)$numconta);
$multa_juros_sql = mysqli_query($conn, "SELECT * FROM sis_boleto WHERE id = '$numconta_safe' LIMIT 1");
$linha_multa_juros = $multa_juros_sql ? mysqli_fetch_assoc($multa_juros_sql) : [];
$multa = (float)($linha_multa_juros['multa'] ?? 0);
$juros = (float)($linha_multa_juros['juros'] ?? 0);

// Adicionais
$sql_consulta_adicional = mysqli_query($conn, "
    SELECT COALESCE(SUM(valor),0) as total_adicional
    FROM sis_mlanc
    WHERE idlanc = '$id_titulo' AND tipo != 'desconto'
");
$linha_adicional = $sql_consulta_adicional ? mysqli_fetch_assoc($sql_consulta_adicional) : [];
$valor_adicional = (float)($linha_adicional['total_adicional'] ?? 0);

// Descontos
$sql_consulta_desconto = mysqli_query($conn, "
    SELECT COALESCE(SUM(valor),0) as total_desconto
    FROM sis_mlanc
    WHERE idlanc = '$id_titulo' AND tipo = 'desconto'
");
$linha_desconto = $sql_consulta_desconto ? mysqli_fetch_assoc($sql_consulta_desconto) : [];
$valor_desconto = (float)($linha_desconto['total_desconto'] ?? 0);

$valor_base = $valor_titulo + $valor_adicional - $valor_desconto;

// atraso
$hoje = date("Y-m-d 00:00:00");
$data_inicio = new DateTime($datavenc);
$data_fim = new DateTime($hoje);
$dateInterval = $data_inicio->diff($data_fim);
$dias_atraso = ($hoje > $datavenc) ? (int)$dateInterval->days : 0;

// valores esperados
$valores = mp_expected_amounts($valor_base, $multa, $juros, $dias_atraso, $acrescimo, $installments);
$valor_esperado = $valores['valor_com_cartao'];
$juros_cartao = $valores['juros_cartao'];

mp_log(
    "Título: $id_titulo | UUID: $uuid_titulo | Login: $login | Base: {$valores['valor_base']} | " .
    "M/J: {$valores['multa_juros']} | Juros cartão: {$valores['juros_cartao']} | " .
    "Esperado: {$valores['valor_com_cartao']} | Recebido MP: {$transaction_amount}"
);

// Garantir tabela de auditoria
mp_ensure_audit_table($conn);

if ($status === 'approved') {
    // proteção contra processar o mesmo payment_id duas vezes
    if (mp_already_processed($conn, $payment_id, $id_titulo)) {
        mp_log("Webhook duplicado ignorado. payment_id=$payment_id titulo=$id_titulo");
        mp_respond_ok();
    }

    // proteção extra: se já está pago, não baixa de novo
    if ($status_atual_titulo === 'pago') {
        mp_log("Título já está pago. Ignorando nova baixa. Titulo=$id_titulo");
        mp_register_audit($conn, $payment_id, $id_titulo, $status, $transaction_amount, $installments, $retorno);
        mp_respond_ok();
    }

    // segurança: título deletado
    if ($deltitulo !== '0' && $deltitulo !== '' && $deltitulo !== null) {
        mp_log("Título marcado como deletado. Titulo=$id_titulo");
        mp_respond_ok();
    }

    // valida valor (com tolerancia melhorada que aceita acrescimo de cartao)
    if (!mp_values_match($valor_esperado, $transaction_amount, 0.10)) {
        // Calcula divergencia para log detalhado
        $diff_abs = abs((float)$valor_esperado - (float)$transaction_amount);
        $diff_pct = $valor_esperado > 0 ? (($diff_abs / $valor_esperado) * 100) : 0;
        mp_log("DIVERGENCIA DE VALOR REJEITADA. Esperado=$valor_esperado | Recebido=$transaction_amount | Diff=R\$$diff_abs (".round($diff_pct,1)."%) | Titulo=$id_titulo | UUID=$uuid_titulo");
        // registra auditoria, mas não baixa
        mp_register_audit($conn, $payment_id, $id_titulo, 'approved_valor_divergente', $transaction_amount, $installments, $retorno);

        // Alerta Telegram para o dono saber
        if (function_exists('rp_alertar_telegram')) {
            rp_alertar_telegram(
                "⚠ Pagamento APROVADO mas REJEITADO por divergencia de valor!\n".
                "Titulo: #$id_titulo (login: $login)\n".
                "Esperado: R$ ".number_format($valor_esperado,2,',','.')."\n".
                "Recebido: R$ ".number_format($transaction_amount,2,',','.')."\n".
                "Diferenca: R$ ".number_format($diff_abs,2,',','.')." (".round($diff_pct,1)."%)\n".
                "Payment ID: $payment_id\n\n".
                "Acao: dar baixa manual ou verificar configuracao de acrescimo.",
                'alerta', ['titulo' => $id_titulo, 'payment_id' => $payment_id]
            );
        }

        mp_respond_ok();
    }

    $data_pagamento = date("Y-m-d H:i:s");
    $id_titulo_safe = mysqli_real_escape_string($conn, (string)$id_titulo);
    $valor_pago_final = number_format((float)$transaction_amount, 2, '.', '');
    $juros_cartao_sql = number_format((float)$juros_cartao, 2, '.', '');

    mysqli_begin_transaction($conn);

    try {
        // cancela boleto no gateway de forma mais segura (evita duplicidade simples)
        $id_empresa_safe = mysqli_real_escape_string($conn, (string)$id_empresa);
        $chave_gnet2_safe = mysqli_real_escape_string($conn, (string)$chave_gnet2);
        $chave_juno_safe = mysqli_real_escape_string($conn, (string)$chave_juno);

        $checkDel = mysqli_query($conn, "
            SELECT id FROM sis_gnetdels
            WHERE titulo = '$id_empresa_safe'
              AND chave_gnet2 = '$chave_gnet2_safe'
              AND chave_juno = '$chave_juno_safe'
              AND remover = '1'
            LIMIT 1
        ");

        if (!$checkDel || mysqli_num_rows($checkDel) == 0) {
            mysqli_query($conn, "
                INSERT INTO sis_gnetdels (titulo, chave_gnet2, chave_juno, remover)
                VALUES('$id_empresa_safe', '$chave_gnet2_safe', '$chave_juno_safe', '1')
            ");
        }

        // atualiza fatura como paga
        mysqli_query($conn, "
            UPDATE sis_lanc
            SET
                status = 'pago',
                valorpag = '$valor_pago_final',
                coletor = 'API CARTAO',
                formapag = 'CARTAO DE CREDITO',
                datapag = '$data_pagamento'
            WHERE id = '$id_titulo_safe'
              AND status != 'pago'
              AND deltitulo = '0'
        ");

        if (mysqli_affected_rows($conn) <= 0) {
            throw new Exception("Falha ao atualizar sis_lanc para o título $id_titulo");
        }

        // registra entrada no caixa
        mysqli_query($conn, "
            INSERT INTO sis_caixa (uuid_caixa, usuario, data, historico, entrada, saida, tipomov, planodecontas)
            VALUES (
                NULL,
                'mkbot',
                '$data_pagamento',
                'pagamento do titulo com numero $id_titulo_safe via CARTAO DE CREDITO MP ($installments x)',
                '$valor_pago_final',
                '0.00',
                'aut',
                'Outros'
            )
        ");

        // tarifa do cartão apenas se houve juros de 3x
        if ((float)$juros_cartao > 0) {
            mysqli_query($conn, "
                INSERT INTO sis_caixa (uuid_caixa, usuario, data, historico, entrada, saida, tipomov, planodecontas)
                VALUES (
                    NULL,
                    'mkbot',
                    '$data_pagamento',
                    'tarifa do titulo com numero $id_titulo_safe via CARTAO DE CREDITO MP ($installments x)',
                    '0.00',
                    '$juros_cartao_sql',
                    'aut',
                    'Outros'
                )
            ");
        }

        mp_register_audit($conn, $payment_id, $id_titulo, $status, $transaction_amount, $installments, $retorno);

        mysqli_commit($conn);

        mp_log("Pagamento APROVADO e baixa realizada - Titulo: $id_titulo | UUID: $uuid_titulo | Login: $login | Valor: $valor_pago_final | Parc: $installments");

        if (!empty($celularf) && !empty($confirmacao_pagamento)) {
            $mensagem = str_replace(
                ['%valor%', '%parcelas%'],
                ['R$ ' . number_format($transaction_amount, 2, ',', '.'), $installments . 'x'],
                $confirmacao_pagamento
            );
            mp_send_evotrix($evotrix_base_url, $evotrix_api_key, $evotrix_channel, $celularf, $mensagem, 'mp-aprovado');
            mp_log("Mensagem Evotrix enviada para: 55$celularf");
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
        mp_log("ERRO na baixa do título: " . $e->getMessage());
        mp_respond_ok();
    }
}

if ($status == 'in_process') {
    $motivo = 'Estamos processando seu pagamento...';

    if ($status_detail == 'pending_contingency') {
        $motivo = 'Nao se preocupe, em menos de 2 dias uteis informaremos se o pagamento foi creditado.';
    } elseif ($status_detail == 'pending_review_manual') {
        $motivo = 'Nao se preocupe, em menos de 2 dias uteis informaremos se o pagamento foi creditado ou se necessitamos de mais informacao.';
    }

    $solicitacao_pagamento = str_replace("%detalhes%", $motivo, $solicitacao_pagamento);

    if (!empty($celularf) && !empty($solicitacao_pagamento)) {
        mp_send_evotrix($evotrix_base_url, $evotrix_api_key, $evotrix_channel, $celularf, $solicitacao_pagamento, 'mp-processando');
    }

    mp_log("Pagamento EM PROCESSO para titulo/ref: $external_reference");
}

if ($status == 'rejected') {
    switch ($status_detail) {
        case 'cc_rejected_bad_filled_card_number': $motivo = 'Revise o numero do cartao.'; break;
        case 'cc_rejected_bad_filled_date': $motivo = 'Revise a data de vencimento.'; break;
        case 'cc_rejected_bad_filled_other': $motivo = 'Revise os dados.'; break;
        case 'cc_rejected_bad_filled_security_code': $motivo = 'Revise o codigo de seguranca do cartao.'; break;
        case 'cc_rejected_blacklist': $motivo = 'Nao pudemos processar seu pagamento.'; break;
        case 'cc_rejected_call_for_authorize': $motivo = "Voce deve autorizar ao $payment_method o pagamento do valor ao Mercado Pago."; break;
        case 'cc_rejected_card_disabled': $motivo = "Ligue para o $payment_method para ativar seu cartao."; break;
        case 'cc_rejected_card_error': $motivo = 'Nao conseguimos processar seu pagamento.'; break;
        case 'cc_rejected_duplicated_payment': $motivo = 'Voce ja efetuou um pagamento com esse valor.'; break;
        case 'cc_rejected_high_risk': $motivo = 'Escolha outra forma de pagamento.'; break;
        case 'cc_rejected_insufficient_amount': $motivo = "O $payment_method possui saldo insuficiente."; break;
        case 'cc_rejected_invalid_installments': $motivo = "O $payment_method nao processa pagamentos em $installments parcelas."; break;
        case 'cc_rejected_max_attempts': $motivo = "Voce atingiu o limite de tentativas permitido."; break;
        case 'cc_rejected_other_reason': $motivo = "$payment_method nao processa o pagamento."; break;
        case 'cc_rejected_card_type_not_allowed': $motivo = 'Funcao credito nao habilitada no cartao.'; break;
        default: $motivo = 'Motivo Desconhecido, tente outra forma de Pagamento';
    }

    $pagamento_recusado = str_replace("%motivo_recusa%", $motivo, $pagamento_recusado);

    if (!empty($celularf) && !empty($pagamento_recusado)) {
        mp_send_evotrix($evotrix_base_url, $evotrix_api_key, $evotrix_channel, $celularf, $pagamento_recusado, 'mp-recusado');
    }

    mp_log("Pagamento REJEITADO para titulo/ref: $external_reference - Motivo: $motivo");
}

mp_log("Processamento concluido");
mysqli_close($conn);
mp_respond_ok();
