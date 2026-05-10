<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';

$ctx_login = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $a_post   = current_a();
    $ctx_post = $ctx_login;

    // Tag pin/unpin
    if (isset($_POST['tag_pin_action'])) {
        $pt      = lower_ascii(preg_replace('/[^A-Za-z0-9_\p{L}]/u', '', $_POST['tag'] ?? ''));
        $pinned  = $_SESSION['accounts'][$a_post]['pinned_tags'] ?? [];
        if (in_array($pt, $pinned, true)) {
            $pinned = array_values(array_filter($pinned, fn($t) => $t !== $pt));
        } else {
            $pinned[] = $pt;
        }
        $_SESSION['accounts'][$a_post]['pinned_tags'] = $pinned;
        redirect('prefs.php?a=' . $a_post . '#followed-tags');
    }

    // Logout confirmation step
    if (isset($_POST['logout_action'])) {
        $logout_idx = (int) ($_POST['logout_account'] ?? $a_post);
        redirect('prefs.php?a=' . $a_post . '&confirm_logout=' . $logout_idx . '#session');
    }

    // List create / delete (handled before the prefs save, then redirect)
    if (isset($_POST['list_action'])) {
        if ($_POST['list_action'] === 'list_confirm_delete') {
            $list_id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['list_id'] ?? '');
            if ($list_id !== '') {
                redirect('prefs.php?a=' . $a_post . '&confirm_list=' . $list_id . '#lists');
            }
        } elseif ($_POST['list_action'] === 'list_create') {
            $title = trim($_POST['list_title'] ?? '');
            if ($title !== '') {
                api_post($ctx_post['instance'], $ctx_post['access_token'], '/api/v1/lists', ['title' => $title]);
                unset($_SESSION['accounts'][$a_post]['lists_cache']);
            }
        } elseif ($_POST['list_action'] === 'list_delete') {
            $list_id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['list_id'] ?? '');
            if ($list_id !== '') {
                api_delete($ctx_post['instance'], $ctx_post['access_token'], '/api/v1/lists/' . $list_id);
                unset($_SESSION['accounts'][$a_post]['lists_cache']);
            }
        }
        redirect('prefs.php?a=' . $a_post);
    }

    if (isset($_POST['filter_action'])) {
        $filter_id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['filter_id'] ?? '');
        if ($_POST['filter_action'] === 'filter_confirm_delete') {
            if ($filter_id !== '') {
                redirect('prefs.php?a=' . $a_post . '&confirm_filter=' . $filter_id . '#filters');
            }
        } elseif ($_POST['filter_action'] === 'filter_delete') {
            if ($filter_id !== '') {
                api_delete($ctx_post['instance'], $ctx_post['access_token'], '/api/v1/filters/' . $filter_id);
                unset($_SESSION['accounts'][$a_post]['filters_cache']);
            }
        } elseif ($_POST['filter_action'] === 'filter_create') {
            $phrase     = trim($_POST['filter_phrase'] ?? '');
            $raw_ctx    = (array) ($_POST['filter_context'] ?? []);
            $allowed    = ['home', 'notifications', 'public', 'thread'];
            $context    = array_values(array_intersect($allowed, $raw_ctx));
            $whole_word = !empty($_POST['filter_whole_word']);
            $expires_in = (int) ($_POST['filter_expires_in'] ?? 0);
            if ($phrase !== '' && !empty($context)) {
                $body = http_build_query(['phrase' => $phrase, 'whole_word' => $whole_word ? 'true' : 'false']);
                if ($expires_in > 0) {
                    $body .= '&expires_in=' . $expires_in;
                }
                foreach ($context as $c) {
                    $body .= '&context[]=' . rawurlencode($c);
                }
                _api_request_raw('POST', rtrim($ctx_post['instance'], '/') . '/api/v1/filters', $ctx_post['access_token'], $body);
                unset($_SESSION['accounts'][$a_post]['filters_cache']);
            }
        }
        redirect('prefs.php?a=' . $a_post . '#filters');
    }

    $prefs = $_SESSION['prefs'] ?? [];

    // Theme
    $theme = $_POST['theme'] ?? 'light';
    $prefs['theme'] = in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
    $prefs['avatar_static']     = !empty($_POST['avatar_static']);
    $prefs['hide_emoji_picker'] = !empty($_POST['hide_emoji_picker']);

    // Font size
    $font_family = $_POST['font_family'] ?? 'sans-serif';
    $prefs['font_family'] = in_array($font_family, ['sans-serif', 'serif', 'monospace'], true) ? $font_family : 'sans-serif';
    $font_size = $_POST['font_size'] ?? 'normal';
    $prefs['font_size'] = in_array($font_size, ['small', 'normal', 'large', 'huge'], true) ? $font_size : 'normal';

    // Body max-width
    $max_width = (int) ($_POST['max_width'] ?? 800);
    $prefs['max_width'] = max(400, min(2000, $max_width));

    // Posts per page
    $ppp = (int) ($_POST['posts_per_page'] ?? POSTS_PER_PAGE);
    $prefs['posts_per_page'] = max(5, min(40, $ppp));

    // ePub export
    $prefs['epub_entries']          = max(10, min(EPUB_MAX_ENTRIES, (int) ($_POST['epub_entries'] ?? 40)));
    $prefs['epub_img_max_width']    = max(100, min(1200, (int) ($_POST['epub_img_max_width'] ?? 600)));
    $prefs['epub_include_cw']       = !empty($_POST['epub_include_cw']);
    $prefs['epub_include_sensitive'] = !empty($_POST['epub_include_sensitive']);

    // Banner ratio (stored as "W:H" string)
    $banner_ratio_raw = trim($_POST['banner_ratio'] ?? '3:1');
    if (preg_match('/^(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$/', $banner_ratio_raw, $brm)) {
        $br_float = (float) $brm[1] / max(0.01, (float) $brm[2]);
        $prefs['banner_ratio'] = ($br_float >= 0.5 && $br_float <= 6.0) ? $banner_ratio_raw : '3:1';
    } else {
        $prefs['banner_ratio'] = '3:1';
    }

    // Timezone
    $tz = $_POST['timezone'] ?? 'UTC';
    $prefs['timezone'] = in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC';
    date_default_timezone_set($prefs['timezone']);

    // Date format
    $allowed_date_formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'd M Y', 'M d, Y'];
    $date_fmt = $_POST['date_format'] ?? 'Y-m-d';
    $prefs['date_format'] = in_array($date_fmt, $allowed_date_formats, true) ? $date_fmt : 'Y-m-d';

    // Time format
    $allowed_time_formats = ['H:i', 'h:i A', 'H:i:s'];
    $time_fmt = $_POST['time_format'] ?? 'H:i';
    $prefs['time_format'] = in_array($time_fmt, $allowed_time_formats, true) ? $time_fmt : 'H:i';

    // Language
    $lang = $_POST['lang'] ?? 'en';
    $prefs['lang'] = isset($available_langs[$lang]) ? $lang : 'en';

    // Default account
    $def_a = (int) ($_POST['default_account'] ?? array_key_first($_SESSION['accounts']));
    $prefs['default_account'] = isset($_SESSION['accounts'][$def_a]) ? $def_a : (int) array_key_first($_SESSION['accounts']);

    $_SESSION['prefs'] = $prefs;

    // Per-account options
    $a = current_a();
    if (isset($_SESSION['accounts'][$a])) {
        $dct = $_POST['default_content_type'] ?? '';
        if (str_starts_with($dct, 'text/')) {
            $_SESSION['accounts'][$a]['default_content_type'] = $dct;
        }
        $_SESSION['accounts'][$a]['cw_default']        = substr(trim($_POST['cw_default'] ?? ''), 0, 100);
        $_SESSION['accounts'][$a]['reveal_cw']         = !empty($_POST['reveal_cw']);
        $_SESSION['accounts'][$a]['show_sensitive']    = !empty($_POST['show_sensitive']);
    }

    redirect('prefs.php?a=' . $a);
}

