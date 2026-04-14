<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';

$ctx      = require_login();
$a        = current_a();
$instance = $ctx['instance'];
$token    = $ctx['access_token'];

$timeline = $_GET['timeline'] ?? 'home';
if (!in_array($timeline, ['home', 'local', 'federated'], true)) {
    $timeline = 'home';
}

$titles = ['home' => 'Home Timeline', 'local' => 'Local Timeline', 'federated' => 'Federated Timeline'];
$title  = $titles[$timeline];

$params = ['limit' => pref('posts_per_page', POSTS_PER_PAGE)];
if (!empty($_GET['max_id'])) {
    $params['max_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['max_id']);
}
if (!empty($_GET['min_id'])) {
    $params['min_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['min_id']);
}

if ($timeline === 'home') {
    $endpoint = '/api/v1/timelines/home';
} else {
    $endpoint = '/api/v1/timelines/public';
    if ($timeline === 'local') {
        $params['local'] = 'true';
    }
}

$statuses = api_get($instance, $token, $endpoint, $params);

html_head($title);
echo '<section>' . "\n";
echo '<h1>' . $title . '</h1>' . "\n";

if (isset($statuses['error'])) {
    html_error($statuses['error']);
} elseif (empty($statuses)) {
    echo '<p>No posts yet.</p>' . "\n";
} else {
    foreach ($statuses as $status) {
        html_post($status);
    }

    $oldest_id   = $statuses[count($statuses) - 1]['id'] ?? null;
    $newest_id   = isset($_GET['max_id']) ? ($statuses[0]['id'] ?? null) : null;
    $base_params = $timeline !== 'home' ? ['timeline' => $timeline] : [];

    html_pagination('index.php', $base_params, $oldest_id, $newest_id);
}

echo '</section>' . "\n";
html_foot();
