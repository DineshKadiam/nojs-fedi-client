<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';

// The instance may redirect here with an error instead of a code
if (!empty($_GET['error'])) {
    $err_msg = $_GET['error_description'] ?? $_GET['error'];
    html_head('Error');
    html_error('Authorization failed: ' . $err_msg);
    echo '<p><a href="login.php">Back to login</a></p>';
    html_foot();
    exit;
}

$code     = $_GET['code'] ?? '';
$state    = $_GET['state'] ?? '';
$instance = $_SESSION['oauth_instance'] ?? '';

// Validate state
if (!$state || !hash_equals($_SESSION['oauth_state'] ?? '', $state)) {
    html_head('Error');
    html_error('Invalid OAuth state. Please try logging in again.');
    echo '<p><a href="login.php">Back to login</a></p>';
    html_foot();
    exit;
}

if (!$code || !$instance) {
    html_head('Error');
    html_error('Missing authorization code or instance. Please try logging in again.');
    echo '<p><a href="login.php">Back to login</a></p>';
    html_foot();
    exit;
}

// Look up the app credentials
$stmt = db()->prepare('SELECT client_id, client_secret FROM oauth_apps WHERE instance = ?');
$stmt->execute([$instance]);
$app = $stmt->fetch();

if (!$app) {
    html_head('Error');
    html_error('App registration not found. Please try logging in again.');
    echo '<p><a href="login.php">Back to login</a></p>';
    html_foot();
    exit;
}

// Exchange code for access token
$token_data = api_token_exchange($instance, $app['client_id'], $app['client_secret'], $code);

if (isset($token_data['error'])) {
    html_head('Error');
    html_error('Token exchange failed: ' . $token_data['error']);
    echo '<p><a href="login.php">Back to login</a></p>';
    html_foot();
    exit;
}

$access_token = $token_data['access_token'] ?? '';
if (!$access_token) {
    html_head('Error');
    html_error('No access token received.');
    echo '<p><a href="login.php">Back to login</a></p>';
    html_foot();
    exit;
}

// Fetch account info
$account = api_get($instance, $access_token, '/api/v1/accounts/verify_credentials');

if (isset($account['error'])) {
    html_head('Error');
    html_error('Could not fetch account info: ' . $account['error']);
    echo '<p><a href="login.php">Back to login</a></p>';
    html_foot();
    exit;
}

// Determine next account index (no reindexing - gaps are allowed)
$accounts = $_SESSION['accounts'] ?? [];
$new_a    = empty($accounts) ? 0 : (max(array_keys($accounts)) + 1);

unset($_SESSION['oauth_state'], $_SESSION['oauth_instance']);
$_SESSION['accounts'][$new_a] = [
    'instance'         => $instance,
    'enc_access_token' => encrypt_token($access_token),
    'account'         => [
        'id'           => $account['id'] ?? '',
        'username'     => $account['username'] ?? '',
        'display_name' => $account['display_name'] ?? '',
        'avatar'       => $account['avatar'] ?? '',
        'locked'       => !empty($account['locked']),
    ],
    'instance_config' => null,
];

redirect('index.php?a=' . $new_a);
