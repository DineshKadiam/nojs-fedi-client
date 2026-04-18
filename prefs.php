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

    $prefs = $_SESSION['prefs'] ?? [];

    // Theme
    $theme = $_POST['theme'] ?? 'light';
    $prefs['theme'] = in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
    $prefs['avatar_static'] = !empty($_POST['avatar_static']);

    // Font size
    $font_size = $_POST['font_size'] ?? 'normal';
    $prefs['font_size'] = in_array($font_size, ['small', 'normal', 'large', 'huge'], true) ? $font_size : 'normal';

    // Body max-width
    $max_width = (int) ($_POST['max_width'] ?? 800);
    $prefs['max_width'] = max(400, min(2000, $max_width));

    // Posts per page
    $ppp = (int) ($_POST['posts_per_page'] ?? POSTS_PER_PAGE);
    $prefs['posts_per_page'] = max(5, min(40, $ppp));

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

html_head('Preferences');
echo '<h1>Preferences</h1>' . "\n";

echo '<form method="post" action="' . url('prefs.php') . '" class="inline-form">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";

// Theme
echo '<fieldset>' . "\n";
echo '<legend>Theme</legend>' . "\n";
$cur_theme = $prefs['theme'] ?? 'light';
echo '<label><input type="radio" name="theme" value="light"' . ($cur_theme === 'light' ? ' checked' : '') . '> Light</label>' . "\n";
echo '<label><input type="radio" name="theme" value="dark"' . ($cur_theme === 'dark' ? ' checked' : '') . '> Dark</label>' . "\n";
echo '<br>' . "\n";
$cur_avatar_static = !empty($prefs['avatar_static']);
echo '<label><input type="checkbox" name="avatar_static" value="1"' . ($cur_avatar_static ? ' checked' : '') . '> Use static avatars (no animation)</label>' . "\n";
echo '</fieldset>' . "\n";

// Font size
echo '<fieldset>' . "\n";
echo '<legend>Font size</legend>' . "\n";
$cur_font = $prefs['font_size'] ?? 'normal';
foreach (['small', 'normal', 'large', 'huge'] as $sz) {
    $checked = ($cur_font === $sz) ? ' checked' : '';
    echo '<label><input type="radio" name="font_size" value="' . $sz . '"' . $checked . '> ' . ucfirst($sz) . '</label>' . "\n";
}
echo '</fieldset>' . "\n";

// Page size
echo '<fieldset>' . "\n";
echo '<legend>Page size</legend>' . "\n";
$cur_width = (int) ($prefs['max_width'] ?? 800);
$cur_ppp = (int) ($prefs['posts_per_page'] ?? POSTS_PER_PAGE);
echo '<label for="max_width">Width (400-2000px):</label>' . "\n";
echo '<input type="number" id="max_width" name="max_width" value="' . $cur_width . '" min="400" max="2000">' . "\n";
echo '<br>' . "\n";
echo '<label for="posts_per_page">Posts per page (5-40):</label>' . "\n";
echo '<input type="number" id="posts_per_page" name="posts_per_page" value="' . $cur_ppp . '" min="5" max="40">' . "\n";
echo '</fieldset>' . "\n";

// Date and time
echo '<fieldset>' . "\n";
echo '<legend>Date and time</legend>' . "\n";

$cur_tz = $prefs['timezone'] ?? 'UTC';
echo '<label for="timezone">Timezone:</label>' . "\n";
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
echo '<label for="date_format">Date format:</label>' . "\n";
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
echo '<label for="time_format">Time format:</label>' . "\n";
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
    echo '<legend>Default account</legend>' . "\n";
    $cur_def = (int) ($prefs['default_account'] ?? array_key_first($_SESSION['accounts']));
    echo '<label for="default_account">Account:</label>' . "\n";
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
echo '<legend>Options for ' . h('@' . $acct) . '</legend>' . "\n";
if (count($mime_types) > 1) {
    $cur_dct = $ctx['default_content_type'] ?? 'text/plain';
    echo '<label for="default_content_type">Default post format:</label>' . "\n";
    echo '<select id="default_content_type" name="default_content_type">' . "\n";
    foreach ($mime_types as $mime) {
        $sel = ($mime === $cur_dct) ? ' selected' : '';
        echo '<option value="' . h($mime) . '"' . $sel . '>' . h(substr($mime, 5)) . '</option>' . "\n";
    }
    echo '</select>' . "\n";
    echo '<br>' . "\n";
}
echo '<label for="cw_default">Default content warning (empty = none):</label>' . "\n";
echo '<input type="text" id="cw_default" name="cw_default" maxlength="100" value="' . h($cur_cw_default) . '">' . "\n";
echo '<br>' . "\n";
echo '<label><input type="checkbox" name="reveal_cw" value="1"' . ($cur_reveal_cw ? ' checked' : '') . '> Reveal all content warnings</label>' . "\n";
echo '<br>' . "\n";
echo '<label><input type="checkbox" name="show_sensitive" value="1"' . ($cur_show_sensitive ? ' checked' : '') . '> Show all sensitive media</label>' . "\n";
echo '</fieldset>' . "\n";

echo '<p><button type="submit" class="active">Save preferences</button></p>' . "\n";
echo '</form>' . "\n";

