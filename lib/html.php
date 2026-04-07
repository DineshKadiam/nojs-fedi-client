<?php
declare(strict_types=1);

// Build an HTML-safe URL with ?a=N threaded in
function url(string $page, array $params = []): string {
    $params = ['a' => current_a()] + $params;
    return $page . '?' . http_build_query($params, '', '&amp;');
}

function html_head(string $title): void {
    echo '<!DOCTYPE html>' . "\n";
    echo '<html lang="en">' . "\n";
    echo '<head>' . "\n";
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . "\n";
    echo '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'self\'; img-src * data:; media-src *; object-src \'none\'; form-action \'self\' https:; base-uri \'self\'">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    $a       = current_a();
    $ctx     = $_SESSION['accounts'][$a] ?? null;
    $host    = $ctx ? (parse_url($ctx['instance'], PHP_URL_HOST) ?? '') : '';
    $acct    = $ctx ? (($ctx['account']['username'] ?? '') . ($host ? '@' . $host : '')) : '';
    $suffix  = $acct ? h(APP_NAME) . ' (' . h($acct) . ')' : h(APP_NAME);
    echo '<title>' . h($title) . ' - ' . $suffix . '</title>' . "\n";
    echo '<link rel="stylesheet" href="static/style.css">' . "\n";
    echo '</head>' . "\n";
    $theme = $_SESSION['theme'] ?? 'light';
    echo '<body' . ($theme === 'dark' ? ' class="dark"' : '') . '>' . "\n";
    html_nav();
    echo '<main>' . "\n";
}

function html_foot(): void {
    echo '</main>' . "\n";
    echo '<footer>' . "\n";
    echo '<p><small>' . h(SMOLFEDI_USER_AGENT) . ' - A smolweb Fediverse client</small></p>' . "\n";
    echo '</footer>' . "\n";
    echo '</body>' . "\n";
    echo '</html>' . "\n";
}

function html_nav(): void {
    echo '<nav>' . "\n";
    if (is_logged_in()) {
        $a    = current_a();
        $ctx  = $_SESSION['accounts'][$a];
        $acct = $ctx['account']['username'] ?? '';
        $id   = $ctx['account']['id'] ?? '';
        echo '<form method="get" action="switch.php" id="a-switch">' . "\n";
        echo '<select name="a">' . "\n";
        foreach ($_SESSION['accounts'] as $i => $acc) {
            $host     = parse_url($acc['instance'], PHP_URL_HOST) ?? $acc['instance'];
            $label    = ($acc['account']['username'] ?? '?') . '@' . $host;
            $selected = ($i === $a) ? ' selected' : '';
            echo '<option value="' . $i . '"' . $selected . '>' . h($label) . '</option>' . "\n";
        }
        echo '<option value="-1">+ Add account</option>' . "\n";
        echo '</select> ';
        echo '<button type="submit">Switch</button>' . "\n";
        echo '</form>' . "\n";

        echo '<a href="' . url('index.php') . '">Home</a> | ';
        echo '<a href="' . url('bookmarks.php') . '">Bookmarks</a> | ';
        echo '<a href="' . url('messages.php') . '">Messages</a> | ';
        echo '<a href="' . url('notifications.php') . '">Notifications</a> | ';
        echo '<a href="' . url('profile.php', ['id' => $id]) . '">@' . h($acct) . '</a> | ';
        echo '<a href="' . url('compose.php') . '">New post</a> | ';
        echo '<a href="' . url('logout.php') . '">Log out</a> | ';
        echo '<a href="' . url('theme.php') . '">Theme</a>';
        echo "\n";
    } else {
        echo '<a href="login.php">Log in</a>';
    }
    echo '</nav>' . "\n";
}

