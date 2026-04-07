<?php
declare(strict_types=1);

// Fediverse instance this client connects to.
// Users will log in with accounts on this instance.
define('DEFAULT_INSTANCE', 'https://mastodon.social');

// SQLite database path - use a hard-to-guess filename and keep it outside
// the document root if possible, or protect it with .htaccess.
define('DB_PATH', __DIR__ . '/data/yourdb.sqlite');

// Application name shown to the user during OAuth authorization.
define('APP_NAME', 'SmolFedi');

// OAuth callback URL - must match exactly what is registered on the instance.
// Set this to the public URL of your callback.php.
define('OAUTH_REDIRECT_URI', 'https://yourdomain.example/callback.php');

// Session cookie lifetime in seconds. Default: 6 months.
define('SESSION_LIFETIME', 15_552_000);

// Number of posts displayed per page.
define('POSTS_PER_PAGE', 20);

// Maximum image width (px) passed through to the client. Set to 0 for no limit.
define('MAX_IMAGE_WIDTH', 800);

// Bootstrap session
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
