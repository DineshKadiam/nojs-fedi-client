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

$allowed_types = ['mention', 'reblog', 'favourite', 'follow', 'follow_request', 'poll', 'update', 'status'];
$type_labels   = [
    'mention'        => '&#128172; Mentions',
    'reblog'         => '&#8635; Boosts',
    'favourite'      => '&#9733; Favs',
    'follow'         => '&#43; Follows',
    'follow_request' => '&#63; Follow reqs',
    'poll'           => '&#128203; Polls',
    'update'         => '&#9998; Edits',
    'status'         => '&#128276; Posts',
];

$filter_type = $_GET['type'] ?? '';
if (!in_array($filter_type, $allowed_types, true)) {
    $filter_type = '';
}

$params = ['limit' => POSTS_PER_PAGE];
if ($filter_type) {
    $params['types[]'] = $filter_type;
}
if (!empty($_GET['max_id'])) {
    $params['max_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['max_id']);
}
if (!empty($_GET['min_id'])) {
    $params['min_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['min_id']);
}

$notifications = api_get($instance, $token, '/api/v1/notifications', $params);

html_head('Notifications');
echo '<section>' . "\n";
echo '<h1>Notifications</h1>' . "\n";

// Filter sub-nav
echo '<nav>' . "\n";
echo ($filter_type ? '<a href="' . url('notifications.php') . '">All</a>' : '<strong>All</strong>');
foreach ($type_labels as $t => $label) {
    echo ' | ';
    if ($filter_type === $t) {
        echo '<strong>' . $label . '</strong>';
    } else {
        echo '<a href="' . url('notifications.php', ['type' => $t]) . '">' . $label . '</a>';
    }
}
echo "\n" . '</nav>' . "\n";

if (isset($notifications['error'])) {
    html_error($notifications['error']);
} elseif (empty($notifications)) {
    echo '<p>No notifications.</p>' . "\n";
} else {
    foreach ($notifications as $notif) {
        html_notification($notif);
    }

    $oldest_id = $notifications[count($notifications) - 1]['id'] ?? null;
    $newest_id = $notifications[0]['id'] ?? null;
    $type_param = $filter_type ? ['type' => $filter_type] : [];

    echo '<nav>' . "\n";
    if ($oldest_id) {
        echo '<a href="' . url('notifications.php', $type_param + ['max_id' => $oldest_id]) . '">&larr; Older</a> ';
    }
    if ($newest_id && isset($_GET['max_id'])) {
        echo '<a href="' . url('notifications.php', $type_param + ['min_id' => $newest_id]) . '">Newer &rarr;</a>';
    }
    echo "\n" . '</nav>' . "\n";
}

echo '</section>' . "\n";
html_foot();
