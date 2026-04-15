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

$list_id = preg_replace('/[^A-Za-z0-9]/', '', $_GET['list_id'] ?? '');
if ($list_id === '') {
    html_head('List');
    html_error('No list ID provided.');
    html_foot();
    exit;
}

$list_info = api_get($instance, $token, '/api/v1/lists/' . $list_id);
$list_title = $list_info['title'] ?? 'List';

$params = ['limit' => pref('posts_per_page', POSTS_PER_PAGE)];
if (!empty($_GET['max_id'])) {
    $params['max_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['max_id']);
}
if (!empty($_GET['min_id'])) {
    $params['min_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['min_id']);
}

$statuses = api_get($instance, $token, '/api/v1/timelines/list/' . $list_id, $params);

html_head(h($list_title));
echo '<section>' . "\n";
echo '<h1>' . h($list_title) . '</h1>' . "\n";

if (isset($statuses['error'])) {
    html_error($statuses['error']);
} elseif (empty($statuses)) {
    echo '<p>No posts in this list yet.</p>' . "\n";
} else {
    foreach ($statuses as $status) {
        html_post($status);
    }

    $oldest_id = $statuses[count($statuses) - 1]['id'] ?? null;
    $newest_id = $statuses[0]['id'] ?? null;

    html_pagination('list.php', ['list_id' => $list_id], $oldest_id, isset($_GET['max_id']) ? $newest_id : null);
}

echo '</section>' . "\n";
html_foot();
