<?php
/**
 * ================================================================
 * Cron Autopay - Cobranca Automatica
 * ================================================================
 *
 * Roda DIARIAMENTE de manha. Cobra clientes com autopay ativo
 * cuja proxima_cobranca eh HOJE.
 *
 * Logica:
 *   1. Pega clientes ativos com proxima_cobranca <= hoje
 *   2. Acha a proxima fatura aberta deles
 *   3. Tenta cobrar via MP
 *   4. Sucesso → webhook baixa fatura, mensagem WhatsApp
 *   5. Falha → conta tentativa, agenda nova pra amanha
 *   6. 3 falhas → desativa, notifica WhatsApp + Telegram
 *
 * Uso (cron):
 *   0 9 * * * /usr/bin/php /opt/mk-auth/admin/addons/cartao_credito/cron_autopay.php
 *
 * Uso manual (web):
 *   ?cobrar=1       roda de verdade
 *   ?dry=1          dry-run (so mostra o que faria)
 * ================================================================
 */

require_once __DIR__ . '/rp_config.php';
require_once __DIR__ . '/financeiro_core.php';

$cli = (php_sapi_name() === 'cli');
$dry = !empty($_GET['dry']);
$cobrar_now = $cli || !empty($_GET['cobrar']);

if (!$cli) {
    if (function_exists('rp_require_mkauth_session')) rp_require_mkauth_session();
}

$conn = rp_db_connect();
if (!$conn || $conn->connect_errno) die('Sem conexao bd');
$conn->set_charset('utf8mb4');

// Carrega helpers
require_once __DIR__ . '/autopay_helper.php';
if (is_file(__DIR__ . '/rp_wa_sender.php')) require_once __DIR__ . '/rp_wa_sender.php';

