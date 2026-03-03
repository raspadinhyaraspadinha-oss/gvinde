<?php
/**
 * ============================================================
 * CLOAKER PHP ULTRA LEVE - CONFIGURAÇÃO CENTRALIZADA
 * ============================================================
 * Edite SOMENTE este arquivo para configurar todo o cloaker.
 * Não precisa mexer em nenhum outro arquivo.
 * ============================================================
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
$allowed_devices = ['mobile'];

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
// API gratuita do ip-api.com (limite: 45 req/min)
$geoip_api_url = 'http://ip-api.com/json/';

// Tempo de cache do GeoIP em segundos (3600 = 1 hora)
$geoip_cache_ttl = 3600;

// Arquivo de cache GeoIP
$geoip_cache_file = __DIR__ . '/geo_cache.json';

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
