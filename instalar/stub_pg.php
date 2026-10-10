<?php
// 09/10/2026: esta página mudou para o addon cartao_credito. Links antigos continuam valendo.
$cc_novo = __DIR__ . '/../cartao_credito/' . basename(__FILE__);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: /admin/addons/cartao_credito/' . basename(__FILE__) . ($qs !== '' ? '?' . $qs : ''), true, 302);
    exit;
}
require $cc_novo;
