<?php
declare(strict_types=1);

define('SMOLFEDI_VERSION', '1.2.7');

if (!defined('API_TIMEOUT')) {
    define('API_TIMEOUT', 30);
}

if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', 0);
}

date_default_timezone_set('UTC');

define('SMOLFEDI_USER_AGENT', 'SmolFedi/'.SMOLFEDI_VERSION);

ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
$_db_dir = dirname(DB_PATH);
if (!is_writable($_db_dir)) {
    $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid()) : posix_geteuid();
    die('<pre>SmolFedi setup error: the data directory is not writable by the web server.' . "\n"
        . 'Run: chown ' . $user . ' ' . escapeshellarg(realpath($_db_dir) ?: $_db_dir) . "\n"
        . ' or: chmod 0700 ' . escapeshellarg(realpath($_db_dir) ?: $_db_dir) . "\n"
        . 'Web server user: ' . $user . '</pre>');
}
if (!file_exists($_db_dir . '/index.html')) {
    file_put_contents($_db_dir . '/index.html', '');
}
unset($_db_dir);

if (!is_dir(SESSIONS_PATH)) {
    mkdir(SESSIONS_PATH, 0700, true);
}
if (!file_exists(SESSIONS_PATH . '/index.html')) {
    file_put_contents(SESSIONS_PATH . '/index.html', '');
}
ini_set('session.save_path', SESSIONS_PATH);
ini_set('session.save_handler', 'files');
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
// Allow session ID to be passed via URL/form when the browser sends no cookie
// (e.g. Dillo). PHP will only append the SID to HTML output when no cookie
// was received, so normal browsers are unaffected.
ini_set('session.use_only_cookies', '0');
ini_set('session.use_trans_sid',    '1');
session_start();

$available_langs = ['en' => 'English', 'de' => 'Deutsch', 'es' => 'Español', 'fr' => 'Français'];
if (!isset($_SESSION['prefs']['lang'])) {
    $lang = 'en';
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if ($accept !== '') {
        $candidates = [];
        foreach (explode(',', $accept) as $entry) {
            $entry = trim($entry);
            if (preg_match('/^([a-zA-Z]{2,3})(?:[-_][a-zA-Z0-9]+)*(?:;q=([0-9.]+))?/', $entry, $m)) {
                $candidates[] = [strtolower($m[1]), isset($m[2]) ? (float) $m[2] : 1.0];
            }
        }
        usort($candidates, fn($a, $b) => $b[1] <=> $a[1]);
        foreach ($candidates as [$code, $q]) {
            if (isset($available_langs[$code])) {
                $lang = $code;
                break;
            }
        }
    }
    $_SESSION['prefs']['lang'] = $lang;
}

$_sf_lang_file = __DIR__ . '/lang/' . ($_SESSION['prefs']['lang'] ?? 'en') . '.php';
$lang_strings = file_exists($_sf_lang_file) ? require $_sf_lang_file : require __DIR__ . '/lang/en.php';
unset($_sf_lang_file);

date_default_timezone_set($_SESSION['prefs']['timezone'] ?? 'UTC');
