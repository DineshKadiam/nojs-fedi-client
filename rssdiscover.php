<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';

$ctx = require_login();

$u = trim($_GET['u'] ?? '');

html_head(t('rssdiscover_title'));
echo '<h1>' . t('rssdiscover_title') . '</h1>' . "\n";

if ($u === '') {
    html_error(t('rssdiscover_invalid_url'));
    html_foot();
    exit;
}

// SSRF guard: https only, public IPs only
$host  = parse_url($u, PHP_URL_HOST);
$valid = false;
if (preg_match('#^https://#i', $u) && $host && strlen($host) <= 253) {
    $ip = gethostbyname($host);
    if ($ip !== $host) {
        $valid = filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
if (!$valid) {
    html_error(t('rssdiscover_invalid_url'));
    html_foot();
    exit;
}

// Fetch HTML, stop as soon as </head> is seen or after 64 KB
$buf = '';
$ch  = curl_init();
curl_setopt($ch, CURLOPT_URL, $u);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: text/html',
    'User-Agent: ' . SMOLFEDI_USER_AGENT,
]);
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$buf): int {
    $buf .= $data;
    if (stripos($buf, '</head>') !== false || strlen($buf) > 65536) {
        return 0; // abort (errno 23)
    }
    return strlen($data);
});
curl_exec($ch);
$curl_err = curl_errno($ch);
curl_close($ch);

// errno 23 = CURLE_WRITE_ERROR: expected when we abort early
if ($curl_err !== 0 && $curl_err !== 23) {
    html_error(t('rssdiscover_fetch_error'));
    html_foot();
    exit;
}

// Narrow to <head> section before parsing
if (($end = stripos($buf, '</head>')) !== false) {
    $buf = substr($buf, 0, $end);
}

// Find first RSS or Atom <link rel="alternate"> tag
$rss_url   = null;
$rss_title = '';
if (preg_match_all('/<link([^>]+)>/i', $buf, $matches)) {
    foreach ($matches[1] as $attrs) {
        if (!preg_match('/\brel=["\']alternate["\']/i', $attrs)) continue;
        if (!preg_match('/\btype=["\']application\/(rss|atom)\+xml["\']/i', $attrs)) continue;
        if (preg_match('/\bhref=["\']([^"\']+)["\']/i', $attrs, $m)) {
            $rss_url = $m[1];
        }
        if (preg_match('/\btitle=["\']([^"\']*)["\']/', $attrs, $m)) {
            $rss_title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        break;
    }
}

// Resolve relative URLs
if ($rss_url !== null && !preg_match('#^https?://#i', $rss_url)) {
    $scheme  = parse_url($u, PHP_URL_SCHEME) ?? 'https';
    $rss_url = $scheme . '://' . $host . '/' . ltrim($rss_url, '/');
}

echo '<p>' . t('rssdiscover_source') . ' <code>' . h($u) . '</code></p>' . "\n";

if ($rss_url !== null) {
    echo '<p>' . t('rssdiscover_found') . '</p>' . "\n";
    echo '<p><input type="url" readonly value="' . h($rss_url) . '"></p>' . "\n";
    if ($rss_title !== '') {
        echo '<p><small>' . h($rss_title) . '</small></p>' . "\n";
    }
    echo '<p><a href="' . h($rss_url) . '" target="_blank" rel="noopener noreferrer">' . t('rssdiscover_open') . '</a></p>' . "\n";
} else {
    echo '<p>' . t('rssdiscover_not_found') . '</p>' . "\n";
}

html_foot();
