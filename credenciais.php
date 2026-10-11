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
    /* 11/10/2026: assinatura do webhook e Telegram pela tela (antes só editando rp_config_local.php / pelo Resumo) */
    $cc_set = function ($k, $v) use ($conn) { $st = $conn->prepare("INSERT INTO addon_msg_config (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)"); $st->bind_param('ss', $k, $v); $st->execute(); $st->close(); };
    if (trim($_POST['mp_webhook_secret'] ?? '') !== '') $cc_set('mp_webhook_secret', trim($_POST['mp_webhook_secret']));   // vazio = mantém a atual
    if (isset($_POST['tg_bot_token'])) $cc_set('tg_bot_token', trim($_POST['tg_bot_token']));
    if (isset($_POST['cartao_nome'])) $cc_set('cartao_nome', trim($_POST['cartao_nome']));
    if (isset($_POST['taxa_1x_percent'])) $cc_set('taxa_1x_percent', str_replace(',', '.', trim($_POST['taxa_1x_percent'])));
    if (isset($_POST['tg_chat_id'])) $cc_set('tg_chat_id', trim($_POST['tg_chat_id']));
    $cc_msg = 'Credenciais do Mercado Pago salvas.'; $cc_msg_tipo = 'success';
}

$cartao = ['access_token'=>'','public_key'=>'','acrescimo'=>'0','confirmacao_pagamento'=>'','solicitacao_pagamento'=>'','pagamento_recusado'=>''];
$rc = $conn->query("SELECT * FROM integrar_cartao_mp LIMIT 1");
if ($rc && $rc->num_rows > 0) $cartao = $rc->fetch_assoc();
$url_servidor = '';
$rc = $conn->query("SELECT valor FROM addon_msg_config WHERE chave='url_servidor' LIMIT 1");
if ($rc && ($x = $rc->fetch_assoc())) $url_servidor = (string)$x['valor'];
$cc_cfg = [];
$rc = $conn->query("SELECT chave, valor FROM addon_msg_config WHERE chave IN ('mp_webhook_secret','tg_bot_token','tg_chat_id','cartao_nome','taxa_1x_percent')");
$cc_nome_mk = ''; $rp9 = $conn->query("SELECT nome FROM sis_provedor ORDER BY id LIMIT 1"); if ($rp9 && ($x9 = $rp9->fetch_assoc())) $cc_nome_mk = trim((string)$x9['nome']);
if ($rc) while ($x = $rc->fetch_assoc()) $cc_cfg[$x['chave']] = (string)$x['valor'];
$cc_secret_ok = RP_MP_WEBHOOK_SECRET !== '' || trim($cc_cfg['mp_webhook_secret'] ?? '') !== '';   // conta também o que acabou de ser salvo
$cc_secret_local = is_file(__DIR__ . '/rp_config_local.php') && ($cc_cfg['mp_webhook_secret'] ?? '') === '' && $cc_secret_ok;
$cc_webhook_url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'SEU-DOMINIO') . '/cartao/webhook_mp.php';
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
                    <label class="tn-fl">Acréscimo no parcelado (%)</label>
                    <input type="text" class="tn-input" name="acrescimo" value="<?php echo htmlspecialchars($cartao['acrescimo'] ?? '0'); ?>" placeholder="5.00">
                    <span class="ds-help">Somado no <b>2x</b>; no <b>3x</b> é o dobro. Ex.: 5 → 2x com +5%, 3x com +10%. Use 0 para parcelar sem acréscimo.</span>
                </div>
                <div class="tn-fg">
                    <label class="tn-fl">Taxa no cartão à vista, 1x (%)</label>
                    <input type="text" class="tn-input" name="taxa_1x_percent" value="<?php echo htmlspecialchars($cc_cfg['taxa_1x_percent'] ?? ''); ?>" placeholder="5 (padrão)">
                    <span class="ds-help">Somada quando o cliente paga 1x no cartão (aparece como "Taxa do cartão"). Vazio = 5%. Use 0 para não cobrar.</span>
                </div>
                <div class="tn-fg">
                    <label class="tn-fl">URL do servidor (callback)</label>
                    <div class="ds-input-icon"><i data-lucide="globe"></i>
                        <input type="text" class="tn-input" name="url_servidor" value="<?php echo htmlspecialchars($url_servidor); ?>" placeholder="https://seudominio.com.br">
                    </div>
                    <span class="ds-help">Endereço https do seu MK-Auth. O débito automático usa para o aviso de pagamento e o link de gerenciar.</span>
                </div>
                <div class="tn-fg">
                    <label class="tn-fl">Nome que o cliente vê</label>
                    <input type="text" class="tn-input" name="cartao_nome" value="<?php echo htmlspecialchars($cc_cfg['cartao_nome'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars($cc_nome_mk ?: 'nome do provedor'); ?>" maxlength="60">
                    <span class="ds-help">Aparece no topo da página de pagamento e na fatura do cartão (cobrança automática). Vazio = nome do provedor no MK-Auth.</span>
                </div>
            </div>
            <div class="tn-fg" style="margin-top:12px">
                <label class="tn-fl">Assinatura secreta do webhook <?php echo $cc_secret_ok ? '<span style="color:#0f7a53">✓ configurada</span>' : '<span style="color:#b42318">✗ falta (a fatura não baixa sozinha)</span>'; ?></label>
                <input type="password" class="tn-input" name="mp_webhook_secret" value="" autocomplete="new-password" placeholder="<?php echo $cc_secret_ok ? ($cc_secret_local ? 'vem do rp_config_local.php (deixe vazio para manter)' : 'deixe vazio para manter a atual') : 'cole a assinatura secreta do Mercado Pago'; ?>">
                <span class="ds-help">Mercado Pago &gt; Suas integrações &gt; sua aplicação &gt; <b>Webhooks</b>. URL de produção: <code><?php echo htmlspecialchars($cc_webhook_url); ?></code>, evento <b>Pagamentos</b>. Depois copie a <b>assinatura secreta</b> e cole aqui.</span>
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
            <i data-lucide="send"></i>
            <div>
                <b>Alertas no Telegram (opcional)</b>
                <small>Avisa quando o webhook do Mercado Pago recusar um aviso ou algo der errado.</small>
            </div>
        </div>
        <div class="ds-form-section-body">
            <div class="cfg-grid">
                <div class="tn-fg">
                    <label class="tn-fl">Token do bot</label>
                    <input type="text" class="tn-input" name="tg_bot_token" value="<?php echo htmlspecialchars($cc_cfg['tg_bot_token'] ?? ''); ?>" placeholder="123456789:ABC...">
                </div>
                <div class="tn-fg">
                    <label class="tn-fl">ID do grupo</label>
                    <input type="text" class="tn-input" name="tg_chat_id" value="<?php echo htmlspecialchars($cc_cfg['tg_chat_id'] ?? ''); ?>" placeholder="-100...">
                </div>
            </div>
            <div class="cfg-help">Crie o bot com o <b>@BotFather</b>, ponha ele num grupo e pegue o ID do grupo em <code>https://api.telegram.org/botSEU_TOKEN/getUpdates</code>. É o mesmo Telegram usado pelo Resumo de Pagamentos, se você tiver.</div>
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
