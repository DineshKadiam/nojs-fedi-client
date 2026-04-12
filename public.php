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

$is_local  = !empty($_GET['local']);
$title     = $is_local ? 'Local Timeline' : 'Federated Timeline';

$params = ['limit' => pref('posts_per_page', POSTS_PER_PAGE)];
if ($is_local) {
    $params['local'] = 'true';
}
if (!empty($_GET['max_id'])) {
    $params['max_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['max_id']);
}
if (!empty($_GET['min_id'])) {
    $params['min_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['min_id']);
}

$statuses = api_get($instance, $token, '/api/v1/timelines/public', $params);

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

    $oldest_id = $statuses[count($statuses) - 1]['id'] ?? null;
    $newest_id = $statuses[0]['id'] ?? null;
    $base_params = $is_local ? ['local' => '1'] : [];

    echo '<nav><ul>' . "\n";
    if ($oldest_id) {
        echo '<li><a href="' . url('public.php', $base_params + ['max_id' => $oldest_id]) . '">&larr; Older</a></li>' . "\n";
    }
    if ($newest_id && isset($_GET['max_id'])) {
        echo '<li><a href="' . url('public.php', $base_params + ['min_id' => $newest_id]) . '">Newer &rarr;</a></li>' . "\n";
    }
    echo '</ul></nav>' . "\n";
}

echo '</section>' . "\n";
html_foot();