// Lists management
$user_lists  = get_user_lists($ctx['instance'], $ctx['access_token'], $a);
$confirm_list = preg_replace('/[^A-Za-z0-9]/', '', $_GET['confirm_list'] ?? '');
echo '<fieldset id="lists">' . "\n";
echo '<legend>Lists for ' . h('@' . $acct) . '</legend>' . "\n";
foreach ($user_lists as $lst) {
    $lst_id    = preg_replace('/[^A-Za-z0-9]/', '', $lst['id'] ?? '');
    $lst_title = h($lst['title'] ?? '');
    $confirming = ($confirm_list !== '' && $confirm_list === $lst_id);
    echo '<form method="post" action="' . url('prefs.php') . '">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="list_id" value="' . $lst_id . '">' . "\n";
    if ($confirming) {
        echo '<input type="hidden" name="list_action" value="list_delete">' . "\n";
        echo $lst_title . ' <button type="submit" class="danger">Confirm?</button>' . "\n";
    } else {
        echo '<input type="hidden" name="list_action" value="list_confirm_delete">' . "\n";
        echo $lst_title . ' <button type="submit" class="danger">Delete</button>' . "\n";
    }
    echo '</form>' . "\n";
}
echo '<form method="post" action="' . url('prefs.php') . '">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
echo '<input type="hidden" name="list_action" value="list_create">' . "\n";
echo '<input type="text" name="list_title" placeholder="New list"> ';
echo '<button type="submit">Create</button>' . "\n";
echo '</form>' . "\n";
echo '</fieldset>' . "\n";

// Followed hashtags
$followed_tags = get_followed_tags($ctx['instance'], $ctx['access_token'], $a);
echo '<fieldset id="followed-tags">' . "\n";
echo '<legend>Followed hashtags for ' . h('@' . $acct) . '</legend>' . "\n";
if (empty($followed_tags)) {
    echo '<p>No followed hashtags.</p>' . "\n";
} else {
    foreach ($followed_tags as $ft) {
        $ft_name = $ft['name'] ?? '';
        if ($ft_name === '') continue;
        echo '<form method="post" action="action.php">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="tag" value="' . h($ft_name) . '">' . "\n";
        echo '<a href="' . url('tag.php', ['tag' => $ft_name]) . '">#' . h($ft_name) . '</a> ';
        echo '<button type="submit" name="action" value="tag_unfollow">Unfollow</button>' . "\n";
        echo '</form>' . "\n";
    }
}
echo '</fieldset>' . "\n";

// Moderation summary
$blocks          = api_get($ctx['instance'], $ctx['access_token'], '/api/v1/blocks', ['limit' => 80]);
$mutes           = api_get($ctx['instance'], $ctx['access_token'], '/api/v1/mutes', ['limit' => 80]);
$blocked_domains = get_blocked_domains($ctx['instance'], $ctx['access_token'], $a);
$blocks          = (is_array($blocks)  && !isset($blocks['error']))  ? $blocks  : [];
$mutes           = (is_array($mutes)   && !isset($mutes['error']))   ? $mutes   : [];

echo '<fieldset id="moderation">' . "\n";
echo '<legend>Moderation for ' . h('@' . $acct) . '</legend>' . "\n";

echo '<details>' . "\n";
echo '<summary>Blocked accounts (' . count($blocks) . ')</summary>' . "\n";
foreach ($blocks as $acc) {
    $acc_id    = preg_replace('/[^A-Za-z0-9]/', '', $acc['id'] ?? '');
    $acc_label = $acc['acct'] ?? ($acc['username'] ?? '?');
    if ($acc_id === '') continue;
    echo '<form method="post" action="action.php">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
    echo '<input type="hidden" name="id" value="' . h($acc_id) . '">' . "\n";
    echo '<a href="' . url('profile.php', ['id' => $acc_id]) . '">@' . h($acc_label) . '</a> ';
    echo '<button type="submit" name="action" value="unblock" class="active">Unblock</button>' . "\n";
    echo '</form>' . "\n";
}
echo '</details>' . "\n";

echo '<details>' . "\n";
echo '<summary>Muted accounts (' . count($mutes) . ')</summary>' . "\n";
foreach ($mutes as $acc) {
    $acc_id    = preg_replace('/[^A-Za-z0-9]/', '', $acc['id'] ?? '');
    $acc_label = $acc['acct'] ?? ($acc['username'] ?? '?');
    if ($acc_id === '') continue;
    echo '<form method="post" action="action.php">' . "\n";
    echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
    echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
    echo '<input type="hidden" name="id" value="' . h($acc_id) . '">' . "\n";
    echo '<a href="' . url('profile.php', ['id' => $acc_id]) . '">@' . h($acc_label) . '</a> ';
    echo '<button type="submit" name="action" value="unmute" class="active">Unmute</button>' . "\n";
    echo '</form>' . "\n";
}
echo '</details>' . "\n";

if (domain_blocks_supported($ctx['instance'], $ctx['access_token'], $a)) {
    echo '<details>' . "\n";
    echo '<summary>Blocked domains (' . count($blocked_domains) . ')</summary>' . "\n";
    foreach ($blocked_domains as $dom) {
        if (!is_string($dom) || $dom === '') continue;
        echo '<form method="post" action="action.php">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="domain" value="' . h($dom) . '">' . "\n";
        echo h($dom) . ' <button type="submit" name="action" value="unblock_domain" class="active">Unblock</button>' . "\n";
        echo '</form>' . "\n";
    }
    echo '</details>' . "\n";
}

echo '</fieldset>' . "\n";

html_foot();
