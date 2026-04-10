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

$params = ['limit' => pref('posts_per_page', POSTS_PER_PAGE)];
if (!empty($_GET['max_id'])) {
    $params['max_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['max_id']);
}

$conversations = api_get($instance, $token, '/api/v1/conversations', $params);

html_head('Messages');
echo '<section>' . "\n";
echo '<h1>Messages</h1>' . "\n";

if (isset($conversations['error'])) {
    html_error($conversations['error']);
} elseif (empty($conversations)) {
    echo '<p>No messages.</p>' . "\n";
} else {
    foreach ($conversations as $conv) {
        $unread      = !empty($conv['unread']);
        $last_status = $conv['last_status'] ?? null;
        if (!$last_status) {
            continue;
        }
        if ($unread) {
            echo '<div><strong>&#9679; Unread</strong></div>' . "\n";
        }
        html_post($last_status);
    }

    $oldest_id = $conversations[count($conversations) - 1]['id'] ?? null;

    echo '<nav><ul>' . "\n";
    if ($oldest_id) {
        echo '<li><a href="' . url('messages.php', ['max_id' => $oldest_id]) . '">&larr; Older</a></li>' . "\n";
    }
    echo '</ul></nav>' . "\n";
}

echo '</section>' . "\n";
html_foot();
