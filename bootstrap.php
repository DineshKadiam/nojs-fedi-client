<?php
declare(strict_types=1);

define('SMOLFEDI_VERSION', '1.0.9');

if (!defined('API_TIMEOUT')) {
    define('API_TIMEOUT', 30);
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
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
date_default_timezone_set($_SESSION['prefs']['timezone'] ?? 'UTC');