$prefs     = $_SESSION['prefs'] ?? [];
$timezones = DateTimeZone::listIdentifiers();
$csrf      = csrf_token();
$a         = current_a();
$ctx       = $ctx_login;
$config    = get_instance_config($ctx['instance'], $ctx['access_token'], $a);

html_head(t('prefs_title'));
echo '<h1>' . t('prefs_title') . '</h1>' . "\n";

echo '<form method="post" action="' . url('prefs.php') . '" class="inline-form">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";

// Theme
$cur_lang = $prefs['lang'] ?? 'en';
echo '<fieldset>' . "\n";
echo '<legend>' . t('prefs_theme_legend') . '</legend>' . "\n";
echo '<label for="lang">' . t('prefs_lang_label') . '</label>' . "\n";
echo '<select id="lang" name="lang">' . "\n";
foreach ($available_langs as $code => $label) {
    $selected = ($code === $cur_lang) ? ' selected' : '';
    echo '<option value="' . h($code) . '"' . $selected . '>' . h($label) . '</option>' . "\n";
}
echo '</select>' . "\n";
echo '<br>' . "\n";
$cur_theme = $prefs['theme'] ?? 'light';
echo '<label><input type="radio" name="theme" value="light"' . ($cur_theme === 'light' ? ' checked' : '') . '> ' . t('prefs_theme_light') . '</label>' . "\n";
echo '<label><input type="radio" name="theme" value="dark"' . ($cur_theme === 'dark' ? ' checked' : '') . '> ' . t('prefs_theme_dark') . '</label>' . "\n";
echo '<br>' . "\n";
$cur_avatar_static    = !empty($prefs['avatar_static']);
$cur_hide_emoji       = !empty($prefs['hide_emoji_picker']);
echo '<label><input type="checkbox" name="avatar_static" value="1"' . ($cur_avatar_static ? ' checked' : '') . '> ' . t('prefs_static_avatars') . '</label>' . "\n";
echo '<br>' . "\n";
echo '<label><input type="checkbox" name="hide_emoji_picker" value="1"' . ($cur_hide_emoji ? ' checked' : '') . '> ' . t('prefs_hide_emoji') . '</label>' . "\n";
echo '</fieldset>' . "\n";

