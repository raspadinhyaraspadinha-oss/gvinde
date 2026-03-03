<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/utmify.php';

// Mangofy postback: always respond 200 to avoid retries storm.
$config = load_config();
$payload = json_input();

$paymentCode = trim((string)($payload['payment_code'] ?? ''));
$paymentStatus = strtolower(trim((string)($payload['payment_status'] ?? '')));
$approvedAt = (string)($payload['approved_at'] ?? '');

if ($paymentCode === '') {
    json_response(['ok' => true, 'ignored' => true, 'reason' => 'missing_payment_code']);
}

$storage = read_storage((string)$config['storage_file']);
$sessions = $storage['sessions'];

$sessionId = find_session_id_by_payment_code($sessions, $paymentCode);
if ($sessionId === null) {
    json_response([
        'ok' => true,
        'found' => false,
        'payment_code' => $paymentCode
    ]);
}

$nextStatus = ($paymentStatus === 'approved') ? 'paid' : ($sessions[$sessionId]['status'] ?? 'pending');

update_session_by_id($sessions, $sessionId, [
    'status' => $nextStatus,
    'paid_at' => $nextStatus === 'paid' ? time() : ($sessions[$sessionId]['paid_at'] ?? null),
    'approved_at' => $approvedAt !== '' ? $approvedAt : ($sessions[$sessionId]['approved_at'] ?? null),
    'last_webhook_payload' => $payload,
    'last_webhook_at' => time(),
]);

$storage['sessions'] = $sessions;
write_storage((string)$config['storage_file'], $storage);

// ── On payment approved: fire Purchase CAPI + UTMify paid ────────
if ($nextStatus === 'paid') {
    $sess = $sessions[$sessionId] ?? [];
    $amountCents = (int)($sess['amount_cents'] ?? 0);
    $flow = (string)($sess['flow'] ?? 'principal');
    $trackingParams = $sess['tracking_parameters'] ?? [];
    $createdAtStr = $sess['utmify_created_at'] ?? gmdate('Y-m-d H:i:s', (int)($sess['created_at'] ?? time()));
    $approvedDateStr = gmdate('Y-m-d H:i:s');

    // UTMify: paid
    utmify_send([
        'orderId'      => $paymentCode,
        'status'       => 'paid',
        'createdAt'    => $createdAtStr,
        'approvedDate' => $approvedDateStr,
        'customer'     => [
            'name'     => (string)($sess['name'] ?? ''),
            'email'    => (string)($sess['email'] ?? ''),
            'phone'    => (string)($sess['phone'] ?? ''),
            'document' => (string)($sess['document'] ?? ''),
            'ip'       => '',
        ],
        'totalCents'   => $amountCents,
        'tracking'     => $trackingParams,
        'flow'         => $flow,
    ]);

    // FB CAPI: Purchase (server-only, no browser dedup)
    send_fb_capi_server([
        'event_name'       => 'Purchase',
        'event_id'         => 'srv_purchase_' . $paymentCode,
        'event_source_url' => '',
        'action_source'    => 'website',
        'custom_data'      => [
            'currency'     => 'BRL',
            'value'        => round($amountCents / 100, 2),
            'content_name' => 'PIX ' . $flow,
            'content_ids'  => [$paymentCode],
        ],
        'user_data' => [
            'external_id' => (string)($sess['document'] ?? ''),
            'fn'          => strtolower(explode(' ', (string)($sess['name'] ?? ''))[0] ?? ''),
            'ln'          => strtolower(implode(' ', array_slice(explode(' ', (string)($sess['name'] ?? '')), 1))),
            'em'          => (string)($sess['email'] ?? ''),
            'ph'          => (string)($sess['phone'] ?? ''),
            'fbp'         => (string)($trackingParams['fbp'] ?? ''),
            'fbc'         => !empty($trackingParams['fbclid']) ? 'fb.1.' . time() . '.' . $trackingParams['fbclid'] : '',
        ],
    ]);
}

json_response([
    'ok' => true,
    'found' => true,
    'session_id' => $sessionId,
    'status' => $nextStatus
]);