function html_post(array $status, bool $show_app = false): void {
    // Handle boosts (reblogs)
    $boosted_by = null;
    if (!empty($status['reblog'])) {
        $boosted_by = $status['account'];
        $status     = $status['reblog'];
    }

    $id       = $status['id'] ?? '';
    $url_raw  = $status['url'] ?? '#';
    $content  = sanitize_post_html($status['content'] ?? '');
    $content  = rewrite_mentions($content, $status['mentions'] ?? []);
    $content  = rewrite_hashtags($content);
    $content  = rewrite_post_links($content);
    $created  = $status['created_at'] ?? '';
    $fav_count    = (int) ($status['favourites_count'] ?? 0);
    $reblog_count = (int) ($status['reblogs_count'] ?? 0);
    $reply_count  = (int) ($status['replies_count'] ?? 0);
    $favourited   = !empty($status['favourited']);
    $reblogged    = !empty($status['reblogged']);
    $bookmarked   = isset($status['bookmarked']) ? !empty($status['bookmarked']) : null;

    $account      = $status['account'] ?? [];
    $display_name = $account['display_name'] ?: ($account['username'] ?? '');
    $acct         = $account['acct'] ?? '';
    $avatar       = $account['avatar'] ?? '';
    $account_id   = $account['id'] ?? '';

    $ts      = strtotime($created);
    $human   = $ts ? date('Y-m-d H:i', $ts) : $created;
    $iso8601 = $ts ? date('c', $ts) : $created;

    $a    = current_a();
    $csrf = csrf_token();

    echo '<article id="status-' . h($id) . '">' . "\n";

    if ($boosted_by) {
        $booster        = $boosted_by['display_name'] ?: ($boosted_by['username'] ?? '');
        $booster_id     = $boosted_by['id'] ?? '';
        $booster_avatar = $boosted_by['avatar'] ?? '';
        echo '<p><small>&#8635; Boosted by <a href="' . url('profile.php', ['id' => $booster_id]) . '">';
        if ($booster_avatar) {
            echo '<img src="' . h($booster_avatar) . '" alt="' . h($booster) . ' avatar" width="24" height="24"> ';
        }
        echo h($booster) . '</a></small></p>' . "\n";
    }

    echo '<header>' . "\n";
    if ($avatar) {
        echo '<img src="' . h($avatar) . '" alt="' . h($display_name) . ' avatar" width="48" height="48">' . "\n";
    }
    $vis_map = [
        'public'   => ['(P)', 'Public'],
        'unlisted' => ['(U)', 'Unlisted'],
        'private'  => ['(F)', 'Followers only'],
        'direct'   => ['(@)', 'Direct message'],
    ];
    [$vis_char, $vis_label] = $vis_map[$status['visibility'] ?? 'public'] ?? ['?', 'Unknown'];

    echo '<strong>' . h($display_name) . '</strong> ';
    echo '&lt;<a href="' . url('profile.php', ['id' => $account_id]) . '">@' . h($acct) . '</a>&gt; ';
    echo '<abbr title="' . h($vis_label) . '">' . $vis_char . '</abbr> ';
    echo '<a href="' . url('post.php', ['id' => $id]) . '"><time datetime="' . h($iso8601) . '">' . h($human) . '</time></a>' . "\n";
    echo '</header>' . "\n";

    $spoiler   = trim($status['spoiler_text'] ?? '');
    $show_full = ($_GET['show'] ?? '') === 'ok';

    if ($spoiler !== '' && !$show_full) {
        echo '<div><strong>CW:</strong> ' . h($spoiler) . ' - <a href="' . url('post.php', ['id' => $id, 'show' => 'ok']) . '">Show content</a></div>' . "\n";
    } else {
        if ($spoiler !== '') {
            echo '<div><strong>CW:</strong> ' . h($spoiler) . '</div>' . "\n";
        }
        echo '<div>' . $content . '</div>' . "\n";
    }

    // Poll
    $poll = $status['poll'] ?? null;
    if ($poll) {
        html_poll($poll, $id, $csrf);
    }

    // Media attachments
    $attachments = $status['media_attachments'] ?? [];
    foreach ($attachments as $media) {
        $type      = $media['type'] ?? '';
        $media_url = $media['url'] ?? '';
        $alt       = $media['description'] ?? '';

        if (!$media_url) {
            continue;
        }

        echo '<figure>' . "\n";
        if ($type === 'image') {
            echo '<img src="' . h($media_url) . '" alt="' . h($alt) . '" style="max-width:100%">' . "\n";
        } elseif ($type === 'video' || $type === 'gifv') {
            echo '<video controls style="max-width:100%">';
            echo '<source src="' . h($media_url) . '">';
            echo '</video>' . "\n";
        } elseif ($type === 'audio') {
            echo '<audio controls>';
            echo '<source src="' . h($media_url) . '">';
            echo '</audio>' . "\n";
        }
        if ($alt) {
            echo '<figcaption>' . h($alt) . '</figcaption>' . "\n";
        }
        echo '</figure>' . "\n";
    }

    // Actions
    echo '<footer>' . "\n";

    $fav_action    = $favourited ? 'unfavourite' : 'favourite';
    $fav_label     = ($favourited ? '&#9733;' : '&#9734;') . ' ' . $fav_count;
    $fav_title     = $favourited ? 'Unfavourite' : 'Favourite';
    $reblog_action = $reblogged ? 'unreblog' : 'reblog';
    $reblog_label  = '&#8635; ' . $reblog_count;
    $reblog_title  = $reblogged ? 'Unboost' : 'Boost';
    echo '<form method="post" action="action.php">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
    echo '<input type="hidden" name="id" value="' . h($id) . '">' . "\n";
    echo '<button type="submit" name="action" value="' . $fav_action . '" title="' . $fav_title . '"' . ($favourited ? ' class="active"' : '') . '>' . $fav_label . '</button>' . "\n";
    echo '<button type="submit" name="action" value="' . $reblog_action . '" title="' . $reblog_title . '"' . ($reblogged ? ' class="active"' : '') . '>' . $reblog_label . '</button>' . "\n";
    if ($bookmarked !== null) {
        $bookmark_action = $bookmarked ? 'unbookmark' : 'bookmark';
        $bookmark_title  = $bookmarked ? 'Remove bookmark' : 'Bookmark';
        echo '<button type="submit" name="action" value="' . $bookmark_action . '" title="' . $bookmark_title . '"' . ($bookmarked ? ' class="active"' : '') . '>&#9873;</button>' . "\n";
    }
    echo '</form>' . "\n";

    echo '<form method="get" action="post.php">' . "\n";
    echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
    echo '<input type="hidden" name="id" value="' . h($id) . '">' . "\n";
    echo '<button type="submit" title="Reply">&#8618; ' . $reply_count . '</button>' . "\n";
    echo '</form>' . "\n";

    // Delete (only own posts)
    $own_id = $_SESSION['accounts'][$a]['account']['id'] ?? '';
    if ($own_id && $account_id === $own_id) {
        echo '<form method="post" action="action.php">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="action" value="delete">' . "\n";
        echo '<input type="hidden" name="id" value="' . h($id) . '">' . "\n";
        echo '<button type="submit" title="Delete this post">X</button>' . "\n";
        echo '</form>' . "\n";
    }

    if ($show_app) {
        $app_name = $status['application']['name'] ?? '';
        if ($app_name !== '') {
            echo '<p><small>posted with <em>' . h($app_name) . '</em></small></p>' . "\n";
        }
    }

    echo '</footer>' . "\n";
    echo '</article>' . "\n";
}

