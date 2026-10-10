<?php
/**
 * ================================================================
 * Alertas Telegram
 * ================================================================
 *
 * Helper para enviar alertas criticos do sistema via Telegram.
 * Usa o token/chat_id ja configurados em addon_msg_config.
 *
 * Uso:
 *   rp_alertar_telegram('Webhook MP rejeitado - secret invalido', 'critico');
 *   rp_alertar_telegram('Cron rodou OK', 'info');
 *
 * Niveis: 'critico' (sempre envia) | 'aviso' | 'info' (so se configurado)
 *
 * Inclui anti-spam: nao envia o mesmo alerta 2x em menos de 5 min.
 * ================================================================
 */

if (!function_exists('rp_alertar_telegram')) {
    /**
     * Envia alerta para o Telegram do admin.
     */
    function rp_alertar_telegram($mensagem, $nivel = 'aviso', $extras = []) {
        // Anti-spam: dedupe por 5min
        $dedupe_key = md5($nivel . '|' . $mensagem);
        $cache_file = sys_get_temp_dir() . '/rp_tg_' . $dedupe_key . '.lock';
        if (is_file($cache_file)) {
            $age = time() - @filemtime($cache_file);
            if ($age < 300) return ['ok' => false, 'erro' => 'dedupe (alerta repetido em <5min)'];
        }
        @touch($cache_file);

        // Pega config do Telegram do banco
        $conn = @$GLOBALS['LOADMYSQL'] ?? null;
        if (!$conn || !method_exists($conn, 'query')) {
            if (function_exists('rp_db_connect')) {
                $conn = rp_db_connect();
                if ($conn && $conn->connect_errno) return ['ok' => false, 'erro' => 'sem conexao bd'];
                if ($conn) $conn->set_charset('utf8mb4');
            }
        }
        if (!$conn) return ['ok' => false, 'erro' => 'sem conexao bd'];

        // Verifica se a tabela existe (em algumas instalacoes ainda nao foi criada)
        $rcheck = @$conn->query("SHOW TABLES LIKE 'addon_msg_config'");
        if (!$rcheck || $rcheck->num_rows === 0) {
            return ['ok' => false, 'erro' => 'addon_msg_config nao existe'];
        }

        $r = $conn->query("SELECT * FROM addon_msg_config LIMIT 1");
        if (!$r || $r->num_rows === 0) return ['ok' => false, 'erro' => 'sem config'];
        $cfg = $r->fetch_assoc();

        $token = $cfg['telegram_token'] ?? '';
        $chat_id = $cfg['telegram_chat_id'] ?? '';

        if (empty($token) || empty($chat_id)) {
            return ['ok' => false, 'erro' => 'telegram nao configurado'];
        }

        // Monta mensagem com emoji por nivel
        $emoji = match($nivel) {
            'critico' => '🚨',
            'erro'    => '❌',
            'aviso'   => '⚠️',
            'info'    => 'ℹ️',
            'ok'      => '✅',
            default   => '📢',
        };
        $titulo = match($nivel) {
            'critico' => '*ALERTA CRITICO*',
            'erro'    => '*Erro*',
            'aviso'   => '*Aviso*',
            'info'    => '*Info*',
            'ok'      => '*OK*',
            default   => '*Sistema*',
        };

        $host = $_SERVER['HTTP_HOST'] ?? gethostname();
        $texto = "{$emoji} {$titulo}\n";
        $texto .= "_{$host}_\n";
        $texto .= "\n" . $mensagem . "\n";

        if (!empty($extras)) {
            $texto .= "\n*Detalhes:*\n";
            foreach ($extras as $k => $v) {
                $texto .= "• `" . str_replace('`', "'", (string)$k) . "`: " . str_replace('`', "'", (string)$v) . "\n";
            }
        }

        $texto .= "\n_" . date('d/m/Y H:i:s') . "_";

        // Envia
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = http_build_query([
            'chat_id' => $chat_id,
            'text' => $texto,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $ok = ($http === 200);

        // Loga
        $logs_dir = __DIR__ . '/logs';
        @mkdir($logs_dir, 0775, true);
        $log_line = sprintf("[%s] nivel=%s http=%d msg=%s%s\n",
            date('Y-m-d H:i:s'), $nivel, $http,
            substr($mensagem, 0, 100),
            $err ? " curl_err=$err" : ''
        );
        @file_put_contents($logs_dir . '/alertas_telegram.log', $log_line, FILE_APPEND);

        return [
            'ok' => $ok,
            'http' => $http,
            'erro' => $err ?: ($ok ? null : 'http != 200'),
        ];
    }
}
