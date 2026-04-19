<?php
declare(strict_types=1);

// Build an HTML-safe URL with ?a=N threaded in
function url(string $page, array $params = []): string {
    $params = ['a' => current_a()] + $params;
    return $page . '?' . http_build_query($params, '', '&amp;');
}

// Compute the return_to URL for the account switcher: same page without max_id/min_id,
// or index.php when the current page has account/instance-specific ID params.
function nav_return_to(): string {
    $self = basename($_SERVER['PHP_SELF']);
    $keep = match($self) {
        'index.php'          => ['timeline'],
        'notifications.php'  => ['type'],
        'tag.php'            => ['tag'],
        'compose.php'        => ['type'],
        'search.php'         => ['q'],
        'bookmarks.php',
        'favourites.php',
        'messages.php',
        'follow_requests.php',
        'prefs.php'          => [],
        default              => null, // fall back to index.php
    };
    if ($keep === null) {
        return 'index.php';
    }
    $params = [];
    foreach ($keep as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') {
            $params[$k] = $_GET[$k];
        }
    }
    return $params ? $self . '?' . http_build_query($params) : $self;
}

// Return a user preference, falling back to $default
function pref(string $key, mixed $default): mixed {
    return $_SESSION['prefs'][$key] ?? $default;
}

// Format a Unix timestamp using the user's date/time format preferences
function fmt_datetime(int $ts): string {
    $date_fmt = pref('date_format', 'Y-m-d');
    $time_fmt = pref('time_format', 'H:i');
    return date($date_fmt . ' ' . $time_fmt, $ts);
}

function html_head(string $title): void {
    echo '<!DOCTYPE html>' . "\n";
    echo '<html lang="en">' . "\n";
    echo '<head>' . "\n";
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . "\n";
    echo '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'self\' \'unsafe-inline\'; img-src * data:; media-src *; object-src \'none\'; form-action \'self\' https:; base-uri \'self\'">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    $a       = current_a();
    $ctx     = $_SESSION['accounts'][$a] ?? null;
    $host    = $ctx ? (parse_url($ctx['instance'], PHP_URL_HOST) ?? '') : '';
    $acct    = $ctx ? (($ctx['account']['username'] ?? '') . ($host ? '@' . $host : '')) : '';
    $suffix  = $acct ? h(APP_NAME) . ' (' . h($acct) . ')' : h(APP_NAME);
    echo '<title>' . h($title) . ' - ' . $suffix . '</title>' . "\n";
    echo '<link rel="icon" href="favicon.ico" type="image/x-icon">' . "\n";
    echo '<link rel="stylesheet" href="static/style.css">' . "\n";
    if (file_exists(__DIR__ . '/../static/custom.css')) {
        echo '<link rel="stylesheet" href="static/custom.css">' . "\n";
    }
    $max_width = (int) pref('max_width', 800);
    echo '<style>body { max-width: ' . $max_width . 'px; }</style>' . "\n";
    echo '</head>' . "\n";
    $theme     = pref('theme', 'light');
    $font_size = pref('font_size', 'normal');
    $classes   = [];
    if ($theme === 'dark') $classes[] = 'dark';
    if ($font_size !== 'normal') $classes[] = 'view-' . $font_size;
    $class_attr = !empty($classes) ? ' class="' . implode(' ', $classes) . '"' : '';
    echo '<body' . $class_attr . '>' . "\n";
    echo '<header id="top">' . "\n";
    html_nav();
    echo '</header>' . "\n";
    if (is_logged_in()) {
        $_ann_a   = current_a();
        $_ann_ctx = $_SESSION['accounts'][$_ann_a] ?? null;
        if ($_ann_ctx && current_token() !== '') {
            $announcements = get_announcements($_ann_ctx['instance'], current_token(), $_ann_a);
            if (!empty($announcements)) {
                $label = 'Announcements (' . count($announcements) . ')';
                echo '<details>' . "\n";
                echo '<summary><strong>' . h($label) . '</strong></summary>' . "\n";
                foreach ($announcements as $ann) {
                    $content = sanitize_post_html($ann['content'] ?? '');
                    $ts      = ($ann['published_at'] ?? '') ? strtotime($ann['published_at']) : 0;
                    echo '<article>' . "\n";
                    if ($ts) {
                        echo '<p><small>' . fmt_datetime($ts) . '</small></p>' . "\n";
                    }
                    echo $content . "\n";
                    echo '</article>' . "\n";
                }
                echo '</details>' . "\n";
            }
        }
    }
    echo '<main>' . "\n";
    if (!empty($_SESSION['flash'])) {
        echo '<p class="flash">' . h($_SESSION['flash']) . '</p>' . "\n";
        unset($_SESSION['flash']);
    }
}

