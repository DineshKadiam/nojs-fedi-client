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

// Handle pin/unpin locally
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_pin') {
    csrf_verify();
    $tag_name = lower_ascii(preg_replace('/[^A-Za-z0-9_\p{L}]/u', '', $_POST['tag'] ?? ''));
    if ($tag_name !== '') {
        $pinned = $_SESSION['accounts'][$a]['pinned_tags'] ?? [];
        if (in_array($tag_name, $pinned, true)) {
            $pinned = array_values(array_filter($pinned, fn($t) => $t !== $tag_name));
        } else {
            $pinned[] = $tag_name;
        }
        $_SESSION['accounts'][$a]['pinned_tags'] = $pinned;
    }
    redirect('explore.php?a=' . $a . '#trending-hashtags');
}

$statuses      = get_trending_statuses($instance, $token, $a);
$tags          = get_trending_tags($instance, $token, $a);
$followed_tags = get_followed_tags($instance, $token, $a);
$links         = get_trending_links($instance, $token, $a);
$suggestions   = get_suggestions($instance, $token, $a);

// Fetch relationships for all suggested accounts and remove already-followed ones
if (!empty($suggestions)) {
    $suggestion_ids = array_filter(array_map(function($s) {
        $acc = isset($s['account']) ? $s['account'] : $s;
        return preg_replace('/[^A-Za-z0-9]/', '', $acc['id'] ?? '');
    }, $suggestions));
    $rel_params = array_combine(
        array_map(fn($i) => 'id[' . $i . ']', array_keys($suggestion_ids)),
        array_values($suggestion_ids)
    );
    $rels_raw  = api_get($instance, $token, '/api/v1/accounts/relationships', $rel_params);
    $following_ids = array_flip(array_column(
        array_filter($rels_raw, fn($r) => !empty($r['following'])),
        'id'
    ));
    $suggestions = array_values(array_filter($suggestions, function($s) use ($following_ids) {
        $acc = isset($s['account']) ? $s['account'] : $s;
        $id  = preg_replace('/[^A-Za-z0-9]/', '', $acc['id'] ?? '');
        return !isset($following_ids[$id]);
    }));
}

$filters      = get_filters($instance, $token, $a);
$followed_set = array_flip(array_column($followed_tags, 'name'));
$pinned       = $_SESSION['accounts'][$a]['pinned_tags'] ?? [];

$section_nav = function(string ...$skip): void {
    $sections = [
        'trending-posts'     => t('explore_trending_posts'),
        'trending-hashtags'  => t('explore_trending_tags'),
        'trending-links'     => t('explore_trending_links'),
        'suggested-accounts' => t('explore_suggested'),
    ];
    echo '<nav aria-label="' . t('explore_sections_label') . '"><ul>' . "\n";
    foreach ($sections as $id => $label) {
        if (!in_array($id, $skip, true)) {
            echo '<li><a href="#' . $id . '">' . $label . '</a></li>' . "\n";
        }
    }
    echo '</ul></nav>' . "\n";
};

html_head(t('explore_title'));
echo '<h1>' . t('explore_title') . '</h1>' . "\n";

// Trending posts
echo '<h2 id="trending-posts">' . t('explore_trending_posts') . '</h2>' . "\n";
$section_nav('trending-posts');
if (empty($statuses)) {
    echo '<p>' . t('explore_no_trending_posts') . '</p>' . "\n";
} else {
    foreach ($statuses as $status) {
        $filter_match = match_filters(
            strip_tags($status['content'] ?? '') . ' ' . ($status['spoiler_text'] ?? ''),
            $filters
        );
        if ($filter_match !== null) {
            echo '<p><em>' . t('explore_post_filtered', h($filter_match)) . '</em></p>' . "\n";
            continue;
        }
        html_post($status);
    }
}

