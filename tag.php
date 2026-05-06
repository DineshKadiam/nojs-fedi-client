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

$tag = lower_ascii(preg_replace('/[^A-Za-z0-9_\p{L}]/u', '', $_GET['tag'] ?? ''));
if (!$tag) {
    html_head('Error');
    html_error(t('tag_err_no_tag'));
    html_foot();
    exit;
}

// Handle pin/unpin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_pin') {
    csrf_verify();
    $pinned = $_SESSION['accounts'][$a]['pinned_tags'] ?? [];
    if (in_array($tag, $pinned, true)) {
        $pinned = array_values(array_filter($pinned, fn($t) => $t !== $tag));
    } else {
        $pinned[] = $tag;
    }
    $_SESSION['accounts'][$a]['pinned_tags'] = $pinned;
    redirect('tag.php?a=' . $a . '&tag=' . rawurlencode($tag));
}

$params = ['limit' => pref('posts_per_page', POSTS_PER_PAGE)];
if (!empty($_GET['max_id'])) {
    $params['max_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['max_id']);
}
if (!empty($_GET['min_id'])) {
    $params['min_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['min_id']);
}

$statuses = api_get($instance, $token, '/api/v1/timelines/tag/' . rawurlencode($tag), $params);

$pinned     = $_SESSION['accounts'][$a]['pinned_tags'] ?? [];
$is_pinned  = in_array($tag, $pinned, true);
$pin_label  = $is_pinned ? t('action_unpin') : t('action_pin');

$tag_info     = api_get($instance, $token, '/api/v1/tags/' . rawurlencode($tag));
$is_following = !empty($tag_info['following']);
$follow_action = $is_following ? 'tag_unfollow' : 'tag_follow';
$follow_label  = $is_following ? t('action_unfollow') : t('action_follow');

html_head('#' . $tag);
$tag_page_url = $tag_info['url'] ?? '';
echo '<h1>#' . h($tag) . '</h1>' . "\n";
if ($tag_page_url) {
    echo '<div class="float-right">';    
    echo '<a href="' . url('rssdiscover.php', ['u' => $tag_page_url]) . '" class="btn" title="' . t('rssdiscover_title') . '">RSS</a>';
    echo '</div>' . "\n";
}
echo '<form method="post" action="' . url('tag.php', ['tag' => $tag]) . '" class="inline">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">' . "\n";
echo '<input type="hidden" name="action" value="toggle_pin">' . "\n";
echo '<button type="submit"' . ($is_pinned ? ' class="active"' : '') . '>' . $pin_label . ' #' . h($tag) . '</button>' . "\n";
echo '</form>' . "\n";
echo '<form method="post" action="action.php" class="inline">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">' . "\n";
echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
echo '<input type="hidden" name="action" value="' . $follow_action . '">' . "\n";
echo '<input type="hidden" name="tag" value="' . h($tag) . '">' . "\n";
echo '<button type="submit"' . ($is_following ? ' class="active"' : '') . '>' . $follow_label . ' #' . h($tag) . '</button>' . "\n";
echo '</form>' . "\n";


if (isset($statuses['error'])) {
    html_error($statuses['error']);
} elseif (empty($statuses)) {
    echo '<p>' . t('tag_no_posts') . '</p>' . "\n";
} else {
    foreach ($statuses as $status) {
        html_post($status);
    }

    $oldest_id = $statuses[count($statuses) - 1]['id'] ?? null;
    $newest_id = $statuses[0]['id'] ?? null;

    html_pagination('tag.php', ['tag' => $tag], $oldest_id, isset($_GET['max_id']) ? $newest_id : null);
}

html_foot();
