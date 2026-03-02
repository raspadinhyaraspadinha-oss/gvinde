<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

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

json_response([
    'ok' => true,
    'found' => true,
    'session_id' => $sessionId,
    'status' => $nextStatus
]);

