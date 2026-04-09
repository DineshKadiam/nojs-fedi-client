<?php
declare(strict_types=1);

function current_a(): int {
    return $GLOBALS['_current_a'] ?? 0;
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
    return $_SESSION['accounts'][$a];
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
