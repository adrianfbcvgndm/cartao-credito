<?php
/* Copie para rp_config_local.php e preencha. Este arquivo NÃO vai para o GitHub. */
return [
    // banco do MK-Auth
    'db_host' => '127.0.0.1',
    'db_user' => 'root',
    'db_pass' => 'SENHA_DO_BANCO',
    'db_name' => 'mkradius',

    // chave interna (vazio = gera sozinha em .rp_autokey)
    'api_key' => '',

    // Mercado Pago > Suas integrações > Webhooks > "Assinatura secreta"
    // Sem isso o webhook recusa os avisos e a fatura NÃO baixa sozinha.
    'mp_webhook_secret' => 'COLE_AQUI_A_ASSINATURA_SECRETA',

    // false = exige login no MK-Auth (deixe false em produção)
    'skip_auth' => false,
];
