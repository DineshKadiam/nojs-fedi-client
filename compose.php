<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';

$ctx    = require_login();
$a      = current_a();
$csrf   = csrf_token();
$config = get_instance_config($ctx['instance'], $ctx['access_token'], $a);
$max_chars = $config['max_characters'];
$max_media = $config['max_media_attachments'];

$creds        = api_get($ctx['instance'], $ctx['access_token'], '/api/v1/accounts/verify_credentials');
$custom_emojis = api_get($ctx['instance'], $ctx['access_token'], '/api/v1/custom_emojis');
if (isset($creds['error'])) {
    html_head('Error');
    html_error(t('compose_session_expired'));
    echo '<p><a href="login.php">' . t('login_btn') . '</a></p>';
    html_foot();
    exit;
}
$default_lang = $creds['source']['language'] ?? '';

// Edit mode: load existing status
$edit_id     = preg_replace('/[^A-Za-z0-9]/', '', $_GET['edit'] ?? '');
$edit_status = null;
if ($edit_id !== '') {
    $edit_status = api_get($ctx['instance'], $ctx['access_token'], '/api/v1/statuses/' . $edit_id);
    if (isset($edit_status['error']) || empty($edit_status['id'])) {
        html_head('Error');
        html_error(t('compose_err_load_edit'));
        html_foot();
        exit;
    }
    $edit_source = api_get($ctx['instance'], $ctx['access_token'], '/api/v1/statuses/' . $edit_id . '/source');
    if (!isset($edit_source['error']) && isset($edit_source['text'])) {
        $edit_status['text']         = $edit_source['text'];
        $edit_status['spoiler_text'] = $edit_source['spoiler_text'] ?? $edit_status['spoiler_text'] ?? '';
    }
}

$is_edit  = $edit_status !== null;
$type     = (!$is_edit && ($_GET['type'] ?? '') === 'poll') ? 'poll' : 'post';

// Quote mode
$quote_id     = preg_replace('/[^A-Za-z0-9]/', '', $_GET['quote_id'] ?? '');
$quote_url_in = filter_var($_GET['quote_url'] ?? '', FILTER_VALIDATE_URL) ? $_GET['quote_url'] : '';

$page_title = $is_edit ? t('compose_edit_title') : ($type === 'poll' ? t('compose_poll_title') : t('compose_post_title'));

$languages = ['af','ar','az','be','bg','bn','bs','ca','cs','cy','da','de','el','en','eo',
              'es','et','eu','fa','fi','fr','ga','gl','gu','he','hi','hr','hu','hy','id',
              'is','it','ja','ka','kk','km','kn','ko','ku','ky','lb','lt','lv','mk','ml',
              'mn','mr','ms','my','nl','nn','no','oc','pa','pl','ps','pt','ro','ru','sk',
              'sl','sq','sr','sv','sw','ta','te','th','tl','tr','uk','ur','uz','vi','zh'];

// Pre-fill values: from existing status in edit mode, or defaults for new post
$prefill_content    = $is_edit ? ($edit_status['text'] ?? strip_tags($edit_status['content'] ?? '')) : '';
$prefill_spoiler    = $is_edit ? ($edit_status['spoiler_text'] ?? '') : ($_SESSION['accounts'][$a]['cw_default'] ?? '');
$prefill_lang       = $is_edit ? ($edit_status['language'] ?? $default_lang) : $default_lang;
$prefill_visibility = $is_edit ? ($edit_status['visibility'] ?? ($creds['source']['privacy'] ?? 'public'))
                                : ($creds['source']['privacy'] ?? 'public');
$prefill_sensitive  = $is_edit ? !empty($edit_status['sensitive']) : !empty($creds['source']['sensitive']);
$prefill_mime       = $is_edit ? ($edit_status['content_type'] ?? ($_SESSION['accounts'][$a]['default_content_type'] ?? 'text/plain'))
                                : ($_SESSION['accounts'][$a]['default_content_type'] ?? 'text/plain');

$draft = null;
if (!$is_edit && !empty($_SESSION['compose_draft'])) {
    $draft              = $_SESSION['compose_draft'];
    unset($_SESSION['compose_draft']);
    $prefill_content    = $draft['content']     ?? $prefill_content;
    $prefill_spoiler    = $draft['spoiler_text'] ?? $prefill_spoiler;
    $prefill_lang       = $draft['lang']         ?? $prefill_lang;
    $prefill_visibility = $draft['visibility']   ?? $prefill_visibility;
    $prefill_sensitive  = !empty($draft['sensitive']);
    $prefill_mime       = $draft['content_type'] ?? $prefill_mime;
    if (!empty($draft['is_poll'])) {
        $type       = 'poll';
        $page_title = t('compose_poll_title');
    }
    if ($quote_id === '' && !empty($draft['quote_id'])) {
        $quote_id = preg_replace('/[^A-Za-z0-9]/', '', $draft['quote_id']);
    }
}
$sensitive_checked  = $prefill_sensitive ? ' checked' : '';

