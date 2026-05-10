<?php
declare(strict_types=1);

// Fediverse instance this client connects to.
// Users will log in with accounts on this instance.
define('DEFAULT_INSTANCE', 'https://mastodon.social');

// SQLite database path. STRONGLY RECOMMENDED to keep this OUTSIDE the document
// root. The DB stores OAuth client_id/client_secret pairs per instance; if the
// docroot were misconfigured to serve raw files, those secrets would leak.
//
// Recommended (outside docroot):
//   define('DB_PATH', '/var/lib/smolfedi/yourdb.sqlite');
// The parent directory must exist and be writable by the web server user.
//
// Fallback (inside docroot, only if you cannot place files outside it):
//   define('DB_PATH', __DIR__ . '/data/yourdb.sqlite');
// In that case, ensure your web server denies access (see README, .htaccess example),
// and use a hard-to-guess filename.
define('DB_PATH', '/var/lib/smolfedi/yourdb.sqlite');

// Session files path. STRONGLY RECOMMENDED to keep this OUTSIDE the document root.
// Session files contain encrypted access tokens (see SESSION_ENC_KEY below) plus
// other per-account state. A web-server misconfiguration that exposes the docroot
// could otherwise leak the entire sessions directory.
//
// Recommended (outside docroot):
//   define('SESSIONS_PATH', '/var/lib/smolfedi/sessions');
// The directory will be created automatically with mode 0700 on first run; it must
// be writable by the web server user (e.g. www-data).
//
// Fallback (inside docroot, only if you cannot place files outside it):
//   define('SESSIONS_PATH', __DIR__ . '/data/sessions');
// In that case, ensure your web server denies access (see README, .htaccess example).
define('SESSIONS_PATH', '/var/lib/smolfedi/sessions');

// Symmetric key used to encrypt OAuth access tokens at rest in session files.
// MUST be a 32-byte random key, base64-encoded (44 chars ending with `=`).
//
// Generate one (pick either):
//   bash:  head -c 32 /dev/urandom | base64
//   php:   php -r 'echo base64_encode(random_bytes(32)), "\n";'
//
// Keep this secret. If it leaks, all stored tokens are decryptable. If you change
// it, all logged-in users are signed out (their encrypted tokens become unreadable).
// Encryption uses AES-256-GCM via the openssl extension, which is bundled with
// virtually every PHP build (it is what powers HTTPS support).
define('SESSION_ENC_KEY', 'PASTE_BASE64_KEY_HERE');

// OAuth callback URL - must match exactly what is registered on the instance.
// Set this to the public URL of your callback.php.
define('OAUTH_REDIRECT_URI', 'https://yourdomain.example/callback.php');

// Application name shown to the user during OAuth authorization.
// Includes the hostname so multiple SmolFedi instances are distinguishable
// in the user's authorized apps list on their Fediverse account.
define('APP_NAME', 'SmolFedi/' . parse_url(OAUTH_REDIRECT_URI, PHP_URL_HOST));

// Session cookie lifetime in seconds. Default: 1 month.
define('SESSION_LIFETIME', 31 * 24 * 3600);

// Number of posts displayed per page.
define('POSTS_PER_PAGE', 20);

// Maximum number of entries allowed for ePub export.
// Users can set any value up to this limit in their preferences.
define('EPUB_MAX_ENTRIES', 80);

// Maximum image width (px) passed through to the client. Set to 0 for no limit.
define('MAX_IMAGE_WIDTH', 800);

// Timeout in seconds for API requests. Increase on slow servers or instances.
define('API_TIMEOUT', 30);

// Cache TTL in seconds for session-cached API responses (filters, lists, trending, …).
define('CACHE_TTL', 600);

// Debug mode: set to 1 to log all Fediverse API calls to the session and display
// them at the end of each page. Never enable this in production.
define('DEBUG_MODE', 0);
