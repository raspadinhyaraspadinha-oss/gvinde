<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/utmify.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Metodo nao permitido'], 405);
}

$config = load_config();
$payload = json_input();

$sessionId = trim((string)($payload['session_id'] ?? ''));
if ($sessionId === '') {
    $sessionId = 'sess_' . round(microtime(true) * 1000) . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
}

$name = trim((string)($payload['name'] ?? ''));
if ($name === '') {
    $name = (string)($config['default_client_name'] ?? 'CLIENTE');
}

$document = normalize_cpf((string)($payload['document'] ?? ''));
if (strlen($document) !== 11) {
    $document = normalize_cpf((string)($config['default_client_document'] ?? ''));
}

$phone = normalize_phone((string)($payload['phone'] ?? ''));
if (strlen($phone) < 10) {
    $phone = normalize_phone((string)($config['default_client_phone'] ?? ''));
}

$email = trim((string)($payload['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = (string)($config['default_client_email'] ?? 'cliente@email.com');
}

if ($name === '' || strlen($document) !== 11 || strlen($phone) < 10 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response([
        'ok' => false,
        'error' => 'Nao foi possivel definir dados do cliente para criar pagamento'
    ], 400);
}

$flow = trim((string)($payload['flow'] ?? 'principal'));

$amountCents = (int)($payload['amount_cents'] ?? $config['default_amount_cents']);
if ($amountCents <= 0) {
    $amountCents = (int)$config['default_amount_cents'];
}

$externalCode = generate_external_code();
$postbackUrl = build_postback_url($config);
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

$requestBody = [
    'store_code' => (string)$config['mangofy_store_code'],
    'external_code' => $externalCode,
    'payment_method' => 'pix',
    'payment_amount' => $amountCents,
    'payment_format' => 'regular',
    'installments' => 1,
    'pix' => [
        'expires_in_days' => 1
    ],
    'postback_url' => $postbackUrl,
    'items' => [[
        'code' => 'ITEM-' . $externalCode,
        'amount' => 1,
        'price' => $amountCents
    ]],
    'customer' => [
        'email' => $email,
        'name' => $name,
        'document' => $document,
        'phone' => $phone,
        'ip' => $ip
    ],
    'metadata' => [
        'session_id' => $sessionId,
        'funnel' => 'funilindeniza',
        'flow' => $flow
    ]
];

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: ' . (string)$config['mangofy_authorization'],
    'Store-Code: ' . (string)$config['mangofy_store_code'],
];

$ch = curl_init(rtrim((string)$config['mangofy_api_url'], '/') . '/payment');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlErr) {
    json_response([
        'ok' => false,
        'error' => 'Falha na comunicacao com Mangofy',
        'details' => $curlErr
    ], 502);
}

$decoded = json_decode($response, true);
if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
    json_response([
        'ok' => false,
        'error' => 'Resposta invalida da Mangofy',
        'http_status' => $statusCode,
        'raw' => $response
    ], 502);
}

$paymentCode = (string)($decoded['payment_code'] ?? '');
$pixText = (string)($decoded['pix']['pix_qrcode_text'] ?? '');
$paymentStatus = (string)($decoded['payment_status'] ?? 'pending');

if ($paymentCode === '' || $pixText === '') {
    json_response([
        'ok' => false,
        'error' => 'Mangofy nao retornou payment_code ou pix_qrcode_text',
        'response' => $decoded
    ], 502);
}

$storage = read_storage((string)$config['storage_file']);
$sessions = $storage['sessions'];

update_session_by_id($sessions, $sessionId, [
    'session_id' => $sessionId,
    'external_code' => $externalCode,
    'payment_code' => $paymentCode,
    'status' => $paymentStatus === 'approved' ? 'paid' : 'pending',
    'amount_cents' => $amountCents,
    'name' => $name,
    'document' => $document,
    'phone' => $phone,
    'email' => $email,
    'flow' => $flow,
    'pix_qrcode_text' => $pixText,
    'expires_at' => $decoded['expires_at'] ?? null,
    'created_by_endpoint' => true,
    'last_mangofy_response' => $decoded,
]);

$storage['sessions'] = $sessions;
write_storage((string)$config['storage_file'], $storage);

// ── UTMify: waiting_payment ──────────────────────────────────────
$trackingParams = $payload['tracking_parameters'] ?? [];
$createdAtStr = gmdate('Y-m-d H:i:s');

$utmifyResult = utmify_send([
    'orderId'      => $paymentCode,
    'status'       => 'waiting_payment',
    'createdAt'    => $createdAtStr,
    'approvedDate' => null,
    'customer'     => [
        'name'     => $name,
        'email'    => $email,
        'phone'    => $phone,
        'document' => $document,
        'ip'       => $ip,
    ],
    'totalCents'   => $amountCents,
    'tracking'     => $trackingParams,
    'flow'         => $flow,
]);

// ── FB CAPI: InitiateCheckout (server-side, no browser dedup needed) ─
$fbCapiPayload = [
    'event_name'       => 'InitiateCheckout',
    'event_id'         => 'srv_ic_' . $paymentCode,
    'event_source_url' => (string)($payload['source_url'] ?? ''),
    'action_source'    => 'website',
    'custom_data'      => [
        'currency' => 'BRL',
        'value'    => round($amountCents / 100, 2),
        'content_name' => 'PIX ' . $flow,
        'content_ids'  => [$paymentCode],
    ],
    'user_data' => [
        'client_ip_address' => $ip,
        'external_id'       => $document,
        'fn'                => strtolower(explode(' ', $name)[0] ?? ''),
        'ln'                => strtolower(implode(' ', array_slice(explode(' ', $name), 1))),
        'em'                => $email,
        'ph'                => $phone,
        'fbp'               => (string)($trackingParams['fbp'] ?? ''),
        'fbc'               => !empty($trackingParams['fbclid']) ? 'fb.1.' . time() . '.' . $trackingParams['fbclid'] : '',
    ],
];
send_fb_capi_server($fbCapiPayload);

// Persist createdAt for UTMify paid event later
update_session_by_id($sessions, $sessionId, [
    'utmify_created_at' => $createdAtStr,
    'tracking_parameters' => $trackingParams,
]);
$storage['sessions'] = $sessions;
write_storage((string)$config['storage_file'], $storage);

json_response([
    'ok' => true,
    'session_id' => $sessionId,
    'payment_code' => $paymentCode,
    'external_code' => $externalCode,
    'payment_status' => $paymentStatus,
    'amount_cents' => $amountCents,
    'flow' => $flow,
    'pix_qrcode_text' => $pixText,
    'expires_at' => $decoded['expires_at'] ?? null,
]);

