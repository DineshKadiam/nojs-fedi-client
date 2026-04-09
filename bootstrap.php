<?php
declare(strict_types=1);

define('SMOLFEDI_VERSION', '1.0.8');

if (!defined('API_TIMEOUT')) {
    define('API_TIMEOUT', 30);
}

date_default_timezone_set('UTC');

define('SMOLFEDI_USER_AGENT', 'SmolFedi/'.SMOLFEDI_VERSION);

ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
$_db_dir = dirname(DB_PATH);
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