// Fetch quoted status if in quote mode
$quoted_status = null;
if ($quote_id !== '') {
    $fetched_quote = api_get($ctx['instance'], $ctx['access_token'], '/api/v1/statuses/' . $quote_id);
    if (is_array($fetched_quote) && !isset($fetched_quote['error'])) {
        $quoted_status = $fetched_quote;
    }
}
$is_quote_compose = $quote_id !== '' || $quote_url_in !== '';
$effective_quote_url = $is_quote_compose
    ? (($quoted_status !== null) ? ($quoted_status['url'] ?? $quote_url_in) : $quote_url_in)
    : '';
if ($is_quote_compose && !$is_edit) {
    $page_title = t('compose_quote_title');
}

html_head($page_title);
echo '<h1>' . h($page_title) . '</h1>' . "\n";

if ($quoted_status !== null) {
    echo '<section>' . "\n";
    echo '<p><strong>' . t('compose_quoting_label') . '</strong></p>' . "\n";
    html_post($quoted_status, false, [], true);
    echo '</section>' . "\n";
} elseif ($quote_url_in !== '') {
    echo '<p><strong>' . t('compose_quoting_label') . '</strong> <a href="' . h($quote_url_in) . '" target="_blank" rel="noopener noreferrer">' . h($quote_url_in) . '</a></p>' . "\n";
}
$use_multipart = !$is_edit && $type !== 'poll';
echo '<form method="post" action="action.php"' . ($use_multipart ? ' enctype="multipart/form-data"' : '') . '>' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
echo '<input type="hidden" name="action" value="' . ($is_edit ? 'edit_save' : 'post') . '">' . "\n";
if ($is_edit) {
    echo '<input type="hidden" name="id" value="' . h($edit_id) . '">' . "\n";
}
if ($quote_id !== '') {
    echo '<input type="hidden" name="quote_id" value="' . h($quote_id) . '">' . "\n";
}
if ($effective_quote_url !== '') {
    echo '<input type="hidden" name="quote_url" value="' . h($effective_quote_url) . '">' . "\n";
}
echo '<fieldset>' . "\n";
echo '<legend>' . t('compose_legend') . '</legend>' . "\n";
echo '<label for="spoiler">' . t('compose_cw_label') . '</label>' . "\n";
echo '<br>' . "\n";
echo '<input type="text" id="spoiler" name="spoiler_text" maxlength="100" value="' . h($prefill_spoiler) . '">' . "\n";
echo '<br>' . "\n";
echo '<label for="content">' . t('compose_content_label') . '</label>' . "\n";
echo '<br>' . "\n";
echo '<textarea id="content" name="content" rows="8" cols="60" required maxlength="' . $max_chars . '">' . h($prefill_content) . '</textarea>' . "\n";
echo '<p><small>' . t('compose_max_chars', $max_chars) . '</small></p>' . "\n";
html_emoji_picker(is_array($custom_emojis) ? $custom_emojis : []);
if (!$is_edit) {
    $tz_name = pref('timezone', 'UTC');
    echo '<details>' . "\n";
    echo '<summary>' . t('compose_schedule_summary') . '</summary>' . "\n";
    echo '<span class="inline-form">' . "\n";
    echo '<label for="scheduled_date">' . t('compose_schedule_date') . '</label> ' . "\n";
    echo '<input type="date" id="scheduled_date" name="scheduled_date"> ' . "\n";
    echo '<label for="scheduled_time">' . t('compose_schedule_time') . '</label> ' . "\n";
    echo '<input type="time" id="scheduled_time" name="scheduled_time"> ' . "\n";
    echo h($tz_name) . "\n";
    echo '</span>' . "\n";
    echo '</details>' . "\n";
}
echo '<br>' . "\n";
echo '<span class="inline-form">' . "\n";
$mime_types = $config['supported_mime_types'] ?? ['text/plain'];
if (count($mime_types) > 1) {
    echo '<label for="content_type">' . t('compose_format_label') . '</label> ' . "\n";
    echo '<select id="content_type" name="content_type">' . "\n";
    foreach ($mime_types as $mime) {
        $sel = ($mime === $prefill_mime) ? ' selected' : '';
        echo '<option value="' . h($mime) . '"' . $sel . '>' . h(substr($mime, 5)) . '</option>' . "\n";
    }
    echo '</select>' . "\n";
    echo ' &nbsp; ' . "\n";
}
echo '<label for="lang">' . t('compose_lang_label') . '</label> ' . "\n";
echo '<select id="lang" name="lang">' . "\n";
echo '<option value=""></option>' . "\n";
foreach ($languages as $code) {
    $sel = ($code === $prefill_lang) ? ' selected' : '';
    echo '<option value="' . $code . '"' . $sel . '>' . $code . '</option>' . "\n";
}
echo '</select>' . "\n";
echo ' &nbsp; ' . "\n";
echo '<label for="visibility">' . t('compose_visibility_label') . '</label> ' . "\n";
echo '<select id="visibility" name="visibility"' . ($is_edit ? ' disabled' : '') . '>' . "\n";
foreach (['public' => t('vis_public_abbr'), 'unlisted' => t('vis_unlisted_abbr'), 'private' => t('vis_followers_abbr'), 'direct' => t('vis_direct_abbr')] as $val => $label) {
    $sel = ($val === $prefill_visibility) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>' . "\n";
}
echo '</select>' . "\n";
echo ' &nbsp; ';
echo '<label><input type="checkbox" name="sensitive" value="1"' . $sensitive_checked . '> ' . t('compose_sensitive_label') . '</label>' . "\n";
echo '</span>' . "\n";
echo '</fieldset>' . "\n";
echo '<button type="submit">' . ($is_edit ? t('compose_save_btn') : t('compose_post_btn')) . '</button>' . "\n";

