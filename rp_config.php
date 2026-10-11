<?php
/**
 * Configuracao central do addon Resumo_Pagamentos.
 * Mantem os mesmos dados padrao do MK-AUTH para nao quebrar instalacoes existentes.
 * Para customizar, crie rp_config_local.php neste mesmo diretorio retornando um array.
 */
if (!defined('RP_DB_HOST')) {
    $local = __DIR__ . '/rp_config_local.php';
    $cfg = is_file($local) ? require $local : [];

    define('RP_DB_HOST', $cfg['db_host'] ?? '127.0.0.1');
    define('RP_DB_USER', $cfg['db_user'] ?? 'root');
    define('RP_DB_PASS', $cfg['db_pass'] ?? 'vertrigo');
    define('RP_DB_NAME', $cfg['db_name'] ?? 'mkradius');

    // API Key para chamadas externas (api_enviar_whatsapp.php)
    // Se vazio, e gerado automaticamente na primeira execucao
    // (pra nao deixar a API exposta)
    define('RP_API_KEY', $cfg['api_key'] ?? '');

    // Segredo do webhook do Mercado Pago. OBRIGATORIO desde v8.0.
    // Pegue em: https://www.mercadopago.com.br/developers/panel/webhooks
    /* 11/10/2026: a assinatura também pode ser colada na tela Credenciais (addon_msg_config mp_webhook_secret),
       sem precisar editar arquivo. O rp_config_local.php continua tendo prioridade. */
    $rp_sec = trim((string)($cfg['mp_webhook_secret'] ?? ''));
    if ($rp_sec === '' || strpos($rp_sec, 'COLE_AQUI') === 0) {
        $rp_sec = '';
        try {
            $c0 = @new mysqli(RP_DB_HOST, RP_DB_USER, RP_DB_PASS, RP_DB_NAME);
            if ($c0 && !$c0->connect_errno) {
                $r0 = @$c0->query("SELECT valor FROM addon_msg_config WHERE chave='mp_webhook_secret' LIMIT 1");
                if ($r0 && ($x0 = $r0->fetch_assoc())) $rp_sec = trim((string)$x0['valor']);
                $c0->close();
            }
        } catch (\Throwable $e) {}
    }
    define('RP_MP_WEBHOOK_SECRET', $rp_sec);

    // SOMENTE PARA DEBUG/DEV: pula a checagem de login do MK-AUTH.
    // NUNCA habilite isso em producao - deixa o painel administrativo aberto!
    define('RP_SKIP_AUTH', isset($cfg['skip_auth']) && $cfg['skip_auth'] === true);

    // Caminho para auto-armazenamento da api_key gerada (gravavel pelo PHP)
    define('RP_AUTOKEY_FILE', __DIR__ . '/.rp_autokey');
}

function rp_db_connect() {
    $conn = @new mysqli(RP_DB_HOST, RP_DB_USER, RP_DB_PASS, RP_DB_NAME);
    if ($conn && !$conn->connect_errno) {
        @$conn->set_charset('utf8mb4');
    }
    return $conn;
}

function rp_db_connect_procedural() {
    $conn = @mysqli_connect(RP_DB_HOST, RP_DB_USER, RP_DB_PASS, RP_DB_NAME);
    if ($conn) {
        @mysqli_set_charset($conn, 'utf8mb4');
    }
    return $conn;
}

/**
 * Retorna a API Key efetiva. Se nao tiver configurada via rp_config_local,
 * gera automaticamente uma chave forte e salva em arquivo (.rp_autokey).
 * Garante que a API NUNCA fica aberta sem chave.
 */
function rp_get_effective_api_key() {
    if (defined('RP_API_KEY') && RP_API_KEY !== '') return RP_API_KEY;

    // Tenta carregar chave auto-gerada
    if (defined('RP_AUTOKEY_FILE') && is_file(RP_AUTOKEY_FILE)) {
        $key = trim(@file_get_contents(RP_AUTOKEY_FILE));
        if (strlen($key) >= 32) return $key;
    }

    // Gera nova
    try {
        $key = bin2hex(random_bytes(32)); // 64 chars
    } catch (Exception $e) {
        $key = sha1(uniqid('rp', true) . microtime(true) . mt_rand());
    }

    if (defined('RP_AUTOKEY_FILE')) {
        @file_put_contents(RP_AUTOKEY_FILE, $key);
        @chmod(RP_AUTOKEY_FILE, 0600);
    }
    return $key;
}

