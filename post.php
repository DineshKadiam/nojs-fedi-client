<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';

$ctx      = require_login();
$a        = current_a();
$instance = $ctx['instance'];
$token    = $ctx['access_token'];

$id = preg_replace('/[^A-Za-z0-9]/', '', $_GET['id'] ?? '');

// Resolve a remote URL via the search API if no local id is known
if (!$id && !empty($_GET['url'])) {
    $remote_url = filter_var($_GET['url'], FILTER_VALIDATE_URL);
    if ($remote_url) {
        $search = api_get($instance, $token, '/api/v2/search', [
            'q'       => $remote_url,
            'resolve' => 'true',
            'limit'   => 1,
        ]);
        $id = $search['statuses'][0]['id'] ?? '';
        if (!$id) {
            html_head('Error');
            html_error('Could not resolve this post on your instance.');
            html_foot();
            exit;
        }
    }
}

if (!$id) {
    html_head('Error');
    html_error('No post ID provided.');
    html_foot();
    exit;
}

$status        = api_get($instance, $token, '/api/v1/statuses/' . $id);
$context       = api_get($instance, $token, '/api/v1/statuses/' . $id . '/context');
$favourited_by = api_get($instance, $token, '/api/v1/statuses/' . $id . '/favourited_by', ['limit' => 40]);
$reblogged_by  = api_get($instance, $token, '/api/v1/statuses/' . $id . '/reblogged_by', ['limit' => 40]);

if (isset($status['error'])) {
    html_head('Error');
    html_error($status['error']);
    html_foot();
    exit;
}

$csrf = csrf_token();

html_head('Post');
echo '<section>' . "\n";

// Ancestors (thread above)
$ancestors = $context['ancestors'] ?? [];
if (!empty($ancestors)) {
    echo '<div>' . "\n";
    foreach ($ancestors as $ancestor) {
        html_post($ancestor);
    }
    echo '</div>' . "\n";
    echo '<hr>' . "\n";
}

// The post itself
html_post($status, true);

// Favourited by
if (!empty($favourited_by) && !isset($favourited_by['error'])) {
    echo '<div>' . "\n";
    echo '<strong>&#9733; Favourited by</strong>' . "\n";
    echo '<div class="avatar-list">' . "\n";
    foreach ($favourited_by as $acct) {
        $name = $acct['display_name'] ?: ($acct['username'] ?? '');
        echo '<a href="' . url('profile.php', ['id' => $acct['id']]) . '" title="' . h($name) . '">';
        echo '<img src="' . h($acct['avatar']) . '" alt="' . h($name) . '" width="32" height="32">';
        echo '</a>' . "\n";
    }
    echo '</div>' . "\n";
    echo '</div>' . "\n";
}

// Reblogged by
if (!empty($reblogged_by) && !isset($reblogged_by['error'])) {
    echo '<div>' . "\n";
    echo '<strong>&#8635; Boosted by</strong>' . "\n";
    echo '<div class="avatar-list">' . "\n";
    foreach ($reblogged_by as $acct) {
        $name = $acct['display_name'] ?: ($acct['username'] ?? '');
        echo '<a href="' . url('profile.php', ['id' => $acct['id']]) . '" title="' . h($name) . '">';
        echo '<img src="' . h($acct['avatar']) . '" alt="' . h($name) . '" width="32" height="32">';
        echo '</a>' . "\n";
    }
    echo '</div>' . "\n";
    echo '</div>' . "\n";
}

// Descendants (replies below)
$descendants = $context['descendants'] ?? [];
if (!empty($descendants)) {
    echo '<hr>' . "\n";
    echo '<div>' . "\n";
    foreach ($descendants as $reply) {
        html_post($reply);
    }
    echo '</div>' . "\n";
}

// Reply form
echo '<hr>' . "\n";
echo '<div>' . "\n";
echo '<h2>Write a reply</h2>' . "\n";
echo '<form method="post" action="action.php">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
echo '<input type="hidden" name="action" value="reply">' . "\n";
echo '<input type="hidden" name="id" value="' . h($id) . '">' . "\n";
$reply_to_acct = '@' . ($status['account']['acct'] ?? '');
echo '<textarea name="content" rows="4" cols="60" required placeholder="' . h($reply_to_acct . ' ') . '">';
echo h($reply_to_acct . ' ');
echo '</textarea>' . "\n";
echo '<br>' . "\n";
echo '<label for="visibility_reply">Visibility</label>' . "\n";
echo '<select id="visibility_reply" name="visibility">' . "\n";
echo '<option value="public">Public</option>' . "\n";
echo '<option value="unlisted">Unlisted</option>' . "\n";
echo '<option value="private">Followers only</option>' . "\n";
echo '<option value="direct">Direct</option>' . "\n";
echo '</select>' . "\n";
echo '<br>' . "\n";
echo '<button type="submit">Reply</button>' . "\n";
echo '</form>' . "\n";
echo '</div>' . "\n";

echo '</section>' . "\n";
html_foot();