// Font family + size
echo '<fieldset>' . "\n";
echo '<legend>' . t('prefs_font_legend') . '</legend>' . "\n";
echo '<strong>' . t('prefs_font_style') . '</strong>' . "\n";
$cur_font_family = $prefs['font_family'] ?? 'sans-serif';
foreach (['sans-serif' => t('font_sans_serif'), 'serif' => t('font_serif'), 'monospace' => t('font_monospace')] as $val => $label) {
    $checked = ($cur_font_family === $val) ? ' checked' : '';
    echo '<label><input type="radio" name="font_family" value="' . $val . '"' . $checked . '> ' . $label . '</label>' . "\n";
}
echo '<br>' . "\n";
echo '<strong>' . t('prefs_font_size') . '</strong>' . "\n";
$cur_font = $prefs['font_size'] ?? 'normal';
foreach (['small' => t('size_small'), 'normal' => t('size_normal'), 'large' => t('size_large'), 'huge' => t('size_huge')] as $sz => $sz_label) {
    $checked = ($cur_font === $sz) ? ' checked' : '';
    echo '<label><input type="radio" name="font_size" value="' . $sz . '"' . $checked . '> ' . $sz_label . '</label>' . "\n";
}
echo '</fieldset>' . "\n";

// Page size
echo '<fieldset>' . "\n";
echo '<legend>' . t('prefs_page_size_legend') . '</legend>' . "\n";
$cur_width = (int) ($prefs['max_width'] ?? 800);
$cur_ppp = (int) ($prefs['posts_per_page'] ?? POSTS_PER_PAGE);
echo '<label for="max_width">' . t('prefs_page_width_label') . '</label>' . "\n";
echo '<input type="number" id="max_width" name="max_width" value="' . $cur_width . '" min="400" max="2000">' . "\n";
echo '<br>' . "\n";
echo '<label for="posts_per_page">' . t('prefs_posts_per_page_label') . '</label>' . "\n";
echo '<input type="number" id="posts_per_page" name="posts_per_page" value="' . $cur_ppp . '" min="5" max="40">' . "\n";
echo '<br>' . "\n";
$cur_banner_ratio = h($prefs['banner_ratio'] ?? '3:1');
echo '<label for="banner_ratio">' . t('prefs_banner_ratio_label') . '</label>' . "\n";
echo '<input type="text" id="banner_ratio" name="banner_ratio" value="' . $cur_banner_ratio . '" pattern="\d+(\.\d+)?:\d+(\.\d+)?" size="6">' . "\n";
echo '</fieldset>' . "\n";

