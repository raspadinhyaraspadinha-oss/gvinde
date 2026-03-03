<?php
/**
 * Atualiza o banco GeoLite2-Country.mmdb da MaxMind.
 *
 * Uso (cron mensal):
 * 0 0 1 * * php /path/to/update_geo.php
 *
 * Requisitos:
 * - Conta gratuita MaxMind
 * - License key
 * - account_id configurado no config.php ou em variavel de ambiente
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require_once __DIR__ . '/config.php';

$accountId = getenv('MAXMIND_ACCOUNT_ID') ?: ($maxmind_account_id ?? '');
$licenseKey = getenv('MAXMIND_LICENSE_KEY') ?: ($maxmind_license_key ?? '');
$editionId = getenv('MAXMIND_EDITION_ID') ?: ($maxmind_edition_id ?? 'GeoLite2-Country');
$targetFile = $geoip_db_file ?? (__DIR__ . '/GeoLite2-Country.mmdb');

if ($accountId === '' || $licenseKey === '') {
    fwrite(STDERR, "Erro: configure MAXMIND_ACCOUNT_ID e MAXMIND_LICENSE_KEY (env) ou no config.php.\n");
    exit(1);
}

$url = sprintf(
    'https://download.maxmind.com/app/geoip_download?edition_id=%s&license_key=%s&suffix=mmdb',
    rawurlencode($editionId),
    rawurlencode($licenseKey)
);

echo '[' . date('Y-m-d H:i:s') . "] Baixando MMDB...\n";

$binary = null;

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $accountId . ':' . $licenseKey,
        CURLOPT_USERAGENT => 'CloakerGeoUpdater/1.0',
    ]);
    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $status >= 400) {
        fwrite(STDERR, "Falha no cURL. HTTP {$status}. {$err}\n");
        exit(1);
    }
    $binary = $resp;
} else {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 60,
            'header' =>
                "Authorization: Basic " . base64_encode($accountId . ':' . $licenseKey) . "\r\n" .
                "User-Agent: CloakerGeoUpdater/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false || $resp === '') {
        fwrite(STDERR, "Falha no download via file_get_contents.\n");
        exit(1);
    }
    $binary = $resp;
}

if (strlen($binary) < 1024) {
    fwrite(STDERR, "Arquivo baixado muito pequeno, abortando para evitar corromper o banco.\n");
    exit(1);
}

$tmpFile = $targetFile . '.tmp';
if (@file_put_contents($tmpFile, $binary, LOCK_EX) === false) {
    fwrite(STDERR, "Erro ao gravar arquivo temporario: {$tmpFile}\n");
    exit(1);
}

if (!@rename($tmpFile, $targetFile)) {
    @unlink($tmpFile);
    fwrite(STDERR, "Erro ao substituir o arquivo final: {$targetFile}\n");
    exit(1);
}

echo '[' . date('Y-m-d H:i:s') . "] Banco atualizado com sucesso em {$targetFile}\n";
exit(0);
