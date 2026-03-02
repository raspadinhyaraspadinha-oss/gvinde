<?php
declare(strict_types=1);

function load_config(): array
{
    return require __DIR__ . '/config.php';
}

function ensure_storage_file(string $storageFile): void
{
    $dir = dirname($storageFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    if (!file_exists($storageFile)) {
        file_put_contents($storageFile, json_encode(['sessions' => []], JSON_PRETTY_PRINT));
    }
}

function read_storage(string $storageFile): array
{
    ensure_storage_file($storageFile);
    $raw = file_get_contents($storageFile);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        $data = ['sessions' => []];
    }
    if (!isset($data['sessions']) || !is_array($data['sessions'])) {
        $data['sessions'] = [];
    }
    return $data;
}

function write_storage(string $storageFile, array $data): void
{
    ensure_storage_file($storageFile);
    $fp = fopen($storageFile, 'c+');
    if (!$fp) {
        throw new RuntimeException('Nao foi possivel abrir o arquivo de storage.');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('Nao foi possivel bloquear o arquivo de storage.');
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_cpf(string $cpf): string
{
    return preg_replace('/\D+/', '', $cpf) ?? '';
}

function normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function generate_external_code(): string
{
    return 'pay_' . round(microtime(true) * 1000) . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
}

function build_postback_url(array $config): string
{
    if (!empty($config['mangofy_postback_url'])) {
        return (string)$config['mangofy_postback_url'];
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/checkout/api/mangofy-webhook.php';
}

function update_session_by_id(array &$sessions, string $sessionId, array $patch): void
{
    if (!isset($sessions[$sessionId])) {
        $sessions[$sessionId] = [
            'session_id' => $sessionId,
            'status' => 'pending',
            'created_at' => time(),
        ];
    }

    $sessions[$sessionId] = array_merge($sessions[$sessionId], $patch, [
        'updated_at' => time(),
    ]);
}

function find_session_id_by_payment_code(array $sessions, string $paymentCode): ?string
{
    foreach ($sessions as $sessionId => $session) {
        if (($session['payment_code'] ?? '') === $paymentCode) {
            return (string)$sessionId;
        }
    }
    return null;
}

