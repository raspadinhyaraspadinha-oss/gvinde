<?php
/**
 * ============================================================
 * CLOAKER PHP ULTRA LEVE - DASHBOARD EM TEMPO REAL
 * ============================================================
 * Protegido por senha definida no config.php
 * Mostra estatísticas e últimas visitas em tempo real
 * ============================================================
 */

require_once __DIR__ . '/config.php';

// ============================================================
// AUTENTICAÇÃO
// ============================================================
session_start();

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Ação de logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: dashboard.php');
    exit;
}

// Verifica login
if (!isset($_SESSION['dash_auth']) || $_SESSION['dash_auth'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $dashboard_password) {
            $_SESSION['dash_auth'] = true;
        } else {
            $login_error = true;
        }
    }

    if (!isset($_SESSION['dash_auth']) || $_SESSION['dash_auth'] !== true) {
        if ($is_ajax) {
            http_response_code(401);
            echo json_encode(['error' => 'unauthorized']);
            exit;
        }
        show_login_page($login_error ?? false);
        exit;
    }
}

// ============================================================
// AÇÕES DO DASHBOARD (AJAX)
// ============================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_GET['action']) {
        // --- Retorna dados para o dashboard ---
        case 'data':
            echo json_encode(get_dashboard_data($log_file), JSON_UNESCAPED_UNICODE);
            exit;

        // --- Limpa o log ---
        case 'clear':
            if (file_exists($log_file)) {
                $fp = fopen($log_file, 'w');
                if ($fp) { flock($fp, LOCK_EX); ftruncate($fp, 0); flock($fp, LOCK_UN); fclose($fp); }
            }
            echo json_encode(['ok' => true]);
            exit;

        // --- Exportar CSV ---
        case 'csv':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="visits_' . date('Y-m-d_H-i') . '.csv"');
            export_csv($log_file);
            exit;
    }
}

// ============================================================
// FUNÇÕES DO DASHBOARD
// ============================================================

function get_dashboard_data(string $log_file): array
{
    $visits = [];
    $today = date('Y-m-d');
    $total_today = 0;
    $blocked_today = 0;
    $allowed_today = 0;
    $countries = [];
    $referrers = [];
    $devices = [];

    if (file_exists($log_file)) {
        // Lê as últimas 500 linhas de forma eficiente
        $lines = [];
        $fp = fopen($log_file, 'r');
        if ($fp) {
            // Vai para o final do arquivo e lê de trás para frente
            fseek($fp, 0, SEEK_END);
            $pos = ftell($fp);
            $line_count = 0;
            $buffer = '';

            while ($pos > 0 && $line_count < 500) {
                $pos--;
                fseek($fp, $pos);
                $char = fgetc($fp);
                if ($char === "\n" && $buffer !== '') {
                    $lines[] = $buffer;
                    $buffer = '';
                    $line_count++;
                } else {
                    $buffer = $char . $buffer;
                }
            }
            if ($buffer !== '') {
                $lines[] = $buffer;
            }
            fclose($fp);
        }

        // Processa cada linha (mais recentes primeiro)
        foreach ($lines as $line) {
            $entry = json_decode(trim($line), true);
            if (!$entry) continue;

            $visits[] = $entry;

            // Estatísticas de hoje
            if (isset($entry['ts']) && str_starts_with($entry['ts'], $today)) {
                $total_today++;
                if ($entry['action'] === 'blocked') {
                    $blocked_today++;
                } else {
                    $allowed_today++;
                }

                // Top países
                $cc = $entry['cc'] ?? 'XX';
                $countries[$cc] = ($countries[$cc] ?? 0) + 1;

                // Top referrers
                $ref = $entry['ref'] ?? '(direto)';
                if (empty($ref)) $ref = '(direto)';
                $ref_host = parse_url($ref, PHP_URL_HOST) ?? $ref;
                $referrers[$ref_host] = ($referrers[$ref_host] ?? 0) + 1;

                // Dispositivos
                $dev = $entry['device'] ?? 'unknown';
                $devices[$dev] = ($devices[$dev] ?? 0) + 1;
            }
        }
    }

    arsort($countries);
    arsort($referrers);
    arsort($devices);

    $pass_rate = $total_today > 0 ? round(($allowed_today / $total_today) * 100, 1) : 0;

    return [
        'visits'        => array_slice($visits, 0, 500),
        'total_today'   => $total_today,
        'blocked_today' => $blocked_today,
        'allowed_today' => $allowed_today,
        'pass_rate'     => $pass_rate,
        'top_countries' => array_slice($countries, 0, 10, true),
        'top_referrers' => array_slice($referrers, 0, 10, true),
        'devices'       => $devices,
        'updated_at'    => date('H:i:s'),
    ];
}

