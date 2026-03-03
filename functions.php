<?php
/**
 * ============================================================
 * CLOAKER PHP ULTRA LEVE - FUNÇÕES DE DETECÇÃO
 * ============================================================
 * Todas as funções de análise de tráfego estão aqui.
 * Não edite este arquivo a menos que saiba o que está fazendo.
 * ============================================================
 */

/**
 * Obtém o IP real do visitante, mesmo atrás de proxies/CDN
 */
function get_visitor_ip(): string
{
    // Cloudflare
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    // Outros proxies confiáveis
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Obtém backend de cache GeoIP:
 * - Redis (se extensao e servidor disponiveis)
 * - Memcached (se extensao e servidor disponiveis)
 * - Arquivo JSON (fallback universal)
 */
function geoip_cache_backend(): array
{
    static $backend = null;

    if ($backend !== null) {
        return $backend;
    }

    if (extension_loaded('redis') && class_exists('Redis')) {
        try {
            $redis = new Redis();
            // Timeout baixo para nao impactar latencia do cloaker em caso de falha de conexao.
            if (@$redis->connect('127.0.0.1', 6379, 0.15)) {
                $backend = ['type' => 'redis', 'client' => $redis];
                return $backend;
            }
        } catch (Throwable $e) {
            // Ignora e cai para o proximo backend.
        }
    }

    if (extension_loaded('memcached') && class_exists('Memcached')) {
        try {
            $memcached = new Memcached();
            $servers = $memcached->getServerList();
            if (empty($servers)) {
                $memcached->addServer('127.0.0.1', 11211);
            }
            $probe = @$memcached->getVersion();
            if (is_array($probe) && !empty($probe)) {
                $backend = ['type' => 'memcached', 'client' => $memcached];
                return $backend;
            }
        } catch (Throwable $e) {
            // Ignora e cai para arquivo.
        }
    }

    $backend = ['type' => 'file'];
    return $backend;
}

/**
 * Le o cache GeoIP em arquivo com lock compartilhado.
 */
function read_geo_cache_file(string $cache_file): array
{
    if (!file_exists($cache_file)) {
        return [];
    }

    $fp = @fopen($cache_file, 'c+');
    if (!$fp) {
        return [];
    }

    $cache = [];
    if (flock($fp, LOCK_SH)) {
        clearstatcache(true, $cache_file);
        $size = filesize($cache_file);
        $raw = $size > 0 ? fread($fp, $size) : '';
        $parsed = json_decode($raw ?: '[]', true);
        if (is_array($parsed)) {
            $cache = $parsed;
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    return $cache;
}

/**
 * Salva o cache GeoIP em arquivo com lock exclusivo.
 */
function write_geo_cache_file(string $cache_file, array $cache): void
{
    $fp = @fopen($cache_file, 'c+');
    if (!$fp) {
        return;
    }

    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

/**
 * Limpa entradas expiradas de cache de forma eficiente.
 */
function cleanup_geo_cache(array $cache, int $ttl, int $now): array
{
    return array_filter($cache, static function ($entry) use ($ttl, $now): bool {
        if (!is_array($entry) || empty($entry['cc']) || !isset($entry['ts'])) {
            return false;
        }
        return ((int) $entry['ts'] + $ttl) > $now;
    });
}

/**
 * Busca item de cache por IP no backend configurado.
 */
function geo_cache_get(string $ip, string $cache_file): ?array
{
    $backend = geoip_cache_backend();
    $key = 'geoip:' . $ip;

    if ($backend['type'] === 'redis') {
        $raw = @$backend['client']->get($key);
        $parsed = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($parsed) ? $parsed : null;
    }

    if ($backend['type'] === 'memcached') {
        $raw = @$backend['client']->get($key);
        $parsed = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($parsed) ? $parsed : null;
    }

    $cache = read_geo_cache_file($cache_file);
    return isset($cache[$ip]) && is_array($cache[$ip]) ? $cache[$ip] : null;
}

/**
 * Salva item de cache GeoIP no backend ativo.
 */
function geo_cache_set(string $ip, string $country, int $now, string $cache_file, int $ttl): void
{
    $backend = geoip_cache_backend();
    $payload = json_encode(['cc' => $country, 'ts' => $now], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $key = 'geoip:' . $ip;

    if ($backend['type'] === 'redis') {
        // Mantem por 30 dias para evitar crescimento infinito e permitir fallback expirado.
        @$backend['client']->setex($key, max(2592000, $ttl), $payload);
        return;
    }

    if ($backend['type'] === 'memcached') {
        @$backend['client']->set($key, $payload, max(2592000, $ttl));
        return;
    }

    $cache = read_geo_cache_file($cache_file);
    $cache[$ip] = ['cc' => $country, 'ts' => $now];
    $cache = cleanup_geo_cache($cache, $ttl, $now);
    write_geo_cache_file($cache_file, $cache);
}

/**
 * Rate limiter simples para chamadas externas residuais de API.
 * Formato do arquivo: timestamp|count
 */
function can_call_geo_api(string $rate_file, int $limit_per_minute): bool
{
    $fp = @fopen($rate_file, 'c+');
    if (!$fp) {
        return false;
    }

    $allowed = false;
    if (flock($fp, LOCK_EX)) {
        rewind($fp);
        $raw = stream_get_contents($fp);
        $parts = explode('|', trim((string) $raw));

        $minute_bucket = (int) floor(time() / 60);
        $stored_bucket = isset($parts[0]) ? (int) $parts[0] : 0;
        $count = isset($parts[1]) ? (int) $parts[1] : 0;

        if ($stored_bucket !== $minute_bucket) {
            $stored_bucket = $minute_bucket;
            $count = 0;
        }

        if ($count < $limit_per_minute) {
            $count++;
            $allowed = true;
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $stored_bucket . '|' . $count);
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    return $allowed;
}

/**
 * Consulta GeoIP local (MaxMind GeoLite2) quando biblioteca + DB estao disponiveis.
 */
function get_country_from_local_db(string $ip, string $geoip_db_file): ?string
{
    if (!file_exists($geoip_db_file)) {
        return null;
    }

    if (!class_exists('\GeoIp2\Database\Reader')) {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
    }

    if (!class_exists('\GeoIp2\Database\Reader')) {
        return null;
    }

    static $reader = null;
    static $reader_db_path = null;

    try {
        if ($reader === null || $reader_db_path !== $geoip_db_file) {
            $reader = new \GeoIp2\Database\Reader($geoip_db_file);
            $reader_db_path = $geoip_db_file;
        }
        $record = $reader->country($ip);
        $cc = strtoupper((string) ($record->country->isoCode ?? ''));
        if (preg_match('/^[A-Z]{2}$/', $cc)) {
            return $cc;
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

/**
 * Monta URL de consulta para cada API de fallback.
 */
function build_geo_api_url(string $base_url, string $ip): string
{
    $base = rtrim($base_url, '/');
    if (strpos($base, 'ip-api.com') !== false) {
        return $base . '/' . rawurlencode($ip) . '?fields=countryCode';
    }
    if (strpos($base, 'ipinfo.io') !== false) {
        return $base . '/' . rawurlencode($ip) . '/json';
    }
    return $base . '/' . rawurlencode($ip);
}

/**
 * Extrai codigo de pais da resposta da API conforme cada provedor.
 */
function parse_geo_api_country(string $base_url, array $data): ?string
{
    $country = '';
    if (strpos($base_url, 'ip-api.com') !== false) {
        $country = (string) ($data['countryCode'] ?? '');
    } elseif (strpos($base_url, 'ipinfo.io') !== false) {
        $country = (string) ($data['country'] ?? '');
    } elseif (strpos($base_url, 'freegeoip.app') !== false) {
        $country = (string) ($data['country_code'] ?? '');
    }

    $country = strtoupper(trim($country));
    return preg_match('/^[A-Z]{2}$/', $country) ? $country : null;
}

/**
 * Retorna pais somente do cache para logging de requests bloqueadas.
 */
function get_cached_country_only(string $ip, string $cache_file, int $ttl): array
{
    $now = time();
    $entry = geo_cache_get($ip, $cache_file);
    if (is_array($entry) && !empty($entry['cc'])) {
        return ['country' => strtoupper((string) $entry['cc']), 'source' => 'cache'];
    }

    // Para backend em arquivo, aproveita para limpar expirados em toda chamada.
    if (geoip_cache_backend()['type'] === 'file') {
        $cache = read_geo_cache_file($cache_file);
        $cleaned = cleanup_geo_cache($cache, $ttl, $now);
        if ($cleaned !== $cache) {
            write_geo_cache_file($cache_file, $cleaned);
        }
    }

    return ['country' => 'XX', 'source' => 'cache'];
}

/**
 * Consulta GeoIP priorizando cache e MaxMind local.
 * Retorna:
 * [
 *   'country' => 'BR',
 *   'source'  => 'cache'|'local'|'api'
 * ]
 */
function get_country(
    string $ip,
    string $cache_file,
    int $ttl,
    string $geoip_db_file,
    array $geoip_apis,
    string $rate_file,
    int $rate_limit
): array {
    $ip = trim($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return ['country' => 'XX', 'source' => 'cache'];
    }

    $now = time();
    $backend = geoip_cache_backend();

    if ($backend['type'] === 'file') {
        // Backend em arquivo: limpa expirados a cada chamada e preserva o IP atual,
        // mesmo expirado, para fallback temporario conforme regra.
        $cache = read_geo_cache_file($cache_file);
        $entry = isset($cache[$ip]) && is_array($cache[$ip]) ? $cache[$ip] : null;

        $cleaned = array_filter(
            $cache,
            static function ($value, $key) use ($ttl, $now, $ip): bool {
                if (!is_array($value) || empty($value['cc']) || !isset($value['ts'])) {
                    return false;
                }
                if ((string) $key === $ip) {
                    return true;
                }
                return ((int) $value['ts'] + $ttl) > $now;
            },
            ARRAY_FILTER_USE_BOTH
        );

        if ($cleaned !== $cache) {
            write_geo_cache_file($cache_file, $cleaned);
        }

        if (is_array($entry) && !empty($entry['cc'])) {
            return ['country' => strtoupper((string) $entry['cc']), 'source' => 'cache'];
        }
    } else {
        // 1) CACHE primeiro. Se existir (mesmo expirado), retorna temporariamente.
        $entry = geo_cache_get($ip, $cache_file);
        if (is_array($entry) && !empty($entry['cc'])) {
            return ['country' => strtoupper((string) $entry['cc']), 'source' => 'cache'];
        }
    }

    // 2) GeoIP local (MaxMind) - evita rede na maioria dos casos.
    $local_country = get_country_from_local_db($ip, $geoip_db_file);
    if ($local_country !== null) {
        geo_cache_set($ip, $local_country, $now, $cache_file, $ttl);
        return ['country' => $local_country, 'source' => 'local'];
    }

    // 3) Fallback API (residual) com rate limiter.
    if (!can_call_geo_api($rate_file, $rate_limit)) {
        return ['country' => 'XX', 'source' => 'api'];
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 2,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: CloakerGeo/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    foreach ($geoip_apis as $base_url) {
        $url = build_geo_api_url($base_url, $ip);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false || $response === '') {
            continue;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            continue;
        }

        $country = parse_geo_api_country($base_url, $data);
        if ($country !== null) {
            geo_cache_set($ip, $country, $now, $cache_file, $ttl);
            return ['country' => $country, 'source' => 'api'];
        }
    }

    return ['country' => 'XX', 'source' => 'api'];
}

/**
 * Detecta o tipo de dispositivo pelo User-Agent e headers
 * Retorna: 'mobile', 'desktop' ou 'tablet'
 */
function detect_device(string $ua): string
{
    $ua_lower = strtolower($ua);

    // === Tablets primeiro (antes de mobile, pois tablets também têm 'android') ===
    $tablet_patterns = [
        'ipad',
        'tablet',
        'playbook',
        'silk',
        'kindle',
        'sm-t',          // Samsung tablets
        'gt-p',          // Samsung tablets antigos
        'mediapad',      // Huawei tablets
        'lenovo tab',
        'surface',
        'tab a',
        'tab s',
    ];

    foreach ($tablet_patterns as $pattern) {
        if (strpos($ua_lower, $pattern) !== false) {
            return 'tablet';
        }
    }

    // Android sem 'mobile' = tablet
    if (strpos($ua_lower, 'android') !== false && strpos($ua_lower, 'mobile') === false) {
        return 'tablet';
    }

    // === Mobile ===
    $mobile_patterns = [
        'iphone',
        'ipod',
        'android',       // Que já tem 'mobile' (tablet filtrado acima)
        'mobile',
        'phone',
        'wp7',
        'wp8',
        'wpdesktop',
        'iemobile',
        'opera mini',
        'opera mobi',
        'blackberry',
        'bb10',
        'webos',
        'symbian',
        'nokia',
        'samsung-gt',
        'sgh-',
        'sm-g',          // Samsung Galaxy
        'sm-a',          // Samsung Galaxy A
        'sm-n',          // Samsung Note
        'lg-',
        'huawei',
        'xiaomi',
        'redmi',
        'poco',
        'oppo',
        'vivo',
        'realme',
        'oneplus',
        'pixel',
        'motorola',
        'moto ',
    ];

    foreach ($mobile_patterns as $pattern) {
        if (strpos($ua_lower, $pattern) !== false) {
            return 'mobile';
        }
    }

    // Checa header específico de dispositivos móveis
    if (
        isset($_SERVER['HTTP_X_WAP_PROFILE']) ||
        isset($_SERVER['HTTP_PROFILE']) ||
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'wap') !== false)
    ) {
        return 'mobile';
    }

    return 'desktop';
}

/**
 * Verifica se o User-Agent é de um bot conhecido
 * Retorna true se for bot
 */
function is_bot(string $ua, array $bot_signatures): bool
{
    // UA vazio ou muito curto = suspeito
    if (strlen(trim($ua)) < 20) {
        return true;
    }

    $ua_lower = strtolower($ua);

    // Verifica contra a lista de assinaturas de bots
    foreach ($bot_signatures as $sig) {
        if (strpos($ua_lower, strtolower($sig)) !== false) {
            return true;
        }
    }

    // Headers que bots geralmente não enviam (browsers reais enviam)
    // Se não tem Accept-Language E não tem Sec-Fetch-Mode, provavelmente é bot
    if (
        empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) &&
        empty($_SERVER['HTTP_SEC_FETCH_MODE'])
    ) {
        return true;
    }

    return false;
}

/**
 * Verifica se o referrer é permitido
 * Retorna true se o referrer é aceitável
 */
function check_referrer(string $referrer, array $allowed_referrers): bool
{
    // Se a lista de permitidos inclui string vazia E o referrer é vazio → OK
    if (empty($referrer)) {
        return in_array('', $allowed_referrers, true);
    }

    // Extrai o host do referrer
    $parsed = parse_url($referrer);
    $host = $parsed['host'] ?? '';
    $host = strtolower(preg_replace('/^www\./', '', $host));

    // Match parcial: verifica se algum referrer permitido está contido no host
    foreach ($allowed_referrers as $allowed) {
        if (empty($allowed)) continue; // Pula string vazia (já tratada acima)
        if (strpos($host, strtolower($allowed)) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Calcula pontuação de legitimidade baseada em parâmetros de anúncio
 * Quanto mais parâmetros de ad, mais legítimo o tráfego
 */
function calculate_ad_score(array $ad_params): int
{
    $score = 0;
    foreach ($ad_params as $param) {
        if (isset($_GET[$param]) && !empty($_GET[$param])) {
            $score++;
        }
    }
    return $score;
}

/**
 * Registra visita no log (arquivo texto, uma linha JSON por visita)
 * Usa file locking para segurança em acessos concorrentes
 */
function log_visit(string $log_file, array $data, int $max_lines): void
{
    $line = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    // Append com lock
    $fp = fopen($log_file, 'a');
    if ($fp && flock($fp, LOCK_EX)) {
        fwrite($fp, $line);
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);

    // Rotacao por tamanho de arquivo (mais eficiente que verificacao aleatoria).
    // Estimativa conservadora: ~380 bytes por linha media de log.
    if ($max_lines > 0 && file_exists($log_file)) {
        clearstatcache(true, $log_file);
        $size = filesize($log_file);
        $target_size = (int) ($max_lines * 380);
        if ($size !== false && $size > $target_size) {
            rotate_log($log_file, $max_lines);
        }
    }
}

/**
 * Rotaciona o log mantendo apenas as últimas N linhas
 */
function rotate_log(string $log_file, int $max_lines): void
{
    if (!file_exists($log_file)) return;

    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || count($lines) <= $max_lines) return;

    // Mantém apenas as últimas $max_lines linhas
    $lines = array_slice($lines, -$max_lines);

    $fp = fopen($log_file, 'w');
    if ($fp && flock($fp, LOCK_EX)) {
        fwrite($fp, implode("\n", $lines) . "\n");
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);
}

/**
 * Serve uma página HTML mantendo a URL original intacta
 * Sem redirecionamento - a URL com todos os parâmetros permanece
 */
function serve_page(string $file): void
{
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        echo file_get_contents($path);
    } else {
        echo '<!DOCTYPE html><html><head><title>OK</title></head><body></body></html>';
    }
    exit;
}
