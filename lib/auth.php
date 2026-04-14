<?php
declare(strict_types=1);

function current_a(): int {
    return $GLOBALS['_current_a'] ?? 0;
}

function current_token(): string {
    return $GLOBALS['_current_token'] ?? '';
}

// Authenticated symmetric encryption for stored OAuth access tokens.
// Output: base64(nonce || ciphertext) so it survives session serialization.
function encrypt_token(string $plain): string {
    if (!function_exists('sodium_crypto_secretbox')) {
        die('SmolFedi requires the PHP sodium extension (bundled by default since PHP 7.2).');
    }
    $key = base64_decode(SESSION_ENC_KEY, true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        die('SESSION_ENC_KEY must be a base64-encoded 32-byte key. See config-sample.php.');
    }
    $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
    return base64_encode($nonce . $cipher);
}

// Returns the plaintext token, or null if the input is corrupted or the key changed.
function decrypt_token(string $encoded): ?string {
    if (!function_exists('sodium_crypto_secretbox_open')) {
        return null;
    }
    $key = base64_decode(SESSION_ENC_KEY, true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        return null;
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1) {
        return null;
    }
    $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    return $plain === false ? null : $plain;
}

// Returns true if there is at least one logged-in account
function is_logged_in(): bool {
    return !empty($_SESSION['accounts']);
}

// Validate $a (from GET or POST), set current_a, return account context; redirect to login if none found
function require_login(): array {
    $a = -1;
    if (isset($_GET['a'])) {
        $a = (int) $_GET['a'];
    } elseif (isset($_POST['a'])) {
        $a = (int) $_POST['a'];
    }

    if (!isset($_SESSION['accounts'][$a])) {
        if (!empty($_SESSION['accounts'])) {
            $default = (int) ($_SESSION['prefs']['default_account'] ?? -1);
            $a = isset($_SESSION['accounts'][$default]) ? $default : (int) array_key_first($_SESSION['accounts']);
        } else {
            header('Location: login.php');
            exit;
        }
    }

    $GLOBALS['_current_a'] = $a;

    // Migrate legacy plaintext tokens, then return a copy with the token decrypted
    // for use during this request. Session storage retains only enc_access_token.
    $acct = &$_SESSION['accounts'][$a];
    if (isset($acct['access_token']) && !isset($acct['enc_access_token'])) {
        $acct['enc_access_token'] = encrypt_token($acct['access_token']);
        unset($acct['access_token']);
    }
    $plain = isset($acct['enc_access_token']) ? decrypt_token($acct['enc_access_token']) : null;
    if ($plain === null) {
        // Key changed or session corrupted: drop this account, force re-login.
        unset($_SESSION['accounts'][$a]);
        if (empty($_SESSION['accounts'])) {
            header('Location: login.php');
        } else {
            header('Location: index.php');
        }
        exit;
    }
    $ctx = $acct;
    unset($ctx['enc_access_token']);
    $ctx['access_token']        = $plain;
    $GLOBALS['_current_token']  = $plain;
    return $ctx;
}

// Returns true when the current account requires follow approval.
// Fills the cached value lazily via verify_credentials for sessions that predate this field.
function account_locked(int $a): bool {
    $ctx = $_SESSION['accounts'][$a] ?? null;
    if ($ctx === null) {
        return false;
    }
    if (!array_key_exists('locked', $ctx['account'] ?? [])) {
        $me = api_get($ctx['instance'], $ctx['access_token'], '/api/v1/accounts/verify_credentials');
        $locked = is_array($me) && !isset($me['error']) ? !empty($me['locked']) : false;
        $_SESSION['accounts'][$a]['account']['locked'] = $locked;
    }
    return !empty($_SESSION['accounts'][$a]['account']['locked']);
}

// Get or register the OAuth app for a given instance; returns ['client_id'=>..., 'client_secret'=>...]
function get_or_register_app(string $instance): array {
    $db   = db();
    $stmt = $db->prepare('SELECT client_id, client_secret FROM oauth_apps WHERE instance = ?');
    $stmt->execute([$instance]);
    $row = $stmt->fetch();

    if ($row) {
        return $row;
    }

    $result = api_register_app($instance);
    if (isset($result['error'])) {
        return $result;
    }
    if (empty($result['client_id']) || empty($result['client_secret'])) {
        return ['error' => 'Unexpected response from instance'];
    }

    $insert = $db->prepare(
        'INSERT INTO oauth_apps (instance, client_id, client_secret, created_at) VALUES (?, ?, ?, ?)'
    );
    $insert->execute([$instance, $result['client_id'], $result['client_secret'], time()]);

    return ['client_id' => $result['client_id'], 'client_secret' => $result['client_secret']];
}

// Generate CSRF token and store it in the session
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verify submitted CSRF token; die on failure
function csrf_verify(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

// Normalize and validate an instance URL
function normalize_instance(string $raw): string|false {
    $raw = trim($raw);
    if (!str_starts_with($raw, 'http://') && !str_starts_with($raw, 'https://')) {
        $raw = 'https://' . $raw;
    }
    $parsed = parse_url($raw);
    if (empty($parsed['host'])) {
        return false;
    }
    return 'https://' . strtolower($parsed['host']);
}
