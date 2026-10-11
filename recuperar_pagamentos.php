<?php
/**
 * ================================================================
 * Recuperar Pagamentos Orfaos do Mercado Pago
 * ================================================================
 *
 * Funciona em 2 modos:
 *  - STANDALONE: acesso direto -> mostra HTML completo
 *  - FRAGMENT: via index.php?tab=recuperar -> so o conteudo
 *
 * Acesso: /admin/addons/Resumo_Pagamentos/?tab=recuperar
 *         /admin/addons/Resumo_Pagamentos/recuperar_pagamentos.php
 * ================================================================
 */

// Detecta se foi incluido como fragmento pelo index.php
$RP_AS_FRAGMENT = defined('RP_TEMA_NOVO_FRAME') || !empty($GLOBALS['rp_in_index']);

// ============================================================
// AJAX: estas acoes sempre chegam DIRETO neste arquivo (POST).
// Precisam carregar deps e responder JSON (nunca HTML do tema).
// ============================================================
$RP_IS_AJAX = isset($_POST['action']) && in_array($_POST['action'], ['dar_baixa','buscar_fatura','baixa_por_id','buscar_cliente'], true);

if ($RP_IS_AJAX) {
    // Carrega dependencias se ainda nao carregadas (acesso direto)
    if (!function_exists('rp_db_connect')) {
        require_once __DIR__ . '/rp_config.php';
        require_once __DIR__ . '/financeiro_core.php';
    }
    // Valida sessao retornando JSON (sem redirect/HTML)
    $temSessao = false;
    foreach ($_COOKIE as $ck => $cv) {
        if (preg_match('/^_admin-.+-MKA$/i', $ck) && !empty($cv)) { $temSessao = true; break; }
    }
    if (!$temSessao) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'erro' => 'Sessao expirada. Recarregue a pagina e faca login.']);
        exit;
    }
    // Limpa qualquer buffer pra garantir JSON limpo
    while (ob_get_level() > 0) { @ob_end_clean(); }
}

// URL base para formularios/links: se estiver embarcado em config>cartao, mantem
$RP_BASE_URL = $RP_AS_FRAGMENT
    ? ((($_GET['tab'] ?? '') === 'config' || ($_GET['cfg_tab'] ?? '') === 'cartao')
        ? '?tab=config&cfg_tab=cartao&mp_sub=recuperar'
        : '?tab=recuperar')
    : '?';

if (!$RP_AS_FRAGMENT && !$RP_IS_AJAX) {
    require_once __DIR__ . '/rp_config.php';
    require_once __DIR__ . '/financeiro_core.php';
    rp_require_mkauth_session();
}

// ============== CONEXAO ==============
$conn = @$GLOBALS['LOADMYSQL'] ?? null;
if (!$conn || (method_exists($conn, 'ping') && !@$conn->ping())) {
    $conn = rp_db_connect();
    if ($conn->connect_errno) { die('Erro de conexao com o banco.'); }
    $conn->set_charset('utf8mb4');
}

// ============== ACCESS TOKEN DO MP ==============
$access_token = '';
$rmp = $conn->query("SELECT access_token FROM integrar_cartao_mp LIMIT 1");
if ($rmp) {
    $row = $rmp->fetch_assoc();
    $access_token = $row['access_token'] ?? '';
}

// ============== AJAX: dar baixa ==============
if (isset($_POST['action']) && $_POST['action'] === 'dar_baixa') {
    // Protecao CSRF
    if (function_exists('rp_csrf_check')) rp_csrf_check();
    header('Content-Type: application/json; charset=utf-8');

    $fatura_id  = intval($_POST['fatura_id'] ?? 0);
    $payment_id = trim($_POST['payment_id'] ?? '');
    $valor_pago = floatval($_POST['valor_pago'] ?? 0);
    $data_pag   = trim($_POST['data_pag'] ?? '');

    if (!$fatura_id || !$payment_id || $valor_pago <= 0) {
        echo json_encode(['ok' => false, 'erro' => 'Dados invalidos']);
        exit;
    }

    // Verifica que a fatura existe e ainda esta em aberto
    $rcheck = $conn->query("SELECT id, login, status FROM sis_lanc WHERE id=$fatura_id LIMIT 1");
    $fat = $rcheck ? $rcheck->fetch_assoc() : null;
    if (!$fat) {
        echo json_encode(['ok' => false, 'erro' => 'Fatura nao encontrada']);
        exit;
    }
    if ($fat['status'] === 'pago') {
        echo json_encode(['ok' => false, 'erro' => 'Fatura ja esta paga']);
        exit;
    }

    // Validar formato de data
    if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $data_pag)) {
        $data_pag = date('Y-m-d H:i:s');
    } elseif (strlen($data_pag) === 10) {
        $data_pag .= ' 12:00:00';
    }

    $valor_safe = number_format($valor_pago, 2, '.', '');
    $data_safe  = $conn->real_escape_string($data_pag);
    $pid_safe   = $conn->real_escape_string($payment_id);
    $login_safe = $conn->real_escape_string($fat['login']);

    // Update da fatura
    $sql_update = "UPDATE sis_lanc SET
        status = 'pago',
        valorpag = '$valor_safe',
        datapag = '$data_safe',
        coletor = 'API CARTAO',
        formapag = 'CARTAO DE CREDITO (recuperacao MP)'
        WHERE id = $fatura_id AND status != 'pago'";
    $ok1 = $conn->query($sql_update);

    // Registra no caixa
    $sql_caixa = "INSERT INTO sis_caixa (usuario, data, historico, entrada, tipomov, planodecontas)
        VALUES('mkbot', '$data_safe',
        'pagamento RECUPERADO do titulo $fatura_id (MP payment_id $pid_safe) via CARTAO',
        '$valor_safe', 'aut', 'Outros')";
    $ok2 = $conn->query($sql_caixa);

    // Loga
    $logs_dir = __DIR__ . '/logs';
    @mkdir($logs_dir, 0775, true);
    $log_msg = sprintf("[%s] Recuperacao manual: fatura %d (%s) | valor R$ %s | MP payment %s | usuario %s\n",
        date('Y-m-d H:i:s'), $fatura_id, $fat['login'], $valor_safe, $pid_safe,
        $_COOKIE['_login-MKA'] ?? 'admin');
    @file_put_contents($logs_dir . '/recuperacao_pagamentos.log', $log_msg, FILE_APPEND);

    echo json_encode([
        'ok' => $ok1 && $ok2,
        'mensagem' => $ok1 ? 'Baixa registrada com sucesso!' : 'Erro ao atualizar fatura',
    ]);
    exit;
}

