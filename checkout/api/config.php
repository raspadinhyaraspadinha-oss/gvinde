<?php
declare(strict_types=1);

return [
    // Mangofy credentials
    'mangofy_api_url' => 'https://checkout.mangofy.com.br/api/v1',
    'mangofy_authorization' => 'COLE_SEU_TOKEN_AQUI',
    'mangofy_store_code' => 'COLE_SEU_STORE_CODE_AQUI',

    // Postback URL. Prefer setting the final production URL.
    // If left empty, the API builds a URL based on current host.
    'mangofy_postback_url' => '',

    // Local storage for payment sessions
    'storage_file' => __DIR__ . '/storage/payments.json',

    // Funnel defaults
    'default_amount_cents' => 7290,
    'default_redirect_after_paid' => '../index.html',
];