if ($type === 'poll') {
    echo '<div id="poll">' . "\n";
    echo '<br><br>' . "\n";
    echo '<fieldset>' . "\n";
    echo '<legend>' . t('compose_poll_legend') . '</legend>' . "\n";
    $draft_poll_opts    = is_array($draft) ? ($draft['poll_options']    ?? []) : [];
    $draft_poll_expires = is_array($draft) ? (string) ($draft['poll_expires_in'] ?? '86400') : '86400';
    $draft_poll_multi   = is_array($draft) && !empty($draft['poll_multiple']);
    $draft_poll_hide    = is_array($draft) && !empty($draft['poll_hide_totals']);
    for ($i = 1; $i <= 4; $i++) {
        $req  = $i <= 2 ? ' required' : '';
        $dval = h($draft_poll_opts[$i - 1] ?? '');
        echo '<label for="poll_opt_' . $i . '">' . t('compose_poll_option', $i) . ($i > 2 ? ' ' . t('compose_optional') : '') . '</label><br>' . "\n";
        echo '<input type="text" id="poll_opt_' . $i . '" name="poll_options[]" maxlength="50" value="' . $dval . '"' . $req . '><br>' . "\n";
    }
    echo '<br>' . "\n";
    echo '<span class="inline-form">' . "\n";
    echo '<label for="poll_expires">' . t('compose_poll_duration') . '</label> ' . "\n";
    echo '<select id="poll_expires" name="poll_expires_in">' . "\n";
    foreach ([
        '300'    => t('duration_5min'),
        '1800'   => t('duration_30min'),
        '3600'   => t('duration_1h'),
        '21600'  => t('duration_6h'),
        '43200'  => t('duration_12h'),
        '86400'  => t('duration_1d'),
        '172800' => t('duration_2d'),
        '259200' => t('duration_3d'),
        '604800' => t('duration_7d'),
    ] as $val => $label) {
        $sel = ($val === $draft_poll_expires) ? ' selected' : '';
        echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>' . "\n";
    }
    echo '</select>' . "\n";
    echo ' &nbsp; ' . "\n";
    echo '<label><input type="checkbox" name="poll_multiple" value="1"' . ($draft_poll_multi ? ' checked' : '') . '> ' . t('compose_poll_multiple') . '</label>' . "\n";
    echo ' &nbsp; ' . "\n";
    echo '<label><input type="checkbox" name="poll_hide_totals" value="1"' . ($draft_poll_hide ? ' checked' : '') . '> ' . t('compose_poll_hide') . '</label>' . "\n";
    echo '</span>' . "\n";
    echo '</fieldset>' . "\n";
    echo '<button type="submit">' . t('compose_post_poll_btn') . '</button>' . "\n";
    echo '</div>' . "\n";
} else {
    echo '<div id="media">' . "\n";
    if ($is_edit) {
        $attachments = $edit_status['media_attachments'] ?? [];
        if (!empty($attachments)) {
            echo '<br><br>' . "\n";
            echo '<fieldset>' . "\n";
            echo '<legend>' . t('compose_media_legend') . '</legend>' . "\n";
            foreach ($attachments as $media) {
                $mid      = preg_replace('/[^A-Za-z0-9]/', '', $media['id'] ?? '');
                $mtype    = $media['type'] ?? '';
                $url      = $media['url'] ?? ($media['preview_url'] ?? '');
                $alt      = $media['description'] ?? '';
                if (!$mid) continue;
                echo '<fieldset>' . "\n";
                echo '<input type="hidden" name="all_media_ids[]" value="' . h($mid) . '">' . "\n";
                if ($url && $mtype === 'image') {
                    echo '<img src="' . h($url) . '" alt="' . h($alt) . '" style="max-width:120px"><br>' . "\n";
                }
                echo '<label for="alt_' . h($mid) . '">' . t('compose_alt_label') . '</label><br>' . "\n";
                echo '<textarea id="alt_' . h($mid) . '" name="media_alt[' . h($mid) . ']" rows="2" cols="60" maxlength="1500">' . h($alt) . '</textarea><br>' . "\n";
                echo '<label><input type="checkbox" name="remove_media[]" value="' . h($mid) . '"> ' . t('compose_media_remove') . '</label>' . "\n";
                echo '</fieldset>' . "\n";
            }
            echo '</fieldset>' . "\n";
        }
    } else {
        echo '<br><br>' . "\n";
        echo '<fieldset>' . "\n";
        echo '<legend>' . t('compose_media_legend_new', $max_media) . '</legend>' . "\n";
        $fmt_bytes = fn(int $b): string => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024) . ' KB';
        $php_file  = ini_get('upload_max_filesize');
        $php_post  = ini_get('post_max_size');
        $php_files = (int) ini_get('max_file_uploads');
        $inst_host = parse_url($ctx['instance'], PHP_URL_HOST) ?? $ctx['instance'];
        $inst_img  = $config['image_size_limit'] !== null ? $fmt_bytes($config['image_size_limit']) : null;
        $inst_vid  = $config['video_size_limit']  !== null ? $fmt_bytes($config['video_size_limit'])  : null;
        $smol_host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        echo '<p><em>' . h($smol_host) . ' ' . t('compose_limits_smol', $php_files, $php_file, $php_post) . '</em></p>' . "\n";
        $inst_parts = [tn('compose_attach_n', $max_media, $max_media)];
        if ($inst_img !== null) $inst_parts[] = t('compose_img_limit', $inst_img);
        if ($inst_vid !== null) $inst_parts[] = t('compose_vid_limit', $inst_vid);
        echo '<p><em>' . h($inst_host) . ' ' . t('compose_limits_inst', implode(', ', $inst_parts)) . '</em></p>' . "\n";
        $draft_media = is_array($draft) ? ($draft['media'] ?? []) : [];
        if (!empty($draft_media)) {
            echo '<fieldset>' . "\n";
            echo '<legend>' . t('compose_already_uploaded') . '</legend>' . "\n";
            foreach ($draft_media as $dm) {
                $mid   = preg_replace('/[^A-Za-z0-9]/', '', $dm['id'] ?? '');
                if (!$mid) continue;
                $purl  = $dm['preview_url'] ?? '';
                $mtype = $dm['type'] ?? 'image';
                $alt   = $dm['alt'] ?? '';
                echo '<fieldset>' . "\n";
                echo '<input type="hidden" name="draft_media_ids[]" value="' . h($mid) . '">' . "\n";
                if ($purl !== '' && in_array($mtype, ['image', 'gifv'], true)) {
                    echo '<img src="' . h($purl) . '" alt="' . h($alt) . '" class="media-thumb">' . "\n";
                } elseif ($purl !== '') {
                    echo '<p>' . h(ucfirst($mtype)) . '</p>' . "\n";
                }
                if ($alt !== '') {
                    echo '<p><small>' . t('compose_alt_prefix') . ' ' . h($alt) . '</small></p>' . "\n";
                }
                echo '</fieldset>' . "\n";
            }
            echo '</fieldset>' . "\n";
        }
        for ($i = 1; $i <= $max_media; $i++) {
            echo '<fieldset>' . "\n";
            echo '<legend>' . t('compose_file_n', $i) . '</legend>' . "\n";
            echo '<input type="file" id="media_' . $i . '" name="media[]" accept="image/*,video/*,audio/*">' . "\n";
            echo '<br>' . "\n";
            echo '<label for="alt_' . $i . '">' . t('compose_alt_label') . '</label>' . "\n";
            echo '<br>' . "\n";
            echo '<textarea id="alt_' . $i . '" name="media_alt[]" rows="2" cols="60" maxlength="1500"></textarea>' . "\n";
            echo '</fieldset>' . "\n";
        }
        echo '</fieldset>' . "\n";
        echo '<button type="submit">' . t('compose_post_btn') . '</button>' . "\n";
    }
    echo '</div>' . "\n";
}

echo '</form>' . "\n";
html_foot();
