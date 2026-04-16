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

$account_id = preg_replace('/[^A-Za-z0-9]/', '', $_GET['id'] ?? '');
$type       = ($_GET['type'] ?? '') === 'following' ? 'following' : 'followers';

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

$params = ['limit' => pref('posts_per_page', POSTS_PER_PAGE)];
if (!empty($_GET['max_id'])) {
    $params['max_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['max_id']);
}

$result      = api_get_linked($instance, $token, '/api/v1/accounts/' . $account_id . '/' . $type, $params);
$accounts    = $result['data'];
$next_max_id = $result['next_max_id'];

$acct  = $account['acct'] ?? '';
$title = ($type === 'followers' ? 'Followers of' : 'Following by') . ' @' . $acct;

html_head($title);
echo '<h1>' . h($title) . '</h1>' . "\n";
echo '<p><a href="' . url('profile.php', ['id' => $account_id]) . '">&larr; Back to profile</a></p>' . "\n";

if (isset($accounts['error'])) {
    html_error($accounts['error']);
} elseif (empty($accounts)) {
    echo '<p>None.</p>' . "\n";
} else {
    echo '<ul class="follow-list">' . "\n";
    foreach ($accounts as $acc) {
        $name     = $acc['display_name'] ?: ($acc['username'] ?? '');
        $acc_acct = $acc['acct'] ?? '';
        $avatar   = $acc['avatar'] ?? '';
        $acc_id   = $acc['id'] ?? '';
        echo '<li>';
        if ($avatar) {
            echo '<img src="' . h($avatar) . '" alt="(y)" width="32" height="32"> ';
        }
        else {
            echo '[(y)] ';
        }
        echo h($name) . ' &lt;<a href="' . url('profile.php', ['id' => $acc_id]) . '">@' . h($acc_acct) . '</a>&gt;';
        echo '</li>' . "\n";
    }
    echo '</ul>' . "\n";

    html_pagination('follow.php', ['id' => $account_id, 'type' => $type], $next_max_id, null);
}

html_foot();
