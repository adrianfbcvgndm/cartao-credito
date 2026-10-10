<?php
/* rp_bolix.php — Pix EMBUTIDO no boleto Efí (Bolix) de uma fatura (03/10/2026).
   Mesma lógica da página de corte (/opt/mk-auth/paginas/corte/rxcorte.php): sis_lanc.chave_gnet2 = id da
   cobrança na Efí; credenciais do sis_boleto DA CONTA DA FATURA (numconta). Pagar esse Pix quita o boleto e a
   Efí avisa o MK-Auth (url.retorno) — baixa automática. Cache no temp do PHP (o FPM não grava em /var/cache). */
if (!function_exists('rp_bolix_pix')) {
    function rp_bolix_token($conn, int $conta, string $cache): string {
        $f = "$cache/efi_token_$conta.json";
        $t = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        if (is_array($t) && ($t['exp'] ?? 0) > time() + 60) return (string)$t['tok'];
        $st = mysqli_prepare($conn, "SELECT cliente_id_gnet, cliente_secret_gnet FROM sis_boleto WHERE id=? AND cliente_id_gnet<>''");
        if (!$st) return '';
        mysqli_stmt_bind_param($st, 'i', $conta); mysqli_stmt_execute($st);
        $b = mysqli_fetch_assoc(mysqli_stmt_get_result($st)); if (!$b) return '';
        $c = curl_init('https://cobrancas.api.efipay.com.br/v1/authorize');
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_POST => 1, CURLOPT_TIMEOUT => 15, CURLOPT_USERPWD => $b['cliente_id_gnet'] . ':' . $b['cliente_secret_gnet'],
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => '{"grant_type":"client_credentials"}']);
        $r = json_decode((string)curl_exec($c), true); curl_close($c);
        if (empty($r['access_token'])) return '';
        @mkdir($cache, 0750, true);
        @file_put_contents($f, json_encode(['tok' => $r['access_token'], 'exp' => time() + (int)($r['expires_in'] ?? 600)]));
        return (string)$r['access_token'];
    }
    /** ['payload'=>copia-e-cola, 'img'=>data:image/svg..., 'status'=>...] ou [] */
    function rp_bolix_pix($conn, array $fatura): array {
        $chg = preg_replace('/\D/', '', (string)($fatura['chave_gnet2'] ?? ''));
        if ($chg === '') return [];
        $cache = sys_get_temp_dir() . '/rxcorte';
        $f = "$cache/chg_$chg.json";
        if (is_file($f) && time() - filemtime($f) < 600) { $p = json_decode((string)@file_get_contents($f), true); if (is_array($p)) return $p; }
        $tok = rp_bolix_token($conn, (int)($fatura['numconta'] ?? 0), $cache); if ($tok === '') return [];
        $c = curl_init("https://cobrancas.api.efipay.com.br/v1/charge/$chg");
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ["Authorization: Bearer $tok"]]);
        $r = json_decode((string)curl_exec($c), true); curl_close($c);
        $d = $r['data'] ?? []; $bb = $d['payment']['banking_billet'] ?? [];
        $p = [];
        if (!empty($bb['pix']['qrcode'])) $p = ['payload' => (string)$bb['pix']['qrcode'], 'img' => (string)($bb['pix']['qrcode_image'] ?? ''),
                                               'barras' => (string)($bb['barcode'] ?? ''), 'link' => (string)($bb['link'] ?? ''), 'efi' => (string)($d['status'] ?? '')];
        if ($p) { @mkdir($cache, 0750, true); @file_put_contents($f, json_encode($p)); }
        return $p;
    }
}