// ePub export
echo '<fieldset>' . "\n";
echo '<legend>' . t('epub_prefs_legend') . '</legend>' . "\n";
$cur_epub_entries = (int) ($prefs['epub_entries'] ?? 40);
$cur_epub_img_w   = (int) ($prefs['epub_img_max_width'] ?? 600);
echo '<label for="epub_entries">' . t('epub_entries_label', EPUB_MAX_ENTRIES) . '</label>' . "\n";
echo '<input type="number" id="epub_entries" name="epub_entries" value="' . $cur_epub_entries . '" min="10" max="' . EPUB_MAX_ENTRIES . '">' . "\n";
echo '<br>' . "\n";
echo '<label for="epub_img_max_width">' . t('epub_img_max_width_label') . '</label>' . "\n";
echo '<input type="number" id="epub_img_max_width" name="epub_img_max_width" value="' . $cur_epub_img_w . '" min="100" max="1200">' . "\n";
echo '<br>' . "\n";
$cur_epub_cw        = !empty($prefs['epub_include_cw'] ?? true) || !isset($prefs['epub_include_cw']);
$cur_epub_sensitive = !empty($prefs['epub_include_sensitive'] ?? true) || !isset($prefs['epub_include_sensitive']);
echo '<label><input type="checkbox" name="epub_include_cw" value="1"' . ($cur_epub_cw ? ' checked' : '') . '> ' . t('epub_include_cw_label') . '</label>' . "\n";
echo '<br>' . "\n";
echo '<label><input type="checkbox" name="epub_include_sensitive" value="1"' . ($cur_epub_sensitive ? ' checked' : '') . '> ' . t('epub_include_sensitive_label') . '</label>' . "\n";
echo '</fieldset>' . "\n";

// Date and time
echo '<fieldset>' . "\n";
echo '<legend>' . t('prefs_datetime_legend') . '</legend>' . "\n";

$cur_tz = $prefs['timezone'] ?? 'UTC';
echo '<label for="timezone">' . t('prefs_timezone_label') . '</label>' . "\n";
echo '<select id="timezone" name="timezone">' . "\n";
foreach ($timezones as $tz) {
    $selected = ($tz === $cur_tz) ? ' selected' : '';
    echo '<option value="' . h($tz) . '"' . $selected . '>' . h($tz) . '</option>' . "\n";
}
echo '</select>' . "\n";
echo '<br>' . "\n";

$date_formats = [
    'Y-m-d'   => '2026-04-15 (ISO 8601)',
    'd/m/Y'   => '15/04/2026 (European)',
    'm/d/Y'   => '04/15/2026 (American)',
    'd.m.Y'   => '15.04.2026 (Central European)',
    'd M Y'   => '15 Apr 2026',
    'M d, Y'  => 'Apr 15, 2026',
];
$cur_date_fmt = $prefs['date_format'] ?? 'Y-m-d';
echo '<label for="date_format">' . t('prefs_date_format_label') . '</label>' . "\n";
echo '<select id="date_format" name="date_format">' . "\n";
foreach ($date_formats as $fmt => $label) {
    $selected = ($fmt === $cur_date_fmt) ? ' selected' : '';
    echo '<option value="' . h($fmt) . '"' . $selected . '>' . h($label) . '</option>' . "\n";
}
echo '</select>' . "\n";
echo '<br>' . "\n";

