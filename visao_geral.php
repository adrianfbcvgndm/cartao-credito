<?php
/**
 * Cartão de Crédito · Visão geral (09/10/2026)
 * Pagamentos por cartão (Mercado Pago) do mês: bruto, taxas, líquido, parcelas e quem pagou.
 * Fonte: sis_lanc (formapag CARTAO%) + addon_mp_webhook_audit (taxa/líquido/parcelas do MP).
 */
if (empty($GLOBALS['rp_in_index'])) { header('Location: ./?tab=visao'); exit; }

$cc_conn = rp_db_connect();
$cc_conn->set_charset('utf8mb4');

$cc_mes = (isset($_GET['mes']) && preg_match('/^\d{4}-\d{2}$/', $_GET['mes'])) ? $_GET['mes'] : date('Y-m');
$cc_ini = $cc_mes . '-01';
$cc_fim = date('Y-m-d', strtotime($cc_ini . ' +1 month'));
$cc_ant = date('Y-m', strtotime($cc_ini . ' -1 month'));
$cc_prox = date('Y-m', strtotime($cc_ini . ' +1 month'));
$cc_meses_nome = [1=>'janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
$cc_nome_mes = function ($ym) use ($cc_meses_nome) { return $cc_meses_nome[(int)substr($ym, 5, 2)] . ' de ' . substr($ym, 0, 4); };
$cc_m = function ($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); };

// dados do Mercado Pago por fatura (taxa, líquido, parcelas, bandeira)
$cc_mp = [];
$r = $cc_conn->query("SELECT titulo_id, installments, payload FROM addon_mp_webhook_audit WHERE status_mp LIKE 'approved%' ORDER BY id");
while ($r && ($a = $r->fetch_assoc())) {
    $p = json_decode((string)$a['payload'], true) ?: [];
    $taxa = 0;
    foreach (($p['fee_details'] ?? []) as $f) $taxa += (float)($f['amount'] ?? 0);
    $cc_mp[(int)$a['titulo_id']] = [
        'parc'  => (int)$a['installments'],
        'taxa'  => $taxa,
        'liq'   => isset($p['transaction_details']['net_received_amount']) ? (float)$p['transaction_details']['net_received_amount'] : null,
        'band'  => (string)($p['payment_method_id'] ?? ''),
        'fim'   => (string)($p['card']['last_four_digits'] ?? ''),
    ];
}

