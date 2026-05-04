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
if (!empty($_GET['min_id'])) {
    $params['min_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['min_id']);
}

$requests = api_get($instance, $token, '/api/v1/follow_requests', $params);
$csrf     = csrf_token();

html_head(t('follow_requests_title'));
echo '<h1>' . t('follow_requests_title') . '</h1>' . "\n";

if (isset($requests['error'])) {
    html_error($requests['error']);
} elseif (empty($requests)) {
    echo '<p>' . t('follow_requests_none') . '</p>' . "\n";
} else {
    foreach ($requests as $acc) {
        $acc_id    = preg_replace('/[^A-Za-z0-9]/', '', $acc['id'] ?? '');
        if ($acc_id === '') continue;
        $name      = $acc['display_name'] ?: ($acc['username'] ?? '');
        $acct      = $acc['acct'] ?? '';
        $avatar    = $acc['avatar'] ?? '';
        $note      = rewrite_hashtags(sanitize_post_html($acc['note'] ?? ''));

        echo '<article>' . "\n";
        echo '<header>' . "\n";
        if ($avatar) {
            echo '<img src="' . h($avatar) . '" alt="' . h($name) . ' avatar" width="48" height="48"> ';
        }
        echo '<a href="' . url('profile.php', ['id' => $acc_id]) . '"><strong>' . h($name) . '</strong></a> ';
        echo '&lt;@' . h($acct) . '&gt;' . "\n";
        echo '</header>' . "\n";
        if ($note !== '') {
            echo '<div>' . $note . '</div>' . "\n";
        }
        echo '<footer>' . "\n";
        echo '<form method="post" action="action.php">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="id" value="' . h($acc_id) . '">' . "\n";
        echo '<button type="submit" name="action" value="follow_authorize">' . t('action_accept') . '</button> ';
        echo '<button type="submit" name="action" value="follow_reject" class="danger">' . t('action_reject') . '</button>' . "\n";
        echo '</form>' . "\n";
        echo '</footer>' . "\n";
        echo '</article>' . "\n";
    }

    $oldest_id = $requests[count($requests) - 1]['id'] ?? null;
    $newest_id = isset($_GET['max_id']) ? ($requests[0]['id'] ?? null) : null;
    html_pagination('follow_requests.php', [], $oldest_id, $newest_id);
}

html_foot();
