<?php
/**
 * ================================================================
 * Pagina PUBLICA - Cliente gerencia seu autopay
 * ================================================================
 *
 * Acesso: ?t=TOKEN_DE_64_CHARS
 *
 * Cliente pode ver dados, ver historico, cancelar.
 * NAO requer login MK-AUTH.
 * ================================================================
 */

require_once __DIR__ . '/rp_config.php';
$conn = rp_db_connect();
$conn->set_charset('utf8mb4');

$token = $_GET['t'] ?? '';

if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
    http_response_code(404);
    die('Link inválido.');
}

$ts = $conn->real_escape_string($token);
$r = $conn->query("SELECT * FROM addon_autopay_clientes WHERE token_gerencia='$ts' LIMIT 1");
if (!$r || $r->num_rows === 0) {
    http_response_code(404);
    die('Link expirado ou inválido.');
}
$ap = $r->fetch_assoc();

// Busca cliente
$lc = $conn->real_escape_string($ap['login']);
$r = $conn->query("SELECT nome FROM sis_cliente WHERE login='$lc' LIMIT 1");
$cliente_nome = $r ? ($r->fetch_assoc()['nome'] ?? $ap['login']) : $ap['login'];

// Acao: cancelar
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cancelar') {
    $motivo = trim($_POST['motivo'] ?? 'Cancelado pelo cliente');
    $motivo_safe = $conn->real_escape_string(substr($motivo, 0, 255));
    $id_ap = (int)$ap['id'];

    $conn->query("UPDATE addon_autopay_clientes
                  SET status='cancelado', cancelado_em=NOW(),
                      cancelado_motivo='$motivo_safe', cancelado_por='cliente'
                  WHERE id=$id_ap");

    // Remove cartao do MP (opcional)
    $hlp = __DIR__ . '/autopay_helper.php';
    if (is_file($hlp)) {
        require_once $hlp;
        @autopay_remover_cartao($conn, $ap['mp_customer_id'], $ap['mp_card_id']);
    }

    @file_put_contents(__DIR__ . '/logs/autopay.log',
        '[' . date('Y-m-d H:i:s') . '] CANCELADO pelo cliente: ' . $ap['login'] . ' (motivo: ' . $motivo . ")\n",
        FILE_APPEND);

    // Refresh ap
    $r = $conn->query("SELECT * FROM addon_autopay_clientes WHERE token_gerencia='$ts' LIMIT 1");
    $ap = $r->fetch_assoc();
    $msg = 'cancelado';
}

// Historico de cobrancas
$historico = [];
$r = $conn->query("SELECT * FROM addon_autopay_log WHERE autopay_id=" . (int)$ap['id'] . " ORDER BY tentativa_em DESC LIMIT 20");
if ($r) while ($row = $r->fetch_assoc()) $historico[] = $row;

?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pagamento Automático - <?php echo htmlspecialchars($cliente_nome); ?></title>
<link rel="stylesheet" href="assets/ds/ds.css?v=20260922">
<link rel="stylesheet" href="assets/ds/pages/pagamento.css?v=20260922">
<script src="assets/vendor/lucide.min.js"></script>
</head><body class="pg-body">

