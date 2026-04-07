<?php
declare(strict_types=1);

ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
$_sessions_path = __DIR__ . '/data/sessions';
if (!is_dir($_sessions_path)) {
    mkdir($_sessions_path, 0700, true);
}
ini_set('session.save_path', $_sessions_path);
unset($_sessions_path);
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