// Trending hashtags
echo '<h2 id="trending-hashtags">' . t('explore_trending_tags') . '</h2>' . "\n";
$section_nav('trending-hashtags');
if (empty($tags)) {
    echo '<p>' . t('explore_no_trending_tags') . '</p>' . "\n";
} else {
    echo '<ol>' . "\n";
    foreach ($tags as $tag) {
        $name = $tag['name'] ?? '';
        if ($name === '') {
            continue;
        }
        $uses        = (int) ($tag['history'][0]['uses'] ?? 0);
        $accs        = (int) ($tag['history'][0]['accounts'] ?? 0);
        $is_pinned   = in_array($name, $pinned, true);
        $is_followed = isset($followed_set[$name]);

        echo '<li>' . "\n";
        echo '<a href="' . url('tag.php', ['tag' => $name]) . '">#' . h($name) . '</a>';
        echo "\n";

        echo '<form method="post" action="' . url('explore.php') . '" class="inline">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">' . "\n";
        echo '<input type="hidden" name="action" value="toggle_pin">' . "\n";
        echo '<input type="hidden" name="tag" value="' . h($name) . '">' . "\n";
        echo '<button type="submit"' . ($is_pinned ? ' class="active"' : '') . '>' . ($is_pinned ? t('action_unpin') : t('action_pin')) . '</button>' . "\n";
        echo '</form>' . "\n";

        echo '<form method="post" action="action.php" class="inline">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="action" value="' . ($is_followed ? 'tag_unfollow' : 'tag_follow') . '">' . "\n";
        echo '<input type="hidden" name="tag" value="' . h($name) . '">' . "\n";
        echo '<input type="hidden" name="return_to" value="explore.php#trending-hashtags">' . "\n";
        echo '<button type="submit"' . ($is_followed ? ' class="active"' : '') . '>' . ($is_followed ? t('action_unfollow') : t('action_follow')) . '</button>' . "\n";
        echo '</form>' . "\n";

        if ($uses > 0) {
            echo ' - ' . tn('stat_post', $uses, $uses);
            if ($accs > 0) {
                echo ', ' . tn('stat_account', $accs, $accs);
            }
        }

        echo '</li>' . "\n";
    }
    echo '</ol>' . "\n";
}

// Trending links
echo '<h2 id="trending-links">' . t('explore_trending_links') . '</h2>' . "\n";
$section_nav('trending-links');
if (empty($links)) {
    echo '<p>' . t('explore_no_trending_links') . '</p>' . "\n";
} else {
    echo '<ul>' . "\n";
    foreach ($links as $link) {
        $href        = $link['url'] ?? '';
        $title       = $link['title'] ?? $href;
        $description = $link['description'] ?? '';
        $provider    = $link['provider_name'] ?? '';
        $uses        = (int) ($link['history'][0]['uses'] ?? 0);

        if ($href === '') {
            continue;
        }

        echo '<li>' . "\n";
        echo '<a href="' . h($href) . '" rel="nofollow noopener">' . h($title) . '</a>';
        if ($provider !== '') {
            echo ' <small>(' . h($provider) . ')</small>';
        }
        echo "\n";
        if ($description !== '') {
            echo '<br>' . "\n" . h($description) . "\n";
        }
        if ($uses > 0) {
            echo '<br>' . "\n" . '<small>' . tn('stat_post', $uses, $uses) . '</small>' . "\n";
        }
        echo '</li>' . "\n";
    }
    echo '</ul>' . "\n";
}

// Suggested accounts
echo '<h2 id="suggested-accounts">' . t('explore_suggested') . '</h2>' . "\n";
$section_nav('suggested-accounts');
if (empty($suggestions)) {
    echo '<p>' . t('explore_no_suggestions') . '</p>' . "\n";
} else {
    echo '<ul>' . "\n";
    foreach ($suggestions as $suggestion) {
        // v2 suggestions wrap the account under 'account'; v1 returns accounts directly
        $account = isset($suggestion['account']) ? $suggestion['account'] : $suggestion;
        $id      = preg_replace('/[^A-Za-z0-9]/', '', $account['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $display = $account['display_name'] ?? '';
        $acct    = $account['acct'] ?? '';
        $note    = strip_tags($account['note'] ?? '');
        $avatar  = pref('avatar_static', false)
            ? ($account['avatar_static'] ?? $account['avatar'] ?? '')
            : ($account['avatar'] ?? '');

        echo '<li>' . "\n";
        if ($avatar !== '') {
            echo '<img src="' . h($avatar) . '" alt="' . h($display !== '' ? $display : $acct) . ' avatar" width="40" height="40"> ';
        }
        echo '<a href="' . url('profile.php', ['id' => $id]) . '">';
        echo ($display !== '' ? h($display) : h($acct));
        if ($acct !== '') {
            echo ' <small>@' . h($acct) . '</small>';
        }
        echo '</a>';
        if ($note !== '') {
            echo '<br>' . "\n" . h(mb_strimwidth($note, 0, 200, '…')) . "\n";
        }

        echo '<form method="post" action="action.php" class="inline">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="action" value="follow">' . "\n";
        echo '<input type="hidden" name="id" value="' . h($id) . '">' . "\n";
        echo '<input type="hidden" name="return_to" value="explore.php#suggested-accounts">' . "\n";
        echo '<button type="submit">' . t('action_follow') . '</button>' . "\n";
        echo '</form>' . "\n";

        echo '</li>' . "\n";
    }
    echo '</ul>' . "\n";
}

html_foot();