// Escape for HTML output
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Sanitize HTML returned by the Mastodon API
function sanitize_post_html(string $html): string {
    $html = strip_tags($html, '<a><p><br><span><strong><em><code><pre>');
    $html = preg_replace('/\s+target="[^"]*"/', '', $html);
    $html = preg_replace('/\s+rel="[^"]*"/', '', $html);
    return $html;
}

// Rewrite links containing @ (remote post/profile URLs) to local post.php?url=...
// Must run after rewrite_mentions so already-rewritten mention links are not touched
function rewrite_post_links(string $html): string {
    $a = current_a();
    return preg_replace_callback(
        '/href="(https?:\/\/[^"]*@[^"]*)"/i',
        function (array $m) use ($a): string {
            return 'href="post.php?a=' . $a . '&amp;url=' . rawurlencode($m[1]) . '"';
        },
        $html
    );
}

// Rewrite remote hashtag URLs to local tag pages
function rewrite_hashtags(string $html): string {
    $a = current_a();
    return preg_replace(
        '|href="https?://[^"]+/tags/([^"]+)"|i',
        'href="tag.php?a=' . $a . '&amp;tag=$1"',
        $html
    );
}

// Rewrite remote mention URLs to local profile pages using the API mentions array
function rewrite_mentions(string $html, array $mentions): string {
    $a = current_a();
    foreach ($mentions as $mention) {
        $remote_url = $mention['url'] ?? '';
        $id         = $mention['id'] ?? '';
        if (!$remote_url || !$id) {
            continue;
        }
        $local_url = 'profile.php?a=' . $a . '&amp;id=' . rawurlencode($id);
        $html = str_replace(
            'href="' . $remote_url . '"',
            'href="' . $local_url . '"',
            $html
        );
    }
    return $html;
}

function html_error(string $message): void {
    echo '<p><strong>Error:</strong> ' . h($message) . '</p>' . "\n";
}