function html_foot(): void {
    echo '</main>' . "\n";
    echo '<footer>' . "\n";
    echo '<p><small><a href="https://codeberg.org/adele/smolfedi">' . h(SMOLFEDI_USER_AGENT) . '</a> - A smolweb Fediverse client</small></p>' . "\n";
    echo '</footer>' . "\n";
    echo '</body>' . "\n";
    echo '</html>' . "\n";
}

function html_nav(): void {
    if (is_logged_in()) {
        $a = current_a();
        if (!isset($_SESSION['accounts'][$a])) {
            $a = (int) array_key_first($_SESSION['accounts']);
        }
        $ctx  = $_SESSION['accounts'][$a];
        $acct = $ctx['account']['username'] ?? '';
        $id   = $ctx['account']['id'] ?? '';
        echo '<form method="get" action="switch.php" id="a-switch" class="inline-form">' . "\n";
        echo '<input type="hidden" name="return_to" value="' . h(nav_return_to()) . '">' . "\n";
        echo '<label for="a-select">Account:</label> ' . "\n";
        echo '<select id="a-select" name="a">' . "\n";
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

        echo '<form method="get" action="search.php" id="search-form"  class="inline-form" role="search">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<label for="search-input">Search:</label> ' . "\n";
        echo '<input type="search" id="search-input" name="q" placeholder="#tag @user@instance post url" size="28"> ';
        echo '<button type="submit">Search</button>' . "\n";
        echo '</form>' . "\n";

        $self = basename($_SERVER['PHP_SELF']);
        $tl   = $_GET['timeline'] ?? '';
        $type = $_GET['type'] ?? '';
        $li   = function(string $href, string $label, bool $active): void {
            $class = $active ? ' class="active"' : '';
            echo '<li' . $class . '><a href="' . $href . '">' . $label . '</a></li>' . "\n";
        };

        echo '<nav aria-label="Main"><ul>' . "\n";
        $br = function(): void { echo '<li class="nav-br"></li>' . "\n"; };
        $li(url('index.php'), 'Home', $self === 'index.php' && $tl === '');
        $li(url('index.php', ['timeline' => 'local']), 'Local', $self === 'index.php' && $tl === 'local');
        $li(url('index.php', ['timeline' => 'federated']), 'Federated', $self === 'index.php' && $tl === 'federated');
        $lists = current_token() !== '' ? get_user_lists($ctx['instance'], current_token(), $a) : [];
        foreach ($lists as $lst) {
            $lst_id = preg_replace('/[^A-Za-z0-9]/', '', $lst['id'] ?? '');
            if ($lst_id === '') {
                continue;
            }
            $active = $self === 'list.php' && ($_GET['list_id'] ?? '') === $lst_id;
            echo '<li' . ($active ? ' class="active"' : '') . '><a href="' . url('list.php', ['list_id' => $lst_id]) . '">&gt;' . h($lst['title'] ?? 'List') . '</a></li>' . "\n";
        }
        $pinned_tags = $_SESSION['accounts'][$a]['pinned_tags'] ?? [];
        foreach ($pinned_tags as $ptag) {
            $active = $self === 'tag.php' && ($_GET['tag'] ?? '') === $ptag;
            echo '<li' . ($active ? ' class="active"' : '') . '><a href="' . url('tag.php', ['tag' => $ptag]) . '">#' . h($ptag) . '</a></li>' . "\n";
        }
        $br();
        $li(url('bookmarks.php'), 'Bookmarks', $self === 'bookmarks.php');
        $li(url('favourites.php'), 'Favourites', $self === 'favourites.php');
        $li(url('notifications.php'), 'Notifications', $self === 'notifications.php');
        $li(url('messages.php'), 'Messages', $self === 'messages.php');
        if (account_locked($a)) {
            $li(url('follow_requests.php'), 'Follow requests', $self === 'follow_requests.php');
        }
        $br();
        $li(url('compose.php'), 'New post', $self === 'compose.php' && $type === '');
        $li(url('compose.php', ['type' => 'poll']), 'New poll', $self === 'compose.php' && $type === 'poll');
        $li(url('profile.php', ['id' => $id]), '@' . h($acct), $self === 'profile.php' && ($_GET['id'] ?? '') === $id);
        $li(url('prefs.php'), 'Prefs', $self === 'prefs.php');
        echo '</ul></nav>' . "\n";
    } else {
        echo '<nav aria-label="Main"><ul><li><a href="login.php">Log in</a></li></ul></nav>';
    }
}

