<?php
/**
 * ============================================================
 * CLOAKER PHP ULTRA LEVE - CONFIGURAÇÃO CENTRALIZADA
 * ============================================================
 * Edite SOMENTE este arquivo para configurar todo o cloaker.
 * Não precisa mexer em nenhum outro arquivo.
 * ============================================================
 */

/**
 * Dica de performance para alto trafego:
 * habilite OPcache no php.ini (opcache.enable=1, opcache.validate_timestamps=0 em producao).
 */

// ============================================================
// PÁGINAS
// ============================================================
// Página que o tráfego REAL (aprovado) vai ver
$offer_page = 'offer.html';

// Página que bots/tráfego bloqueado vão ver
$white_page = 'white.html';

// ============================================================
// PAÍSES PERMITIDOS (códigos ISO 3166-1 alpha-2)
// ============================================================
// Apenas visitantes desses países passam. Vazio = todos os países.
$allowed_countries = ['BR'];

// ============================================================
// DISPOSITIVOS PERMITIDOS
// ============================================================
// Opções: 'mobile', 'desktop', 'tablet', 'all'
// Use ['all'] para liberar todos os dispositivos
$allowed_devices = ['mobile', 'tablet'];

// ============================================================
// REFERRERS PERMITIDOS (match parcial)
// ============================================================
// String vazia '' = aceita tráfego direto (sem referrer)
// Muito comum em anúncios mobile do Facebook/TikTok/Kwai
$allowed_referrers = [
    '',                     // Tráfego direto (sem referrer) - ESSENCIAL para anúncios mobile
    'facebook.com',
    'm.facebook.com',
    'l.facebook.com',
    'lm.facebook.com',
    'web.facebook.com',
    'meta.com',
    'instagram.com',
    'l.instagram.com',
    'tiktok.com',
    'www.tiktok.com',
    'kwai.com',
    'v.kwai.com',
    'm.kwai.com',
    'google.com',
    'google.com.br',
];

// ============================================================
// PARÂMETROS DE ANÚNCIO (aumentam pontuação de legitimidade)
// ============================================================
// Se a URL tiver algum desses parâmetros, o visitante ganha pontos extras
$ad_params = [
    'fbclid',
    'tiktokclid',
    'ttclid',
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'utm_content',
    'utm_term',
    'gclid',
    'wbraid',
    'gbraid',
    'kwai_click_id',
    'click_id',
    'campaign_id',
    'adset_id',
    'ad_id',
];

// ============================================================
// DASHBOARD
// ============================================================
// Senha para acessar /dashboard.php
$dashboard_password = 'MinhaSenha@2026!';

// ============================================================
// GEOIP
// ============================================================
// Banco local MaxMind GeoLite2 (principal fonte, latencia local e sem limite por minuto)
// Baixe o arquivo gratuito em:
// https://dev.maxmind.com/geoip/geolite2-free-geolocation-data
// e atualize mensalmente (via update_geo.php + cron).
$geoip_db_file = __DIR__ . '/GeoLite2-Country.mmdb';

// APIs de fallback (usadas apenas se o banco local falhar/indisponivel)
$geoip_apis = [
    'http://ip-api.com/json/',
    'https://ipinfo.io/',
    'https://freegeoip.app/json/',
];

// Tempo de cache do GeoIP em segundos (86400 = 24 horas)
$geoip_cache_ttl = 86400;

// Arquivo de cache GeoIP (fallback quando Redis/Memcached nao existirem)
$geoip_cache_file = __DIR__ . '/geo_cache.json';

// Arquivo para rate limiter de chamadas externas GeoIP (fallback residual)
$geoip_api_rate_file = __DIR__ . '/api_rate.txt';
$geoip_api_rate_limit_per_minute = 40;

// Configuracao MaxMind para update_geo.php (tambem pode usar variaveis de ambiente)
$maxmind_account_id = '';
$maxmind_license_key = '';
$maxmind_edition_id = 'GeoLite2-Country';

// ============================================================
// LOG DE VISITAS
// ============================================================
// Arquivo de log (texto simples, uma linha por visita)
$log_file = __DIR__ . '/visits.log';

// Máximo de linhas no log antes de rotacionar (0 = sem limite)
$log_max_lines = 50000;

// ============================================================
// LISTA DE USER-AGENTS DE BOTS CONHECIDOS (70+ entradas)
// ============================================================
// Adicione novos bots aqui conforme necessário
$bot_signatures = [
    // === Bots de redes sociais / crawlers ===
    'facebookexternalhit',
    'facebot',
    'facebookcatalog',
    'facebookplatform',
    'meta-externalagent',
    'meta-externalfetcher',
    'twitterbot',
    'tweetmemebot',
    'linkedinbot',
    'pinterest',
    'slackbot',
    'telegrambot',
    'whatsapp',
    'discordbot',
    'skypeuripreview',
    'vkshare',
    'redditbot',

    // === Bots de busca ===
    'googlebot',
    'google-inspectiontool',
    'google-structured-data-testing-tool',
    'adsbot-google',
    'mediapartners-google',
    'apis-google',
    'feedfetcher-google',
    'bingbot',
    'bingpreview',
    'msnbot',
    'slurp',           // Yahoo
    'duckduckbot',
    'baiduspider',
    'yandexbot',
    'yandexmobilebot',
    'sogou',
    'exabot',
    'ia_archiver',     // Alexa

    // === Bots de SEO / Marketing ===
    'semrush',
    'ahrefs',
    'moz.com',
    'rogerbot',
    'dotbot',
    'majestic',
    'megaindex',
    'serpstatbot',
    'blexbot',
    'dataforseo',
    'sistrix',
    'screaming frog',
    'seokicks',
    'seostar',
    'petalbot',

    // === Bots de segurança / scan ===
    'zgrab',
    'masscan',
    'censys',
    'nmap',
    'nikto',
    'sqlmap',
    'dirbuster',
    'gobuster',
    'nuclei',
    'openvas',
    'nessus',
    'qualys',
    'acunetix',
    'burpsuite',
    'owasp',
    'wpscan',

    // === Ferramentas de linha de comando / libs ===
    'curl',
    'wget',
    'python-requests',
    'python-urllib',
    'python/',
    'httpie',
    'java/',
    'okhttp',
    'apache-httpclient',
    'go-http-client',
    'node-fetch',
    'axios',
    'got/',
    'undici',
    'libwww',
    'lwp-trivial',
    'php/',
    'guzzle',
    'postman',
    'insomnia',

    // === Outros crawlers / bots ===
    'crawl',
    'spider',
    'bot/',
    'bot;',
    'headlesschrome',
    'phantomjs',
    'selenium',
    'puppeteer',
    'playwright',
    'archive.org_bot',
    'ccbot',
    'gptbot',
    'chatgpt-user',
    'anthropic-ai',
    'claudebot',
    'cohere-ai',
    'bytespider',
    'amazonbot',
    'applebot',
    'cloudflare',
    'cloudflare-healthchecks',
    'amazon cloudfront',
    'aws',
    'elb-healthchecker',
    'kube-probe',
    'uptimerobot',
];

// ============================================================
// HEADERS SUSPEITOS (indicam bots/ferramentas automatizadas)
// ============================================================
$suspicious_headers = [
    'HTTP_X_FORWARDED_FOR',    // Proxies
    'HTTP_VIA',                // Proxies
    'HTTP_X_REAL_IP',          // Proxies reversos
];

// ============================================================
// MODO DEBUG (mostra razão do bloqueio no log)
// ============================================================
$debug_mode = true;
