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
$following   = false;
$followed_by = false;
$blocking    = false;
$muting      = false;
if (is_array($relationships) && !empty($relationships[0])) {
    $following   = !empty($relationships[0]['following']);
    $followed_by = !empty($relationships[0]['followed_by']);
    $blocking    = !empty($relationships[0]['blocking']);
    $muting      = !empty($relationships[0]['muting']);
}

// Fetch pinned statuses (only on first page, no max_id)
$pinned_statuses = [];
if (empty($_GET['max_id'])) {
    $pinned_raw = api_get($instance, $token, '/api/v1/accounts/' . $account_id . '/statuses', ['pinned' => 'true']);
    if (is_array($pinned_raw) && !isset($pinned_raw['error'])) {
        $pinned_statuses = $pinned_raw;
    }
}

// Fetch recent statuses (reduce limit by pinned count to keep total consistent)
$pinned_ids = array_flip(array_column($pinned_statuses, 'id'));
$params = ['limit' => pref('posts_per_page', POSTS_PER_PAGE), 'exclude_replies' => 'true'];
if (!empty($_GET['max_id'])) {
    $params['max_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['max_id']);
}

$statuses_raw = api_get($instance, $token, '/api/v1/accounts/' . $account_id . '/statuses', $params);
// Remove pinned posts from regular timeline to avoid duplicates
$statuses = is_array($statuses_raw) && !isset($statuses_raw['error'])
    ? array_values(array_filter($statuses_raw, fn($s) => !isset($pinned_ids[$s['id'] ?? ''])))
    : $statuses_raw;

$display_name = $account['display_name'] ?: ($account['username'] ?? '');
$acct         = $account['acct'] ?? '';
$avatar       = $account['avatar'] ?? '';
$note         = rewrite_hashtags(sanitize_post_html($account['note'] ?? ''));
$followers    = (int) ($account['followers_count'] ?? 0);
$following_c  = (int) ($account['following_count'] ?? 0);
$posts_count  = (int) ($account['statuses_count'] ?? 0);

$csrf = csrf_token();

html_head('@' . $acct);
echo '<section class="profile">' . "\n";

// Profile header
echo '<div>' . "\n";
if ($avatar) {
    echo '<img src="' . h($avatar) . '" alt="' . h($display_name) . ' avatar" width="96" height="96">' . "\n";
}
$profile_url = $account['url'] ?? '';
echo '<h1>' . h($display_name) . '</h1>' . "\n";
echo '<p>@' . h($acct);
if ($profile_url) {
    echo ' <a href="' . h($profile_url) . '" title="Original profile" target="_blank">[&nbsp;^&nbsp;]</a>';
}
echo '</p>' . "\n";
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

// Follow/Unfollow and list management (not for own profile)
if ($account_id !== $own_id) {
    $follow_action = $following ? 'unfollow' : 'follow';
    $follow_label  = $following ? 'Unfollow' : 'Follow';
    if ($followed_by) {
        echo '<div><strong>Follows you</strong></div>' . "\n";
    }
    echo '<form method="post" action="action.php">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
    echo '<input type="hidden" name="action" value="' . h($follow_action) . '">' . "\n";
    echo '<input type="hidden" name="id" value="' . h($account_id) . '">' . "\n";
    echo '<button type="submit"'.($following?' class="active"':'').'>' . h($follow_label) . '</button>' . "\n";
    echo '</form>' . "\n";

    $lists            = get_user_lists($instance, $token, $a);
    $account_lists    = api_get($instance, $token, '/api/v1/accounts/' . $account_id . '/lists');
    $member_list_ids  = [];
    if (is_array($account_lists) && !isset($account_lists['error'])) {
        foreach ($account_lists as $al) {
            $member_list_ids[$al['id'] ?? ''] = true;
        }
    }
    foreach ($lists as $lst) {
        $lst_id = preg_replace('/[^A-Za-z0-9]/', '', $lst['id'] ?? '');
        if ($lst_id === '') {
            continue;
        }
        $lst_title   = $lst['title'] ?? 'List';
        $in_list     = isset($member_list_ids[$lst['id'] ?? '']);
        $list_action = $in_list ? 'list_remove' : 'list_add';
        $list_prefix = $in_list ? '- ' : '+ ';
        echo '<form method="post" action="action.php">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="action" value="' . $list_action . '">' . "\n";
        echo '<input type="hidden" name="list_id" value="' . h($lst_id) . '">' . "\n";
        echo '<input type="hidden" name="account_id" value="' . h($account_id) . '">' . "\n";
        echo '<button type="submit"'.($in_list?' class="active"':'').'>' . $list_prefix . h($lst_title) . '</button>' . "\n";
        echo '</form>' . "\n";
    }

    $mute_action  = $muting ? 'unmute' : 'mute';
    $mute_label   = $muting ? 'Unmute' : 'Mute';
    $block_action = $blocking ? 'unblock' : 'block';
    $block_label  = $blocking ? 'Unblock' : 'Block';
    $domain       = strpos($acct, '@') !== false ? substr($acct, strpos($acct, '@') + 1) : '';

    echo '<details>' . "\n";
    echo '<summary>Moderation</summary>' . "\n";
    echo '<form method="post" action="action.php">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
    echo '<input type="hidden" name="id" value="' . h($account_id) . '">' . "\n";
    echo '<button type="submit" name="action" value="' . $mute_action . '"' . ($muting ? ' class="active"' : '') . '>' . $mute_label . '</button> ';
    echo '<button type="submit" name="action" value="open_report" class="danger">Report</button> ';
    echo '<button type="submit" name="action" value="' . $block_action . '" class="danger' . ($blocking ? ' active' : '') . '">' . $block_label . '</button>' . "\n";
    if ($domain !== '' && domain_blocks_supported($instance, $token, $a)) {
        $blocked_domains = get_blocked_domains($instance, $token, $a);
        $domain_blocked  = in_array($domain, $blocked_domains, true);
        $dom_action      = $domain_blocked ? 'unblock_domain' : 'block_domain';
        $dom_label       = ($domain_blocked ? 'Unblock all @' : 'Block all @') . $domain;
        echo '<input type="hidden" name="domain" value="' . h($domain) . '">' . "\n";
        echo ' <button type="submit" name="action" value="' . $dom_action . '" class="danger' . ($domain_blocked ? ' active' : '') . '">' . h($dom_label) . '</button>' . "\n";
    }
    echo '</form>' . "\n";
    echo '</details>' . "\n";
}

echo '</div>' . "\n";
echo '<hr>' . "\n";

// Pinned posts
if (!empty($pinned_statuses)) {
    echo '<h2>! Pinned</h2>' . "\n";
    foreach ($pinned_statuses as $status) {
        html_post($status);
    }
    echo '<hr>' . "\n";
}

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
    $newest_id = isset($_GET['max_id']) ? ($statuses[0]['id'] ?? null) : null;

    html_pagination('profile.php', ['id' => $account_id], $oldest_id, $newest_id);
}

echo '</section>' . "\n";
html_foot();
