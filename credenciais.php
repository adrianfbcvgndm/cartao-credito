<?php
/**
 * Cartão de Crédito · Credenciais do Mercado Pago e mensagens (09/10/2026)
 * Veio de Resumo_Pagamentos/configuracoes.php (cfg_tab=cartao, mp_sub=credenciais).
 */
if (empty($GLOBALS['rp_in_index'])) { header('Location: ./?tab=credenciais'); exit; }

$conn = rp_db_connect();
$conn->set_charset('utf8mb4');
$cc_msg = ''; $cc_msg_tipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['cfg_acao'] ?? '') === 'salvar_cartao') {
    if (function_exists('rp_csrf_check')) rp_csrf_check();
    $at = $conn->real_escape_string($_POST['access_token'] ?? '');
    $pk = $conn->real_escape_string($_POST['public_key'] ?? '');
    $ac = floatval(str_replace(',', '.', $_POST['acrescimo'] ?? '0'));
    $cp = $conn->real_escape_string($_POST['confirmacao_pagamento'] ?? '');
    $sp = $conn->real_escape_string($_POST['solicitacao_pagamento'] ?? '');
    $pr = $conn->real_escape_string($_POST['pagamento_recusado'] ?? '');
    $rc = $conn->query("SELECT COUNT(*) as c FROM integrar_cartao_mp");
    if ($rc && intval($rc->fetch_assoc()['c']) > 0) {
        $conn->query("UPDATE integrar_cartao_mp SET access_token='$at', public_key='$pk', acrescimo=$ac, confirmacao_pagamento='$cp', solicitacao_pagamento='$sp', pagamento_recusado='$pr'");
    } else {
        $conn->query("INSERT INTO integrar_cartao_mp (access_token,public_key,acrescimo,confirmacao_pagamento,solicitacao_pagamento,pagamento_recusado) VALUES ('$at','$pk',$ac,'$cp','$sp','$pr')");
    }
    $url_srv = $_POST['url_servidor'] ?? '';
    $stmtU = $conn->prepare("INSERT INTO addon_msg_config (chave, valor) VALUES ('url_servidor', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    $stmtU->bind_param('s', $url_srv); $stmtU->execute(); $stmtU->close();
    $cc_msg = 'Credenciais do Mercado Pago salvas.'; $cc_msg_tipo = 'success';
}

$cartao = ['access_token'=>'','public_key'=>'','acrescimo'=>'0','confirmacao_pagamento'=>'','solicitacao_pagamento'=>'','pagamento_recusado'=>''];
$rc = $conn->query("SELECT * FROM integrar_cartao_mp LIMIT 1");
if ($rc && $rc->num_rows > 0) $cartao = $rc->fetch_assoc();
$url_servidor = '';
$rc = $conn->query("SELECT valor FROM addon_msg_config WHERE chave='url_servidor' LIMIT 1");
if ($rc && ($x = $rc->fetch_assoc())) $url_servidor = (string)$x['valor'];
?>
<link rel="stylesheet" href="assets/ds/pages/config.css?v=20260922">

<?php if ($cc_msg): ?>
    <div class="ds-alert ds-alert-<?php echo $cc_msg_tipo; ?>" style="margin-bottom:14px"><?php echo htmlspecialchars($cc_msg); ?></div>
<?php endif; ?>

<form method="POST" action="?tab=credenciais">
    <?php if (function_exists('rp_csrf_field')) rp_csrf_field(); ?>
    <input type="hidden" name="cfg_acao" value="salvar_cartao">

    <div class="ds-form-section">
        <div class="ds-form-section-head">
            <i data-lucide="credit-card"></i>
            <div>
                <b>Credenciais Mercado Pago</b>
                <small>Chaves de produção usadas para processar pagamentos com cartão.</small>
            </div>
        </div>
        <div class="ds-form-section-body">
            <div class="cfg-grid">
                <div class="tn-fg cfg-span-full">
                    <label class="tn-fl">Access Token (privado)</label>
                    <textarea class="cfg-textarea" name="access_token" rows="3"><?php echo htmlspecialchars($cartao['access_token'] ?? ''); ?></textarea>
                    <span class="ds-help">Mantenha este token em sigilo: ele dá acesso total à sua conta Mercado Pago.</span>
                </div>
                <div class="tn-fg cfg-span-full">
                    <label class="tn-fl">Public Key</label>
                    <textarea class="cfg-textarea" name="public_key" rows="3"><?php echo htmlspecialchars($cartao['public_key'] ?? ''); ?></textarea>
                </div>
                <div class="tn-fg">
                    <label class="tn-fl">Acréscimo % (3x cartão)</label>
                    <input type="text" class="tn-input" name="acrescimo" value="<?php echo htmlspecialchars($cartao['acrescimo'] ?? '0'); ?>" placeholder="5.00">
                    <span class="ds-help">Percentual aplicado sobre o valor no parcelamento em 3x.</span>
                </div>
                <div class="tn-fg">
                    <label class="tn-fl">URL do servidor (callback)</label>
                    <div class="ds-input-icon"><i data-lucide="globe"></i>
                        <input type="text" class="tn-input" name="url_servidor" value="<?php echo htmlspecialchars($url_servidor); ?>" placeholder="https://seudominio.com.br">
                    </div>
                </div>
            </div>
            <div class="cfg-help">
                <strong>Como obter:</strong>
                acesse <a href="https://www.mercadopago.com.br/developers/panel/credentials" target="_blank">Mercado Pago Developers</a>
                e copie o <code>Access Token</code> e a <code>Public Key</code>. Use as credenciais de <strong>produção</strong> para receber pagamentos reais.
            </div>
        </div>
    </div>

    <div class="ds-form-section">
        <div class="ds-form-section-head">
            <i data-lucide="message-square"></i>
            <div>
                <b>Mensagens de pagamento (cartão)</b>
                <small>Textos enviados ao cliente em cada etapa da cobrança.</small>
            </div>
        </div>
        <div class="ds-form-section-body">
            <div class="tn-fg">
                <label class="tn-fl">Mensagem ao solicitar pagamento</label>
                <textarea class="cfg-textarea" name="solicitacao_pagamento" rows="4"><?php echo htmlspecialchars($cartao['solicitacao_pagamento'] ?? ''); ?></textarea>
            </div>
            <div class="tn-fg">
                <label class="tn-fl">Mensagem ao confirmar pagamento</label>
                <textarea class="cfg-textarea" name="confirmacao_pagamento" rows="4"><?php echo htmlspecialchars($cartao['confirmacao_pagamento'] ?? ''); ?></textarea>
            </div>
            <div class="tn-fg">
                <label class="tn-fl">Mensagem em caso de pagamento recusado</label>
                <textarea class="cfg-textarea" name="pagamento_recusado" rows="4"><?php echo htmlspecialchars($cartao['pagamento_recusado'] ?? ''); ?></textarea>
            </div>
        </div>
        <div class="ds-form-section-foot">
            <button type="submit" class="tn-btn tn-btn-primary"><i data-lucide="save"></i> Salvar credenciais</button>
        </div>
    </div>
</form>