$time_formats = [
    'H:i'   => '23:45 (24h)',
    'h:i A' => '11:45 PM (12h)',
    'H:i:s' => '23:45:00 (24h with seconds)',
];
$cur_time_fmt = $prefs['time_format'] ?? 'H:i';
echo '<label for="time_format">' . t('prefs_time_format_label') . '</label>' . "\n";
echo '<select id="time_format" name="time_format">' . "\n";
foreach ($time_formats as $fmt => $label) {
    $selected = ($fmt === $cur_time_fmt) ? ' selected' : '';
    echo '<option value="' . h($fmt) . '"' . $selected . '>' . h($label) . '</option>' . "\n";
}
echo '</select>' . "\n";

echo '</fieldset>' . "\n";

// Default account (only shown when multiple accounts are logged in)
if (count($_SESSION['accounts']) > 1) {
    echo '<fieldset>' . "\n";
    echo '<legend>' . t('prefs_default_account_legend') . '</legend>' . "\n";
    $cur_def = (int) ($prefs['default_account'] ?? array_key_first($_SESSION['accounts']));
    echo '<label for="default_account">' . t('prefs_account_label') . '</label>' . "\n";
    echo '<select id="default_account" name="default_account">' . "\n";
    foreach ($_SESSION['accounts'] as $i => $acc) {
        $host     = parse_url($acc['instance'], PHP_URL_HOST) ?? $acc['instance'];
        $label    = ($acc['account']['username'] ?? '?') . '@' . $host;
        $selected = ($i === $cur_def) ? ' selected' : '';
        echo '<option value="' . $i . '"' . $selected . '>' . h($label) . '</option>' . "\n";
    }
    echo '</select>' . "\n";
    echo '</fieldset>' . "\n";
}

// Per-account options
$mime_types = $config['supported_mime_types'] ?? ['text/plain'];
$host    = parse_url($ctx['instance'], PHP_URL_HOST) ?? $ctx['instance'];
$acct    = ($ctx['account']['username'] ?? '?') . '@' . $host;

$cur_cw_default    = $ctx['cw_default'] ?? '';
$cur_reveal_cw     = !empty($ctx['reveal_cw']);
$cur_show_sensitive = !empty($ctx['show_sensitive']);
echo '<fieldset>' . "\n";
echo '<legend>' . t('prefs_account_options_legend', h('@' . $acct)) . '</legend>' . "\n";
if (count($mime_types) > 1) {
    $cur_dct = $ctx['default_content_type'] ?? 'text/plain';
    echo '<label for="default_content_type">' . t('prefs_default_post_format') . '</label>' . "\n";
    echo '<select id="default_content_type" name="default_content_type">' . "\n";
    foreach ($mime_types as $mime) {
        $sel = ($mime === $cur_dct) ? ' selected' : '';
        echo '<option value="' . h($mime) . '"' . $sel . '>' . h(substr($mime, 5)) . '</option>' . "\n";
    }
    echo '</select>' . "\n";
    echo '<br>' . "\n";
}
echo '<label for="cw_default">' . t('prefs_default_cw') . '</label>' . "\n";
echo '<input type="text" id="cw_default" name="cw_default" maxlength="100" value="' . h($cur_cw_default) . '">' . "\n";
echo '<br>' . "\n";
echo '<label><input type="checkbox" name="reveal_cw" value="1"' . ($cur_reveal_cw ? ' checked' : '') . '> ' . t('prefs_reveal_cw') . '</label>' . "\n";
echo '<br>' . "\n";
echo '<label><input type="checkbox" name="show_sensitive" value="1"' . ($cur_show_sensitive ? ' checked' : '') . '> ' . t('prefs_show_sensitive') . '</label>' . "\n";
echo '</fieldset>' . "\n";

echo '<p><button type="submit" class="active">' . t('prefs_save_btn') . '</button></p>' . "\n";
echo '</form>' . "\n";