// Return the first matching filter phrase, or null if no match
function match_filters(string $text, array $filters): ?string {
    $now  = time();
    $plain = strtolower(strip_tags($text));
    foreach ($filters as $filter) {
        $phrase = $filter['phrase'] ?? '';
        if ($phrase === '') continue;
        $expires = $filter['expires_at'] ?? null;
        if ($expires && strtotime($expires) < $now) continue;
        $pattern = strtolower($phrase);
        if (!empty($filter['whole_word'])) {
            if (!preg_match('/(?<![a-z0-9_])' . preg_quote($pattern, '/') . '(?![a-z0-9_])/i', $plain)) continue;
        } else {
            if (!str_contains($plain, $pattern)) continue;
        }
        return $phrase;
    }
    return null;
}

function html_post(array $status, bool $show_app = false, array $history = []): void {
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
    $language     = $status['language'] ?? false;
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
    $human   = $ts ? fmt_datetime($ts) : $created;
    $iso8601 = $ts ? date('c', $ts) : $created;

    $a    = current_a();
    $csrf = csrf_token();

    $ctx_session = $_SESSION['accounts'][$a] ?? [];
    $filters     = get_filters($ctx_session['instance'] ?? '', current_token(), $a);
    $filter_text = ($status['spoiler_text'] ?? '') . ' ' . ($status['content'] ?? '');
    $filtered    = match_filters($filter_text, $filters);

    $visibility = $status['visibility'] ?? '';
    $vis_class  = in_array($visibility, ['public', 'unlisted', 'private', 'direct'], true) ? ' class="type-' . $visibility . '"' : '';
    echo '<article id="status-' . h($id) . '"' . $vis_class . '>' . "\n";
    if ($filtered !== null) {
        echo '<details>' . "\n";
        echo '<summary>Hidden: ' . h($filtered) . '</summary>' . "\n";
    }

    $use_static = pref('avatar_static', false);

    if ($boosted_by) {
        $booster        = $boosted_by['display_name'] ?: ($boosted_by['username'] ?? '');
        $booster_id     = $boosted_by['id'] ?? '';
        $booster_avatar = $use_static
            ? ($boosted_by['avatar_static'] ?? $boosted_by['avatar'] ?? '')
            : ($boosted_by['avatar'] ?? '');
        echo '<p><small>&#8635; Boosted by <a href="' . url('profile.php', ['id' => $booster_id]) . '">';
        if ($booster_avatar) {
            $booster_acct  = $boosted_by['acct'] ?? '';
            $booster_title = $booster . ($booster_acct ? ' <@' . $booster_acct . '>' : '');
            echo '<img src="' . h($booster_avatar) . '" alt="' . h($booster) . ' avatar" title="' . h($booster_title) . '" width="24" height="24"> ';
        }
        echo h($booster) . '</a></small></p>' . "\n";
    }

    $avatar = $use_static
        ? ($account['avatar_static'] ?? $account['avatar'] ?? '')
        : ($account['avatar'] ?? '');

    echo '<header>' . "\n";
    if ($avatar) {
        $avatar_title = $display_name . ($acct ? ' <@' . $acct . '>' : '');
        echo '<img class="post-avatar" src="' . h($avatar) . '" alt="' . h($display_name) . ' avatar" title="' . h($avatar_title) . '">' . "\n";
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
    echo '<br><small>';
    echo '<abbr title="' . h($vis_label) . '">' . $vis_char . '</abbr> ';
    if(!empty($status['pinned'])) {
        echo '<abbr title="Pinned">[&nbsp;!&nbsp;]</abbr> ';
    }
    if($language) {
        echo '[' . h($language) . ']' . "\n";
    }
    echo '</small> <a href="' . url('post.php', ['id' => $id]) . '#status-'.$id.'"><time datetime="' . h($iso8601) . '">' . h($human) . '</time></a> ';
    if ($url_raw && $url_raw !== '#') {
        echo '<a href="' . h($url_raw) . '" aria-label="Original post (external)" target="_blank">[&nbsp;^&nbsp;]</a> ';
    }

    echo '</header>' . "\n";
    echo '<div class="post-content">' . "\n";

    $spoiler        = trim($status['spoiler_text'] ?? '');
    $attachments    = $status['media_attachments'] ?? [];
    $sensitive      = !empty($status['sensitive']);
    $acc_prefs      = $_SESSION['accounts'][$a] ?? [];
    $reveal_cw      = !empty($acc_prefs['reveal_cw']);
    $show_sensitive = !empty($acc_prefs['show_sensitive']);

    if ($spoiler !== '') {
        echo '<details' . ($reveal_cw ? ' open' : '') . '>' . "\n";
        echo '<summary><strong>CW:</strong> ' . h($spoiler) . '</summary>' . "\n";
        echo '<div>' . $content . '</div>' . "\n";
    } else {
        echo '<div>' . $content . '</div>' . "\n";
    }

    // Poll
    $poll = $status['poll'] ?? null;
    if ($poll) {
        html_poll($poll, $id, $csrf);
    }

    // Media attachments
    if (!empty($attachments)) {
        if ($sensitive) {
            echo '<details' . ($show_sensitive ? ' open' : '') . '>' . "\n";
            echo '<summary>Sensitive media</summary>' . "\n";
        }
        echo '<div class="attachments">' . "\n";
        foreach ($attachments as $media) {
            $type        = $media['type'] ?? '';
            $media_url   = $media['url'] ?? '';
            $preview_url = $media['preview_url'] ?? $media_url;
            $alt         = $media['description'] ?? '';

            if (!$media_url) {
                continue;
            }

            echo '<figure>' . "\n";
            if ($type === 'image') {
                echo '<a href="' . h($media_url) . '" target="_blank">';
                echo '<img src="' . h($preview_url) . '" alt="' . h($alt) . '">' . "\n";
                echo '</a>';
            } elseif ($type === 'video' || $type === 'gifv') {
                $poster = $preview_url !== $media_url ? ' poster="' . h($preview_url) . '"' : '';
                echo '<video controls preload="none"' . $poster . '>';
                echo '<source src="' . h($media_url) . '">';
                echo '</video>' . "\n";
            } elseif ($type === 'audio') {
                echo '<audio controls preload="none">';
                echo '<source src="' . h($media_url) . '">';
                echo '</audio>' . "\n";
            }
            if ($alt) {
                echo '<figcaption>' . h($alt) . '</figcaption>' . "\n";
            }
            echo '</figure>' . "\n";
        }
        echo '</div>' . "\n";
        if ($sensitive) {
            echo '</details>' . "\n";
        }
    }

    if ($spoiler !== '') {
        echo '</details>' . "\n";
    }
    echo '</div>' . "\n";

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
    echo '<button type="submit" name="action" value="open_post" title="Reply">&#8618; ' . $reply_count . '</button>' . "\n";
    if ($bookmarked !== null) {
        $bookmark_action = $bookmarked ? 'unbookmark' : 'bookmark';
        $bookmark_title  = $bookmarked ? 'Remove bookmark' : 'Bookmark';
        echo '<button type="submit" name="action" value="' . $bookmark_action . '" title="' . $bookmark_title . '"' . ($bookmarked ? ' class="active"' : '') . '>&#9873;</button>' . "\n";
    }
    $own_id = $_SESSION['accounts'][$a]['account']['id'] ?? '';
    if ($own_id && $account_id === $own_id) {
        $pinned     = !empty($status['pinned']);
        $pin_action = $pinned ? 'unpin' : 'pin';
        $pin_title  = $pinned ? 'Unpin' : 'Pin';
        echo '<button type="submit" name="action" value="' . $pin_action . '" title="' . $pin_title . '"' . ($pinned ? ' class="active"' : '') . '>!</button>' . "\n";
        if (empty($status['poll'])) {
            echo '<button type="submit" name="action" value="edit" title="Edit" class="active">&#9998;</button>' . "\n";
        }
        $confirm_id = preg_replace('/[^A-Za-z0-9]/', '', $_GET['confirm_delete'] ?? '');
        if ($confirm_id === $id) {
            echo '<button type="submit" name="action" value="delete" class="danger" title="Confirm deletion">Confirm?</button>' . "\n";
        } else {
            echo '<button type="submit" name="action" value="confirm_delete" class="danger" title="Delete this post">X</button>' . "\n";
        }
    }
    echo '</form>' . "\n";

    // Post-level moderation (not for own posts)
    if (!$own_id || $account_id !== $own_id) {
        $muted_conv    = !empty($status['muted']);
        $mute_conv_act = $muted_conv ? 'unmute_conversation' : 'mute_conversation';
        $mute_conv_lbl = $muted_conv ? 'Unmute thread' : 'Mute thread';
        echo '<details>' . "\n";
        echo '<summary><small>Moderation</small></summary>' . "\n";
        echo '<form method="post" action="action.php">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="id" value="' . h($id) . '">' . "\n";
        echo '<input type="hidden" name="account_id" value="' . h($account_id) . '">' . "\n";
        echo '<button type="submit" name="action" value="' . $mute_conv_act . '"' . ($muted_conv ? ' class="active"' : '') . '>' . $mute_conv_lbl . '</button> ';
        echo '<button type="submit" name="action" value="open_report" class="danger">Report post</button>' . "\n";
        echo '</form>' . "\n";
        if ($url_raw && $url_raw !== '#') {
            echo '<input type="text" readonly aria-label="Post URL" value="' . h($url_raw) . '">' . "\n";
        }
        echo '</details>' . "\n";
    }

    if ($show_app) {
        $app_name = $status['application']['name'] ?? '';
        if ($app_name !== '') {
            echo '<p><small>posted with <em>' . h($app_name) . '</em></small></p>' . "\n";
        }
    }

    echo '</footer>' . "\n";

    // Edit history (only passed in from the post detail page)
    if (!empty($history) && !isset($history['error'])) {
        html_edit_history($history);
    }

    if ($filtered !== null) {
        echo '</details>' . "\n";
    }
    echo '</article>' . "\n";
}

// Escape for HTML output
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Sanitize HTML returned by the Mastodon API.
// Allowed tags are kept (with target/rel stripped); everything else is HTML-encoded
// so it displays as literal text rather than being silently removed.
function sanitize_post_html(string $html): string {
    static $allowed = ['a', 'p', 'br', 'span', 'strong', 'em', 'code', 'pre','hr',
                       'ul', 'ol', 'li', 'blockquote', 'del',
                       'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    return preg_replace_callback('/<\/?([a-z][a-z0-9]*)\b[^>]*>/i', function (array $m) use ($allowed): string {
        if (in_array(strtolower($m[1]), $allowed, true)) {
            $tag = preg_replace('/\s+target="[^"]*"/', '', $m[0]);
            $tag = preg_replace('/\s+rel="[^"]*"/', '', $tag);
            return $tag;
        }
        return htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8');
    }, $html);
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

// Lowercase and transliterate a string to ASCII (for hashtag normalization)
function lower_ascii(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    if (extension_loaded('iconv')) {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    } else {
        $s = htmlentities($s, ENT_QUOTES, 'UTF-8');
        $s = preg_replace('/&([a-z])[a-z]*;/', '$1', $s);
        $s = preg_replace('/[^a-z0-9_]/', '', $s);
    }
    return $s;
}

// Rewrite remote hashtag URLs to local tag pages
function rewrite_hashtags(string $html): string {
    $a = current_a();
    return preg_replace_callback(
        '/<a\s[^>]*class="[^"]*hashtag[^"]*"[^>]*>.*?<\/a>/is',
        function (array $m) use ($a): string {
            $full_el = $m[0];
            $open_tag = substr($full_el, 0, strpos($full_el, '>') + 1);
            if (!preg_match('/href="([^"]+)"/i', $open_tag, $hm)) {
                return $full_el;
            }
            // Try extracting tag from URL path first
            $tag = basename(parse_url(html_entity_decode($hm[1]), PHP_URL_PATH) ?? '');
            // Fallback: extract from link text content (#tagname)
            if (!$tag || $tag === '/') {
                if (preg_match('/>.*?#([^<\s]+)<\/a>/is', $full_el, $tm)) {
                    $tag = $tm[1];
                }
            }
            if (!$tag) return $full_el;
            $tag = lower_ascii($tag);
            $local = 'href="tag.php?a=' . $a . '&amp;tag=' . rawurlencode($tag) . '"';
            return preg_replace('/href="[^"]+"/i', $local, $full_el, 1);
        },
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
            echo ' - ends ' . fmt_datetime($ts);
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
            echo ' - ends ' . fmt_datetime($ts);
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
    $human        = $ts ? fmt_datetime($ts) : $created;
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
    $notif_avatar = pref('avatar_static', false)
        ? ($account['avatar_static'] ?? $account['avatar'] ?? '')
        : ($account['avatar'] ?? '');
    if ($notif_avatar) {
        $notif_acct  = $account['acct'] ?? '';
        $notif_title = $display_name . ($notif_acct ? ' <@' . $notif_acct . '>' : '');
        echo '<img src="' . h($notif_avatar) . '" alt="' . h($display_name) . ' avatar" title="' . h($notif_title) . '" width="32" height="32"> ';
    }
    echo '<a href="' . url('profile.php', ['id' => $account_id]) . '"><strong>' . h($display_name) . '</strong></a> ';
    echo $label . ' ';
    echo '<time datetime="' . h($iso8601) . '">' . h($human) . '</time>';
    echo "\n" . '</header>' . "\n";

    if ($status) {
        html_post($status);
    }

    if ($type === 'follow_request' && $account_id !== '') {
        $a_cur = current_a();
        echo '<form method="post" action="action.php">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a_cur . '">' . "\n";
        echo '<input type="hidden" name="id" value="' . h($account_id) . '">' . "\n";
        echo '<button type="submit" name="action" value="follow_authorize">Accept</button> ';
        echo '<button type="submit" name="action" value="follow_reject" class="danger">Reject</button>' . "\n";
        echo '</form>' . "\n";
    }

    echo '</article>' . "\n";
}

function html_edit_history(array $history): void {
    if (count($history) < 2) {
        return;
    }
    $count = count($history);
    echo '<details>' . "\n";
    echo '<summary>Edit history (' . $count . ' versions)</summary>' . "\n";
    // Newest first
    $entries = array_reverse($history);
    foreach ($entries as $i => $entry) {
        $created  = $entry['created_at'] ?? '';
        $ts       = strtotime($created);
        $human    = $ts ? fmt_datetime($ts) : $created;
        $iso8601  = $ts ? date('c', $ts) : $created;
        $label    = ($i === 0) ? 'Current' : 'Version ' . ($count - $i);
        $content  = sanitize_post_html($entry['content'] ?? '');
        $content  = rewrite_hashtags($content);
        $content  = rewrite_post_links($content);
        $spoiler  = trim($entry['spoiler_text'] ?? '');
        echo '<details>' . "\n";
        echo '<summary><strong>' . $label . '</strong> — <time datetime="' . h($iso8601) . '">' . h($human) . '</time></summary>' . "\n";
        if ($spoiler !== '') {
            echo '<p><strong>CW:</strong> ' . h($spoiler) . '</p>' . "\n";
        }
        echo '<div>' . $content . '</div>' . "\n";
        echo '</details>' . "\n";
    }
    echo '</details>' . "\n";
}

function html_pagination(string $base_page, array $base_params, ?string $max_id, ?string $min_id): void {
    echo '<nav class="pagination" aria-label="Pagination"><ul>' . "\n";
    if ($max_id) {
        echo '<li><a href="' . url($base_page, $base_params + ['max_id' => $max_id]) . '">&larr; Older</a></li>' . "\n";
    }
    if ($min_id) {
        echo '<li><a href="' . url($base_page, $base_params + ['min_id' => $min_id]) . '">Newer &rarr;</a></li>' . "\n";
    }
    echo '<li class="link-top"><a href="#top">Top of page</a></li>' . "\n";
    echo '</ul></nav>' . "\n";
}