<div class="tn-scope pg-public">
<div class="pg-shell pg-shell-narrow">

    <!-- CABEÇALHO PÚBLICO -->
    <header class="pg-topbar ds-anim-up">
        <div class="pg-brand">
            <div class="pg-brand-fallback"><i data-lucide="zap"></i></div>
            <div class="pg-brand-text">
                <div class="pg-brand-name">Pagamento automático</div>
                <div class="pg-brand-host"><?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? ''); ?></div>
            </div>
        </div>
        <div class="pg-secure-badge">
            <i data-lucide="shield-check"></i>
            <span>Pagamento seguro</span>
        </div>
    </header>

    <div class="pg-ap-card ds-anim-up">
        <div class="pg-ap-head">
            <div class="pg-ap-head-icon"><i data-lucide="repeat"></i></div>
            <div>
                <h1 class="pg-ap-title">Pagamento Automático</h1>
                <div class="pg-ap-sub">Olá, <strong><?php echo htmlspecialchars($cliente_nome); ?></strong>!</div>
            </div>
        </div>

        <?php if ($msg === 'cancelado'): ?>
            <div class="pg-ap-alert-ok">
                <i data-lucide="check-circle-2"></i>
                <span>
                    <strong>Pagamento automático cancelado com sucesso!</strong>
                    <small>Você não será mais cobrado automaticamente. Pode pagar suas próximas faturas manualmente quando quiser.</small>
                </span>
            </div>
        <?php endif; ?>

        <!-- Status -->
        <div class="pg-ap-row">
            <span>Status</span>
            <strong>
                <?php
                $statusMap = [
                    'ativo'      => ['ok',    'check',          'Ativo'],
                    'pausado'    => ['warn',  'pause',          'Pausado'],
                    'cancelado'  => ['err',   'x',              'Cancelado'],
                    'expirado'   => ['muted', 'clock',          'Expirado'],
                    'falha_max'  => ['err',   'alert-triangle', 'Falhas máx.'],
                ];
                $s = $statusMap[$ap['status']] ?? ['info', 'info', $ap['status']];
                ?>
                <span class="pg-ap-badge <?php echo $s[0]; ?>"><i data-lucide="<?php echo $s[1]; ?>"></i><?php echo htmlspecialchars($s[2]); ?></span>
            </strong>
        </div>

        <!-- Cartao -->
        <div class="pg-ap-row">
            <span>Cartão</span>
            <strong>
                <?php echo strtoupper(htmlspecialchars($ap['cartao_bandeira'] ?? 'Cartão')); ?>
                •••• <?php echo htmlspecialchars($ap['cartao_final']); ?>
            </strong>
        </div>

        <!-- Validade -->
        <div class="pg-ap-row">
            <span>Validade</span>
            <strong><?php echo htmlspecialchars($ap['cartao_validade'] ?? '—'); ?></strong>
        </div>

        <!-- Email -->
        <div class="pg-ap-row">
            <span>E-mail</span>
            <strong style="font-size:12px"><?php echo htmlspecialchars($ap['email_pagador'] ?? '—'); ?></strong>
        </div>

        <!-- Duracao -->
        <div class="pg-ap-row">
            <span>Duração</span>
            <strong>
                <?php if ($ap['meses_total'] == 0): ?>
                    Indefinida
                <?php else:
                    $restantes = max(0, $ap['meses_total'] - $ap['meses_executados']);
                ?>
                    <?php echo $ap['meses_executados']; ?> / <?php echo $ap['meses_total']; ?> meses
                    <small>(<?php echo $restantes; ?> restantes)</small>
                <?php endif; ?>
            </strong>
        </div>

        <!-- Proxima cobranca -->
        <?php if ($ap['status'] === 'ativo' && $ap['proxima_cobranca']):
            $dias = floor((strtotime($ap['proxima_cobranca']) - time()) / 86400);
        ?>
        <div class="pg-ap-row">
            <span>Próxima cobrança</span>
            <strong>
                <?php echo date('d/m/Y', strtotime($ap['proxima_cobranca'])); ?>
                <small>(em <?php echo $dias; ?> dias)</small>
            </strong>
        </div>
        <?php endif; ?>

        <!-- Falhas -->
        <?php if ($ap['falhas_consecutivas'] > 0): ?>
        <div class="pg-ap-falhas">
            <span><i data-lucide="alert-triangle"></i>Tentativas falhas</span>
            <strong><?php echo $ap['falhas_consecutivas']; ?></strong>
        </div>
        <?php endif; ?>

        <!-- Acoes -->
        <?php if ($ap['status'] === 'ativo'): ?>
            <div class="pg-ap-cancel">
                <h3 class="pg-ap-cancel-title"><i data-lucide="circle-x"></i>Cancelar pagamento automático</h3>
                <p class="pg-ap-cancel-desc">
                    Você não será mais cobrado automaticamente. As próximas faturas terão que ser pagas manualmente.
                </p>
                <form method="POST" onsubmit="return confirm('Tem certeza que quer cancelar o débito automático?');">
                    <textarea name="motivo" rows="2" placeholder="Motivo (opcional)"></textarea>
                    <input type="hidden" name="acao" value="cancelar">
                    <button type="submit" class="pg-ap-btn-danger"><i data-lucide="trash-2"></i>Cancelar</button>
                </form>
            </div>
        <?php else: ?>
            <div class="pg-ap-reativar">
                <p style="margin:0">
                    Para reativar o pagamento automático, faça um novo pagamento via cartão e marque a opção <strong>"Ativar pagamento automático"</strong>.
                </p>
            </div>
        <?php endif; ?>

        <!-- Historico -->
        <?php if (!empty($historico)): ?>
        <div class="pg-ap-history">
            <h3 class="pg-ap-history-title"><i data-lucide="history"></i>Últimas cobranças</h3>
            <?php foreach ($historico as $h):
                $statCls = $h['status'] === 'aprovado' ? 'ok' : 'err';
            ?>
            <div class="pg-ap-history-item">
                <span><?php echo date('d/m/Y H:i', strtotime($h['tentativa_em'])); ?></span>
                <span class="pg-ap-history-val <?php echo $statCls; ?>">
                    R$ <?php echo number_format($h['valor'], 2, ',', '.'); ?> · <?php echo htmlspecialchars($h['status']); ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="pg-ap-footer">
        <i data-lucide="lock"></i>
        <span>Seus dados estão seguros. Cartão criptografado pelo Mercado Pago.</span>
    </div>

</div><!-- /.pg-shell -->
</div><!-- /.tn-scope -->

<script src="assets/ds/js/ds.js?v=20260922"></script>
<script src="assets/ds/js/toast.js?v=20260922"></script>
</body></html>
