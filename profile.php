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
$own_id   = $ctx['account']['id'] ?? '';

$account_id = preg_replace('/[^A-Za-z0-9]/', '', $_GET['id'] ?? '');
if (!$account_id) {
    html_head('Error');
    html_error('No account ID provided.');
    html_foot();
    exit;
}

$account = api_get($instance, $token, '/api/v1/accounts/' . $account_id);

if (isset($account['error'])) {
    html_head('Error');
    html_error($account['error']);
    html_foot();
    exit;
}

// Fetch relationships
$relationships = api_get($instance, $token, '/api/v1/accounts/relationships', ['id[]' => $account_id]);
$following = false;
if (is_array($relationships) && !empty($relationships[0])) {
    $following = !empty($relationships[0]['following']);
}

// Fetch recent statuses
$params = ['limit' => POSTS_PER_PAGE, 'exclude_replies' => 'true'];
if (!empty($_GET['max_id'])) {
    $params['max_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['max_id']);
}

$statuses = api_get($instance, $token, '/api/v1/accounts/' . $account_id . '/statuses', $params);

$display_name = $account['display_name'] ?: ($account['username'] ?? '');
$acct         = $account['acct'] ?? '';
$avatar       = $account['avatar'] ?? '';
$note         = sanitize_post_html($account['note'] ?? '');
$followers    = (int) ($account['followers_count'] ?? 0);
$following_c  = (int) ($account['following_count'] ?? 0);
$posts_count  = (int) ($account['statuses_count'] ?? 0);

$csrf = csrf_token();

html_head('@' . $acct);
echo '<section>' . "\n";

// Profile header
echo '<div>' . "\n";
if ($avatar) {
    echo '<img src="' . h($avatar) . '" alt="' . h($display_name) . ' avatar" width="96" height="96">' . "\n";
}
echo '<h1>' . h($display_name) . '</h1>' . "\n";
echo '<p>@' . h($acct) . '</p>' . "\n";
echo '<p>' . $note . '</p>' . "\n";

$fields = $account['fields'] ?? [];
if (!empty($fields)) {
    echo '<table>' . "\n";
    foreach ($fields as $field) {
        $fname    = $field['name'] ?? '';
        $fvalue   = sanitize_post_html($field['value'] ?? '');
        $verified = !empty($field['verified_at']);
        echo '<tr>' . "\n";
        echo '<th>' . h($fname) . ($verified ? ' &#10003;' : '') . '</th>' . "\n";
        echo '<td>' . $fvalue . '</td>' . "\n";
        echo '</tr>' . "\n";
    }
    echo '</table>' . "\n";
}

echo '<p>';
echo 'Posts: ' . $posts_count . ' | ';
echo '<a href="' . url('follow.php', ['id' => $account_id, 'type' => 'followers']) . '">Followers: ' . $followers . '</a> | ';
echo '<a href="' . url('follow.php', ['id' => $account_id, 'type' => 'following']) . '">Following: ' . $following_c . '</a>';
echo '</p>' . "\n";

// Follow/Unfollow (not for own profile)
if ($account_id !== $own_id) {
    $follow_action = $following ? 'unfollow' : 'follow';
    $follow_label  = $following ? 'Unfollow' : 'Follow';
    echo '<form method="post" action="action.php">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
    echo '<input type="hidden" name="action" value="' . h($follow_action) . '">' . "\n";
    echo '<input type="hidden" name="id" value="' . h($account_id) . '">' . "\n";
    echo '<button type="submit">' . h($follow_label) . '</button>' . "\n";
    echo '</form>' . "\n";
}

echo '</div>' . "\n";
echo '<hr>' . "\n";

// Posts
if (isset($statuses['error'])) {
    html_error($statuses['error']);
} elseif (empty($statuses)) {
    echo '<p>No posts.</p>' . "\n";
} else {
    foreach ($statuses as $status) {
        html_post($status);
    }

    $oldest_id = $statuses[count($statuses) - 1]['id'] ?? null;

    echo '<nav>' . "\n";
    if ($oldest_id) {
        echo '<a href="' . url('profile.php', ['id' => $account_id, 'max_id' => $oldest_id]) . '">&larr; Older</a> ';
    }
    if (isset($_GET['max_id'])) {
        $newest_id = $statuses[0]['id'] ?? null;
        if ($newest_id) {
            echo '<a href="' . url('profile.php', ['id' => $account_id, 'min_id' => $newest_id]) . '">Newer &rarr;</a>';
        }
    }
    echo "\n" . '</nav>' . "\n";
}

echo '</section>' . "\n";
html_foot();
