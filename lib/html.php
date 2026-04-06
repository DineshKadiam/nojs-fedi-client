<?php
declare(strict_types=1);

function html_head(string $title): void {
    echo '<!DOCTYPE html>' . "\n";
    echo '<html lang="en">' . "\n";
    echo '<head>' . "\n";
    echo '<meta charset="UTF-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '<title>' . h($title) . ' — ' . h(APP_NAME) . '</title>' . "\n";
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
    echo '<p><small>' . h(APP_NAME) . ' — A smolweb Fediverse client</small></p>' . "\n";
    echo '</footer>' . "\n";
    echo '</body>' . "\n";
    echo '</html>' . "\n";
}

function html_nav(): void {
    echo '<nav>' . "\n";
    if (is_logged_in()) {
        $acct = $_SESSION['account']['username'] ?? '';
        $id   = $_SESSION['account']['id'] ?? '';
        echo '<a href="index.php">Home</a> | ';
        echo '<a href="profile.php?id=' . h($id) . '">@' . h($acct) . '</a> | ';
        echo '<a href="notifications.php">Notifications</a> | ';
        echo '<a href="compose.php">New post</a> | ';
        echo '<a href="logout.php">Log out</a> | ';
        $theme_label = (($_SESSION['theme'] ?? 'light') === 'dark') ? 'Light theme' : 'Dark theme';
        echo '<a href="theme.php">' . $theme_label . '</a>';
    } else {
        echo '<a href="login.php">Log in</a>';
    }
    echo "\n" . '</nav>' . "\n";
}

