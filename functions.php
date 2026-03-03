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
 * Consulta GeoIP com cache em arquivo JSON
 * Retorna código do país (ex: 'BR') ou 'XX' se falhar
 */
function get_country(string $ip, string $cache_file, int $ttl, string $api_url): string
{
    // Carrega cache existente
    $cache = [];
    if (file_exists($cache_file)) {
        $raw = file_get_contents($cache_file);
        if ($raw !== false) {
            $cache = json_decode($raw, true) ?: [];
        }
    }

    // Verifica se o IP está no cache e ainda é válido
    if (isset($cache[$ip]) && ($cache[$ip]['ts'] + $ttl) > time()) {
        return $cache[$ip]['cc'];
    }

    // Consulta a API
    $country = 'XX';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 3,        // 3 segundos de timeout
            'ignore_errors' => true,
        ]
    ]);

    $response = @file_get_contents($api_url . $ip . '?fields=countryCode', false, $ctx);

    if ($response !== false) {
        $data = json_decode($response, true);
        if (isset($data['countryCode'])) {
            $country = strtoupper($data['countryCode']);
        }
    }

    // Salva no cache
    $cache[$ip] = [
        'cc' => $country,
        'ts' => time(),
    ];

    // Limpa entradas expiradas do cache (máximo 5000 entradas)
    if (count($cache) > 5000) {
        $now = time();
        $cache = array_filter($cache, function ($entry) use ($now, $ttl) {
            return ($entry['ts'] + $ttl) > $now;
        });
    }

    // Salva cache com lock para evitar corrupção
    $fp = fopen($cache_file, 'c');
    if ($fp && flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($cache, JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    if ($fp) fclose($fp);

    return $country;
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
    if (strlen($ua) < 20) {
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

    // Rotação de log se exceder máximo (verificação leve, a cada ~100 visitas)
    if ($max_lines > 0 && mt_rand(1, 100) === 1) {
        rotate_log($log_file, $max_lines);
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
