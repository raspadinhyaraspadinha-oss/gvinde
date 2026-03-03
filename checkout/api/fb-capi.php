<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

$PIXEL_ID = '895868873361776';
$ACCESS_TOKEN = 'EAAMJ4lbAOXMBQzNNuYkG1xiQ2o1bbrxhHKZAFcnD2bX2lDQD4sIVEJNU8ZBgegihu7yWnmJbVNM2ZB59WeGurtTxkUuBAwXCNgfCk5Ieso5EUHFX8EdeOO2K40itwbIaplHjETLAZC9Fd8HlD4RZBNqBqSuBQXqWR9c7gCy3IQeyDQA05WBAsToLtf8yzywZDZD';
$API_VERSION = 'v21.0';

$payload = json_input();

$eventName = trim((string)($payload['event_name'] ?? ''));
$eventId = trim((string)($payload['event_id'] ?? ''));
$eventSourceUrl = trim((string)($payload['event_source_url'] ?? ''));
$customData = $payload['custom_data'] ?? [];
$userData = $payload['user_data'] ?? [];
$actionSource = trim((string)($payload['action_source'] ?? 'website'));

if ($eventName === '') {
    json_response(['ok' => false, 'error' => 'event_name required'], 400);
}

$serverEvent = [
    'event_name' => $eventName,
    'event_time' => time(),
    'event_source_url' => $eventSourceUrl ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')),
    'action_source' => $actionSource,
];

if ($eventId !== '') {
    $serverEvent['event_id'] = $eventId;
}

$userDataClean = [];
if (!empty($userData['fbp'])) $userDataClean['fbp'] = (string)$userData['fbp'];
if (!empty($userData['fbc'])) $userDataClean['fbc'] = (string)$userData['fbc'];
if (!empty($userData['client_user_agent'])) $userDataClean['client_user_agent'] = (string)$userData['client_user_agent'];
if (!empty($userData['client_ip_address'])) {
    $userDataClean['client_ip_address'] = (string)$userData['client_ip_address'];
} else {
    $userDataClean['client_ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
}

if (!empty($userData['external_id'])) {
    $userDataClean['external_id'] = hash('sha256', (string)$userData['external_id']);
}
if (!empty($userData['fn'])) {
    $userDataClean['fn'] = hash('sha256', strtolower(trim((string)$userData['fn'])));
}
if (!empty($userData['ln'])) {
    $userDataClean['ln'] = hash('sha256', strtolower(trim((string)$userData['ln'])));
}
if (!empty($userData['em'])) {
    $userDataClean['em'] = hash('sha256', strtolower(trim((string)$userData['em'])));
}
if (!empty($userData['ph'])) {
    $userDataClean['ph'] = hash('sha256', preg_replace('/\D/', '', (string)$userData['ph']));
}

$userDataClean['country'] = hash('sha256', 'br');

$serverEvent['user_data'] = $userDataClean;

if (!empty($customData) && is_array($customData)) {
    $serverEvent['custom_data'] = $customData;
}

$body = [
    'data' => [$serverEvent],
];

$url = "https://graph.facebook.com/{$API_VERSION}/{$PIXEL_ID}/events?access_token=" . urlencode($ACCESS_TOKEN);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

json_response([
    'ok' => $httpCode >= 200 && $httpCode < 300,
    'fb_status' => $httpCode,
    'fb_response' => json_decode((string)$response, true),
    'curl_error' => $curlErr ?: null,
]);
