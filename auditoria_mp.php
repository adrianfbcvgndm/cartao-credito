<?php
/**
 * ================================================================
 * Auditoria Mercado Pago
 * ================================================================
 *
 * Relatorio mensal de TODOS os pagamentos do MP cruzando com as
 * faturas do MK-AUTH. Util para conciliacao contabil.
 *
 * Colunas:
 *  - Data | Cliente | Valor MP | Valor fatura | Divergencia
 *  - Status MP | Status MK-AUTH | Parcelas | Taxa MP estimada
 *
 * Acesso: ?tab=auditoria
 * ================================================================
 */

$RP_AS_FRAGMENT = defined('RP_TEMA_NOVO_FRAME') || !empty($GLOBALS['rp_in_index']);
// URL base para formularios/links: se estiver embarcado em config>cartao, mantem
$RP_BASE_URL = $RP_AS_FRAGMENT
    ? ((($_GET['tab'] ?? '') === 'config' || ($_GET['cfg_tab'] ?? '') === 'cartao')
        ? '?tab=config&cfg_tab=cartao&mp_sub=auditoria'
        : '?tab=auditoria')
    : '?';

if (!$RP_AS_FRAGMENT) {
    require_once __DIR__ . '/rp_config.php';
    require_once __DIR__ . '/financeiro_core.php';
    rp_require_mkauth_session();
}

$conn = @$GLOBALS['LOADMYSQL'] ?? null;
if (!$conn || (method_exists($conn, 'ping') && !@$conn->ping())) {
    $conn = rp_db_connect();
    if ($conn && !$conn->connect_errno) $conn->set_charset('utf8mb4');
}

// Periodo
$mes_atual = date('Y-m');
$mes_sel = $_GET['mes'] ?? $mes_atual;
if (!preg_match('/^\d{4}-\d{2}$/', $mes_sel)) $mes_sel = $mes_atual;
[$ano, $mes] = explode('-', $mes_sel);

$dt_ini = "$mes_sel-01T00:00:00.000-03:00";
$dt_fim = date('Y-m-d\TH:i:s.000-03:00', strtotime("$mes_sel-01 +1 month -1 second"));

// Pega access_token
$access_token = '';
if ($conn) {
    $r = $conn->query("SELECT access_token, acrescimo FROM integrar_cartao_mp LIMIT 1");
    if ($r) {
        $row = $r->fetch_assoc();
        $access_token = $row['access_token'] ?? '';
        $acrescimo_padrao = floatval($row['acrescimo'] ?? 0);
    }
}

$pagamentos = [];
$erro_busca = '';
$total_mp = 0;
$total_baixados = 0;
$total_orfaos = 0;
$total_avulsos = 0;
$total_valor_mp = 0;
$total_valor_fat = 0;

$buscar = isset($_GET['buscar']);