// pagamentos do mês
$cc_lista = [];
$st = $cc_conn->prepare("SELECT l.id, l.login, l.valorpag, l.datapag, l.datavenc, c.nome
                         FROM sis_lanc l LEFT JOIN sis_cliente c ON c.login = l.login
                         WHERE l.formapag LIKE 'CARTAO%' AND l.datapag >= ? AND l.datapag < ?
                         ORDER BY l.datapag DESC");
$st->bind_param('ss', $cc_ini, $cc_fim);
$st->execute();
$rs = $st->get_result();
$cc_bruto = 0; $cc_taxa = 0; $cc_liq = 0; $cc_parcelado = 0;
while ($l = $rs->fetch_assoc()) {
    $mp = $cc_mp[(int)$l['id']] ?? null;
    $v = (float)$l['valorpag'];
    $tx = $mp ? $mp['taxa'] : 0;
    $lq = ($mp && $mp['liq'] !== null) ? $mp['liq'] : $v - $tx;
    $cc_bruto += $v; $cc_taxa += $tx; $cc_liq += $lq;
    if ($mp && $mp['parc'] > 1) $cc_parcelado++;
    $l['mp'] = $mp; $l['liq'] = $lq; $l['taxa'] = $tx;
    $cc_lista[] = $l;
}
$st->close();
$cc_qtd = count($cc_lista);

// últimos 6 meses (para as barras)
$cc_hist = [];
for ($i = 5; $i >= 0; $i--) { $ym = date('Y-m', strtotime($cc_ini . " -$i month")); $cc_hist[$ym] = ['v' => 0, 'n' => 0]; }
$h_ini = array_key_first($cc_hist) . '-01';
$st = $cc_conn->prepare("SELECT DATE_FORMAT(datapag,'%Y-%m') ym, SUM(valorpag) v, COUNT(*) n FROM sis_lanc
                         WHERE formapag LIKE 'CARTAO%' AND datapag >= ? AND datapag < ? GROUP BY ym");
$st->bind_param('ss', $h_ini, $cc_fim);
$st->execute();
$rs = $st->get_result();
while ($h = $rs->fetch_assoc()) if (isset($cc_hist[$h['ym']])) $cc_hist[$h['ym']] = ['v' => (float)$h['v'], 'n' => (int)$h['n']];
$st->close();
$cc_max = max(1, max(array_column($cc_hist, 'v')));
$cc_ant_v = $cc_hist[$cc_ant]['v'] ?? 0;
$cc_var = $cc_ant_v > 0 ? round(($cc_bruto - $cc_ant_v) / $cc_ant_v * 100) : null;

// status rápido da integração
$cc_cfg = $cc_conn->query("SELECT access_token, public_key FROM integrar_cartao_mp LIMIT 1");
$cc_cfg = $cc_cfg ? $cc_cfg->fetch_assoc() : null;
$cc_ok_cred = $cc_cfg && trim((string)$cc_cfg['access_token']) !== '' && trim((string)$cc_cfg['public_key']) !== '';
$cc_ult = $cc_conn->query("SELECT MAX(created_at) u FROM addon_mp_webhook_audit");
$cc_ult = $cc_ult ? ($cc_ult->fetch_assoc()['u'] ?? null) : null;
$cc_ap = $cc_conn->query("SELECT COUNT(*) n FROM addon_autopay_clientes WHERE status='ativo'");
$cc_ap = $cc_ap ? (int)($cc_ap->fetch_assoc()['n'] ?? 0) : 0;
?>
<link rel="stylesheet" href="assets/ds/pages/resumo_p3.css?v=20261009">
<link rel="stylesheet" href="assets/ds/pages/cartao.css?v=20261009">

<div class="rp3-card">
    <div class="rp3-hero">
        <div>
            <small>Recebido no cartão em <?php echo $cc_nome_mes($cc_mes); ?></small>
            <span class="rp3-big"><?php echo $cc_m($cc_bruto); ?></span>
        </div>
        <div class="rp3-de">
            <small>Líquido</small><br><b><?php echo $cc_m($cc_liq); ?></b>
            <?php if ($cc_var !== null): ?><span class="rp3-cmp <?php echo $cc_var >= 0 ? 'pos' : 'neg'; ?>"><?php echo ($cc_var >= 0 ? '+' : '') . $cc_var; ?>% no mês</span><?php endif; ?>
        </div>
        <div class="rp3-de"><small>Taxas do Mercado Pago</small><br><b class="rp3-neg"><?php echo $cc_m($cc_taxa); ?></b></div>
        <div class="rp3-de"><small>Pagamentos</small><br><b><?php echo $cc_qtd; ?></b><?php if ($cc_parcelado): ?> <span class="rp3-n"><?php echo $cc_parcelado; ?> parcelado<?php echo $cc_parcelado > 1 ? 's' : ''; ?></span><?php endif; ?></div>
        <div class="rp3-esp"></div>
        <div class="rp3-data">
            <a href="?tab=visao&mes=<?php echo $cc_ant; ?>" aria-label="Mês anterior"><i data-lucide="chevron-left"></i></a>
            <a class="rp3-hj" href="?tab=visao">Este mês</a>
            <a href="?tab=visao&mes=<?php echo $cc_prox; ?>" class="<?php echo $cc_prox > date('Y-m') ? 'off' : ''; ?>" aria-label="Próximo mês"><i data-lucide="chevron-right"></i></a>
        </div>
    </div>

    <?php if ($cc_bruto > 0): ?>
    <div class="rp3-pb" title="Líquido x taxas">
        <span style="width:<?php echo round($cc_liq / $cc_bruto * 100, 2); ?>%;background:#1baf7a"></span>
        <span style="width:<?php echo round($cc_taxa / $cc_bruto * 100, 2); ?>%;background:#e34948"></span>
    </div>
    <div class="rp3-lg">
        <span><i style="background:#1baf7a"></i>Líquido <b><?php echo $cc_m($cc_liq); ?></b></span>
        <span><i style="background:#e34948"></i>Taxas <b><?php echo $cc_m($cc_taxa); ?></b> (<?php echo number_format($cc_taxa / $cc_bruto * 100, 1, ',', ''); ?>%)</span>
    </div>
    <?php endif; ?>

    <div class="cc-meses">
        <?php foreach ($cc_hist as $ym => $h): ?>
        <a class="rp3-dia <?php echo $ym === $cc_mes ? 'sel' : ''; ?> <?php echo $h['v'] <= 0 ? 'zero' : ''; ?>" href="?tab=visao&mes=<?php echo $ym; ?>">
            <span class="d"><?php echo ucfirst(substr($cc_meses_nome[(int)substr($ym, 5, 2)], 0, 3)); ?> <small><?php echo substr($ym, 0, 4); ?></small></span>
            <b><?php echo $cc_m($h['v']); ?></b>
            <small><?php echo $h['n']; ?> pagamento<?php echo $h['n'] == 1 ? '' : 's'; ?></small>
            <span class="rp3-b"><span style="width:<?php echo round($h['v'] / $cc_max * 100); ?>%;background:#2a78d6"></span></span>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="rp3-dois">
        <div>
            <p class="rp3-tit">Quem pagou no cartão <span class="rp3-n"><?php echo $cc_qtd; ?></span></p>
            <?php if (!$cc_lista): ?>
                <div class="rp3-vazio">Nenhum pagamento com cartão em <?php echo $cc_nome_mes($cc_mes); ?>.</div>
            <?php else: ?>
            <div class="rp3-scroll">
            <table class="rp3-tab">
                <thead><tr><th>Cliente</th><th>Pago em</th><th class="c">Parcelas</th><th class="r">Taxa</th><th class="r">Valor</th></tr></thead>
                <tbody>
                <?php foreach ($cc_lista as $l): $mp = $l['mp']; ?>
                <tr>
                    <td><?php echo htmlspecialchars($l['nome'] ?: $l['login']); ?>
                        <small><?php echo htmlspecialchars($l['login']); ?><?php if ($mp && $mp['fim']): ?> · <?php echo htmlspecialchars(ucfirst($mp['band'])); ?> final <?php echo htmlspecialchars($mp['fim']); ?><?php endif; ?></small></td>
                    <td class="rp3-q"><?php echo date('d/m H:i', strtotime($l['datapag'])); ?></td>
                    <td class="c"><?php echo $mp ? ($mp['parc'] > 1 ? $mp['parc'] . 'x' : 'à vista') : '—'; ?></td>
                    <td class="r rp3-q"><?php echo $l['taxa'] > 0 ? $cc_m($l['taxa']) : '—'; ?></td>
                    <td class="r rp3-v"><?php echo $cc_m($l['valorpag']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <div>
            <p class="rp3-tit">Situação da integração</p>
            <div class="cc-st">
                <div class="cc-st-i"><span class="rp3-tg" style="background:<?php echo $cc_ok_cred ? '#e3f6ee;color:#0f7a53' : '#fde8e8;color:#b42318'; ?>"><?php echo $cc_ok_cred ? 'Configurado' : 'Faltando'; ?></span>
                    <span>Credenciais do Mercado Pago</span><a href="?tab=credenciais">Abrir</a></div>
                <div class="cc-st-i"><span class="rp3-tg" style="background:#eef0f2;color:#59636e"><?php echo $cc_ult ? date('d/m H:i', strtotime($cc_ult)) : 'nunca'; ?></span>
                    <span>Último aviso do Mercado Pago</span><a href="?tab=auditoria">Auditoria</a></div>
                <div class="cc-st-i"><span class="rp3-tg" style="background:#eef0f2;color:#59636e"><?php echo $cc_ap; ?> ativo<?php echo $cc_ap == 1 ? '' : 's'; ?></span>
                    <span>Clientes no débito automático</span><a href="?tab=autopay">Autopay</a></div>
            </div>
            <p class="rp3-fx-info">O cliente paga pelo link da fatura (<b>/cartao?id=…</b>). O Mercado Pago avisa o MK-Auth e a fatura é baixada sozinha. Se algum pagamento não baixou, use <a href="?tab=recuperar">Recuperar pagamentos</a>.</p>
        </div>
    </div>
    <div class="rp3-rod">Fonte: <b>lançamentos pagos com cartão no MK-Auth</b> e avisos do Mercado Pago</div>
</div>
