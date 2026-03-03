<?php
/**
 * ============================================================
 * CLOAKER PHP ULTRA LEVE - MOTOR PRINCIPAL
 * ============================================================
 * Este arquivo faz todas as verificações e decide se mostra
 * a offer page (tráfego real) ou a white page (bots/bloqueados).
 * A URL visível NUNCA muda - todos os parâmetros GET são preservados.
 * ============================================================
 */

// Inicia cronômetro para medir performance
$start_time = microtime(true);

// Carrega configurações e funções
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// ============================================================
// COLETA DE DADOS DO VISITANTE
// ============================================================
$ip         = get_visitor_ip();
$ua         = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referrer   = $_SERVER['HTTP_REFERER'] ?? '';
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$timestamp  = date('Y-m-d H:i:s');

// ============================================================
// VERIFICAÇÕES DE SEGURANÇA (ordem otimizada por velocidade)
// ============================================================
$blocked     = false;
$reason      = '';
$device_type = 'unknown';
$country     = 'XX';
$ad_score    = 0;

// --- 1. DETECÇÃO DE BOT (mais rápido, não precisa de rede) ---
if (is_bot($ua, $bot_signatures)) {
    $blocked = true;
    $reason  = 'bot_detected';
}

// --- 2. DETECÇÃO DE DISPOSITIVO ---
if (!$blocked) {
    $device_type = detect_device($ua);

    // Verifica se o dispositivo é permitido
    if (!in_array('all', $allowed_devices, true)) {
        if (!in_array($device_type, $allowed_devices, true)) {
            $blocked = true;
            $reason  = 'device_blocked:' . $device_type;
        }
    }
}

// --- 3. VERIFICAÇÃO DE REFERRER ---
if (!$blocked) {
    if (!check_referrer($referrer, $allowed_referrers)) {
        $blocked = true;
        $reason  = 'referrer_blocked';
    }
}

// --- 4. GEOIP (faz chamada de rede, por isso é a última) ---
if (!$blocked) {
    // Se a lista de países está vazia, aceita todos
    if (!empty($allowed_countries)) {
        $country = get_country($ip, $geoip_cache_file, $geoip_cache_ttl, $geoip_api_url);

        if (!in_array($country, $allowed_countries, true)) {
            $blocked = true;
            $reason  = 'country_blocked:' . $country;
        }
    }
} else {
    // Mesmo bloqueado, tenta pegar o país do cache para o log
    if (file_exists($geoip_cache_file)) {
        $cache_raw = @file_get_contents($geoip_cache_file);
        if ($cache_raw) {
            $cache_data = json_decode($cache_raw, true);
            if (isset($cache_data[$ip]['cc'])) {
                $country = $cache_data[$ip]['cc'];
            }
        }
    }
}

// --- 5. PONTUAÇÃO DE PARÂMETROS DE ANÚNCIO ---
$ad_score = calculate_ad_score($ad_params);

// ============================================================
// DECISÃO FINAL
// ============================================================
$action = $blocked ? 'blocked' : 'allowed';

// Tempo de processamento em milissegundos
$process_time = round((microtime(true) - $start_time) * 1000, 2);

// ============================================================
// REGISTRO NO LOG
// ============================================================
$log_entry = [
    'ts'      => $timestamp,
    'ip'      => $ip,
    'cc'      => $country,
    'device'  => $device_type,
    'action'  => $action,
    'reason'  => $reason,
    'ref'     => $referrer,
    'ua'      => substr($ua, 0, 200), // Limita UA no log para economizar espaço
    'uri'     => $request_uri,
    'ad'      => $ad_score,
    'ms'      => $process_time,
];

log_visit($log_file, $log_entry, $log_max_lines);

// ============================================================
// SERVE A PÁGINA CORRETA (sem redirecionar, URL preservada)
// ============================================================
if ($blocked) {
    serve_page($white_page);
} else {
    serve_page($offer_page);
}
