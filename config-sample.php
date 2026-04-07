<?php
declare(strict_types=1);

// Fediverse instance this client connects to.
// Users will log in with accounts on this instance.
define('DEFAULT_INSTANCE', 'https://mastodon.social');

// SQLite database path - use a hard-to-guess filename and keep it outside
// the document root if possible, or protect it with .htaccess.
define('DB_PATH', __DIR__ . '/data/yourdb.sqlite');

// OAuth callback URL - must match exactly what is registered on the instance.
// Set this to the public URL of your callback.php.
define('OAUTH_REDIRECT_URI', 'https://yourdomain.example/callback.php');

// Application name shown to the user during OAuth authorization.
// Includes the hostname so multiple SmolFedi instances are distinguishable
// in the user's authorized apps list on their Fediverse account.
define('APP_NAME', 'SmolFedi/' . parse_url(OAUTH_REDIRECT_URI, PHP_URL_HOST));

// Session cookie lifetime in seconds. Default: 6 months.
define('SESSION_LIFETIME', 31 * 24 * 3600);

// Number of posts displayed per page.
define('POSTS_PER_PAGE', 20);

// Maximum image width (px) passed through to the client. Set to 0 for no limit.
define('MAX_IMAGE_WIDTH', 800);