// Garante tabela
@$conn->query("CREATE TABLE IF NOT EXISTS addon_autopay_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    autopay_id INT UNSIGNED NOT NULL,
    login VARCHAR(80) NOT NULL,
    fatura_id INT UNSIGNED DEFAULT NULL,
    payment_id VARCHAR(50) DEFAULT NULL,
    valor DECIMAL(10,2) DEFAULT 0,
    status VARCHAR(40) NOT NULL,
    erro_detail VARCHAR(255) DEFAULT NULL,
    tentativa_num TINYINT UNSIGNED NOT NULL DEFAULT 1,
    response_full TEXT DEFAULT NULL,
    tentativa_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_autopay (autopay_id, tentativa_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$hoje = date('Y-m-d');
$agora = date('Y-m-d H:i:s');

// Pega clientes ativos cuja proxima cobranca eh hoje (ou atrasada)
$r = $conn->query("SELECT * FROM addon_autopay_clientes
    WHERE status='ativo'
      AND proxima_cobranca IS NOT NULL
      AND proxima_cobranca <= '$hoje'
    ORDER BY proxima_cobranca ASC LIMIT 100");

$resultado = ['processados' => 0, 'aprovados' => 0, 'rejeitados' => 0, 'expirados' => 0, 'falhados_max' => 0, 'detalhes' => []];

if (!$r || $r->num_rows === 0) {
    echo "Nenhum autopay para processar hoje ($hoje).\n";
    exit;
}

while ($ap = $r->fetch_assoc()) {
    $resultado['processados']++;
    $id_ap = (int)$ap['id'];
    $login = $ap['login'];
    $detalhe = ['login' => $login, 'id' => $id_ap];

    // 1. Verifica se atingiu limite de meses
    if ($ap['meses_total'] > 0 && $ap['meses_executados'] >= $ap['meses_total']) {
        if (!$dry) {
            $conn->query("UPDATE addon_autopay_clientes SET status='expirado', cancelado_em='$agora', cancelado_motivo='Atingiu limite de meses', cancelado_por='sistema' WHERE id=$id_ap");
        }
        $resultado['expirados']++;
        $detalhe['acao'] = 'expirado (atingiu limite de meses)';
        $resultado['detalhes'][] = $detalhe;

        // Notifica cliente
        if (function_exists('rp_wa_enviar') && !$dry) {
            $url = autopay_url_gerenciar($ap['token_gerencia']);
            $msg = "Olá! O pagamento automático no cartão final {$ap['cartao_final']} terminou após {$ap['meses_total']} meses. Para continuar pagando suas faturas, faça um novo pagamento via cartão. {$url}";
            $lse = $conn->real_escape_string($login); $rcx = $conn->query("SELECT celular, fone FROM sis_cliente WHERE login='$lse' LIMIT 1");
            $rcy = $rcx ? $rcx->fetch_assoc() : []; $celx = trim((string)(($rcy['celular'] ?? '') ?: ($rcy['fone'] ?? '')));
            if ($celx) rp_wa_enviar(['conn' => $conn, 'numero' => $celx, 'mensagem' => $msg, 'provedor' => 'auto']);
        }
        continue;
    }

    // 2. Acha proxima fatura aberta
    $ls = $conn->real_escape_string($login);
    $r2 = $conn->query("SELECT id, valor, datavenc, uuid_lanc FROM sis_lanc
        WHERE login='$ls' AND deltitulo=0 AND status != 'pago'
        ORDER BY datavenc ASC LIMIT 1");
    if (!$r2 || $r2->num_rows === 0) {
        // Sem fatura - adia 30 dias
        $nova_data = date('Y-m-d', strtotime('+30 days'));
        if (!$dry) {
            $conn->query("UPDATE addon_autopay_clientes SET proxima_cobranca='$nova_data', ultimo_status='sem_fatura' WHERE id=$id_ap");
        }
        $detalhe['acao'] = 'sem fatura aberta - adiado +30d';
        $resultado['detalhes'][] = $detalhe;
        continue;
    }
    $fatura = $r2->fetch_assoc();
    $detalhe['fatura'] = $fatura['id'];

    // 02/10/2026: trava anti-cobrança dupla — se esta fatura já teve cobrança APROVADA no cartão
    // (ex.: o webhook não deu baixa), não cobra de novo; avisa no Telegram para baixar à mão.
    $rdup = $conn->query("SELECT payment_id FROM addon_autopay_log WHERE fatura_id=" . (int)$fatura['id'] . " AND status IN ('aprovado','pendente') LIMIT 1");
    if ($rdup && $rdup->num_rows > 0) {
        $pid = $rdup->fetch_assoc()['payment_id'];
        $detalhe['acao'] = "fatura #{$fatura['id']} JÁ cobrada no cartão (payment $pid) mas segue aberta — NÃO cobrei de novo";
        if (!$dry) {
            $conn->query("UPDATE addon_autopay_clientes SET proxima_cobranca='" . date('Y-m-d', strtotime('+1 day')) . "', ultimo_status='aguardando_baixa' WHERE id=$id_ap");
            if (function_exists('rp_alertar_telegram')) rp_alertar_telegram("⚠ Autopay: fatura #{$fatura['id']} ($login) já foi paga no cartão (MP $pid) mas continua em aberto no MK-Auth. Dê baixa manual — o robô não vai cobrar de novo.", 'erro', ['login' => $login]);
        }
        $resultado['detalhes'][] = $detalhe;
        continue;
    }
    $detalhe['valor_base'] = $fatura['valor'];

    // 3. Calcula valor com acrescimo
    $r3 = $conn->query("SELECT acrescimo FROM integrar_cartao_mp LIMIT 1");
    $acres_pct = $r3 ? floatval($r3->fetch_assoc()['acrescimo'] ?? 0) : 0;
    $valor_cobrar = round(floatval($fatura['valor']) * (1 + $acres_pct / 100), 2);

    // 4. Cobra
    $tentativa_num = $ap['falhas_consecutivas'] + 1;
    $detalhe['tentativa'] = $tentativa_num;
    $detalhe['valor_cobrar'] = $valor_cobrar;

    if ($dry) {
        $detalhe['acao'] = "DRY: cobraria R$ $valor_cobrar do cartao final {$ap['cartao_final']}";
        $resultado['detalhes'][] = $detalhe;
        continue;
    }

    $ext_ref = $fatura['uuid_lanc'] ?: strval($fatura['id']);
    $cobra = autopay_cobrar_cartao(
        $conn,
        $ap['mp_customer_id'],
        $ap['mp_card_id'],
        $valor_cobrar,
        $ext_ref,
        "Autopay {$ap['login']} - fatura #{$fatura['id']}"
    );

    // 5. Log da tentativa
    $status_log = $cobra['ok'] && ($cobra['status'] ?? '') === 'approved' ? 'aprovado'
                 : (($cobra['ok'] && in_array($cobra['status'] ?? '', ['pending','in_process'])) ? 'pendente'
                 : 'rejeitado');
    $payment_id = $cobra['payment_id'] ?? '';
    $erro_detail = substr($cobra['erro'] ?? ($cobra['data']['status_detail'] ?? ''), 0, 255);
    $response_full = substr(json_encode($cobra), 0, 65000);

    $stmt = $conn->prepare("INSERT INTO addon_autopay_log
        (autopay_id, login, fatura_id, payment_id, valor, status, erro_detail, tentativa_num, response_full)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $fat_id_int = (int)$fatura['id'];
    $stmt->bind_param('isisdssis',   // 02/10/2026: antes o status ia como número (gravava 0)
        $id_ap, $login, $fat_id_int, $payment_id, $valor_cobrar,
        $status_log, $erro_detail, $tentativa_num, $response_full);
    $stmt->execute();
    $stmt->close();

    if ($status_log === 'aprovado') {
        // SUCESSO! Atualiza autopay e agenda proxima cobranca
        $resultado['aprovados']++;

        // Proxima cobranca = 5 dias antes da PROXIMA fatura aberta
        $r3 = $conn->query("SELECT datavenc FROM sis_lanc
            WHERE login='$ls' AND deltitulo=0 AND status != 'pago'
              AND id != " . (int)$fatura['id'] . "
            ORDER BY datavenc ASC LIMIT 1");
        $prox_data = $r3 && $r3->num_rows > 0
            ? date('Y-m-d', strtotime($r3->fetch_assoc()['datavenc'] . ' -5 days'))
            : date('Y-m-d', strtotime('+25 days'));

        $conn->query("UPDATE addon_autopay_clientes
            SET meses_executados = meses_executados + 1,
                proxima_cobranca = '$prox_data',
                falhas_consecutivas = 0,
                ultima_tentativa = '$agora',
                ultimo_status = 'aprovado'
            WHERE id=$id_ap");

        $detalhe['acao'] = "✓ APROVADO. payment=$payment_id, proxima=$prox_data";

        // Notifica cliente via WhatsApp
        if (function_exists('rp_wa_enviar')) {
            $rcli = $conn->query("SELECT celular, fone FROM sis_cliente WHERE login='$ls' LIMIT 1");
            $rc = $rcli ? $rcli->fetch_assoc() : []; $cel = trim((string)(($rc['celular'] ?? '') ?: ($rc['fone'] ?? '')));
            if ($cel) {
                $url = autopay_url_gerenciar($ap['token_gerencia']);
                $msg = "✅ *Pagamento automatico realizado!*\n\nFatura #" . $fatura['id'] . " quitada\nValor: R$ " . number_format($valor_cobrar, 2, ',', '.') . "\nCartao: **** " . $ap['cartao_final'] . "\n\nVer detalhes ou cancelar:\n$url\n\nObrigado!";
                rp_wa_enviar(['conn' => $conn, 'numero' => $cel, 'mensagem' => $msg, 'provedor' => 'auto']);
            }
        }

    } else {
        // FALHA - conta tentativa
        $novas_falhas = $tentativa_num;
        if ($novas_falhas >= 3) {
            // 3 falhas - desativa
            $conn->query("UPDATE addon_autopay_clientes
                SET status='falha_max', falhas_consecutivas=$novas_falhas,
                    ultima_tentativa='$agora', ultimo_status='$status_log',
                    cancelado_em='$agora', cancelado_motivo='3 tentativas consecutivas falharam',
                    cancelado_por='sistema'
                WHERE id=$id_ap");
            $resultado['falhados_max']++;
            $detalhe['acao'] = "✗ DESATIVADO apos 3 falhas. Ultimo erro: $erro_detail";

            // Notifica cliente + telegram do dono
            if (function_exists('rp_wa_enviar')) {
                $rcli = $conn->query("SELECT celular, fone FROM sis_cliente WHERE login='$ls' LIMIT 1");
                $rc = $rcli ? $rcli->fetch_assoc() : []; $cel = trim((string)(($rc['celular'] ?? '') ?: ($rc['fone'] ?? '')));
                if ($cel) {
                    $url = autopay_url_gerenciar($ap['token_gerencia']);
                    $msg = "⚠ *Pagamento automatico DESATIVADO*\n\nTentamos cobrar 3 vezes mas nao conseguimos. Motivo: $erro_detail\n\nFatura em aberto: #" . $fatura['id'] . " - R$ " . number_format($valor_cobrar, 2, ',', '.') . "\n\nPor favor, pague manualmente ou cadastre um novo cartao. Acesse:\n$url";
                    rp_wa_enviar(['conn' => $conn, 'numero' => $cel, 'mensagem' => $msg, 'provedor' => 'auto']);
                }
            }
            if (function_exists('rp_alertar_telegram')) {
                rp_alertar_telegram(
                    "❌ Autopay DESATIVADO apos 3 falhas - login: $login\nFatura: #{$fatura['id']} R$ $valor_cobrar\nErro: $erro_detail",
                    'erro', ['login' => $login]
                );
            }
        } else {
            // Tenta amanha
            $proxima = date('Y-m-d', strtotime('+1 day'));
            $conn->query("UPDATE addon_autopay_clientes
                SET falhas_consecutivas=$novas_falhas,
                    proxima_cobranca='$proxima',
                    ultima_tentativa='$agora',
                    ultimo_status='$status_log'
                WHERE id=$id_ap");
            $resultado['rejeitados']++;
            $detalhe['acao'] = "✗ FALHA $novas_falhas/3. Nova tentativa em $proxima. Erro: $erro_detail";
        }
    }

    $resultado['detalhes'][] = $detalhe;
}

// === RESUMO ===
$resumo = sprintf(
    "[%s] AUTOPAY - Processados=%d Aprovados=%d Rejeitados=%d Expirados=%d Desativados=%d",
    $agora, $resultado['processados'], $resultado['aprovados'],
    $resultado['rejeitados'], $resultado['expirados'], $resultado['falhados_max']
);
@file_put_contents(__DIR__ . '/logs/autopay.log', $resumo . "\n", FILE_APPEND);

if ($cli) {
    echo $resumo . "\n";
    foreach ($resultado['detalhes'] as $d) {
        echo "  - " . ($d['login'] ?? '?') . ": " . ($d['acao'] ?? '?') . "\n";
    }
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Resultado Autopay</h2><pre>' . htmlspecialchars(print_r($resultado, true)) . '</pre>';
}