function html_poll(array $poll, string $status_id, string $csrf): void {
    $poll_id     = $poll['id'] ?? '';
    $expired     = !empty($poll['expired']);
    $voted       = !empty($poll['voted']);
    $multiple    = !empty($poll['multiple']);
    $votes_total = (int) ($poll['votes_count'] ?? 0);
    $options     = $poll['options'] ?? [];
    $own_votes   = $poll['own_votes'] ?? [];
    $expires_at  = $poll['expires_at'] ?? '';
    $ts          = $expires_at ? strtotime($expires_at) : 0;
    $a           = current_a();

    echo '<div>' . "\n";

    if ($voted || $expired) {
        echo '<ol>' . "\n";
        foreach ($options as $i => $option) {
            $count  = (int) ($option['votes_count'] ?? 0);
            $pct    = $votes_total > 0 ? round($count / $votes_total * 100) : 0;
            $chosen = in_array($i, $own_votes, true);
            echo '<li>';
            if ($chosen) {
                echo '<strong>';
            }
            echo h($option['title']) . ' - ' . $count . ' vote' . ($count !== 1 ? 's' : '') . ' (' . $pct . '%)';
            if ($chosen) {
                echo '</strong>';
            }
            echo '</li>' . "\n";
        }
        echo '</ol>' . "\n";
        echo '<p><small>' . $votes_total . ' vote' . ($votes_total !== 1 ? 's' : '');
        if ($expired) {
            echo ' - poll ended';
        } elseif ($ts) {
            echo ' - ends ' . date('Y-m-d H:i', $ts);
        }
        echo '</small></p>' . "\n";
    } else {
        echo '<form method="post" action="action.php">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="action" value="poll_vote">' . "\n";
        echo '<input type="hidden" name="poll_id" value="' . h($poll_id) . '">' . "\n";
        echo '<input type="hidden" name="status_id" value="' . h($status_id) . '">' . "\n";
        $input_type = $multiple ? 'checkbox' : 'radio';
        echo '<ol>' . "\n";
        foreach ($options as $i => $option) {
            $input_id = 'poll_' . h($poll_id) . '_' . $i;
            echo '<li>';
            echo '<label for="' . $input_id . '">';
            echo '<input type="' . $input_type . '" id="' . $input_id . '" name="choices[]" value="' . $i . '"> ';
            echo h($option['title']);
            echo '</label>';
            echo '</li>' . "\n";
        }
        echo '</ol>' . "\n";
        echo '<p><small>' . $votes_total . ' vote' . ($votes_total !== 1 ? 's' : '');
        if ($ts) {
            echo ' - ends ' . date('Y-m-d H:i', $ts);
        }
        echo '</small></p>' . "\n";
        echo '<button type="submit" title="Submit your vote">Vote</button>' . "\n";
        echo '</form>' . "\n";
    }

    echo '</div>' . "\n";
}

function html_notification(array $notif): void {
    $type    = $notif['type'] ?? '';
    $account = $notif['account'] ?? [];
    $status  = $notif['status'] ?? null;

    $display_name = $account['display_name'] ?: ($account['username'] ?? '');
    $account_id   = $account['id'] ?? '';
    $created      = $notif['created_at'] ?? '';
    $ts           = strtotime($created);
    $human        = $ts ? date('Y-m-d H:i', $ts) : $created;
    $iso8601      = $ts ? date('c', $ts) : $created;

    $labels = [
        'mention'        => '&#8618; mentioned you',
        'reblog'         => '&#8635; boosted your post',
        'favourite'      => '&#9733; favourited your post',
        'follow'         => '&#43; followed you',
        'follow_request' => '&#63; wants to follow you',
        'poll'           => '&#9745; a poll you voted in ended',
        'update'         => '&#9998; edited a post',
        'status'         => '! posted',
    ];
    $label = $labels[$type] ?? h($type);

    echo '<article>' . "\n";
    echo '<header>' . "\n";
    if (!empty($account['avatar'])) {
        echo '<img src="' . h($account['avatar']) . '" alt="' . h($display_name) . ' avatar" width="32" height="32"> ';
    }
    echo '<a href="' . url('profile.php', ['id' => $account_id]) . '"><strong>' . h($display_name) . '</strong></a> ';
    echo $label . ' ';
    echo '<time datetime="' . h($iso8601) . '">' . h($human) . '</time>';
    echo "\n" . '</header>' . "\n";

    if ($status) {
        html_post($status);
    }

    echo '</article>' . "\n";
}

function html_pagination(string $base_page, array $base_params, ?string $max_id, ?string $min_id): void {
    echo '<nav>' . "\n";
    if ($max_id) {
        echo '<a href="' . url($base_page, $base_params + ['max_id' => $max_id]) . '">&larr; Older</a> ';
    }
    if ($min_id) {
        echo '<a href="' . url($base_page, $base_params + ['min_id' => $min_id]) . '">Newer &rarr;</a>';
    }
    echo "\n" . '</nav>' . "\n";
}