/**
 * v8.1: agora SEMPRE exige api_key para chamadas externas.
 * A chave e gerada automaticamente se nao tiver configurada,
 * mas chamadas internas sao permitidas em 2 casos:
 *  - sessao $_SESSION ativa (versao futura do MK-AUTH)
 *  - cookies _login-MKA + _senha-MKA + _admin-*-MKA (MK-AUTH atual)
 */
function rp_require_api_key_if_configured() {
    // 1) Tenta detectar login via cookies do MK-AUTH (mais comum)
    //
    // O MK-AUTH usa varios cookies de auth, mas eles podem variar
    // dependendo da pagina/contexto:
    //   _login-MKA      = nome do usuario (nem sempre presente!)
    //   _senha-MKA      = hash da senha (nem sempre presente!)
    //   _admin-*-MKA    = TOKEN PERSISTENTE - SO existe quando logado como admin
    //   MKASESSID       = ID da sessao
    //   campoCLI        = contexto atual (login, nome, etc)
    //
    // Estrategia: se tem o cookie _admin-*-MKA, esta logado como admin.
    // Esse cookie e setado no login e so e removido no logout.
    $cookies = $_COOKIE ?? [];

    // CHECK PRIMARIO: cookie _admin-*-MKA (mais confiavel)
    foreach ($cookies as $name => $value) {
        if (preg_match('/^_admin-.+-MKA$/i', $name) && !empty($value)) {
            return; // logado como admin, libera
        }
    }

    // CHECK SECUNDARIO: _login + _senha (alternativo)
    if (!empty($cookies['_login-MKA']) && !empty($cookies['_senha-MKA'])) {
        return;
    }

    // 2) Fallback: tenta sessao $_SESSION
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $tem_sessao = !empty($_SESSION['logado']) || !empty($_SESSION['login']) ||
                  !empty($_SESSION['usuario']) || !empty($_SESSION['user']) ||
                  !empty($_SESSION['nivel']) || !empty($_SESSION['admin']);
    if ($tem_sessao) return;

    // 3) Senao, exige a chave (chamada externa)
    // SOMENTE via header X-API-Key (nunca por GET para nao vazar em logs/referer)
    $api_key = rp_get_effective_api_key();
    $received = $_SERVER['HTTP_X_API_KEY'] ?? '';
    // Backward compat: aceita POST tambem mas avisa no log
    if (!$received && !empty($_POST['api_key'])) {
        $received = $_POST['api_key'];
        @rp_safe_log(__DIR__ . '/logs/seguranca.log',
            'AVISO: api_key recebida via POST (use HEADER X-API-Key). ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    }
    // BLOQUEIA api_key via GET (vaza em log/referer/historico)
    if (!empty($_GET['api_key'])) {
        @rp_safe_log(__DIR__ . '/logs/seguranca.log',
            '🔴 BLOQUEADO: tentativa de api_key via GET (PROIBIDO). ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'erro'=>'api_key via GET nao eh permitido. Use o cabecalho X-API-Key.']);
        exit;
    }
    if (!hash_equals($api_key, (string)$received)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'erro'=>'API key obrigatoria. Use o cabecalho X-API-Key.']);
        exit;
    }
}

function rp_safe_log($file, $message) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // Mascara dados sensiveis (api keys, tokens, senhas, telefones)
    $message = preg_replace('/(access_token|token|senha|password|Authorization|api_key|api-key|secret)(["\'\s:=]+)[^,"\'\s&]+/i', '$1$2***', (string)$message);
    // Mascara telefones brasileiros (mantem so DDD)
    $message = preg_replace('/(\b55)?(\d{2})\d{4,5}\d{4}\b/', '$1$2*****', $message);
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND);
}

// Carrega helper CSRF (rp_csrf_field, rp_csrf_check, rp_csrf_token)
if (!defined('RP_CSRF_LOADED')) {
    $_csrf_file = __DIR__ . '/rp_csrf.php';
    if (is_file($_csrf_file)) require_once $_csrf_file;
}

// Carrega helper de Alertas Telegram (rp_alertar_telegram)
$_alertas_file = __DIR__ . '/rp_alertas.php';
if (is_file($_alertas_file)) require_once $_alertas_file;

// Carrega helper unificado de WhatsApp (Evotrix + Evolution)
$_wa_file = __DIR__ . '/rp_wa_sender.php';
if (is_file($_wa_file)) require_once $_wa_file;