function html_post(array $status): void {
    // Handle boosts (reblogs)
    $boosted_by = null;
    if (!empty($status['reblog'])) {
        $boosted_by = $status['account'];
        $status     = $status['reblog'];
    }

    $id       = $status['id'] ?? '';
    $url      = $status['url'] ?? '#';
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

    // Format the date
    $ts       = strtotime($created);
    $human    = $ts ? date('Y-m-d H:i', $ts) : $created;
    $iso8601  = $ts ? date('c', $ts) : $created;

    $csrf = csrf_token();

    echo '<article id="status-' . h($id) . '">' . "\n";

    if ($boosted_by) {
        $booster = $boosted_by['display_name'] ?: ($boosted_by['username'] ?? '');
        $booster_id     = $boosted_by['id'] ?? '';
        $booster_avatar = $boosted_by['avatar'] ?? '';
        echo '<p><small>&#8635; Boosted by <a href="profile.php?id=' . h($booster_id) . '">';
        if ($booster_avatar) {
            echo '<img src="' . h($booster_avatar) . '" alt="' . h($booster) . ' avatar" width="24" height="24"> ';
        }
        echo h($booster) . '</a></small></p>' . "\n";
    }

    echo '<header>' . "\n";
    if ($avatar) {
        echo '<img src="' . h($avatar) . '" alt="' . h($display_name) . ' avatar" width="48" height="48">' . "\n";
    }
    echo '<strong>' . h($display_name) . '</strong> ';
    echo '&lt;<a href="profile.php?id=' . h($account_id) . '">@' . h($acct) . '</a>&gt; &middot; ';
    echo '<a href="post.php?id=' . h($id) . '"><time datetime="' . h($iso8601) . '">' . h($human) . '</time></a>' . "\n";
    echo '</header>' . "\n";

    echo '<div>' . $content . '</div>' . "\n";

    // Poll
    $poll = $status['poll'] ?? null;
    if ($poll) {
        html_poll($poll, $id, $csrf);
    }

    // Media attachments
    $attachments = $status['media_attachments'] ?? [];
    foreach ($attachments as $media) {
        $type     = $media['type'] ?? '';
        $media_url = $media['url'] ?? '';
        $alt      = $media['description'] ?? '';

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

    // Favourite + Reblog in a single form
    $fav_action   = $favourited ? 'unfavourite' : 'favourite';
    $fav_label    = ($favourited ? '&#9733;' : '&#9734;') . ' ' . $fav_count;
    $fav_title    = $favourited ? 'Unfavourite' : 'Favourite';
    $reblog_action = $reblogged ? 'unreblog' : 'reblog';
    $reblog_label  = '&#8635; ' . $reblog_count;
    $reblog_title  = $reblogged ? 'Unboost' : 'Boost';
    echo '<form method="post" action="action.php">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="id" value="' . h($id) . '">' . "\n";
    echo '<button type="submit" name="action" value="' . $fav_action . '" title="' . $fav_title . '"' . ($favourited ? ' class="active"' : '') . '>' . $fav_label . '</button>' . "\n";
    echo '<button type="submit" name="action" value="' . $reblog_action . '" title="' . $reblog_title . '"' . ($reblogged ? ' class="active"' : '') . '>' . $reblog_label . '</button>' . "\n";
    if ($bookmarked !== null) {
        $bookmark_action = $bookmarked ? 'unbookmark' : 'bookmark';
        $bookmark_title  = $bookmarked ? 'Remove bookmark' : 'Bookmark';
        $bookmark_label  = $bookmarked ? '&#128278;' : '&#128278;';
        echo '<button type="submit" name="action" value="' . $bookmark_action . '" title="' . $bookmark_title . '"' . ($bookmarked ? ' class="active"' : '') . '>' . $bookmark_label . '</button>' . "\n";
    }
    echo '</form>' . "\n";

    // Reply link
    echo '<a href="post.php?id=' . h($id) . '">&#128172; ' . $reply_count . ' Reply</a>' . "\n";

    // Delete (only own posts)
    $own_id = $_SESSION['account']['id'] ?? '';
    if ($own_id && $account_id === $own_id) {
        echo '<form method="post" action="action.php">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
        echo '<input type="hidden" name="action" value="delete">' . "\n";
        echo '<input type="hidden" name="id" value="' . h($id) . '">' . "\n";
        echo '<button type="submit" title="Delete this post">&#128465; Delete</button>' . "\n";
        echo '</form>' . "\n";
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
    // Remove target and rel attributes injected by the API
    $html = preg_replace('/\s+target="[^"]*"/', '', $html);
    $html = preg_replace('/\s+rel="[^"]*"/', '', $html);
    return $html;
}

// Rewrite links containing @ (remote post/profile URLs) to local post.php?url=...
// Must run after rewrite_mentions so already-rewritten mention links are not touched
function rewrite_post_links(string $html): string {
    return preg_replace_callback(
        '/href="(https?:\/\/[^"]*@[^"]*)"/i',
        function (array $m): string {
            return 'href="post.php?url=' . rawurlencode($m[1]) . '"';
        },
        $html
    );
}

// Rewrite remote hashtag URLs to local tag pages
function rewrite_hashtags(string $html): string {
    return preg_replace(
        '|href="https?://[^"]+/tags/([^"]+)"|i',
        'href="tag.php?tag=$1"',
        $html
    );
}

// Rewrite remote mention URLs to local profile pages using the API mentions array
function rewrite_mentions(string $html, array $mentions): string {
    foreach ($mentions as $mention) {
        $remote_url = $mention['url'] ?? '';
        $id         = $mention['id'] ?? '';
        if (!$remote_url || !$id) {
            continue;
        }
        $local_url = 'profile.php?id=' . rawurlencode($id);
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

    echo '<div>' . "\n";

    if ($voted || $expired) {
        // Show results
        echo '<ol>' . "\n";
        foreach ($options as $i => $option) {
            $count   = (int) ($option['votes_count'] ?? 0);
            $pct     = $votes_total > 0 ? round($count / $votes_total * 100) : 0;
            $chosen  = in_array($i, $own_votes, true);
            echo '<li>';
            if ($chosen) {
                echo '<strong>';
            }
            echo h($option['title']) . ' — ' . $count . ' vote' . ($count !== 1 ? 's' : '') . ' (' . $pct . '%)';
            if ($chosen) {
                echo '</strong>';
            }
            echo '</li>' . "\n";
        }
        echo '</ol>' . "\n";
        echo '<p><small>' . $votes_total . ' vote' . ($votes_total !== 1 ? 's' : '');
        if ($expired) {
            echo ' — poll ended';
        } elseif ($ts) {
            echo ' — ends ' . date('Y-m-d H:i', $ts);
        }
        echo '</small></p>' . "\n";
    } else {
        // Show votable form
        echo '<form method="post" action="action.php">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
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
            echo ' — ends ' . date('Y-m-d H:i', $ts);
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
        'mention'        => '&#128172; mentioned you',
        'reblog'         => '&#8635; boosted your post',
        'favourite'      => '&#9733; favourited your post',
        'follow'         => '&#43; followed you',
        'follow_request' => '&#63; wants to follow you',
        'poll'           => '&#128203; a poll you voted in ended',
        'update'         => '&#9998; edited a post',
        'status'         => '&#128196; posted',
    ];
    $label = $labels[$type] ?? h($type);

    echo '<article>' . "\n";
    echo '<header>' . "\n";
    if (!empty($account['avatar'])) {
        echo '<img src="' . h($account['avatar']) . '" alt="' . h($display_name) . ' avatar" width="32" height="32"> ';
    }
    echo '<a href="profile.php?id=' . h($account_id) . '"><strong>' . h($display_name) . '</strong></a> ';
    echo $label . ' ';
    echo '<time datetime="' . h($iso8601) . '">' . h($human) . '</time>';
    echo "\n" . '</header>' . "\n";

    if ($status) {
        html_post($status);
    }

    echo '</article>' . "\n";
}

function html_pagination(string $base_url, ?string $max_id, ?string $min_id): void {
    echo '<nav>' . "\n";
    if ($max_id) {
        echo '<a href="' . h($base_url . '?max_id=' . $max_id) . '">&larr; Older</a> ';
    }
    if ($min_id) {
        echo '<a href="' . h($base_url . '?min_id=' . $min_id) . '">Newer &rarr;</a>';
    }
    echo "\n" . '</nav>' . "\n";
}
