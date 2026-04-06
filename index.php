<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';

require_login();

$instance = $_SESSION['instance'];
$token    = $_SESSION['access_token'];

$params = ['limit' => POSTS_PER_PAGE];
if (!empty($_GET['max_id'])) {
    $params['max_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['max_id']);
}
if (!empty($_GET['min_id'])) {
    $params['min_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['min_id']);
}

$statuses = api_get($instance, $token, '/api/v1/timelines/home', $params);

html_head('Home');
echo '<section>' . "\n";
echo '<h1>Home Timeline</h1>' . "\n";

if (isset($statuses['error'])) {
    html_error($statuses['error']);
} elseif (empty($statuses)) {
    echo '<p>No posts yet.</p>' . "\n";
} else {
    foreach ($statuses as $status) {
        html_post($status);
    }

    // Pagination
    $oldest_id = $statuses[count($statuses) - 1]['id'] ?? null;
    $newest_id = $statuses[0]['id'] ?? null;

    echo '<nav>' . "\n";
    if ($oldest_id) {
        echo '<a href="index.php?max_id=' . h($oldest_id) . '">&larr; Older</a> ';
    }
    if ($newest_id && isset($_GET['max_id'])) {
        echo '<a href="index.php?min_id=' . h($newest_id) . '">Newer &rarr;</a>';
    }
    echo "\n" . '</nav>' . "\n";
}

echo '</section>' . "\n";
html_foot();
