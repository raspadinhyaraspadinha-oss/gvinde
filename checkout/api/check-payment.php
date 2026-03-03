<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Metodo nao permitido'], 405);
}

$config = load_config();
$sessionId = trim((string)($_GET['session_id'] ?? $_GET['id'] ?? ''));

if ($sessionId === '') {
    json_response(['ok' => false, 'status' => 'error', 'error' => 'session_id obrigatorio'], 400);
}

$storage = read_storage((string)$config['storage_file']);
$sessions = $storage['sessions'];

if (!isset($sessions[$sessionId])) {
    json_response([
        'ok' => false,
        'status' => 'error',
        'error' => 'Sessao nao encontrada'
    ], 404);
}

$session = $sessions[$sessionId];
$status = (string)($session['status'] ?? 'pending');

json_response([
    'ok' => true,
    'status' => $status === 'paid' ? 'paid' : 'pending',
    'session_id' => $sessionId,
    'payment_code' => $session['payment_code'] ?? null,
    'external_code' => $session['external_code'] ?? null,
    'amount_cents' => $session['amount_cents'] ?? null,
    'flow' => $session['flow'] ?? null,
    'paidAt' => $session['paid_at'] ?? null,
    'redirectUrl' => (string)$config['default_redirect_after_paid'],
]);