// Lists management
$user_lists  = get_user_lists($ctx['instance'], $ctx['access_token'], $a);
$confirm_list = preg_replace('/[^A-Za-z0-9]/', '', $_GET['confirm_list'] ?? '');
echo '<fieldset id="lists">' . "\n";
echo '<legend>' . t('prefs_lists_legend', h('@' . $acct)) . '</legend>' . "\n";
foreach ($user_lists as $lst) {
    $lst_id    = preg_replace('/[^A-Za-z0-9]/', '', $lst['id'] ?? '');
    $lst_title = h($lst['title'] ?? '');
    $confirming = ($confirm_list !== '' && $confirm_list === $lst_id);
    echo '<form method="post" action="' . url('prefs.php') . '">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="list_id" value="' . $lst_id . '">' . "\n";
    if ($confirming) {
        echo '<input type="hidden" name="list_action" value="list_delete">' . "\n";
        echo $lst_title . ' <button type="submit" class="danger">' . t('action_confirm') . '</button>' . "\n";
    } else {
        echo '<input type="hidden" name="list_action" value="list_confirm_delete">' . "\n";
        echo $lst_title . ' <button type="submit" class="danger">' . t('action_delete') . '</button>' . "\n";
    }
    echo '</form>' . "\n";
}
echo '<form method="post" action="' . url('prefs.php') . '">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
echo '<input type="hidden" name="list_action" value="list_create">' . "\n";
echo '<input type="text" name="list_title" placeholder="' . t('prefs_new_list_ph') . '"> ';
echo '<button type="submit">' . t('action_create') . '</button>' . "\n";
echo '</form>' . "\n";
echo '</fieldset>' . "\n";

// Followed hashtags + pinned-but-not-followed, merged and sorted
$followed_tags    = get_followed_tags($ctx['instance'], $ctx['access_token'], $a);
$pinned_tags_list = $_SESSION['accounts'][$a]['pinned_tags'] ?? [];
$followed_names   = array_filter(array_column($followed_tags, 'name'));

// Build unified tag list: followed tags + pinned tags not already followed
$all_tags = [];
foreach ($followed_names as $name) {
    $all_tags[$name] = ['followed' => true, 'pinned' => in_array($name, $pinned_tags_list, true)];
}
foreach ($pinned_tags_list as $name) {
    if ($name !== '' && !isset($all_tags[$name])) {
        $all_tags[$name] = ['followed' => false, 'pinned' => true];
    }
}
ksort($all_tags);

echo '<fieldset id="followed-tags">' . "\n";
echo '<legend>' . t('prefs_tags_legend', h('@' . $acct)) . '</legend>' . "\n";
if (empty($all_tags)) {
    echo '<p>' . t('prefs_tags_none') . '</p>' . "\n";
} else {
    foreach ($all_tags as $tag_name => $tag_state) {
        $is_pinned   = $tag_state['pinned'];
        $is_followed = $tag_state['followed'];
        $pin_label   = $is_pinned ? t('action_unpin') : t('action_pin');
        $pin_class   = $is_pinned ? ' class="active"' : '';
        echo '<a href="' . url('tag.php', ['tag' => $tag_name]) . '">#' . h($tag_name) . '</a> ';
        echo '<form method="post" action="' . url('prefs.php') . '" class="inline">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="tag" value="' . h($tag_name) . '">' . "\n";
        echo '<button type="submit" name="tag_pin_action" value="1"' . $pin_class . '>' . $pin_label . '</button>' . "\n";
        echo '</form>' . "\n";
        echo '<form method="post" action="action.php" class="inline">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="tag" value="' . h($tag_name) . '">' . "\n";
        if ($is_followed) {
            echo '<button type="submit" name="action" value="tag_unfollow" class="danger">' . t('action_unfollow') . '</button>' . "\n";
        } else {
            echo '<button type="submit" name="action" value="tag_follow">' . t('action_follow') . '</button>' . "\n";
        }
        echo '</form><br>' . "\n";
    }
}
echo '</fieldset>' . "\n";

// Filters management
$filters        = get_filters($ctx['instance'], $ctx['access_token'], $a);
$confirm_filter = preg_replace('/[^A-Za-z0-9]/', '', $_GET['confirm_filter'] ?? '');
$ctx_labels     = ['home' => t('ctx_home'), 'notifications' => t('ctx_notifs'), 'public' => t('ctx_public'), 'thread' => t('ctx_thread')];

