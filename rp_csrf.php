<?php
/**
 * ================================================================
 * CSRF Helper - protecao contra Cross-Site Request Forgery
 * ================================================================
 *
 * Uso em formularios POST:
 *
 *   <form method="POST" ...>
 *       <?php rp_csrf_field(); ?>
 *       ...
 *   </form>
 *
 * Uso no PHP que recebe o POST:
 *
 *   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 *       rp_csrf_check();  // mata o request se token invalido
 *       // ... resto da logica
 *   }
 *
 * ================================================================
 */

if (!function_exists('rp_csrf_token')) {
    /**
     * Retorna (ou gera) o token CSRF para a sessao atual.
     *
     * Como o MK-AUTH NAO usa $_SESSION nativo (usa cookies _login-MKA),
     * derivamos o token deterministicamente do hash da senha (que e
     * unico por sessao e muda quando o usuario faz logout/login).
     */
    function rp_csrf_token() {
        static $cached = null;
        if ($cached !== null) return $cached;

        // Pega o hash da senha do MK-AUTH (cookie _senha-MKA)
        $senha_hash = $_COOKIE['_senha-MKA'] ?? '';
        $login      = $_COOKIE['_login-MKA'] ?? '';

        // Se nao tem nenhum cookie (skip_auth ativo), gera um token
        // baseado no IP+UserAgent (menos seguro, mas funcional)
        if ($senha_hash === '' && $login === '') {
            $base = ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '');
        } else {
            $base = $login . '|' . $senha_hash;
        }

        // Sal local (do api_key se existir, senao um valor fixo)
        $sal = defined('RP_API_KEY_LOADED') && constant('RP_API_KEY_LOADED')
            ? rp_get_effective_api_key()
            : 'rp_csrf_v1';

        $cached = hash_hmac('sha256', $base, $sal);
        return $cached;
    }
}

if (!function_exists('rp_csrf_field')) {
    /**
     * Imprime o input hidden com o token CSRF.
     * Usar dentro de <form method="POST">.
     */
    function rp_csrf_field() {
        $token = rp_csrf_token();
        echo '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('rp_csrf_check')) {
    /**
     * Valida o token CSRF do POST. Se invalido, retorna erro 403 e sai.
     *
     * @param bool $strict Se true, exige token. Se false, so loga warning.
     */
    function rp_csrf_check($strict = true) {
        // Permite desligar via config (debug/dev)
        if (defined('RP_CSRF_OFF') && RP_CSRF_OFF === true) return true;

        // So checa em POST/PUT/DELETE
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) return true;

        $received = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expected = rp_csrf_token();

        if ($received === '' || !hash_equals($expected, $received)) {
            // Loga tentativa suspeita
            $logs_dir = __DIR__ . '/logs';
            @mkdir($logs_dir, 0775, true);
            $log = sprintf("[%s] CSRF FAIL: ip=%s ua=%s referer=%s method=%s uri=%s\n",
                date('Y-m-d H:i:s'),
                $_SERVER['REMOTE_ADDR'] ?? '-',
                substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 80),
                substr($_SERVER['HTTP_REFERER'] ?? '-', 0, 120),
                $method,
                $_SERVER['REQUEST_URI'] ?? '-'
            );
            @file_put_contents($logs_dir . '/csrf_fail.log', $log, FILE_APPEND);

            if (!$strict) return false;

            http_response_code(403);
            if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'json') !== false ||
                !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'erro' => 'Token CSRF invalido. Recarregue a pagina e tente novamente.']);
            } else {
                echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Acesso Negado</title>';
                echo '<style>body{font-family:system-ui;background:#fef2f2;color:#991b1b;padding:40px;text-align:center}</style></head>';
                echo '<body><h1>🛑 Acesso Negado</h1>';
                echo '<p><strong>Token de seguranca invalido.</strong></p>';
                echo '<p>Isso pode acontecer se voce ficou muito tempo na pagina sem usar, ou se o link veio de outro site.</p>';
                echo '<p><a href="javascript:history.back()">← Voltar</a> e tentar novamente.</p></body></html>';
            }
            exit;
        }
        return true;
    }
}

// Marca a constante pra outros arquivos saberem que ja carregamos
if (!defined('RP_CSRF_LOADED')) define('RP_CSRF_LOADED', true);
