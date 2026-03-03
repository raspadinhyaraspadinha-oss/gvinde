<?php
declare(strict_types=1);

return [
    // Mangofy credentials
    'mangofy_api_url' => 'https://checkout.mangofy.com.br/api/v1',
    'mangofy_authorization' => '2d7ec7be4856d113b6dea617d389cb711dlhqysglpgl6h8tiy3jd5lzc6tx2ei',
    'mangofy_store_code' => '0d4e1ba5d97eba0bb822b05fae41df4b',

    // Postback URL. Prefer setting the final production URL.
    // If left empty, the API builds a URL based on current host.
    'mangofy_postback_url' => '',

    // Local storage for payment sessions
    'storage_file' => __DIR__ . '/storage/payments.json',

    // Funnel defaults
    'default_amount_cents' => 7290,
    'default_redirect_after_paid' => '../index.html',

    // Fallback customer data (used when frontend sends empty fields)
    'default_client_email' => 'cidinha_lira10@hotmail.com',
    'default_client_name' => 'MARIA APARECIDA NUNES DE LIRA',
    'default_client_document' => '88017427468',
    'default_client_phone' => '11973003483',
];

