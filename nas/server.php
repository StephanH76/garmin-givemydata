<?php
/**
 * Garmin MCP server for a Synology NAS (or any machine with PHP 7.4+ and pdo_sqlite).
 *
 * Serves garmin.db to the Claude connector: OAuth login with one password,
 * MCP over Streamable HTTP (JSON responses) on /mcp. No Python, no Docker.
 *
 * Run:   php -S 127.0.0.1:8099 nas/server.php
 * Public HTTPS via Tailscale Funnel:  tailscale funnel --bg 8099
 * Settings: nas/config.php (copy nas/config.example.php).
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$CFG = [];
$cfgFile = __DIR__ . '/config.php';
if (is_file($cfgFile)) { $CFG = require $cfgFile; }
function cfg($key, $default = null) {
    global $CFG;
    $env = getenv('GARMIN_' . strtoupper($key));
    if ($env !== false && $env !== '') return $env;
    return isset($CFG[$key]) && $CFG[$key] !== '' ? $CFG[$key] : $default;
}

$PASSWORD = (string) cfg('password', '');
$DB_PATH = (string) cfg('db_path', __DIR__ . '/../garmin.db');
$STATE_DIR = (string) cfg('state_dir', __DIR__);
$SECRET = 'garmin-mcp:' . $PASSWORD;
$TOKEN_LIFETIME = 365 * 86400;

function base_url() {
    $b = cfg('base_url', '');
    if ($b) return rtrim($b, '/');
    $host = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
    return 'https://' . $host;
}

// ---------- helpers ----------
function send_json($data, $status = 200, $headers = []) {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    foreach ($headers as $h) header($h);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function b64u_enc($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec($s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function sign_blob($kind, $payload) {
    global $SECRET;
    $body = b64u_enc(json_encode($payload));
    return $kind . '.' . $body . '.' . hash_hmac('sha256', $kind . '.' . $body, $SECRET);
}
function verify_blob($kind, $blob) {
    global $SECRET, $PASSWORD;
    if ($PASSWORD === '') return null;
    $parts = explode('.', (string) $blob);
    if (count($parts) !== 3 || $parts[0] !== $kind) return null;
    if (!hash_equals(hash_hmac('sha256', $kind . '.' . $parts[1], $SECRET), $parts[2])) return null;
    $data = json_decode(b64u_dec($parts[1]), true);
    if (!is_array($data) || !isset($data['e']) || $data['e'] < time()) return null;
    return $data;
}
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function bearer_ok() {
    $auth = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $auth = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    elseif (function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $auth = $v; }
    if (stripos($auth, 'Bearer ') !== 0) return false;
    return verify_blob('gt', trim(substr($auth, 7))) !== null;
}
function unauthorized() {
    send_json(['error' => 'unauthorized'], 401, ['WWW-Authenticate: Bearer resource_metadata="' . base_url() . '/.well-known/oauth-protected-resource"']);
}

// ---------- database ----------
function db() {
    global $DB_PATH;
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_file($DB_PATH)) throw new Exception('garmin.db not found');
    $pdo = new PDO('sqlite:' . $DB_PATH, null, null, [
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA query_only = ON');
    return $pdo;
}
function run_select($sql, $limit) {
    $sql = trim($sql);
    $sql = rtrim($sql, "; \n\r\t");
    if (!preg_match('/^(select|with)\b/i', $sql)) throw new Exception('Only SELECT queries are allowed.');
    if (strpos($sql, ';') !== false) throw new Exception('Only one statement is allowed.');
    $limit = max(1, min(10000, (int) $limit));
    $stmt = db()->query($sql);
    $rows = [];
    while (($r = $stmt->fetch()) !== false) { $rows[] = $r; if (count($rows) >= $limit) break; }
    return $rows;
}

// ---------- MCP ----------
function tool_defs() {
    return [
        [
            'name' => 'garmin_query',
            'description' => "Run a read-only SELECT query against the Garmin SQLite database (tables include activity, daily_summary, sleep, hrv, race_predictions, vo2max, weight, training_status, personal_record). Results are capped at limit rows (default 1000).",
            'inputSchema' => ['type' => 'object', 'properties' => ['sql' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'default' => 1000]], 'required' => ['sql']],
            'annotations' => ['readOnlyHint' => true],
        ],
        [
            'name' => 'garmin_schema',
            'description' => 'All tables with their columns and row counts.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            'annotations' => ['readOnlyHint' => true],
        ],
        [
            'name' => 'garmin_recent_activities',
            'description' => 'The most recent activities (name, type, start time, km, duration, heart rate, training load).',
            'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'default' => 10]]],
            'annotations' => ['readOnlyHint' => true],
        ],
        [
            'name' => 'garmin_status',
            'description' => 'When garmin.db was last updated and the date of the latest activity.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            'annotations' => ['readOnlyHint' => true],
        ],
    ];
}
function tool_call($name, $args) {
    global $DB_PATH;
    switch ($name) {
        case 'garmin_query':
            return run_select(isset($args['sql']) ? $args['sql'] : '', isset($args['limit']) ? $args['limit'] : 1000);
        case 'garmin_schema':
            $out = [];
            foreach (db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll() as $t) {
                $n = $t['name'];
                $cols = array_map(function ($c) { return $c['name']; }, db()->query('PRAGMA table_info("' . str_replace('"', '', $n) . '")')->fetchAll());
                $cnt = db()->query('SELECT count(*) AS c FROM "' . str_replace('"', '', $n) . '"')->fetch();
                $out[] = ['table' => $n, 'rows' => (int) $cnt['c'], 'columns' => $cols];
            }
            return $out;
        case 'garmin_recent_activities':
            $lim = max(1, min(200, (int) (isset($args['limit']) ? $args['limit'] : 10)));
            return run_select("SELECT activity_name, activity_type, start_time_local, round(distance_meters/1000.0,2) AS km, round(duration_seconds/60.0,1) AS minutes, average_hr, max_hr, training_load FROM activity ORDER BY start_time_local DESC LIMIT $lim", $lim);
        case 'garmin_status':
            $last = db()->query('SELECT max(start_time_local) AS last FROM activity')->fetch();
            return ['db_modified_utc' => gmdate('Y-m-d H:i', filemtime($DB_PATH)), 'last_activity' => $last['last']];
    }
    throw new Exception('Unknown tool: ' . $name);
}
function mcp_handle($msg) {
    $id = array_key_exists('id', $msg) ? $msg['id'] : null;
    $method = isset($msg['method']) ? $msg['method'] : '';
    if ($id === null) return null; // notification
    $ok = function ($result) use ($id) { return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]; };
    $err = function ($code, $text) use ($id) { return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $text]]; };
    $params = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : [];
    switch ($method) {
        case 'initialize':
            $pv = isset($params['protocolVersion']) ? $params['protocolVersion'] : '2025-06-18';
            return $ok([
                'protocolVersion' => $pv,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'garmin-nas', 'version' => '1.0.0'],
                'instructions' => 'Garmin Connect data (activities, sleep, HRV, training load, race predictions) in SQLite. Use garmin_schema to see tables and garmin_query for SELECT queries.',
            ]);
        case 'ping':
            return $ok(new stdClass());
        case 'tools/list':
            return $ok(['tools' => tool_defs()]);
        case 'tools/call':
            $name = isset($params['name']) ? $params['name'] : '';
            $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
            try {
                $res = tool_call($name, $args);
                return $ok(['content' => [['type' => 'text', 'text' => json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]], 'isError' => false]);
            } catch (Exception $e) {
                return $ok(['content' => [['type' => 'text', 'text' => json_encode(['error' => $e->getMessage()])]], 'isError' => true]);
            }
        case 'resources/list':
            return $ok(['resources' => []]);
        case 'prompts/list':
            return $ok(['prompts' => []]);
    }
    return $err(-32601, 'Method not found: ' . $method);
}

// ---------- login rate limit ----------
function too_many_attempts($record) {
    global $STATE_DIR;
    $f = $STATE_DIR . '/login_attempts.json';
    $now = time();
    $list = is_file($f) ? json_decode((string) @file_get_contents($f), true) : [];
    if (!is_array($list)) $list = [];
    $list = array_values(array_filter($list, function ($t) use ($now) { return $now - $t < 600; }));
    if ($record) { $list[] = $now; @file_put_contents($f, json_encode($list)); }
    return count($list) >= 5;
}

function login_page($p, $error = '') {
    global $PASSWORD;
    if ($PASSWORD === '') $error = 'Er is geen wachtwoord ingesteld in nas/config.php; inloggen is uitgeschakeld.';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Garmin MCP</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:360px;margin:15vh auto;padding:0 16px}input,button{width:100%;padding:12px;margin-top:10px;font-size:16px;box-sizing:border-box}.err{color:#b00020}</style></head><body>'
        . '<h2>Garmin MCP</h2><p>Voer het wachtwoord in om Claude toegang te geven.</p>' . ($error ? '<p class="err">' . h($error) . '</p>' : '')
        . '<form method="post" action="/oauth/authorize">';
    foreach (['redirect_uri', 'state', 'client_id', 'code_challenge'] as $k) echo '<input type="hidden" name="' . $k . '" value="' . h(isset($p[$k]) ? $p[$k] : '') . '">';
    echo '<input type="password" name="password" placeholder="Wachtwoord" autofocus required><button type="submit">Toegang geven</button></form></body></html>';
    exit;
}

// ---------- routing ----------
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$B = base_url();

if ($method === 'OPTIONS') { header('Access-Control-Allow-Origin: *'); header('Access-Control-Allow-Headers: *'); http_response_code(204); exit; }

if (preg_match('#^/\.well-known/oauth-protected-resource#', $path)) {
    send_json(['resource' => $B . '/mcp', 'authorization_servers' => [$B], 'bearer_methods_supported' => ['header']]);
}
if (preg_match('#^/\.well-known/(oauth-authorization-server|openid-configuration)#', $path)) {
    send_json([
        'issuer' => $B,
        'authorization_endpoint' => $B . '/oauth/authorize',
        'token_endpoint' => $B . '/oauth/token',
        'registration_endpoint' => $B . '/oauth/register',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
    ]);
}
if ($path === '/oauth/register' && $method === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    $body['client_id'] = bin2hex(random_bytes(16));
    $body['client_id_issued_at'] = time();
    $body['token_endpoint_auth_method'] = 'none';
    send_json($body, 201);
}
if ($path === '/oauth/authorize' && $method === 'GET') {
    login_page($_GET);
}
if ($path === '/oauth/authorize' && $method === 'POST') {
    if (too_many_attempts(false)) { http_response_code(429); echo 'Te veel pogingen. Probeer het over 10 minuten opnieuw.'; exit; }
    $pw = isset($_POST['password']) ? (string) $_POST['password'] : '';
    if ($PASSWORD === '' || !hash_equals($PASSWORD, $pw)) { too_many_attempts(true); login_page($_POST, 'Onjuist wachtwoord.'); }
    $redirect = isset($_POST['redirect_uri']) ? (string) $_POST['redirect_uri'] : '';
    if (!preg_match('#^https://#', $redirect)) { http_response_code(400); echo 'Ongeldige redirect_uri'; exit; }
    $code = sign_blob('gc', ['r' => $redirect, 'c' => isset($_POST['code_challenge']) ? (string) $_POST['code_challenge'] : '', 'e' => time() + 300, 'n' => bin2hex(random_bytes(6))]);
    $sep = strpos($redirect, '?') === false ? '?' : '&';
    header('Location: ' . $redirect . $sep . http_build_query(['code' => $code, 'state' => isset($_POST['state']) ? $_POST['state'] : '']), true, 303);
    exit;
}
if ($path === '/oauth/token' && $method === 'POST') {
    $in = $_POST;
    if (!$in) { $j = json_decode((string) file_get_contents('php://input'), true); if (is_array($j)) $in = $j; }
    $code = verify_blob('gc', isset($in['code']) ? $in['code'] : '');
    if (!$code) send_json(['error' => 'invalid_grant'], 400);
    if ($code['c'] !== '') {
        $ver = isset($in['code_verifier']) ? (string) $in['code_verifier'] : '';
        if (!hash_equals($code['c'], b64u_enc(hash('sha256', $ver, true)))) send_json(['error' => 'invalid_grant'], 400);
    }
    $tok = sign_blob('gt', ['e' => time() + $TOKEN_LIFETIME, 'n' => bin2hex(random_bytes(8))]);
    send_json(['access_token' => $tok, 'token_type' => 'bearer', 'expires_in' => $TOKEN_LIFETIME]);
}
if ($path === '/health') {
    $ok = is_file($DB_PATH);
    $last = null;
    try { if ($ok) { $r = db()->query('SELECT max(start_time_local) AS last FROM activity')->fetch(); $last = $r['last']; } } catch (Exception $e) { $last = 'error: ' . $e->getMessage(); }
    send_json(['status' => 'ok', 'base_url' => $B, 'password_set' => $PASSWORD !== '', 'db_present' => $ok,
        'db_modified_utc' => $ok ? gmdate('Y-m-d H:i', filemtime($DB_PATH)) : null, 'last_activity' => $last, 'php' => PHP_VERSION]);
}
if ($path === '/mcp' || $path === '/mcp/' || $path === '/sse') {
    if (!bearer_ok()) unauthorized();
    if ($method !== 'POST') { header('Allow: POST'); send_json(['error' => 'Use POST (Streamable HTTP)'], 405); }
    $req = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($req)) send_json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
    $isBatch = array_keys($req) === range(0, count($req) - 1);
    $msgs = $isBatch ? $req : [$req];
    $out = [];
    foreach ($msgs as $m) { if (is_array($m)) { $r = mcp_handle($m); if ($r !== null) $out[] = $r; } }
    if (!$out) { http_response_code(202); exit; }
    send_json($isBatch ? $out : $out[0]);
}
if ($path === '/') { header('Content-Type: text/plain'); echo "Garmin MCP server. Connector URL: $B/mcp\n"; exit; }
send_json(['error' => 'not found'], 404);
