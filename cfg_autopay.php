<?php
/**
 * Aba Admin: Gerenciar Autopays
 *
 * Mostra:
 * - Estatisticas (total ativo, expirado, falha, etc)
 * - Tabela de todos os clientes com autopay
 * - Filtro por status
 * - Acoes: cancelar manualmente, ver historico, copiar link de gerencia
 *
 * Incluido em configuracoes.php quando cfg_tab=cartao&mp_sub=autopay
 *
 * Variaveis esperadas: $conn (definido em configuracoes.php)
 */

if (!isset($conn) || !$conn) {
    echo '<div class="tn-alert">⚠ Sem conexao com o banco</div>';
    return;
}

// Garante tabelas
@$conn->query("CREATE TABLE IF NOT EXISTS addon_autopay_clientes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(80) NOT NULL,
    mp_customer_id VARCHAR(100) NOT NULL,
    mp_card_id VARCHAR(100) NOT NULL,
    cartao_final VARCHAR(4) NOT NULL,
    cartao_bandeira VARCHAR(30) DEFAULT NULL,
    cartao_validade VARCHAR(7) DEFAULT NULL,
    email_pagador VARCHAR(150) DEFAULT NULL,
    meses_total INT UNSIGNED NOT NULL DEFAULT 0,
    meses_executados INT UNSIGNED NOT NULL DEFAULT 0,
    proxima_cobranca DATE DEFAULT NULL,
    falhas_consecutivas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ultima_tentativa DATETIME DEFAULT NULL,
    ultimo_status VARCHAR(80) DEFAULT NULL,
    status ENUM('ativo','pausado','cancelado','expirado','falha_max') NOT NULL DEFAULT 'ativo',
    autorizado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    cancelado_em DATETIME DEFAULT NULL,
    cancelado_motivo VARCHAR(255) DEFAULT NULL,
    cancelado_por VARCHAR(50) DEFAULT NULL,
    token_gerencia VARCHAR(64) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_token (token_gerencia),
    INDEX idx_proxima (status, proxima_cobranca)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ==================== ACOES ADMIN ====================
$msg_admin = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ap_acao'] ?? '') !== '') {
    if (function_exists('rp_csrf_check')) rp_csrf_check();
    $acao = $_POST['ap_acao'];
    $id = intval($_POST['ap_id'] ?? 0);

    if ($id > 0) {
        if ($acao === 'cancelar') {
            $motivo = $conn->real_escape_string($_POST['ap_motivo'] ?? 'Cancelado pelo administrador');
            $conn->query("UPDATE addon_autopay_clientes
                SET status='cancelado', cancelado_em=NOW(),
                    cancelado_motivo='$motivo', cancelado_por='admin'
                WHERE id=$id");

            // Remove cartao do MP
            $r = $conn->query("SELECT mp_customer_id, mp_card_id FROM addon_autopay_clientes WHERE id=$id");
            if ($r && $r->num_rows > 0 && is_file(__DIR__ . '/autopay_helper.php')) {
                require_once __DIR__ . '/autopay_helper.php';
                $row = $r->fetch_assoc();
                @autopay_remover_cartao($conn, $row['mp_customer_id'], $row['mp_card_id']);
            }
            $msg_admin = '✓ Autopay cancelado pelo admin';
        } elseif ($acao === 'pausar') {
            $conn->query("UPDATE addon_autopay_clientes SET status='pausado' WHERE id=$id");
            $msg_admin = '✓ Autopay pausado';
        } elseif ($acao === 'reativar') {
            $conn->query("UPDATE addon_autopay_clientes SET status='ativo', falhas_consecutivas=0 WHERE id=$id");
            $msg_admin = '✓ Autopay reativado';
        } elseif ($acao === 'forcar_cobranca') {
            // Forca proxima_cobranca = hoje
            $conn->query("UPDATE addon_autopay_clientes SET proxima_cobranca=CURDATE() WHERE id=$id");
            $msg_admin = '✓ Cobranca forcada pra hoje. Sera processada pelo cron.';
        }
    }
}

// ==================== ESTATISTICAS ====================
$stats = ['ativo' => 0, 'pausado' => 0, 'cancelado' => 0, 'expirado' => 0, 'falha_max' => 0];
$r = $conn->query("SELECT status, COUNT(*) as t FROM addon_autopay_clientes GROUP BY status");
if ($r) while ($row = $r->fetch_assoc()) $stats[$row['status']] = (int)$row['t'];

// Receita prevista (soma valor_planejado das faturas dos clientes ativos)
$receita_prevista = 0;
$r = $conn->query("SELECT SUM(l.valor) as t
    FROM addon_autopay_clientes a
    LEFT JOIN sis_lanc l ON l.login = a.login AND l.deltitulo=0 AND l.status != 'pago'
    WHERE a.status='ativo'");
if ($r) $receita_prevista = floatval($r->fetch_assoc()['t'] ?? 0);

// Taxa de sucesso
$total_aprov = 0; $total_log = 0;
$r = $conn->query("SELECT status, COUNT(*) as t FROM addon_autopay_log GROUP BY status");
if ($r) while ($row = $r->fetch_assoc()) {
    $total_log += (int)$row['t'];
    if ($row['status'] === 'aprovado') $total_aprov = (int)$row['t'];
}
$taxa_sucesso = $total_log > 0 ? round(($total_aprov / $total_log) * 100, 1) : 0;

// ==================== LISTA ====================
$filtro = $_GET['ap_filtro'] ?? 'ativos';
$where = "WHERE 1=1";
if ($filtro === 'ativos') $where .= " AND status='ativo'";
elseif ($filtro === 'cancelados') $where .= " AND status='cancelado'";
elseif ($filtro === 'pausados') $where .= " AND status='pausado'";
elseif ($filtro === 'falhas') $where .= " AND (status='falha_max' OR falhas_consecutivas > 0)";
elseif ($filtro === 'expirados') $where .= " AND status='expirado'";

$lista = [];
$r = $conn->query("SELECT a.*, c.nome as cliente_nome, c.celular, c.fone
    FROM addon_autopay_clientes a
    LEFT JOIN sis_cliente c ON c.login = a.login
    $where
    ORDER BY a.status='ativo' DESC, a.created_at DESC LIMIT 200");
if ($r) while ($row = $r->fetch_assoc()) $lista[] = $row;

?>

<div class="ap-head">
    <h3><i data-lucide="zap"></i> Gerenciar Pagamentos Automáticos</h3>
    <p>Clientes com débito automático no cartão de crédito. Cobranças via Mercado Pago tokenizado.</p>
</div>

<?php if ($msg_admin): ?>
<div class="ds-alert ds-alert-success">
    <i data-lucide="check-circle-2"></i>
    <div class="ds-alert-body"><?php echo htmlspecialchars($msg_admin); ?></div>
</div>
<?php endif; ?>

<!-- ============ STATS CARDS ============ -->
<div class="tn-stats-grid">
    <div class="ds-stat green">
        <div class="ds-stat-label">Ativos</div>
        <div class="ds-stat-value"><?php echo $stats['ativo']; ?></div>
        <div class="ds-stat-sub">clientes pagando automaticamente</div>
    </div>
    <div class="ds-stat blue">
        <div class="ds-stat-label">Receita prevista</div>
        <div class="ds-stat-value">R$ <?php echo number_format($receita_prevista, 2, ',', '.'); ?></div>
        <div class="ds-stat-sub">próxima cobrança</div>
    </div>
    <div class="ds-stat amber">
        <div class="ds-stat-label">Taxa de sucesso</div>
        <div class="ds-stat-value"><?php echo $taxa_sucesso; ?>%</div>
        <div class="ds-stat-sub"><?php echo $total_log; ?> cobranças no total</div>
    </div>
    <div class="ds-stat red">
        <div class="ds-stat-label">Inativos</div>
        <div class="ds-stat-value"><?php echo $stats['cancelado'] + $stats['falha_max'] + $stats['expirado']; ?></div>
        <div class="ds-stat-sub">cancelados, falhas e expirados</div>
    </div>
</div>

<!-- ============ FILTROS ============ -->
<div class="ap-chips">
    <?php
    $filtros = [
        'ativos'     => ['Ativos', 'green', $stats['ativo']],
        'pausados'   => ['Pausados', 'amber', $stats['pausado']],
        'falhas'     => ['Falhas', 'red', $stats['falha_max']],
        'cancelados' => ['Cancelados', 'muted', $stats['cancelado']],
        'expirados'  => ['Expirados', 'purple', $stats['expirado']],
        'todos'      => ['Todos', 'blue', array_sum($stats)],
    ];
    foreach ($filtros as $k => $f):
        $active = $filtro === $k;
    ?>
    <a href="?tab=autopay&ap_filtro=<?php echo $k; ?>"
       class="ap-chip <?php echo $f[1]; ?><?php echo $active ? ' active' : ''; ?>">
        <?php echo $f[0]; ?> (<?php echo $f[2]; ?>)
    </a>
    <?php endforeach; ?>
</div>

<!-- ============ TABELA ============ -->
<div class="ds-form-section">
<div class="ds-form-section-body flush">
<?php if (empty($lista)): ?>
    <div class="ds-empty">
        <div class="ds-empty-ico"><i data-lucide="zap-off"></i></div>
        <b>Nenhum cliente com autopay neste filtro</b>
        <p>Clientes ativam o autopay marcando a opção no fluxo de pagamento via cartão.</p>
    </div>
<?php else: ?>
<div class="tn-table-scroll">
<table class="tn-table">
    <thead>
        <tr>
            <th>Cliente</th>
            <th>Cartão</th>
            <th class="c">Duração</th>
            <th class="c">Próxima</th>
            <th class="c">Falhas</th>
            <th class="c">Status</th>
            <th class="c">Ações</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($lista as $ap):
        $statusMap = [
            'ativo'     => ['tn-badge-green', 'Ativo'],
            'pausado'   => ['tn-badge-amber', 'Pausado'],
            'cancelado' => ['tn-badge-muted', 'Cancelado'],
            'expirado'  => ['tn-badge-purple', 'Expirado'],
            'falha_max' => ['tn-badge-red', 'Falha máx'],
        ];
        $s = $statusMap[$ap['status']] ?? ['tn-badge-muted', $ap['status']];

        // Calcula dias ate proxima cobranca
        $dias_prox = '';
        if ($ap['proxima_cobranca'] && $ap['status']==='ativo') {
            $d = floor((strtotime($ap['proxima_cobranca']) - time())/86400);
            $cor_d = $d < 0 ? 'var(--ds-danger-text)' : ($d < 3 ? 'var(--ds-warning-text)' : 'var(--ds-text-3)');
            $txt_d = $d < 0 ? "atras ".abs($d)."d" : ($d===0 ? "hoje" : "em $d d");
            $dias_prox = '<br><small style="color:'.$cor_d.';font-size:10px">'.$txt_d.'</small>';
        }
    ?>
    <tr>
        <td>
            <strong><?php echo htmlspecialchars($ap['cliente_nome'] ?: $ap['login']); ?></strong>
            <br><small class="muted">@<?php echo htmlspecialchars($ap['login']); ?></small>
        </td>
        <td>
            <strong><?php echo strtoupper(htmlspecialchars($ap['cartao_bandeira'])); ?> **** <?php echo htmlspecialchars($ap['cartao_final']); ?></strong>
            <br><small class="muted">val <?php echo htmlspecialchars($ap['cartao_validade']); ?></small>
        </td>
        <td class="c">
            <?php if ($ap['meses_total']==0): ?>
                <strong>Sempre</strong>
                <br><small class="muted"><?php echo $ap['meses_executados']; ?> meses</small>
            <?php else: ?>
                <strong><?php echo $ap['meses_executados']; ?> / <?php echo $ap['meses_total']; ?></strong>
                <br>
                <?php $pct = round(($ap['meses_executados']/$ap['meses_total'])*100); ?>
                <div class="ap-mini-progress">
                    <span style="width:<?php echo $pct; ?>%"></span>
                </div>
            <?php endif; ?>
        </td>
        <td class="c">
            <?php echo $ap['proxima_cobranca'] ? date('d/m/Y', strtotime($ap['proxima_cobranca'])) : '—'; ?>
            <?php echo $dias_prox; ?>
        </td>
        <td class="c">
            <?php if ($ap['falhas_consecutivas']>0): ?>
                <span class="tn-badge tn-badge-red"><?php echo $ap['falhas_consecutivas']; ?>/3</span>
            <?php else: ?>
                <span class="muted">—</span>
            <?php endif; ?>
        </td>
        <td class="c">
            <span class="tn-badge <?php echo $s[0]; ?>"><?php echo $s[1]; ?></span>
        </td>
        <td class="c cfg-nowrap">
            <!-- Copiar link cliente -->
            <?php
            $link_cliente = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/admin/addons/cartao_credito/autopay_publico.php?t=' . $ap['token_gerencia']);
            ?>
            <button type="button" class="tn-btn tn-btn-outline ds-btn-sm" data-ds-tip="Copiar link de gerência do cliente"
                    onclick="apCopiarLink('<?php echo $link_cliente; ?>', this)">
                Link
            </button>

            <?php if ($ap['status']==='ativo'): ?>
                <form method="POST" style="display:inline" data-ds-confirm="Forçar cobrança HOJE deste cliente?" data-ds-confirm-title="Forçar cobrança" data-ds-confirm-tone="warning">
                    <?php if (function_exists('rp_csrf_field')) rp_csrf_field(); ?>
                    <input type="hidden" name="ap_acao" value="forcar_cobranca">
                    <input type="hidden" name="ap_id" value="<?php echo $ap['id']; ?>">
                    <button type="submit" class="tn-btn tn-btn-green ds-btn-sm" data-ds-tip="Forçar cobrança hoje"><i data-lucide="banknote"></i> Cobrar</button>
                </form>
                <form method="POST" style="display:inline" data-ds-confirm="Pausar o autopay deste cliente?" data-ds-confirm-title="Pausar autopay" data-ds-confirm-tone="warning">
                    <?php if (function_exists('rp_csrf_field')) rp_csrf_field(); ?>
                    <input type="hidden" name="ap_acao" value="pausar">
                    <input type="hidden" name="ap_id" value="<?php echo $ap['id']; ?>">
                    <button type="submit" class="tn-btn tn-btn-amber ds-btn-sm" data-ds-tip="Pausar autopay"><i data-lucide="pause"></i></button>
                </form>
                <form method="POST" style="display:inline" data-ds-confirm="Cancelar o autopay deste cliente? O cartão será removido do Mercado Pago e o cliente terá que pagar manualmente." data-ds-confirm-title="Cancelar autopay">
                    <?php if (function_exists('rp_csrf_field')) rp_csrf_field(); ?>
                    <input type="hidden" name="ap_acao" value="cancelar">
                    <input type="hidden" name="ap_id" value="<?php echo $ap['id']; ?>">
                    <input type="hidden" name="ap_motivo" value="Cancelado pelo administrador">
                    <button type="submit" class="tn-btn tn-btn-red ds-btn-sm" data-ds-tip="Cancelar autopay"><i data-lucide="x"></i></button>
                </form>
            <?php elseif ($ap['status']==='pausado'): ?>
                <form method="POST" style="display:inline" data-ds-confirm="Reativar o autopay deste cliente?" data-ds-confirm-title="Reativar autopay" data-ds-confirm-tone="success">
                    <?php if (function_exists('rp_csrf_field')) rp_csrf_field(); ?>
                    <input type="hidden" name="ap_acao" value="reativar">
                    <input type="hidden" name="ap_id" value="<?php echo $ap['id']; ?>">
                    <button type="submit" class="tn-btn tn-btn-green ds-btn-sm"><i data-lucide="play"></i> Reativar</button>
                </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div>
</div>

<!-- ============ INFO BOX ============ -->
<div class="cfg-help">
    <strong>Como funciona o Autopay</strong><br>
    1. Cliente paga uma fatura com cartão e marca "Ativar pagamento automático"<br>
    2. O cartão fica salvo no Mercado Pago (tokenizado, sem armazenar dados sensíveis aqui)<br>
    3. O cron <code>cron_autopay.php</code> roda diariamente, cobra 5 dias antes do vencimento<br>
    4. Se falhar, tenta de novo no dia seguinte (máx. 3 tentativas)<br>
    5. Cliente recebe WhatsApp confirmando cada cobrança, com link para cancelar<br><br>
    <strong>Cron sugerido:</strong> <code>0 9 * * * /usr/bin/php /opt/mk-auth/admin/addons/cartao_credito/cron_autopay.php</code>
</div>

<script>
function apCopiarLink(url, btn) {
    navigator.clipboard.writeText(url).then(function(){
        var txt = btn.innerText;
        btn.innerText = '✓ Copiado!';
        btn.style.background = 'rgba(34,197,94,.15)';
        btn.style.color = '#22c55e';
        setTimeout(function(){
            btn.innerText = txt;
            btn.style.background = '';
            btn.style.color = '';
        }, 2000);
    }).catch(function(){
        prompt('Copie o link:', url);
    });
}

// Confirmação premium (dsModal) para forms com data-ds-confirm — preserva o mesmo fluxo de submit
if (!window.__dsConfirmBound) {
    window.__dsConfirmBound = true;
    document.addEventListener('submit', function (ev) {
        var f = ev.target;
        if (!f || !f.getAttribute || !f.getAttribute('data-ds-confirm')) return;
        if (!window.dsModal || !window.dsModal.confirm) return; // sem dsModal: segue o submit normal
        ev.preventDefault();
        window.dsModal.confirm({
            title: f.getAttribute('data-ds-confirm-title') || 'Confirmar ação',
            message: f.getAttribute('data-ds-confirm'),
            tone: f.getAttribute('data-ds-confirm-tone') || 'danger',
            okText: 'Confirmar',
            cancelText: 'Cancelar'
        }).then(function (ok) { if (ok) f.submit(); });
    }, true);
}
</script>
