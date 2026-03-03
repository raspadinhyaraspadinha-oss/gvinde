<?php
declare(strict_types=1);

/**
 * UTMify integration helper.
 * Called from create-payment.php (waiting_payment) and mangofy-webhook.php (paid).
 */

define('UTMIFY_API_URL', 'https://api.utmify.com.br/api-credentials/orders');
define('UTMIFY_API_TOKEN', 'yARhXSwXRQjkzShUDcbunTufC18KRcpSCIsK');

/**
 * Send an order event to UTMify.
 *
 * @param array $params Keys:
 *   orderId       (string)  payment_code from Mangofy
 *   status        (string)  "waiting_payment" | "paid"
 *   createdAt     (string)  "YYYY-MM-DD HH:MM:SS"
 *   approvedDate  (string|null)
 *   customer      (array)   name, email, phone, document, country, ip
 *   products      (array)   [{id, name, planId, planName, quantity, priceInCents}]
 *   tracking      (array)   utm_source, utm_campaign, etc.
 *   totalCents    (int)
 *   flow          (string)  "principal" | "up1" | "up2"
 * @return array  decoded response
 */
function utmify_send(array $params): array
{
    $flow = $params['flow'] ?? 'principal';
    $productName = 'Funil Indeniza';
    if ($flow === 'up1') $productName = 'Funil Indeniza - Upsell 1';
    if ($flow === 'up2') $productName = 'Funil Indeniza - Upsell 2';

    $totalCents = (int)($params['totalCents'] ?? 0);

    $tracking = $params['tracking'] ?? [];

    $body = [
        'orderId'   => (string)($params['orderId'] ?? ''),
        'platform'  => 'Mangofy',
        'paymentMethod' => 'pix',
        'status'    => (string)($params['status'] ?? 'waiting_payment'),
        'createdAt' => (string)($params['createdAt'] ?? gmdate('Y-m-d H:i:s')),
        'approvedDate' => $params['approvedDate'] ?? null,
        'refundedAt'   => null,
        'customer' => [
            'name'     => (string)($params['customer']['name'] ?? ''),
            'email'    => (string)($params['customer']['email'] ?? ''),
            'phone'    => (string)($params['customer']['phone'] ?? ''),
            'document' => (string)($params['customer']['document'] ?? ''),
            'country'  => 'BR',
            'ip'       => (string)($params['customer']['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
        ],
        'products' => [
            [
                'id'           => (string)($params['orderId'] ?? ''),
                'name'         => $productName,
                'planId'       => $flow,
                'planName'     => $productName,
                'quantity'     => 1,
                'priceInCents' => $totalCents,
            ],
        ],
        'trackingParameters' => [
            'src'          => $tracking['src'] ?? null,
            'sck'          => $tracking['sck'] ?? null,
            'utm_source'   => $tracking['utm_source'] ?? null,
            'utm_campaign' => $tracking['utm_campaign'] ?? null,
            'utm_medium'   => $tracking['utm_medium'] ?? null,
            'utm_content'  => $tracking['utm_content'] ?? null,
            'utm_term'     => $tracking['utm_term'] ?? null,
            'fbclid'       => $tracking['fbclid'] ?? null,
            'fbp'          => $tracking['fbp'] ?? null,
        ],
        'commission' => [
            'totalPriceInCents'    => $totalCents,
            'gatewayFeeInCents'    => 0,
            'userCommissionInCents' => $totalCents,
        ],
        'isTest' => false,
    ];

    $ch = curl_init(UTMIFY_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-token: ' . UTMIFY_API_TOKEN,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'       => $httpCode >= 200 && $httpCode < 300,
        'status'   => $httpCode,
        'response' => json_decode((string)$response, true),
        'sent'     => $body,
    ];
}