function export_csv(string $log_file): void
{
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Data/Hora', 'IP', 'País', 'Dispositivo', 'Ação', 'Motivo', 'Referrer', 'User-Agent', 'URI', 'Ad Score', 'MS']);

    if (file_exists($log_file)) {
        $fp = fopen($log_file, 'r');
        if ($fp) {
            while (($line = fgets($fp)) !== false) {
                $e = json_decode(trim($line), true);
                if (!$e) continue;
                fputcsv($out, [
                    $e['ts'] ?? '', $e['ip'] ?? '', $e['cc'] ?? '',
                    $e['device'] ?? '', $e['action'] ?? '', $e['reason'] ?? '',
                    $e['ref'] ?? '', $e['ua'] ?? '', $e['uri'] ?? '',
                    $e['ad'] ?? 0, $e['ms'] ?? 0,
                ]);
            }
            fclose($fp);
        }
    }
    fclose($out);
}

function show_login_page(bool $error): void
{
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Login</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh}
        .login-box{background:#1e293b;padding:2.5rem;border-radius:12px;width:100%;max-width:380px;box-shadow:0 25px 50px rgba(0,0,0,.3)}
        h1{font-size:1.5rem;text-align:center;margin-bottom:1.5rem;color:#60a5fa}
        input[type=password]{width:100%;padding:.8rem 1rem;border:2px solid #334155;border-radius:8px;background:#0f172a;color:#e2e8f0;font-size:1rem;margin-bottom:1rem;outline:none;transition:border .2s}
        input:focus{border-color:#60a5fa}
        button{width:100%;padding:.8rem;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-size:1rem;cursor:pointer;font-weight:600;transition:background .2s}
        button:hover{background:#2563eb}
        .error{background:#991b1b;color:#fca5a5;padding:.6rem;border-radius:6px;text-align:center;margin-bottom:1rem;font-size:.9rem}
    </style>
</head>
<body>
    <div class="login-box">
        <h1>Dashboard</h1>
        <?php if ($error): ?>
            <div class="error">Senha incorreta</div>
        <?php endif; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Digite a senha" autofocus required>
            <button type="submit">Entrar</button>
        </form>
    </div>
</body>
</html>
<?php
}

// ============================================================
// PÁGINA PRINCIPAL DO DASHBOARD
// ============================================================
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Cloaker</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f172a;color:#e2e8f0;padding:1rem}

        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:.5rem}
        .header h1{font-size:1.4rem;color:#60a5fa}
        .header .info{font-size:.85rem;color:#64748b}
        .actions{display:flex;gap:.5rem;flex-wrap:wrap}
        .btn{padding:.5rem 1rem;border:none;border-radius:6px;font-size:.85rem;cursor:pointer;font-weight:600;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem}
        .btn-blue{background:#3b82f6;color:#fff}.btn-blue:hover{background:#2563eb}
        .btn-red{background:#dc2626;color:#fff}.btn-red:hover{background:#b91c1c}
        .btn-green{background:#16a34a;color:#fff}.btn-green:hover{background:#15803d}
        .btn-gray{background:#475569;color:#fff}.btn-gray:hover{background:#374151}

        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem}
        .stat-card{background:#1e293b;padding:1.2rem;border-radius:10px;text-align:center}
        .stat-card .value{font-size:2rem;font-weight:700;line-height:1.2}
        .stat-card .label{font-size:.8rem;color:#94a3b8;margin-top:.3rem}
        .stat-allowed .value{color:#4ade80}
        .stat-blocked .value{color:#f87171}
        .stat-total .value{color:#60a5fa}
        .stat-rate .value{color:#fbbf24}

        .panels{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.5rem}
        .panel{background:#1e293b;border-radius:10px;padding:1rem}
        .panel h3{font-size:.9rem;color:#94a3b8;margin-bottom:.8rem;text-transform:uppercase;letter-spacing:.5px}
        .panel-row{display:flex;justify-content:space-between;padding:.3rem 0;font-size:.85rem;border-bottom:1px solid #334155}
        .panel-row:last-child{border-bottom:none}
        .panel-row .count{color:#60a5fa;font-weight:600}

        .table-wrap{background:#1e293b;border-radius:10px;overflow:hidden}
        .table-wrap h3{padding:1rem;font-size:.9rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px}
        table{width:100%;border-collapse:collapse;font-size:.8rem}
        th{background:#334155;padding:.6rem .5rem;text-align:left;font-weight:600;position:sticky;top:0;color:#94a3b8;text-transform:uppercase;font-size:.7rem}
        td{padding:.5rem;border-bottom:1px solid #1e293b}
        tr:hover td{background:#1e293b80}
        .table-scroll{max-height:500px;overflow-y:auto}

        .badge{padding:.15rem .5rem;border-radius:4px;font-size:.75rem;font-weight:600}
        .badge-green{background:#16a34a30;color:#4ade80}
        .badge-red{background:#dc262630;color:#f87171}

        @media(max-width:900px){
            .panels{grid-template-columns:1fr}
            table{font-size:.7rem}
        }

        .pulse{animation:pulse 2s infinite}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Dashboard Cloaker</h1>
            <span class="info">Atualizado: <span id="updated">--:--:--</span> <span id="loading" class="pulse" style="display:none">&#9679;</span></span>
        </div>
        <div class="actions">
            <button class="btn btn-blue" onclick="refresh()">Atualizar</button>
            <button class="btn btn-red" onclick="clearLogs()">Limpar Logs</button>
            <a class="btn btn-green" href="dashboard.php?action=csv">Exportar CSV</a>
            <a class="btn btn-gray" href="dashboard.php?action=logout">Sair</a>
        </div>
    </div>

    <div class="stats">
        <div class="stat-card stat-total"><div class="value" id="s-total">0</div><div class="label">Total Hoje</div></div>
        <div class="stat-card stat-allowed"><div class="value" id="s-allowed">0</div><div class="label">Permitidos</div></div>
        <div class="stat-card stat-blocked"><div class="value" id="s-blocked">0</div><div class="label">Bloqueados</div></div>
        <div class="stat-card stat-rate"><div class="value" id="s-rate">0%</div><div class="label">Pass-Through</div></div>
    </div>

    <div class="panels">
        <div class="panel"><h3>Top Paises</h3><div id="p-countries"></div></div>
        <div class="panel"><h3>Top Referrers</h3><div id="p-referrers"></div></div>
        <div class="panel"><h3>Dispositivos</h3><div id="p-devices"></div></div>
    </div>

    <div class="table-wrap">
        <h3>Ultimas Visitas</h3>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>IP</th>
                        <th>Pais</th>
                        <th>Device</th>
                        <th>Status</th>
                        <th>Motivo</th>
                        <th>Referrer</th>
                        <th>Ad</th>
                        <th>MS</th>
                    </tr>
                </thead>
                <tbody id="visits-body"></tbody>
            </table>
        </div>
    </div>

<script>
let autoTimer;

function ajax(url, cb) {
    const x = new XMLHttpRequest();
    x.open('GET', url, true);
    x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    x.onload = function() {
        if (x.status === 200) cb(JSON.parse(x.responseText));
        else if (x.status === 401) location.reload();
    };
    x.send();
}

function refresh() {
    document.getElementById('loading').style.display = 'inline';
    ajax('dashboard.php?action=data', function(d) {
        document.getElementById('loading').style.display = 'none';

        // Estatísticas
        document.getElementById('s-total').textContent = d.total_today;
        document.getElementById('s-allowed').textContent = d.allowed_today;
        document.getElementById('s-blocked').textContent = d.blocked_today;
        document.getElementById('s-rate').textContent = d.pass_rate + '%';
        document.getElementById('updated').textContent = d.updated_at;

        // Painéis
        renderPanel('p-countries', d.top_countries);
        renderPanel('p-referrers', d.top_referrers);
        renderPanel('p-devices', d.devices);

        // Tabela
        let html = '';
        (d.visits || []).forEach(function(v) {
            const badge = v.action === 'allowed'
                ? '<span class="badge badge-green">OK</span>'
                : '<span class="badge badge-red">BLOCK</span>';
            const time = (v.ts || '').substring(11);
            const ref = v.ref ? (new URL(v.ref).hostname || v.ref) : '(direto)';
            html += '<tr>' +
                '<td>' + esc(time) + '</td>' +
                '<td>' + esc(v.ip || '') + '</td>' +
                '<td>' + esc(v.cc || '') + '</td>' +
                '<td>' + esc(v.device || '') + '</td>' +
                '<td>' + badge + '</td>' +
                '<td>' + esc(v.reason || '-') + '</td>' +
                '<td>' + esc(ref) + '</td>' +
                '<td>' + (v.ad || 0) + '</td>' +
                '<td>' + (v.ms || 0) + '</td>' +
                '</tr>';
        });
        document.getElementById('visits-body').innerHTML = html;
    });
}

function renderPanel(id, obj) {
    let html = '';
    for (const key in obj) {
        html += '<div class="panel-row"><span>' + esc(key) + '</span><span class="count">' + obj[key] + '</span></div>';
    }
    document.getElementById(id).innerHTML = html || '<div style="color:#475569;font-size:.85rem">Sem dados</div>';
}

function clearLogs() {
    if (!confirm('Tem certeza que deseja limpar todos os logs?')) return;
    ajax('dashboard.php?action=clear', function() { refresh(); });
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// Atualização automática a cada 5 segundos
refresh();
autoTimer = setInterval(refresh, 5000);
</script>
</body>
</html>
