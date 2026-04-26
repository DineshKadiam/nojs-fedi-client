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

$allowed_views = ['posts', 'boosts', 'replies', 'media'];
$view = in_array($_GET['view'] ?? '', $allowed_views) ? $_GET['view'] : 'boosts';

$status_params = ['limit' => pref('posts_per_page', POSTS_PER_PAGE)];
switch ($view) {
    case 'posts':
        $status_params['exclude_replies'] = 'true';
        $status_params['exclude_reblogs'] = 'true';
        break;
    case 'boosts':
        $status_params['exclude_replies'] = 'true';
        break;
    case 'replies':
        $status_params['exclude_reblogs'] = 'true';
        break;
    case 'media':
        $status_params['only_media'] = 'true';
        break;
}
if (!empty($_GET['max_id'])) {
    $status_params['max_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['max_id']);
}

// Build parallel request set
$requests = [
    'account'       => ['/api/v1/accounts/' . $account_id, []],
    'relationships' => ['/api/v1/accounts/relationships', ['id[]' => $account_id]],
    'statuses'      => ['/api/v1/accounts/' . $account_id . '/statuses', $status_params],
];
// Pinned only on first page and not for media view
$fetch_pinned = empty($_GET['max_id']) && $view !== 'media';
if ($fetch_pinned) {
    $requests['pinned'] = ['/api/v1/accounts/' . $account_id . '/statuses', ['pinned' => 'true']];
}

$results = api_get_multi($instance, $token, $requests);

$account = $results['account'] ?? [];
if (isset($account['error'])) {
    html_head('Error');
    html_error($account['error']);
    html_foot();
    exit;
}

$relationships_raw = $results['relationships'] ?? [];
$following   = false;
$followed_by = false;
$blocking    = false;
$muting      = false;
$requested   = false;
$notifying   = false;
$showing_reblogs = true;
if (is_array($relationships_raw) && !empty($relationships_raw[0])) {
    $following   = !empty($relationships_raw[0]['following']);
    $followed_by = !empty($relationships_raw[0]['followed_by']);
    $blocking    = !empty($relationships_raw[0]['blocking']);
    $muting      = !empty($relationships_raw[0]['muting']);
    $requested   = !empty($relationships_raw[0]['requested']);
    $notifying   = !empty($relationships_raw[0]['notifying']);
    $showing_reblogs = !empty($relationships_raw[0]['showing_reblogs']);
}

$pinned_raw      = $fetch_pinned ? ($results['pinned'] ?? []) : [];
$pinned_statuses = (is_array($pinned_raw) && !isset($pinned_raw['error'])) ? $pinned_raw : [];
$pinned_ids      = array_flip(array_column($pinned_statuses, 'id'));

$statuses_raw = $results['statuses'] ?? [];
// Remove pinned posts from regular timeline to avoid duplicates
$statuses = is_array($statuses_raw) && !isset($statuses_raw['error'])
    ? array_values(array_filter($statuses_raw, fn($s) => !isset($pinned_ids[$s['id'] ?? ''])))
    : $statuses_raw;

$display_name = $account['display_name'] ?: ($account['username'] ?? '');
$acct         = $account['acct'] ?? '';
$avatar       = $account['avatar'] ?? '';
$note         = rewrite_hashtags(render_emojis_in_html(sanitize_post_html($account['note'] ?? ''), $account['emojis'] ?? []));
$followers    = (int) ($account['followers_count'] ?? 0);
$following_c  = (int) ($account['following_count'] ?? 0);
$posts_count  = (int) ($account['statuses_count'] ?? 0);

$csrf = csrf_token();

html_head('@' . $acct);
echo '<section class="profile">' . "\n";

// Migration notice
$moved = $account['moved'] ?? null;
if (is_array($moved) && isset($moved['id'])) {
    $moved_name = $moved['display_name'] ?: ($moved['username'] ?? '');
    $moved_acct = $moved['acct'] ?? '';
    $moved_id   = preg_replace('/[^A-Za-z0-9]/', '', $moved['id']);
    echo '<p><strong>&#8594; This account has moved to ';
    echo '<a href="' . url('profile.php', ['id' => $moved_id]) . '">@' . h($moved_acct) . '</a>';
    echo '</strong></p>' . "\n";
}

// Profile header
echo '<div>' . "\n";
if ($avatar) {
    echo '<a href="' . h($avatar) . '" target="_blank"><img src="' . h($avatar) . '" alt="' . h($display_name) . ' avatar" width="96" height="96"></a>' . "\n";
}
$profile_url = $account['url'] ?? '';
echo '<h1>' . render_emojis($display_name, $account['emojis'] ?? []) . '</h1>' . "\n";
echo '<p>@' . h($acct);
if ($profile_url) {
    echo ' <a href="' . h($profile_url) . '" aria-label="Original profile (external)" target="_blank">[&nbsp;^&nbsp;]</a>';
}
if (!empty($account['bot'])) {
    echo ' [bot]';
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
        echo '<th>' . render_emojis($fname, $account['emojis'] ?? []) . ($verified ? ' &#10003;' : '') . '</th>' . "\n";
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

if ($account_id === $own_id) {
    echo '<form method="get" action="profile_edit.php">' . "\n";
    echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
    echo '<button type="submit" class="active">Edit profile</button>' . "\n";
    echo '</form>' . "\n";
}

// Follow/Unfollow and list management (not for own profile)
if ($account_id !== $own_id) {
    $locked = !empty($account['locked']);
    if ($following) {
        $follow_action = 'unfollow';
        $follow_label  = 'Unfollow';
        $follow_active = true;
    } elseif ($requested) {
        $follow_action = 'unfollow';
        $follow_label  = 'Cancel request';
        $follow_active = true;
    } elseif ($locked) {
        $follow_action = 'follow';
        $follow_label  = 'Request follow';
        $follow_active = false;
    } else {
        $follow_action = 'follow';
        $follow_label  = 'Follow';
        $follow_active = false;
    }
    if ($followed_by) {
        echo '<div><strong>Follows you</strong></div>' . "\n";
    }
    echo '<form method="post" action="action.php">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
    echo '<input type="hidden" name="action" value="' . h($follow_action) . '">' . "\n";
    echo '<input type="hidden" name="id" value="' . h($account_id) . '">' . "\n";
    echo '<button type="submit"'.($follow_active ? ' class="active"' : '').'>' . h($follow_label) . '</button>' . "\n";
    echo '</form>' . "\n";

    if ($following) {
        $notify_action = $notifying ? 'notify_off' : 'notify_on';
        $notify_label  = $notifying ? 'Stop notify me' : 'Notify me';
        echo '<form method="post" action="action.php">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="action" value="' . $notify_action . '">' . "\n";
        echo '<input type="hidden" name="id" value="' . h($account_id) . '">' . "\n";
        echo '<input type="hidden" name="reblogs" value="' . ($showing_reblogs ? '1' : '0') . '">' . "\n";
        echo '<button type="submit"' . ($notifying ? ' class="active"' : '') . '>' . h($notify_label) . '</button>' . "\n";
        echo '</form>' . "\n";
    }

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
    echo '<summary><small>Moderation</small></summary>' . "\n";
    echo '<form method="post" action="action.php">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
    echo '<input type="hidden" name="id" value="' . h($account_id) . '">' . "\n";
    echo '<button type="submit" name="action" value="' . $mute_action . '"' . ($muting ? ' class="active"' : '') . '>' . $mute_label . '</button> ';
    echo '<button type="submit" name="action" value="open_report" class="danger">Report</button> ';
    echo '<button type="submit" name="action" value="' . $block_action . '" class="' . ($blocking ? 'active' : 'danger') . '">' . $block_label . '</button>' . "\n";
    if ($domain !== '' && domain_blocks_supported($instance, $token, $a)) {
        $blocked_domains = get_blocked_domains($instance, $token, $a);
        $domain_blocked  = in_array($domain, $blocked_domains, true);
        $dom_action      = $domain_blocked ? 'unblock_domain' : 'block_domain';
        $dom_label       = ($domain_blocked ? 'Unblock all @' : 'Block all @') . $domain;
        echo '<input type="hidden" name="domain" value="' . h($domain) . '">' . "\n";
        echo ' <button type="submit" name="action" value="' . $dom_action . '" class="' . ($domain_blocked ? 'active' : 'danger') . '">' . h($dom_label) . '</button>' . "\n";
    }
    echo '</form>' . "\n";
    echo '</details>' . "\n";
}

echo '</div>' . "\n";
echo '</section>' . "\n";

// View selector nav
$view_labels = [
    'posts'   => 'Posts',
    'boosts'  => 'Posts &amp; boosts',
    'replies' => 'Posts &amp; replies',
    'media'   => 'Media only',
];
echo '<nav id="post-filter" aria-label="Post filter">' . "\n";
echo '<ul>' . "\n";
echo '<li class="nav-label">Filter:</li>' . "\n";
foreach ($view_labels as $key => $label) {
    $active = ($view === $key) ? ' class="active"' : '';
    echo '<li' . $active . '><a href="' . url('profile.php', ['id' => $account_id, 'view' => $key]) . '#post-filter">' . $label . '</a></li>' . "\n";
}
echo '</ul>' . "\n";
echo '</nav>' . "\n";

// Pinned posts
if (!empty($pinned_statuses)) {
    echo '<section class="pinned-posts">' . "\n";
    echo '<h2>! Pinned</h2>' . "\n";
    foreach ($pinned_statuses as $status) {
        html_post($status);
    }
    echo '</section>' . "\n";
}

// Posts
echo '<section class="posts">' . "\n";
if (isset($statuses['error'])) {
    echo '<p>Could not load posts. <a href="' . url('profile.php', ['id' => $account_id, 'view' => $view]) . '">Try again</a></p>' . "\n";
} elseif (empty($statuses)) {
    echo '<p>No posts.</p>' . "\n";
} else {
    if ($view === 'media') {
        echo '<div class="media-gallery">' . "\n";
        foreach ($statuses as $status) {
            html_post_media($status);
        }
        echo '</div>' . "\n";
    } else {
        foreach ($statuses as $status) {
            html_post($status);
        }
    }

    $oldest_id = $statuses[count($statuses) - 1]['id'] ?? null;
    $newest_id = isset($_GET['max_id']) ? ($statuses[0]['id'] ?? null) : null;

    html_pagination('profile.php', ['id' => $account_id, 'view' => $view], $oldest_id, $newest_id);
}
echo '</section>' . "\n";
html_foot();
