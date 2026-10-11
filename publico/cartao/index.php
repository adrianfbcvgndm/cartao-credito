<?php
/**
 * Redirecionador curto para pagamento com cartão.
 *
 * Instalar em:
 * /var/www/cartao/index.php
 *
 * Links aceitos:
 * https://SEU-DOMINIO/cartao?id=22617
 * https://SEU-DOMINIO/cartao/?id=22617
 * https://SEU-DOMINIO/cartao?uuid=UUID
 * https://SEU-DOMINIO/cartao/?cartao=22617
 */

$id = '';

foreach (['id', 'uuid', 'uuid_lanc', 'cartao'] as $param) {
    if (isset($_GET[$param]) && trim((string)$_GET[$param]) !== '' && trim((string)$_GET[$param]) !== '1') {
        $id = trim((string)$_GET[$param]);
        break;
    }
}

// Também aceita formato /cartao/22617 se o .htaccess estiver ativo
if ($id === '' && !empty($_SERVER['PATH_INFO'])) {
    $id = trim($_SERVER['PATH_INFO'], '/');
}

if ($id === '') {
    http_response_code(400);
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Link inválido</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f4f7fb;color:#172033;margin:0;padding:28px}
.box{max-width:560px;margin:8vh auto;background:#fff;border:1px solid #dde6f0;border-radius:16px;padding:26px;text-align:center;box-shadow:0 10px 30px rgba(15,23,42,.08)}
h1{font-size:24px;margin:0 0 10px} p{color:#526174;line-height:1.5}
code{background:#f1f5f9;padding:3px 7px;border-radius:6px}
</style>
</head>
<body>
<div class="box">
<h1>Link de pagamento inválido</h1>
<p>O link precisa conter o ID ou UUID da fatura.</p>
<p>Use: <code>/cartao?id=22617</code> ou <code>/cartao/?id=22617</code></p>
</div>
</body>
</html>
    <?php
    exit;
}

// Permite números e UUIDs com letras, números, hífen e underscore
$id_safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);

if ($id_safe === '') {
    http_response_code(400);
    exit('ID inválido.');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$destino = $scheme . '://' . $host . '/admin/addons/cartao_credito/pagamento.php?id=' . urlencode($id_safe);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: ' . $destino, true, 302);
exit;
