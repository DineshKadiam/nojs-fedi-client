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
echo '<h1>Messages</h1>' . "\n";

if (isset($conversations['error'])) {
    html_error($conversations['error']);
} elseif (empty($conversations)) {
    echo '<p>No messages.</p>' . "\n";
} else {
    foreach ($conversations as $conv) {
        $last_status = $conv['last_status'] ?? null;
        if (!$last_status) {
            continue;
        }
        html_post($last_status);
    }

    $oldest_id = $conversations[count($conversations) - 1]['id'] ?? null;

    html_pagination('messages.php', [], $oldest_id, null);
}

html_foot();