echo '<fieldset id="filters">' . "\n";
echo '<legend>' . t('prefs_filters_legend', h('@' . $acct)) . '</legend>' . "\n";

foreach ($filters as $f) {
    $f_id      = preg_replace('/[^A-Za-z0-9]/', '', $f['id'] ?? '');
    $f_phrase  = $f['phrase'] ?? '';
    $f_context = $f['context'] ?? [];
    $f_whole   = !empty($f['whole_word']);
    $f_expires = $f['expires_at'] ?? null;
    if ($f_id === '' || $f_phrase === '') continue;
    $confirming = ($confirm_filter !== '' && $confirm_filter === $f_id);
    $ctx_str    = implode(', ', array_map(fn($c) => $ctx_labels[$c] ?? $c, $f_context));
    $meta       = $ctx_str;
    if ($f_whole) $meta .= ', ' . t('prefs_filter_whole_word');
    if ($f_expires) {
        $ts = strtotime($f_expires);
        if ($ts) $meta .= ', expires ' . fmt_datetime($ts);
    }
    echo '<form method="post" action="' . url('prefs.php') . '">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="filter_id" value="' . h($f_id) . '">' . "\n";
    echo h($f_phrase) . ' <small>(' . h($meta) . ')</small> ';
    if ($confirming) {
        echo '<input type="hidden" name="filter_action" value="filter_delete">' . "\n";
        echo '<button type="submit" class="danger">' . t('action_confirm') . '</button>' . "\n";
    } else {
        echo '<input type="hidden" name="filter_action" value="filter_confirm_delete">' . "\n";
        echo '<button type="submit" class="danger">' . t('action_delete') . '</button>' . "\n";
    }
    echo '</form>' . "\n";
}

echo '<details>' . "\n";
echo '<summary>' . t('prefs_add_filter') . '</summary>' . "\n";
echo '<form method="post" action="' . url('prefs.php') . '">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
echo '<input type="hidden" name="filter_action" value="filter_create">' . "\n";
echo '<label for="filter_phrase">' . t('prefs_filter_phrase_label') . '</label> ';
echo '<input type="text" id="filter_phrase" name="filter_phrase" required>' . "\n";
echo '<br>' . "\n";
echo t('prefs_filter_context_label') . ' ';
foreach ($ctx_labels as $ctx_val => $ctx_lbl) {
    echo '<label><input type="checkbox" name="filter_context[]" value="' . $ctx_val . '" checked> ' . $ctx_lbl . '</label> ' . "\n";
}
echo '<br>' . "\n";
echo '<label><input type="checkbox" name="filter_whole_word" value="1"> ' . t('prefs_filter_whole_word') . '</label>' . "\n";
echo '<br>' . "\n";
echo '<label for="filter_expires">' . t('prefs_filter_expires_label') . '</label> ';
echo '<select id="filter_expires" name="filter_expires_in">' . "\n";
foreach ([0 => t('expires_never'), 86400 => t('duration_1d'), 604800 => t('expires_1week'), 2592000 => t('expires_30days')] as $secs => $label) {
    echo '<option value="' . $secs . '">' . $label . '</option>' . "\n";
}
echo '</select>' . "\n";
echo ' <button type="submit">' . t('prefs_filter_add_btn') . '</button>' . "\n";
echo '</form>' . "\n";
echo '</details>' . "\n";

echo '</fieldset>' . "\n";

// Moderation summary
$blocks          = api_get($ctx['instance'], $ctx['access_token'], '/api/v1/blocks', ['limit' => 80]);
$mutes           = api_get($ctx['instance'], $ctx['access_token'], '/api/v1/mutes', ['limit' => 80]);
$blocked_domains = get_blocked_domains($ctx['instance'], $ctx['access_token'], $a);
$blocks          = (is_array($blocks)  && !isset($blocks['error']))  ? $blocks  : [];
$mutes           = (is_array($mutes)   && !isset($mutes['error']))   ? $mutes   : [];

echo '<fieldset id="moderation">' . "\n";
echo '<legend>' . t('prefs_moderation_legend', h('@' . $acct)) . '</legend>' . "\n";

