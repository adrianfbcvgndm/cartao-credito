<?php
require_once __DIR__ . '/rp_config.php';
/**
 * Arquivo de configuracao do addon
 * Necessario para paginas standalone (faturas_cliente.php, pagamento.php)
 */
error_reporting(0);
date_default_timezone_set('America/Sao_Paulo');

// CORREÇÃO DE COMPATIBILIDADE PARA VERSÕES DO MK-AUTH (PHP 8.0+)
// Define constantes críticas que o MK-AUTH exige em alguns ambientes
if (!defined('ADMIN2URL')) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('ADMIN2URL', $protocol . "://" . $host . "/admin/");
}

if (!defined('KEY2PROVEDOR')) {
    define('KEY2PROVEDOR', 'MKAUTH'); // Valor padrão para evitar erro fatal
}

if (!defined('DIR2ADMIN')) {
    define('DIR2ADMIN', '/opt/mk-auth/admin/');
}

if (!defined('DIR2INCLUDE')) {
    define('DIR2INCLUDE', '/opt/mk-auth/include/');
}
