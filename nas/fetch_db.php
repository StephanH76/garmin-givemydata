<?php
/**
 * Download the newest garmin.db from the private draft release "garmin-db".
 * Run daily on the NAS (Task Scheduler):  php nas/fetch_db.php
 * Needs a GitHub token in nas/github_token (or GARMIN_GITHUB_TOKEN).
 * Only downloads when the release has a newer file. Uses no extensions beyond openssl.
 */
$cfg = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$repo = getenv('GARMIN_REPO') ?: (isset($cfg['repo']) ? $cfg['repo'] : 'StephanH76/garmin-givemydata');
$target = getenv('GARMIN_DB_PATH') ?: (isset($cfg['db_path']) ? $cfg['db_path'] : __DIR__ . '/../data/garmin.db');
$token = getenv('GARMIN_GITHUB_TOKEN') ?: trim((string) @file_get_contents(__DIR__ . '/github_token'));
function out($m) { echo date('Y-m-d H:i:s') . ' ' . $m . PHP_EOL; }
if ($token === '') { out('No GitHub token (nas/github_token) - cannot fetch garmin.db.'); exit(1); }

function ctx($headers, $follow) {
    $opts = ['header' => $headers, 'follow_location' => $follow, 'max_redirects' => 5, 'timeout' => 300, 'ignore_errors' => true];
    $proxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy');
    if ($proxy) { $opts['proxy'] = preg_replace('#^https?://#', 'tcp://', $proxy); $opts['request_fulluri'] = true; }
    $ssl = getenv('SSL_CERT_FILE') ? ['cafile' => getenv('SSL_CERT_FILE')] : [];
    return stream_context_create(['http' => $opts, 'ssl' => $ssl]);
}
function gh($url, $accept, $sink = null) {
    global $token;
    $headers = "Authorization: Bearer $token\r\nAccept: $accept\r\nUser-Agent: garmin-nas\r\nX-GitHub-Api-Version: 2022-11-28\r\n";
    if ($sink === null) {
        $body = @file_get_contents($url, false, ctx($headers, 1));
        $code = isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) ? (int) $m[1] : 0;
        return [$code, $body];
    }
    // The asset URL redirects to a storage host that must NOT get the GitHub token.
    @file_get_contents($url, false, ctx($headers, 0), 0, 1);
    $location = null;
    foreach ((array) $http_response_header as $hl) if (stripos($hl, 'Location:') === 0) $location = trim(substr($hl, 9));
    $src = $location ?: $url;
    $in = @fopen($src, 'rb', false, ctx($location ? "User-Agent: garmin-nas\r\nAccept: application/octet-stream\r\n" : $headers, 1));
    if (!$in) return [0, null];
    $outf = fopen($sink, 'wb');
    $n = stream_copy_to_stream($in, $outf);
    fclose($in); fclose($outf);
    return [200, $n];
}

list($code, $body) = gh("https://api.github.com/repos/$repo/releases?per_page=100", 'application/vnd.github+json');
if ($code !== 200) { out("GitHub API error $code: " . substr((string) $body, 0, 200)); exit(1); }
$asset = null;
foreach (json_decode($body, true) as $rel) {
    if ($rel['tag_name'] !== 'garmin-db') continue;
    foreach ($rel['assets'] as $a) if ($a['name'] === 'garmin.db') $asset = $a;
}
if (!$asset) { out("No garmin.db in release 'garmin-db' yet."); exit(1); }
$uploaded = strtotime($asset['updated_at']);
if (is_file($target) && filemtime($target) >= $uploaded) { out('garmin.db is up to date (uploaded ' . gmdate('Y-m-d H:i', $uploaded) . ' UTC).'); exit(0); }
@mkdir(dirname($target), 0755, true);
$tmp = $target . '.download';
out('Downloading garmin.db (' . round($asset['size'] / 1e6, 1) . ' MB, uploaded ' . gmdate('Y-m-d H:i', $uploaded) . ' UTC)...');
list($c, $n) = gh($asset['url'], 'application/octet-stream', $tmp);
if (!is_file($tmp) || filesize($tmp) !== (int) $asset['size']) { @unlink($tmp); out('Download incomplete - keeping the existing garmin.db.'); exit(1); }
@unlink($target . '-wal'); @unlink($target . '-shm');
rename($tmp, $target);
touch($target, $uploaded);
out('garmin.db updated.');