if ($buscar && $access_token) {
    $offset = 0; $limit = 50; $loop = 0;
    do {
        $loop++;
        $params = http_build_query([
            'range' => 'date_approved',
            'begin_date' => $dt_ini,
            'end_date' => $dt_fim,
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
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http !== 200) { $erro_busca = "MP HTTP $http"; break; }
        $data = json_decode($resp, true);
        if (!is_array($data) || !isset($data['results'])) break;

        foreach ($data['results'] as $p) {
            // Resolve fatura
            $ext_ref = $p['external_reference'] ?? '';
            $fatura = null;
            $cliente_nome = '';
            if ($ext_ref && intval($ext_ref) > 0) {
                $fid = intval($ext_ref);
                $rf = $conn->query("SELECT id, login, valor, status, datapag, datavenc FROM sis_lanc WHERE id=$fid LIMIT 1");
                if ($rf && $rf->num_rows > 0) {
                    $fatura = $rf->fetch_assoc();
                    $ls = $conn->real_escape_string($fatura['login']);
                    $rc = $conn->query("SELECT nome FROM sis_cliente WHERE login='$ls' LIMIT 1");
                    if ($rc && $rc->num_rows > 0) $cliente_nome = $rc->fetch_assoc()['nome'];
                }
            }
            $pagamentos[] = ['mp' => $p, 'fatura' => $fatura, 'cliente_nome' => $cliente_nome];
            $total_mp++;
            $val_mp = floatval($p['transaction_amount'] ?? 0);
            $total_valor_mp += $val_mp;
            if ($fatura) {
                if ($fatura['status'] === 'pago') $total_baixados++;
                else $total_orfaos++;
                $total_valor_fat += floatval($fatura['valor']);
            } else {
                $total_avulsos++;
            }
        }
        $tot = $data['paging']['total'] ?? count($data['results']);
        $offset += $limit;
        if ($offset >= $tot) break;
        if ($loop >= 10) break;
    } while (true);
}

// Export CSV?
if (!empty($_GET['csv']) && $buscar && !empty($pagamentos)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="auditoria_mp_'.$mes_sel.'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Data','Payment ID','Cliente','Login','Fatura','Status MP','Status MKA','Valor MP','Valor Fatura','Divergencia','Parcelas','Taxa Estimada']);
    foreach ($pagamentos as $r) {
        $mp = $r['mp']; $f = $r['fatura'];
        $val_mp = floatval($mp['transaction_amount'] ?? 0);
        $val_fat = $f ? floatval($f['valor']) : 0;
        $div = $val_mp - $val_fat;
        // Taxa estimada (acrescimo do MP)
        $taxa = $val_mp - floatval($mp['transaction_details']['net_received_amount'] ?? $val_mp);
        fputcsv($out, [
            !empty($mp['date_approved']) ? date('d/m/Y H:i', strtotime($mp['date_approved'])) : '',
            $mp['id'] ?? '',
            $r['cliente_nome'],
            $f['login'] ?? '',
            $f['id'] ?? '',
            $mp['status'] ?? '',
            $f['status'] ?? 'sem_fatura',
            number_format($val_mp, 2, ',', '.'),
            number_format($val_fat, 2, ',', '.'),
            number_format($div, 2, ',', '.'),
            $mp['installments'] ?? 1,
            number_format($taxa, 2, ',', '.'),
        ]);
    }
    fclose($out);
    exit;
}
?>

<?php if (!$RP_AS_FRAGMENT): ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Auditoria Mercado Pago</title>
<link rel="stylesheet" href="assets/ds/ds.css?v=20260922">
<link rel="stylesheet" href="assets/ds/pages/auditoria-mp.css?v=20260922">
<script src="assets/vendor/lucide.min.js"></script>
</head>
<body>
<div class="tn-scope amp-wrap">
<?php else: ?>
<link rel="stylesheet" href="assets/ds/pages/auditoria-mp.css?v=20260922">
<?php endif; ?>

<div class="ds-page-head">
    <div>
        <h1 class="ds-page-title"><i data-lucide="clipboard-check"></i> Auditoria Mercado Pago</h1>
        <div class="ds-page-desc">Relatório mensal de pagamentos do Mercado Pago com conciliação nas faturas do MK-AUTH.</div>
    </div>
</div>

<form method="GET" class="ds-toolbar amp-toolbar">
    <?php if ($RP_AS_FRAGMENT): ?>
        <?php
        if (($_GET['tab'] ?? '') === 'config' || ($_GET['cfg_tab'] ?? '') === 'cartao') {
            echo '<input type="hidden" name="tab" value="config">';
            echo '<input type="hidden" name="cfg_tab" value="cartao">';
            echo '<input type="hidden" name="mp_sub" value="auditoria">';
        } else {
            echo '<input type="hidden" name="tab" value="auditoria">';
        }
        ?>
    <?php endif; ?>
    <div class="tn-fg">
        <label class="tn-fl">Mês de referência</label>
        <input type="month" class="tn-input" name="mes" value="<?php echo htmlspecialchars($mes_sel); ?>">
    </div>
    <div class="ds-toolbar-spacer"></div>
    <button type="submit" name="buscar" value="1" class="tn-btn tn-btn-primary"><i data-lucide="search"></i> Gerar relatório</button>
    <?php if ($buscar && !empty($pagamentos)): ?>
        <a href="<?php echo $RP_BASE_URL; ?>&mes=<?php echo $mes_sel; ?>&buscar=1&csv=1" class="tn-btn tn-btn-outline"><i data-lucide="download"></i> Exportar CSV</a>
    <?php endif; ?>
</form>

<?php if ($erro_busca): ?>
    <div class="ds-alert ds-alert-danger">
        <i data-lucide="circle-x"></i>
        <div class="ds-alert-body">
            <span class="ds-alert-title">Falha ao consultar o Mercado Pago</span>
            <?php echo htmlspecialchars($erro_busca); ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($buscar && empty($erro_busca)): ?>
    <div class="ds-stats-grid ds-stagger">
        <div class="ds-stat blue">
            <div class="ds-stat-icon"><i data-lucide="credit-card"></i></div>
            <div class="tn-stat-label">Total MP</div>
            <div class="tn-stat-value"><?php echo $total_mp; ?></div>
            <div class="tn-stat-sub">pagamentos no mês</div>
        </div>
        <div class="ds-stat green">
            <div class="ds-stat-icon"><i data-lucide="check-check"></i></div>
            <div class="tn-stat-label">Baixados</div>
            <div class="tn-stat-value"><?php echo $total_baixados; ?></div>
            <div class="tn-stat-sub">conciliados no MK-AUTH</div>
        </div>
        <div class="ds-stat <?php echo $total_orfaos ? 'red' : 'green'; ?>">
            <div class="ds-stat-icon"><i data-lucide="unlink"></i></div>
            <div class="tn-stat-label">Órfãos</div>
            <div class="tn-stat-value"><?php echo $total_orfaos; ?></div>
            <div class="tn-stat-sub">pagos sem baixa na fatura</div>
        </div>
        <div class="ds-stat purple">
            <div class="ds-stat-icon"><i data-lucide="user-x"></i></div>
            <div class="tn-stat-label">Avulsos</div>
            <div class="tn-stat-value"><?php echo $total_avulsos; ?></div>
            <div class="tn-stat-sub">sem fatura vinculada</div>
        </div>
        <div class="ds-stat teal">
            <div class="ds-stat-icon"><i data-lucide="wallet"></i></div>
            <div class="tn-stat-label">Total recebido</div>
            <div class="tn-stat-value">R$ <?php echo number_format($total_valor_mp,2,',','.'); ?></div>
            <div class="tn-stat-sub">bruto no Mercado Pago</div>
        </div>
    </div>

    <?php if (empty($pagamentos)): ?>
        <div class="tn-section">
            <div class="tn-section-body">
                <div class="ds-empty">
                    <div class="ds-empty-ico"><i data-lucide="inbox"></i></div>
                    <b>Nenhum pagamento encontrado</b>
                    <p>Não há pagamentos aprovados no Mercado Pago para o mês selecionado.</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="tn-section">
            <div class="tn-section-header">
                <div class="tn-section-title">
                    <i data-lucide="git-compare"></i> Conciliação Mercado Pago × MK-AUTH
                </div>
                <span class="tn-badge tn-badge-blue"><?php echo count($pagamentos); ?> pagamentos</span>
            </div>
            <div class="tn-section-body flush">
                <div class="tn-table-scroll">
                    <table class="tn-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Cliente</th>
                                <th class="c">Fatura</th>
                                <th class="num">Valor MP</th>
                                <th class="num">Valor Fat.</th>
                                <th class="num">Diferença</th>
                                <th class="c">Status</th>
                                <th class="c">Parc.</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pagamentos as $r):
                            $mp = $r['mp']; $f = $r['fatura'];
                            $val_mp = floatval($mp['transaction_amount'] ?? 0);
                            $val_fat = $f ? floatval($f['valor']) : 0;
                            $div = $val_mp - $val_fat;
                            $status_mka = $f ? $f['status'] : 'sem_fatura';
                            $row_bg = '';
                            if (!$f) $row_bg = 'row-avulso';
                            elseif ($status_mka === 'pago') $row_bg = '';
                            else $row_bg = 'row-orfao';
                        ?>
                        <tr class="<?php echo $row_bg; ?>">
                            <td class="muted"><?php echo !empty($mp['date_approved']) ? date('d/m H:i', strtotime($mp['date_approved'])) : '—'; ?></td>
                            <td>
                                <?php echo htmlspecialchars($r['cliente_nome'] ?: '(avulso)'); ?>
                                <?php if ($f): ?><br><small class="mono"><?php echo htmlspecialchars($f['login']); ?></small><?php endif; ?>
                            </td>
                            <td class="c mono"><?php echo $f ? '#'.$f['id'] : '—'; ?></td>
                            <td class="num"><strong class="tn-money">R$ <?php echo number_format($val_mp,2,',','.'); ?></strong></td>
                            <td class="num muted"><?php echo $f ? 'R$ '.number_format($val_fat,2,',','.') : '—'; ?></td>
                            <td class="num"><span class="<?php echo abs($div)<.10?'amp-diff-ok':'amp-diff-warn'; ?>"><?php echo $f ? (($div>=0?'+':'').number_format($div,2,',','.')) : '—'; ?></span></td>
                            <td class="c">
                                <span class="tn-badge <?php echo $status_mka==='pago'?'tn-badge-green':($status_mka==='sem_fatura'?'tn-badge-muted':'tn-badge-amber'); ?> dot"><?php echo htmlspecialchars($status_mka); ?></span>
                            </td>
                            <td class="c mono"><?php echo intval($mp['installments'] ?? 1); ?>x</td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="amp-legend">
                    <span><span class="swatch orfao"></span> Órfão: pagamento aprovado com fatura ainda em aberto</span>
                    <span><span class="swatch avulso"></span> Avulso: pagamento sem fatura vinculada</span>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="tn-section">
        <div class="tn-section-body">
            <div class="ds-empty">
                <div class="ds-empty-ico"><i data-lucide="calendar-search"></i></div>
                <b>Selecione um período</b>
                <p>Escolha um mês acima e clique em <strong>Gerar relatório</strong> para ver a conciliação completa.</p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!$RP_AS_FRAGMENT): ?>
</div>
<script src="assets/ds/js/ds.js"></script>
<script src="assets/ds/js/toast.js"></script>
<script src="assets/ds/js/modal.js"></script>
</body>
</html>
<?php endif; ?>