echo '<details>' . "\n";
echo '<summary>' . t('prefs_blocked_accounts', count($blocks)) . '</summary>' . "\n";
foreach ($blocks as $acc) {
    $acc_id    = preg_replace('/[^A-Za-z0-9]/', '', $acc['id'] ?? '');
    $acc_label = $acc['acct'] ?? ($acc['username'] ?? '?');
    if ($acc_id === '') continue;
    echo '<form method="post" action="action.php">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
    echo '<input type="hidden" name="id" value="' . h($acc_id) . '">' . "\n";
    echo '<a href="' . url('profile.php', ['id' => $acc_id]) . '">@' . h($acc_label) . '</a> ';
    echo '<button type="submit" name="action" value="unblock" class="active">' . t('action_unblock') . '</button>' . "\n";
    echo '</form>' . "\n";
}
echo '</details>' . "\n";

echo '<details>' . "\n";
echo '<summary>' . t('prefs_muted_accounts', count($mutes)) . '</summary>' . "\n";
foreach ($mutes as $acc) {
    $acc_id    = preg_replace('/[^A-Za-z0-9]/', '', $acc['id'] ?? '');
    $acc_label = $acc['acct'] ?? ($acc['username'] ?? '?');
    if ($acc_id === '') continue;
    echo '<form method="post" action="action.php">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
    echo '<input type="hidden" name="id" value="' . h($acc_id) . '">' . "\n";
    echo '<a href="' . url('profile.php', ['id' => $acc_id]) . '">@' . h($acc_label) . '</a> ';
    echo '<button type="submit" name="action" value="unmute" class="active">' . t('action_unmute') . '</button>' . "\n";
    echo '</form>' . "\n";
}
echo '</details>' . "\n";

if (domain_blocks_supported($ctx['instance'], $ctx['access_token'], $a)) {
    echo '<details>' . "\n";
    echo '<summary>' . t('prefs_blocked_domains', count($blocked_domains)) . '</summary>' . "\n";
    foreach ($blocked_domains as $dom) {
        if (!is_string($dom) || $dom === '') continue;
        echo '<form method="post" action="action.php">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="domain" value="' . h($dom) . '">' . "\n";
        echo h($dom) . ' <button type="submit" name="action" value="unblock_domain" class="active">' . t('action_unblock') . '</button>' . "\n";
        echo '</form>' . "\n";
    }
    echo '</details>' . "\n";
}

echo '</fieldset>' . "\n";

$confirm_logout_idx = isset($_GET['confirm_logout']) ? (int) $_GET['confirm_logout'] : -1;

echo '<hr>' . "\n";
echo '<fieldset id="session">' . "\n";
echo '<legend>' . t('prefs_session_legend') . '</legend>' . "\n";
foreach ($_SESSION['accounts'] as $i => $acc) {
    $acc_host   = parse_url($acc['instance'], PHP_URL_HOST) ?? $acc['instance'];
    $acc_label  = '@' . ($acc['account']['acct'] ?? $acc['account']['username'] ?? '?') . '@' . $acc_host;
    $is_current = ($i === $a);
    if ($confirm_logout_idx === $i) {
        echo '<form method="get" action="logout.php">' . "\n";
        echo '<input type="hidden" name="a" value="' . $i . '">' . "\n";
        if ($is_current) {
            echo '<strong>' . h($acc_label) . '</strong>';
        } else {
            echo h($acc_label);
        }
        echo ' <button type="submit" class="danger">' . t('action_confirm') . '</button>' . "\n";
        echo '</form>' . "\n";
    } else {
        echo '<form method="post" action="' . url('prefs.php') . '">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="logout_account" value="' . $i . '">' . "\n";
        if ($is_current) {
            echo '<strong>' . h($acc_label) . '</strong>';
        } else {
            echo h($acc_label);
        }
        echo ' <button type="submit" name="logout_action" value="confirm" class="danger">' . t('action_logout') . '</button>' . "\n";
        echo '</form>' . "\n";
    }
}
echo '<p><a href="login.php">' . t('prefs_connect_new') . '</a></p>' . "\n";
echo '</fieldset>' . "\n";

html_foot();