// ============== AJAX: buscar fatura por ID (mostra cliente/valor antes de baixar) ==============
if (isset($_POST['action']) && $_POST['action'] === 'buscar_fatura') {
    if (function_exists('rp_csrf_check')) rp_csrf_check();
    header('Content-Type: application/json; charset=utf-8');

    $fid = intval($_POST['fatura_id'] ?? 0);
    if ($fid <= 0) {
        echo json_encode(['ok' => false, 'erro' => 'Informe um ID de fatura valido']);
        exit;
    }

    // IMPORTANTE: c.* primeiro, l.* depois + alias do id, para o id da FATURA
    // nao ser sobrescrito pelo id do cliente (sis_cliente tem coluna id).
    $sql = "SELECT c.*, l.*,
                   l.id AS id, l.login AS login, l.valor AS valor,
                   l.valorpag AS valorpag, l.uuid_lanc AS uuid_lanc,
                   l.status AS status, l.datavenc AS datavenc,
                   l.datapag AS datapag, l.formapag AS formapag,
                   c.nome AS cliente_nome, c.celular AS cliente_celular
            FROM sis_lanc l
            LEFT JOIN sis_cliente c ON l.login = c.login
            WHERE l.id = $fid LIMIT 1";
    $r = $conn->query($sql);
    if (!$r || $r->num_rows === 0) {
        echo json_encode(['ok' => false, 'erro' => "Fatura #$fid nao encontrada"]);
        exit;
    }
    $f = $r->fetch_assoc();

    // Acrescimo de cartao (pra sugerir o valor com taxa)
    $acrescimo = 0;
    $ra = $conn->query("SELECT acrescimo FROM integrar_cartao_mp LIMIT 1");
    if ($ra && $ra->num_rows > 0) $acrescimo = floatval($ra->fetch_assoc()['acrescimo'] ?? 0);
    $valor_base = floatval($f['valor']);
    $valor_com_taxa = round($valor_base * (1 + $acrescimo/100), 2);

    echo json_encode([
        'ok' => true,
        'fatura' => [
            'id' => intval($f['id']),
            'login' => $f['login'],
            'nome' => $f['cliente_nome'] ?? $f['nome'] ?? '(sem nome)',
            'celular' => $f['cliente_celular'] ?? '',
            'valor' => $valor_base,
            'valor_com_taxa' => $valor_com_taxa,
            'acrescimo' => $acrescimo,
            'status' => $f['status'],
            'datavenc' => $f['datavenc'],
            'datapag' => $f['datapag'],
            'formapag' => $f['formapag'],
            'uuid_lanc' => $f['uuid_lanc'],
            'ja_pago' => ($f['status'] === 'pago'),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============== AJAX: baixa direta por ID da fatura (forma CARTAO) ==============
if (isset($_POST['action']) && $_POST['action'] === 'baixa_por_id') {
    if (function_exists('rp_csrf_check')) rp_csrf_check();
    header('Content-Type: application/json; charset=utf-8');

    $fid = intval($_POST['fatura_id'] ?? 0);
    $valor_pago = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor_pago'] ?? '0')));
    // tolera tanto "100,00" quanto "100.00"
    if ($valor_pago <= 0) $valor_pago = floatval(str_replace(',', '.', $_POST['valor_pago'] ?? '0'));

    if ($fid <= 0 || $valor_pago <= 0) {
        echo json_encode(['ok' => false, 'erro' => 'ID ou valor invalido']);
        exit;
    }

    // Confere fatura (com alias seguro do id)
    $r = $conn->query("SELECT l.id AS id, l.login AS login, l.status AS status, l.valor AS valor
                       FROM sis_lanc l WHERE l.id = $fid LIMIT 1");
    $f = $r ? $r->fetch_assoc() : null;
    if (!$f) { echo json_encode(['ok' => false, 'erro' => "Fatura #$fid nao existe"]); exit; }
    if ($f['status'] === 'pago') { echo json_encode(['ok' => false, 'erro' => "Fatura #$fid JA esta paga"]); exit; }

    $dataPag = date('Y-m-d H:i:s');
    $valor_safe = number_format($valor_pago, 2, '.', '');
    $login_safe = $conn->real_escape_string($f['login']);

    $ok1 = $conn->query("UPDATE sis_lanc SET
        status='pago', valorpag='$valor_safe', datapag='$dataPag',
        coletor='API CARTAO', formapag='CARTAO DE CREDITO'
        WHERE id=$fid AND status != 'pago'");

    $hist = "pagamento do titulo $fid via CARTAO (baixa manual por ID)";
    $hist_safe = $conn->real_escape_string($hist);
    $ok2 = $conn->query("INSERT INTO sis_caixa (usuario, data, historico, entrada, tipomov, planodecontas)
        VALUES('mkbot', '$dataPag', '$hist_safe', '$valor_safe', 'aut', 'Outros')");

    // Log
    $logs_dir = __DIR__ . '/logs';
    @mkdir($logs_dir, 0775, true);
    @file_put_contents($logs_dir . '/recuperacao_pagamentos.log',
        sprintf("[%s] Baixa manual por ID: fatura %d (%s) valor R$ %s usuario %s\n",
            date('Y-m-d H:i:s'), $fid, $f['login'], $valor_safe, $_COOKIE['_login-MKA'] ?? 'admin'),
        FILE_APPEND);

    echo json_encode([
        'ok' => ($ok1 && $ok2),
        'mensagem' => $ok1 ? "Fatura #$fid baixada com sucesso!" : 'Erro ao atualizar fatura',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============== AJAX: buscar cliente (email/nome/login/cpf) + faturas em aberto ==============
if (isset($_POST['action']) && $_POST['action'] === 'buscar_cliente') {
    if (function_exists('rp_csrf_check')) rp_csrf_check();
    header('Content-Type: application/json; charset=utf-8');

    $termo = trim($_POST['termo'] ?? '');
    if (mb_strlen($termo) < 3) {
        echo json_encode(['ok' => false, 'erro' => 'Digite ao menos 3 caracteres']);
        exit;
    }

    $t = $conn->real_escape_string($termo);
    // CPF: tira pontuacao pra casar tanto "014.762.791-52" quanto "01476279152"
    $tNum = preg_replace('/\D/', '', $termo);
    $tNumSafe = $conn->real_escape_string($tNum);

    // Busca clientes que batem em email, nome, login ou cpf
    $sqlCpf = ($tNum !== '') ? " OR REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),'/','') LIKE '%$tNumSafe%'" : '';
    $sqlCli = "SELECT login, nome, email, cpf, celular, fone
               FROM sis_cliente
               WHERE LOWER(email) LIKE LOWER('%$t%')
                  OR LOWER(nome) LIKE LOWER('%$t%')
                  OR LOWER(login) LIKE LOWER('%$t%')
                  $sqlCpf
               ORDER BY nome ASC
               LIMIT 20";
    $rc = $conn->query($sqlCli);

    if (!$rc || $rc->num_rows === 0) {
        echo json_encode(['ok' => true, 'clientes' => [], 'total' => 0]);
        exit;
    }

    // Acrescimo cartao
    $acrescimo = 0;
    $ra = $conn->query("SELECT acrescimo FROM integrar_cartao_mp LIMIT 1");
    if ($ra && $ra->num_rows > 0) $acrescimo = floatval($ra->fetch_assoc()['acrescimo'] ?? 0);

    $clientes = [];
    while ($cli = $rc->fetch_assoc()) {
        $loginSafe = $conn->real_escape_string($cli['login']);
        // Faturas EM ABERTO desse cliente (alias seguro no id!)
        $rf = $conn->query("SELECT l.id AS id, l.valor AS valor, l.datavenc AS datavenc,
                                   l.status AS status, l.uuid_lanc AS uuid_lanc
                            FROM sis_lanc l
                            WHERE l.login = '$loginSafe'
                              AND l.status != 'pago'
                              AND l.deltitulo = 0
                            ORDER BY l.datavenc ASC
                            LIMIT 30");
        $faturas = [];
        if ($rf) while ($f = $rf->fetch_assoc()) {
            $vbase = floatval($f['valor']);
            $faturas[] = [
                'id' => intval($f['id']),
                'valor' => $vbase,
                'valor_com_taxa' => round($vbase * (1 + $acrescimo/100), 2),
                'datavenc' => $f['datavenc'],
                'status' => $f['status'],
            ];
        }
        $clientes[] = [
            'login' => $cli['login'],
            'nome' => $cli['nome'] ?: '(sem nome)',
            'email' => $cli['email'] ?: '',
            'cpf' => $cli['cpf'] ?: '',
            'celular' => $cli['celular'] ?: ($cli['fone'] ?: ''),
            'faturas' => $faturas,
            'qtd_abertas' => count($faturas),
        ];
    }

    echo json_encode([
        'ok' => true,
        'total' => count($clientes),
        'acrescimo' => $acrescimo,
        'clientes' => $clientes,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$payment_id_busca = trim($_GET['payment_id'] ?? '');
$resultado_busca = [];
$erro_busca = '';
$total_aprovados = 0;
$total_orfaos = 0;
$buscou = isset($_GET['buscar']) || $payment_id_busca !== '';

if ($buscou) {
    if (empty($access_token)) {
        $erro_busca = 'Access Token do Mercado Pago nao configurado em integrar_cartao_mp.access_token';
    } else {
        // Modo 1: busca por payment_id especifico
        if ($payment_id_busca !== '') {
            $url = 'https://api.mercadopago.com/v1/payments/' . urlencode($payment_id_busca);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token],
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http === 200) {
                $pay = json_decode($resp, true);
                if (is_array($pay)) $resultado_busca[] = $pay;
            } else {
                $erro_busca = "Pagamento $payment_id_busca nao encontrado (HTTP $http)";
            }
        } else {
            // Modo 2: busca pelos ultimos N dias
            $de = date('Y-m-d\TH:i:s.000P', strtotime("-$dias days"));
            $offset = 0;
            $limit = 50;
            $loop = 0;
            do {
                $loop++;
                $params = http_build_query([
                    'status' => 'approved',
                    'range' => 'date_approved',
                    'begin_date' => $de,
                    'end_date' => date('Y-m-d\TH:i:s.000P'),
                    'limit' => $limit,
                    'offset' => $offset,
                    'sort' => 'date_approved',
                    'criteria' => 'desc',
                ]);
                $url = 'https://api.mercadopago.com/v1/payments/search?' . $params;
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token],
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $resp = curl_exec($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($http !== 200) {
                    $erro_busca = "Erro ao consultar MP (HTTP $http)";
                    break;
                }
                $data = json_decode($resp, true);
                if (!is_array($data) || !isset($data['results'])) break;

                foreach ($data['results'] as $p) {
                    $resultado_busca[] = $p;
                }
                $total_results = $data['paging']['total'] ?? count($data['results']);
                $offset += $limit;
                if ($offset >= $total_results) break;
                if ($loop >= 6) break; // max 300 resultados
            } while (true);
        }

        $total_aprovados = count($resultado_busca);

        // Filtra so os que NAO baixaram no MK-AUTH (orfaos) - mas guarda TODOS para exibicao
        $orfaos = [];
        $todos_com_fatura = [];
        foreach ($resultado_busca as $p) {
            $ext_ref = $p['external_reference'] ?? '';
            if (!$ext_ref) {
                // Sem external_reference: cobranca avulsa (nao vinculada a fatura MK-AUTH)
                $todos_com_fatura[] = [
                    'fatura' => null,
                    'cliente_nome' => '(sem vinculo)',
                    'mp' => $p,
                    'orfao' => false,
                    'avulso' => true,
                ];
                continue;
            }

            // external_reference do MK-AUTH e o id da fatura
            $fatura_id_ref = intval($ext_ref);
            if (!$fatura_id_ref) continue;

            $rfat = $conn->query("SELECT id, login, valor, valorpag, status, datavenc, datapag, formapag
                FROM sis_lanc WHERE id = $fatura_id_ref LIMIT 1");
            if (!$rfat || $rfat->num_rows === 0) {
                $todos_com_fatura[] = [
                    'fatura' => ['id' => $fatura_id_ref, 'login' => '?', 'valor' => 0, 'status' => 'inexistente'],
                    'cliente_nome' => '(fatura nao encontrada)',
                    'mp' => $p,
                    'orfao' => false,
                    'avulso' => false,
                    'inexistente' => true,
                ];
                continue;
            }
            $fat = $rfat->fetch_assoc();

            $login_safe = $conn->real_escape_string($fat['login']);
            $rcli = $conn->query("SELECT nome FROM sis_cliente WHERE login = '$login_safe' LIMIT 1");
            $cliente_nome = $rcli && $rcli->num_rows > 0 ? $rcli->fetch_assoc()['nome'] : $fat['login'];

            $eh_orfao = ($fat['status'] !== 'pago');
            $item = [
                'fatura' => $fat,
                'cliente_nome' => $cliente_nome,
                'mp' => $p,
                'orfao' => $eh_orfao,
                'avulso' => false,
            ];
            $todos_com_fatura[] = $item;
            if ($eh_orfao) $orfaos[] = $item;
        }
        $total_orfaos = count($orfaos);

        // Filtro: por padrao mostra so orfaos, mas pode mostrar todos
        $mostrar_todos = !empty($_GET['mostrar_todos']);
        $resultado_busca = $mostrar_todos ? $todos_com_fatura : $orfaos;
    }
}

?>
<?php if (!$RP_AS_FRAGMENT): ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recuperar Pagamentos - Cartão de Crédito</title>
<link rel="stylesheet" href="assets/ds/ds.css?v=20260922">
<script src="assets/vendor/lucide.min.js"></script>
</head>
<body>
<div class="tn-scope">
<?php endif; ?>

<link rel="stylesheet" href="assets/ds/pages/recuperar.css?v=20260922">

<div class="ds-page-head">
    <div>
        <div class="ds-page-title"><i data-lucide="rotate-ccw"></i> Recuperar Pagamentos do Mercado Pago</div>
        <div class="ds-page-desc">Encontra pagamentos aprovados no MP que ainda não deram baixa no MK-AUTH.</div>
    </div>
</div>

<div class="ds-alert">
    <i data-lucide="info"></i>
    <div class="ds-alert-body">
        <span class="ds-alert-title">Como funciona</span>
        Esta tela busca todos os pagamentos <strong>aprovados</strong> no MP nos últimos N dias
        e cruza com as faturas do MK-AUTH. Se o cliente pagou no cartão mas a fatura ficou aberta
        (ex.: webhook do MP rejeitado por falta de secret), o pagamento aparece aqui para você dar baixa manual.
    </div>
</div>

<div class="tn-section">
    <div class="tn-section-header">
        <div class="tn-section-title"><span class="tn-ico"><i data-lucide="search"></i></span> Buscar pagamentos aprovados</div>
    </div>
    <div class="tn-section-body">
        <form method="GET" class="rec-search-form">
            <?php if ($RP_AS_FRAGMENT): ?>
                <?php
                // Mantem o contexto do parent quando embarcado
                if (($_GET['tab'] ?? '') === 'config' || ($_GET['cfg_tab'] ?? '') === 'cartao') {
                    echo '<input type="hidden" name="tab" value="config">';
                    echo '<input type="hidden" name="cfg_tab" value="cartao">';
                    echo '<input type="hidden" name="mp_sub" value="recuperar">';
                } else {
                    echo '<input type="hidden" name="tab" value="recuperar">';
                }
                ?>
            <?php endif; ?>
            <div class="tn-fg">
                <label class="tn-fl">Período</label>
                <select name="dias" class="tn-input">
                    <option value="7"  <?php if ($dias==7)  echo 'selected'; ?>>Últimos 7 dias</option>
                    <option value="15" <?php if ($dias==15) echo 'selected'; ?>>Últimos 15 dias</option>
                    <option value="30" <?php if ($dias==30) echo 'selected'; ?>>Últimos 30 dias</option>
                    <option value="60" <?php if ($dias==60) echo 'selected'; ?>>Últimos 60 dias</option>
                    <option value="90" <?php if ($dias==90) echo 'selected'; ?>>Últimos 90 dias</option>
                </select>
            </div>
            <button type="submit" name="buscar" value="1" class="tn-btn tn-btn-primary"><i data-lucide="search"></i> Buscar por período</button>

            <div class="rec-search-sep"></div>

            <div class="tn-fg rec-grow">
                <label class="tn-fl">OU: Payment ID específico</label>
                <input type="text" name="payment_id" class="tn-input"
                       placeholder="Ex: 12345678901"
                       value="<?php echo htmlspecialchars($payment_id_busca); ?>">
            </div>
            <button type="submit" class="tn-btn"><i data-lucide="hash"></i> Buscar pelo ID</button>
        </form>
    </div>
</div>

<!-- ============ BAIXA MANUAL (POR ID OU POR CLIENTE) ============ -->
<div class="tn-section ds-card-accent green">
    <div class="tn-section-header">
        <div class="tn-section-title"><span class="tn-ico"><i data-lucide="banknote"></i></span> Baixa manual de fatura (cartão)</div>
    </div>
    <div class="tn-section-body">

        <!-- Mini-abas -->
        <div class="bx-tabs">
            <button type="button" id="bxTabId" class="pf-tab-btn active" onclick="bxTrocarAba('id')">
                <i data-lucide="hash"></i> Por ID da fatura
            </button>
            <button type="button" id="bxTabCli" class="pf-tab-btn" onclick="bxTrocarAba('cli')">
                <i data-lucide="user-search"></i> Por cliente (e-mail, nome, CPF...)
            </button>
        </div>

        <!-- ABA POR ID -->
        <div id="bxPaneId">
            <p class="rec-panel-desc">
                Digite o número da fatura (ID do título). Mostramos o cliente e o valor para confirmar a baixa como <strong>pago no cartão</strong>.
            </p>
            <div class="rec-search-form">
                <div class="tn-fg bx-w-id">
                    <label class="tn-fl">ID da fatura (título)</label>
                    <input type="number" id="bxFaturaId" class="tn-input" placeholder="Ex: 22898"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();bxBuscarFatura();}">
                </div>
                <button type="button" class="tn-btn tn-btn-primary" onclick="bxBuscarFatura()"><i data-lucide="search"></i> Buscar fatura</button>
            </div>
            <div id="bxResultado" class="bx-result-area" style="display:none"></div>
        </div>

        <!-- ABA POR CLIENTE -->
        <div id="bxPaneCli" style="display:none">
            <p class="rec-panel-desc">
                Busque o cliente por <strong>e-mail</strong> (o mesmo do pagador no MP), nome, login ou CPF. Mostramos as <strong>faturas em aberto</strong> dele para você escolher qual baixar.
            </p>
            <div class="rec-search-form">
                <div class="tn-fg rec-grow bx-w-cli">
                    <label class="tn-fl">E-mail, nome, login ou CPF</label>
                    <input type="text" id="bxClienteTermo" class="tn-input" placeholder="Ex: zayra1979almeida@gmail.com"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();bxBuscarCliente();}">
                </div>
                <button type="button" class="tn-btn tn-btn-primary" onclick="bxBuscarCliente()"><i data-lucide="search"></i> Buscar cliente</button>
            </div>
            <div id="bxResultadoCli" class="bx-result-area" style="display:none"></div>
        </div>
    </div>
</div>
<!-- ============ FIM BAIXA MANUAL ============ -->

<?php if ($erro_busca): ?>
    <div class="ds-alert ds-alert-danger">
        <i data-lucide="alert-octagon"></i>
        <div class="ds-alert-body"><span class="ds-alert-title">Erro</span><?php echo htmlspecialchars($erro_busca); ?></div>
    </div>
<?php endif; ?>

<?php if ($buscou && !$erro_busca): ?>
    <div class="ds-stats-grid ds-stagger">
        <div class="ds-stat blue">
            <div class="ds-stat-icon"><i data-lucide="credit-card"></i></div>
            <div class="tn-stat-label">Pagamentos no MP</div>
            <div class="tn-stat-value"><?php echo $total_aprovados; ?></div>
            <div class="tn-stat-sub">aprovados no período</div>
        </div>
        <div class="ds-stat <?php echo $total_orfaos > 0 ? 'red' : 'green'; ?>">
            <div class="ds-stat-icon"><i data-lucide="<?php echo $total_orfaos > 0 ? 'alert-triangle' : 'check-circle-2'; ?>"></i></div>
            <div class="tn-stat-label">Órfãos (precisam de baixa)</div>
            <div class="tn-stat-value"><?php echo $total_orfaos; ?></div>
            <div class="tn-stat-sub"><?php echo $total_orfaos > 0 ? 'ainda NÃO baixaram no MK-AUTH' : 'tudo certo!'; ?></div>
        </div>
    </div>

    <?php if ($total_orfaos === 0 && !$mostrar_todos): ?>
        <div class="ds-alert ds-alert-success">
            <i data-lucide="check-circle-2"></i>
            <div class="ds-alert-body">
                <span class="ds-alert-title">Tudo certo!</span>
                Nenhum pagamento órfão encontrado nesse período.
                Todos os pagamentos aprovados no MP já estão baixados no MK-AUTH.
            </div>
        </div>
        <?php if ($total_aprovados > 0): ?>
        <p class="ds-center ds-mt-2">
            <a href="<?php echo $RP_BASE_URL; ?>&dias=<?php echo $dias; ?>&buscar=1&mostrar_todos=1" class="tn-btn">
                <i data-lucide="eye"></i> Ver os <?php echo $total_aprovados; ?> pagamentos aprovados (todos)
            </a>
        </p>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($total_orfaos > 0): ?>
        <div class="ds-alert ds-alert-warning">
            <i data-lucide="alert-triangle"></i>
            <div class="ds-alert-body">
                <strong><?php echo $total_orfaos; ?> pagamento(s)</strong> aprovado(s) no MP mas ainda em ABERTO no MK-AUTH.
                Revise e clique em <strong>"Dar baixa"</strong> em cada um.
            </div>
        </div>
        <?php endif; ?>

        <?php if ($mostrar_todos): ?>
        <div class="ds-toolbar">
            <strong class="ds-text-sm">Mostrando:</strong>
            <a href="<?php echo $RP_BASE_URL; ?>&dias=<?php echo $dias; ?>&buscar=1" class="ds-chip"><i data-lucide="alert-triangle"></i> Apenas órfãos (<?php echo $total_orfaos; ?>)</a>
            <span class="ds-chip active"><i data-lucide="list"></i> Todos (<?php echo count($todos_com_fatura); ?>)</span>
        </div>
        <?php endif; ?>

        <?php foreach ($resultado_busca as $idx => $r):
            $fat = $r['fatura'];
            $mp  = $r['mp'];
            $eh_orfao = !empty($r['orfao']);
            $eh_avulso = !empty($r['avulso']);
            $eh_inexistente = !empty($r['inexistente']);
            $valor_mp = floatval($mp['transaction_amount'] ?? 0);
            $valor_fat = $fat ? floatval($fat['valor']) : 0;
            $payment_id = $mp['id'] ?? '';
            $date_approved = !empty($mp['date_approved']) ? date('d/m/Y H:i', strtotime($mp['date_approved'])) : '—';
            $payer_email = $mp['payer']['email'] ?? '—';
            $card_last4 = $mp['card']['last_four_digits'] ?? '';
            $installments = $mp['installments'] ?? 1;
            $valor_dif = abs($valor_mp - $valor_fat);

            // Classe da borda lateral
            if ($eh_orfao) $border_class = 'rec-orfao';                          // âmbar = órfão
            elseif ($eh_avulso || $eh_inexistente) $border_class = 'rec-avulso'; // roxo = avulso
            else $border_class = 'rec-ok';                                       // verde = OK
        ?>
        <div class="rec-card <?php echo $border_class; ?>" id="rec-<?php echo $idx; ?>">
            <?php if (!$eh_orfao && $fat): ?>
            <div class="rec-flags">
                <span class="tn-badge tn-badge-green dot">Baixado</span>
                <?php if (!empty($fat['datapag'])): ?>
                    <small class="ds-faint">em <?php echo date('d/m/Y H:i', strtotime($fat['datapag'])); ?></small>
                <?php endif; ?>
            </div>
            <?php elseif ($eh_avulso): ?>
            <div class="rec-flags">
                <span class="tn-badge tn-badge-purple dot">Sem vínculo com fatura</span>
            </div>
            <?php elseif ($eh_inexistente): ?>
            <div class="rec-flags">
                <span class="tn-badge tn-badge-red dot">Fatura não existe</span>
                <small class="ds-faint">External ref aponta para fatura #<?php echo intval($fat['id']); ?></small>
            </div>
            <?php endif; ?>

            <div class="rec-cols">
                <div class="rec-col">
                    <h3>
                        <?php echo htmlspecialchars($r['cliente_nome'] ?: 'Cliente desconhecido'); ?>
                        <?php if ($fat && !empty($fat['login']) && $fat['login'] !== '?'): ?>
                            <span class="tn-badge tn-badge-muted"><?php echo htmlspecialchars($fat['login']); ?></span>
                        <?php endif; ?>
                    </h3>
                    <?php if ($fat && !$eh_avulso): ?>
                    <table class="rec-kv">
                        <tr><td>Fatura:</td>
                            <td><strong>#<?php echo $fat['id']; ?></strong></td></tr>
                        <?php if (!$eh_inexistente): ?>
                        <tr><td>Valor da fatura:</td>
                            <td>R$ <?php echo number_format($valor_fat, 2, ',', '.'); ?></td></tr>
                        <tr><td>Vencimento:</td>
                            <td><?php echo !empty($fat['datavenc']) ? date('d/m/Y', strtotime($fat['datavenc'])) : '—'; ?></td></tr>
                        <tr><td>Status:</td>
                            <td><span class="tn-badge <?php echo $eh_orfao ? 'tn-badge-amber' : 'tn-badge-green'; ?> dot"><?php echo htmlspecialchars($fat['status']); ?></span></td></tr>
                        <?php endif; ?>
                    </table>
                    <?php elseif ($eh_avulso): ?>
                    <p class="rec-muted-note">
                        Esse pagamento foi aprovado no MP mas <strong>não tem <code>external_reference</code></strong>
                        (campo que liga pagamento → fatura). Pode ser uma cobrança avulsa ou um pagamento de outro sistema.
                    </p>
                    <?php endif; ?>
                </div>

                <div class="rec-col-mp">
                    <div class="head"><i data-lucide="badge-check"></i> Aprovado no Mercado Pago</div>
                    <table class="rec-kv">
                        <tr><td>Payment ID:</td>
                            <td class="mono"><?php echo htmlspecialchars($payment_id); ?></td></tr>
                        <tr><td>Valor pago:</td>
                            <td class="money">R$ <?php echo number_format($valor_mp, 2, ',', '.'); ?></td></tr>
                        <tr><td>Parcelas:</td>
                            <td><?php echo $installments; ?>x</td></tr>
                        <tr><td>Cartão:</td>
                            <td>**** <?php echo htmlspecialchars($card_last4 ?: '—'); ?></td></tr>
                        <tr><td>Aprovado em:</td>
                            <td><?php echo $date_approved; ?></td></tr>
                        <tr><td>E-mail do pagador:</td>
                            <td class="mono"><?php echo htmlspecialchars($payer_email); ?></td></tr>
                    </table>
                </div>
            </div>

            <?php if ($eh_orfao && $valor_dif > 0.10): ?>
            <div class="ds-alert ds-alert-warning ds-mt-1">
                <i data-lucide="scale"></i>
                <div class="ds-alert-body">
                    <span class="ds-alert-title">Divergência de valor</span>
                    MP recebeu R$ <?php echo number_format($valor_mp,2,',','.'); ?>
                    mas a fatura era R$ <?php echo number_format($valor_fat,2,',','.'); ?>
                    (diferença de R$ <?php echo number_format($valor_dif,2,',','.'); ?>).
                    Pode ser juros/parcelamento. Confira antes de dar baixa.
                </div>
            </div>
            <?php endif; ?>

            <div class="rec-actions">
                <button type="button" class="tn-btn"
                        onclick="recAbrirMP('<?php echo htmlspecialchars($payment_id); ?>')">
                    <i data-lucide="external-link"></i> Ver no MP
                </button>
                <?php if ($eh_orfao): ?>
                <button type="button" class="tn-btn tn-btn-primary rec-btn-baixa"
                        data-idx="<?php echo $idx; ?>"
                        data-fatura="<?php echo $fat['id']; ?>"
                        data-payment="<?php echo htmlspecialchars($payment_id); ?>"
                        data-valor="<?php echo $valor_mp; ?>"
                        data-data="<?php echo htmlspecialchars($mp['date_approved'] ?? ''); ?>">
                    <i data-lucide="check"></i> Dar baixa
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<!-- Toast (fallback quando dsToast não estiver carregado) -->
<div id="recToast" class="rec-toast-fallback" style="display:none"></div>

<script>
// Endpoint sempre aponta DIRETO pro arquivo (nao via index.php que envolve HTML no tema)
var RP_ENDPOINT = 'recuperar_pagamentos.php';

function recToast(msg, tipo) {
    // Usa o toast do design system quando disponivel
    if (window.dsToast) {
        if (tipo === 'ok') { dsToast.success(msg); } else { dsToast.danger(msg); }
        return;
    }
    var t = document.getElementById('recToast');
    t.style.background = tipo === 'ok' ? '#1f9d57' : '#e5484d';
    t.textContent = msg;
    t.style.display = 'block';
    clearTimeout(window._recT);
    window._recT = setTimeout(function(){ t.style.display = 'none'; }, 4500);
}

function recIcons() {
    if (window.lucide && lucide.createIcons) lucide.createIcons();
}

function recAbrirMP(payment_id) {
    if (!payment_id) return;
    window.open('https://www.mercadopago.com.br/activities/detail/' + payment_id, '_blank');
}

document.querySelectorAll('.rec-btn-baixa').forEach(function(btn) {
    btn.addEventListener('click', function() {
        if (btn.disabled) return;
        var fatura  = btn.getAttribute('data-fatura');
        var payment = btn.getAttribute('data-payment');
        var valor   = btn.getAttribute('data-valor');
        var data    = btn.getAttribute('data-data');
        var idx     = btn.getAttribute('data-idx');

        if (!confirm('Confirmar baixa da fatura #' + fatura + '?\n\nValor: R$ ' + Number(valor).toFixed(2).replace('.',',') + '\nMP Payment: ' + payment + '\n\nEssa operacao nao tem desfazer.')) return;

        btn.disabled = true;
        var oldText = btn.textContent;
        btn.textContent = 'Processando…';

        var fd = new FormData();
        fd.append('action', 'dar_baixa');
        fd.append('_csrf', '<?php echo function_exists("rp_csrf_token") ? rp_csrf_token() : ""; ?>');
        fd.append('fatura_id', fatura);
        fd.append('payment_id', payment);
        fd.append('valor_pago', valor);
        fd.append('data_pag', data);

        fetch(RP_ENDPOINT, { method: 'POST', body: fd, credentials: 'include' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.ok) {
                    recToast(d.mensagem || 'Baixa registrada!', 'ok');
                    var card = document.getElementById('rec-' + idx);
                    if (card) {
                        card.style.transition = 'opacity .5s';
                        card.style.opacity = '0.4';
                        card.classList.remove('rec-orfao');
                        card.classList.add('rec-ok');
                        btn.textContent = 'Baixado';
                    }
                } else {
                    recToast(d.erro || 'Erro ao dar baixa', 'err');
                    btn.disabled = false;
                    btn.textContent = oldText;
                }
            })
            .catch(function(err) {
                recToast('Erro de conexao', 'err');
                btn.disabled = false;
                btn.textContent = oldText;
            });
    });
});

// ============ BAIXA POR ID DA FATURA ============
var BX_CSRF = '<?php echo function_exists("rp_csrf_token") ? rp_csrf_token() : ""; ?>';

function bxEsc(s){ if(s==null) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function bxMoney(v){ return Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); }

function bxBuscarFatura() {
    var fid = (document.getElementById('bxFaturaId').value || '').trim();
    var box = document.getElementById('bxResultado');
    if (!fid || parseInt(fid) <= 0) {
        recToast('Digite um ID de fatura valido', 'err');
        return;
    }

    box.style.display = 'block';
    box.innerHTML = '<div class="bx-loading">Buscando fatura #' + bxEsc(fid) + '…</div>';

    var fd = new FormData();
    fd.append('action', 'buscar_fatura');
    fd.append('_csrf', BX_CSRF);
    fd.append('fatura_id', fid);

    fetch(RP_ENDPOINT, { method:'POST', body:fd, credentials:'include' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) {
                box.innerHTML = '<div class="ds-alert ds-alert-danger">' + bxEsc(d.erro) + '</div>';
                return;
            }
            var f = d.fatura;
            bxRenderFatura(f);
        })
        .catch(function(err){
            box.innerHTML = '<div class="ds-alert ds-alert-danger">Erro de conexao: ' + bxEsc(err) + '</div>';
        });
}

function bxRenderFatura(f) {
    var box = document.getElementById('bxResultado');

    // Se ja paga, mostra aviso
    var jaPagoBanner = '';
    if (f.ja_pago) {
        jaPagoBanner = '<div class="ds-alert ds-alert-success">' +
            'Esta fatura JA esta paga (datapag: ' + bxEsc(f.datapag || '-') + ', forma: ' + bxEsc(f.formapag || '-') + '). Nao precisa baixar de novo.</div>';
    }

    var vencFmt = f.datavenc ? bxEsc(String(f.datavenc).substr(0,10).split('-').reverse().join('/')) : '-';
    var celular = f.celular ? ' · ' + bxEsc(f.celular) : '';

    var html = jaPagoBanner +
        '<div class="bx-result-box">' +
            '<div class="bx-kv-grid">' +
                '<div class="bx-kv"><div class="k">Fatura</div>' +
                    '<div class="v">#' + f.id + '</div></div>' +
                '<div class="bx-kv"><div class="k">Cliente</div>' +
                    '<div class="v plain">' + bxEsc(f.nome) + '</div>' +
                    '<div class="sub">@' + bxEsc(f.login) + celular + '</div></div>' +
                '<div class="bx-kv"><div class="k">Valor original</div>' +
                    '<div class="v">R$ ' + bxMoney(f.valor) + '</div></div>' +
                '<div class="bx-kv"><div class="k">Vencimento</div>' +
                    '<div class="v plain">' + vencFmt + '</div></div>' +
                '<div class="bx-kv"><div class="k">Status atual</div>' +
                    '<div class="v"><span class="tn-badge ' + (f.ja_pago?'tn-badge-green':'tn-badge-red') + ' dot">' + bxEsc(String(f.status).toUpperCase()) + '</span></div></div>' +
            '</div>';

    if (!f.ja_pago) {
        html +=
            '<div class="bx-confirm-row">' +
                '<div class="tn-fg">' +
                    '<label class="tn-fl">Valor a baixar (R$)</label>' +
                    '<input type="text" id="bxValorPago" class="tn-input" value="' + bxMoney(f.valor_com_taxa) + '">' +
                    '<small class="ds-help">Original R$ ' + bxMoney(f.valor) +
                    (f.acrescimo > 0 ? ' + ' + f.acrescimo + '% taxa cartao = R$ ' + bxMoney(f.valor_com_taxa) : '') + '</small>' +
                '</div>' +
                '<button type="button" id="bxBtnConfirmar" class="tn-btn tn-btn-green">Confirmar baixa (CARTAO)</button>' +
            '</div>';
    }

    html += '</div>';
    box.innerHTML = html;
    recIcons();

    // Liga o botao via JS (evita problemas de escape no onclick inline)
    var btnConf = document.getElementById('bxBtnConfirmar');
    if (btnConf) {
        btnConf.addEventListener('click', function(){ bxConfirmarBaixa(f.id, f.nome); });
    }
}

function bxConfirmarBaixa(fid, nome) {
    var valorEl = document.getElementById('bxValorPago');
    var valor = valorEl ? valorEl.value : '0';

    if (!confirm('Confirmar baixa da fatura #' + fid + ' (' + nome + ')?\n\nValor: R$ ' + valor + '\nForma: CARTAO DE CREDITO\n\nEssa operacao nao tem desfazer.')) return;

    var box = document.getElementById('bxResultado');
    box.style.opacity = '0.6';

    var fd = new FormData();
    fd.append('action', 'baixa_por_id');
    fd.append('_csrf', BX_CSRF);
    fd.append('fatura_id', fid);
    fd.append('valor_pago', valor);

    fetch(RP_ENDPOINT, { method:'POST', body:fd, credentials:'include' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            box.style.opacity = '1';
            if (d.ok) {
                recToast(d.mensagem || 'Baixa registrada!', 'ok');
                box.innerHTML = '<div class="ds-alert ds-alert-success">' +
                    bxEsc(d.mensagem) + ' Recarregue a pagina para ver atualizado.</div>';
                document.getElementById('bxFaturaId').value = '';
            } else {
                recToast(d.erro || 'Erro', 'err');
            }
        })
        .catch(function(err){
            box.style.opacity = '1';
            recToast('Erro de conexao', 'err');
        });
}

// ============ TROCAR ABAS (ID / CLIENTE) ============
function bxTrocarAba(qual) {
    var tabId = document.getElementById('bxTabId');
    var tabCli = document.getElementById('bxTabCli');
    var paneId = document.getElementById('bxPaneId');
    var paneCli = document.getElementById('bxPaneCli');

    if (qual === 'cli') {
        paneId.style.display = 'none';
        paneCli.style.display = 'block';
        tabCli.classList.add('active');
        tabId.classList.remove('active');
    } else {
        paneId.style.display = 'block';
        paneCli.style.display = 'none';
        tabId.classList.add('active');
        tabCli.classList.remove('active');
    }
}

// ============ BUSCAR CLIENTE ============
function bxBuscarCliente() {
    var termo = (document.getElementById('bxClienteTermo').value || '').trim();
    var box = document.getElementById('bxResultadoCli');
    if (termo.length < 3) {
        recToast('Digite ao menos 3 caracteres', 'err');
        return;
    }

    box.style.display = 'block';
    box.innerHTML = '<div class="bx-loading">Buscando clientes…</div>';

    var fd = new FormData();
    fd.append('action', 'buscar_cliente');
    fd.append('_csrf', BX_CSRF);
    fd.append('termo', termo);

    fetch(RP_ENDPOINT, { method:'POST', body:fd, credentials:'include' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (!d.ok) {
                box.innerHTML = '<div class="ds-alert ds-alert-danger">' + bxEsc(d.erro) + '</div>';
                return;
            }
            bxRenderClientes(d.clientes);
        })
        .catch(function(err){
            box.innerHTML = '<div class="ds-alert ds-alert-danger">Erro de conexao: ' + bxEsc(err) + '</div>';
        });
}

function bxRenderClientes(clientes) {
    var box = document.getElementById('bxResultadoCli');

    if (!clientes || clientes.length === 0) {
        box.innerHTML = '<div class="ds-alert ds-alert-warning">Nenhum cliente encontrado com esse termo.</div>';
        return;
    }

    var html = '<div class="ds-text-sm ds-muted ds-mb-1">' + clientes.length + ' cliente(s) encontrado(s):</div>';

    clientes.forEach(function(c) {
        var vencInfo = c.cpf ? ' · CPF ' + bxEsc(c.cpf) : '';
        var celInfo = c.celular ? ' · ' + bxEsc(c.celular) : '';

        html += '<div class="bx-cli-card">';
        // Cabecalho do cliente
        html += '<div class="bx-cli-head" style="margin-bottom:' + (c.faturas.length ? '10px' : '0') + '">';
        html +=   '<div>';
        html +=     '<div class="nome">' + bxEsc(c.nome) + '</div>';
        html +=     '<div class="meta">@' + bxEsc(c.login) + (c.email ? ' · ' + bxEsc(c.email) : '') + '</div>';
        html +=     '<div class="meta">' + (vencInfo + celInfo).replace(/^ · /, '') + '</div>';
        html +=   '</div>';
        var badgeCls = c.qtd_abertas > 0 ? 'tn-badge-red' : 'tn-badge-green';
        html +=   '<span class="tn-badge ' + badgeCls + ' dot">' + c.qtd_abertas + ' em aberto</span>';
        html += '</div>';

        // Faturas em aberto
        if (c.faturas.length === 0) {
            html += '<div class="ds-text-sm ds-success-text">Sem faturas em aberto.</div>';
        } else {
            html += '<table class="bx-fat-table">';
            html += '<tr><th>Fatura</th><th>Vencimento</th><th>Valor</th><th>Com taxa</th><th></th></tr>';
            c.faturas.forEach(function(f) {
                var vencFmt = f.datavenc ? String(f.datavenc).substr(0,10).split('-').reverse().join('/') : '-';
                var vencido = f.datavenc && f.datavenc.substr(0,10) < new Date().toISOString().substr(0,10);
                html += '<tr>';
                html +=   '<td><strong>#' + f.id + '</strong></td>';
                html +=   '<td class="' + (vencido?'venc-late':'') + '">' + vencFmt + (vencido?' (vencida)':'') + '</td>';
                html +=   '<td>R$ ' + bxMoney(f.valor) + '</td>';
                html +=   '<td class="taxa">R$ ' + bxMoney(f.valor_com_taxa) + '</td>';
                html +=   '<td>' +
                            '<button type="button" class="tn-btn tn-btn-green ds-btn-sm" ' +
                            'data-fid="' + f.id + '" data-valor="' + f.valor_com_taxa + '" data-nome="' + bxEsc(c.nome).replace(/"/g,'&quot;') + '" ' +
                            'onclick="bxBaixarDaLista(this)">Baixar</button>' +
                          '</td>';
                html += '</tr>';
            });
            html += '</table>';
        }
        html += '</div>';
    });

    box.innerHTML = html;
    recIcons();
}

// Baixa direto a partir da lista de faturas do cliente
function bxBaixarDaLista(btn) {
    var fid = btn.getAttribute('data-fid');
    var valor = btn.getAttribute('data-valor');
    var nome = btn.getAttribute('data-nome');

    if (!confirm('Confirmar baixa da fatura #' + fid + ' (' + nome + ')?\n\nValor: R$ ' + bxMoney(valor) + '\nForma: CARTAO DE CREDITO\n\nEssa operacao nao tem desfazer.')) return;

    btn.disabled = true;
    var txt = btn.textContent;
    btn.textContent = '…';

    var fd = new FormData();
    fd.append('action', 'baixa_por_id');
    fd.append('_csrf', BX_CSRF);
    fd.append('fatura_id', fid);
    fd.append('valor_pago', valor);

    fetch(RP_ENDPOINT, { method:'POST', body:fd, credentials:'include' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                recToast(d.mensagem || 'Baixa registrada!', 'ok');
                // Marca a linha como baixada
                var tr = btn.closest('tr');
                if (tr) { tr.style.opacity = '0.4'; btn.textContent = 'Baixado'; }
            } else {
                recToast(d.erro || 'Erro', 'err');
                btn.disabled = false;
                btn.textContent = txt;
            }
        })
        .catch(function(err){
            recToast('Erro de conexao', 'err');
            btn.disabled = false;
            btn.textContent = txt;
        });
}
</script>

<?php if (!$RP_AS_FRAGMENT): ?>
</div>
<script src="assets/ds/js/ds.js"></script>
<script src="assets/ds/js/toast.js"></script>
<script src="assets/ds/js/modal.js"></script>
</body>
</html>
<?php endif; ?>
