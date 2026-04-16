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

$creds = api_get($ctx['instance'], $ctx['access_token'], '/api/v1/accounts/verify_credentials');
if (isset($creds['error'])) {
    html_head('Error');
    html_error('Session expired or token invalid. Please log in again.');
    echo '<p><a href="login.php">Log in</a></p>';
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
        html_error('Could not load post for editing.');
        html_foot();
        exit;
    }
}

$is_edit  = $edit_status !== null;
$type     = (!$is_edit && ($_GET['type'] ?? '') === 'poll') ? 'poll' : 'post';
$page_title = $is_edit ? 'Edit post' : ($type === 'poll' ? 'New poll' : 'New post');

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
$sensitive_checked  = $prefill_sensitive ? ' checked' : '';

html_head($page_title);
echo '<h1>' . h($page_title) . '</h1>' . "\n";
$use_multipart = !$is_edit && $type !== 'poll';
echo '<form method="post" action="action.php"' . ($use_multipart ? ' enctype="multipart/form-data"' : '') . '>' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
echo '<input type="hidden" name="action" value="' . ($is_edit ? 'edit_save' : 'post') . '">' . "\n";
if ($is_edit) {
    echo '<input type="hidden" name="id" value="' . h($edit_id) . '">' . "\n";
}
echo '<fieldset>' . "\n";
echo '<legend>Compose</legend>' . "\n";
echo '<label for="spoiler">Content warning (optional)</label>' . "\n";
echo '<br>' . "\n";
echo '<input type="text" id="spoiler" name="spoiler_text" maxlength="100" value="' . h($prefill_spoiler) . '">' . "\n";
echo '<br>' . "\n";
echo '<label for="content">Content</label>' . "\n";
echo '<br>' . "\n";
echo '<textarea id="content" name="content" rows="8" cols="60" required maxlength="' . $max_chars . '">' . h($prefill_content) . '</textarea>' . "\n";
echo '<p><small>Max ' . $max_chars . ' characters</small></p>' . "\n";
echo '<br>' . "\n";
echo '<span class="inline-form">' . "\n";
$mime_types = $config['supported_mime_types'] ?? ['text/plain'];
if (count($mime_types) > 1) {
    echo '<label for="content_type">Format</label> ' . "\n";
    echo '<select id="content_type" name="content_type">' . "\n";
    foreach ($mime_types as $mime) {
        $sel = ($mime === $prefill_mime) ? ' selected' : '';
        echo '<option value="' . h($mime) . '"' . $sel . '>' . h(substr($mime, 5)) . '</option>' . "\n";
    }
    echo '</select>' . "\n";
    echo ' &nbsp; ' . "\n";
}
echo '<label for="lang">Language</label> ' . "\n";
echo '<select id="lang" name="lang">' . "\n";
echo '<option value=""></option>' . "\n";
foreach ($languages as $code) {
    $sel = ($code === $prefill_lang) ? ' selected' : '';
    echo '<option value="' . $code . '"' . $sel . '>' . $code . '</option>' . "\n";
}
echo '</select>' . "\n";
echo ' &nbsp; ' . "\n";
echo '<label for="visibility">Visibility</label> ' . "\n";
echo '<select id="visibility" name="visibility"' . ($is_edit ? ' disabled' : '') . '>' . "\n";
foreach (['public' => 'Public (P)', 'unlisted' => 'Unlisted (U)', 'private' => 'Followers only (F)', 'direct' => 'Direct message (@)'] as $val => $label) {
    $sel = ($val === $prefill_visibility) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>' . "\n";
}
echo '</select>' . "\n";
echo ' &nbsp; ';
echo '<label><input type="checkbox" name="sensitive" value="1"' . $sensitive_checked . '> Sensitive media</label>' . "\n";
echo '</span>' . "\n";
echo '</fieldset>' . "\n";
echo '<button type="submit">' . ($is_edit ? 'Save' : 'Post') . '</button>' . "\n";

if ($type === 'poll') {
    echo '<div id="poll">' . "\n";
    echo '<br><br>' . "\n";
    echo '<fieldset>' . "\n";
    echo '<legend>Poll options</legend>' . "\n";
    for ($i = 1; $i <= 4; $i++) {
        $req = $i <= 2 ? ' required' : '';
        echo '<label for="poll_opt_' . $i . '">Option ' . $i . ($i > 2 ? ' (optional)' : '') . '</label><br>' . "\n";
        echo '<input type="text" id="poll_opt_' . $i . '" name="poll_options[]" maxlength="50"' . $req . '><br>' . "\n";
    }
    echo '<br>' . "\n";
    echo '<span class="inline-form">' . "\n";
    echo '<label for="poll_expires">Duration</label> ' . "\n";
    echo '<select id="poll_expires" name="poll_expires_in">' . "\n";
    foreach ([
        '300'    => '5 minutes',
        '1800'   => '30 minutes',
        '3600'   => '1 hour',
        '21600'  => '6 hours',
        '43200'  => '12 hours',
        '86400'  => '1 day',
        '172800' => '2 days',
        '259200' => '3 days',
        '604800' => '7 days',
    ] as $val => $label) {
        $sel = ($val === '86400') ? ' selected' : '';
        echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>' . "\n";
    }
    echo '</select>' . "\n";
    echo ' &nbsp; ' . "\n";
    echo '<label><input type="checkbox" name="poll_multiple" value="1"> Multiple choices</label>' . "\n";
    echo ' &nbsp; ' . "\n";
    echo '<label><input type="checkbox" name="poll_hide_totals" value="1"> Hide totals until end</label>' . "\n";
    echo '</span>' . "\n";
    echo '</fieldset>' . "\n";
    echo '<button type="submit">Post poll</button>' . "\n";
    echo '</div>' . "\n";
} else {
    echo '<div id="media">' . "\n";
    if ($is_edit) {
        $attachments = $edit_status['media_attachments'] ?? [];
        if (!empty($attachments)) {
            echo '<br><br>' . "\n";
            echo '<fieldset>' . "\n";
            echo '<legend>Media attachments</legend>' . "\n";
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
                echo '<label for="alt_' . h($mid) . '">Alt text</label><br>' . "\n";
                echo '<textarea id="alt_' . h($mid) . '" name="media_alt[' . h($mid) . ']" rows="2" cols="60" maxlength="1500">' . h($alt) . '</textarea><br>' . "\n";
                echo '<label><input type="checkbox" name="remove_media[]" value="' . h($mid) . '"> Remove</label>' . "\n";
                echo '</fieldset>' . "\n";
            }
            echo '</fieldset>' . "\n";
        }
    } else {
        echo '<br><br>' . "\n";
        echo '<fieldset>' . "\n";
        echo '<legend>Media attachments (up to ' . $max_media . ')</legend>' . "\n";
        $fmt_bytes = fn(int $b): string => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024) . ' KB';
        $php_file  = ini_get('upload_max_filesize');
        $php_post  = ini_get('post_max_size');
        $php_files = (int) ini_get('max_file_uploads');
        $inst_host = parse_url($ctx['instance'], PHP_URL_HOST) ?? $ctx['instance'];
        $inst_img  = $config['image_size_limit'] !== null ? $fmt_bytes($config['image_size_limit']) : null;
        $inst_vid  = $config['video_size_limit']  !== null ? $fmt_bytes($config['video_size_limit'])  : null;
        $smol_host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        echo '<p><em>' . h($smol_host) . ' limits: ' . $php_files . ' files max, ' . h($php_file) . ' per file, ' . h($php_post) . ' total</em></p>' . "\n";
        $inst_parts = ['up to ' . $max_media . ' attachment' . ($max_media !== 1 ? 's' : '')];
        if ($inst_img !== null) $inst_parts[] = 'images up to ' . $inst_img;
        if ($inst_vid !== null) $inst_parts[] = 'videos up to ' . $inst_vid;
        echo '<p><em>' . h($inst_host) . ' limits: ' . implode(', ', $inst_parts) . '</em></p>' . "\n";
        for ($i = 1; $i <= $max_media; $i++) {
            echo '<fieldset>' . "\n";
            echo '<legend>File ' . $i . '</legend>' . "\n";
            echo '<input type="file" id="media_' . $i . '" name="media[]" accept="image/*,video/*,audio/*">' . "\n";
            echo '<br>' . "\n";
            echo '<label for="alt_' . $i . '">Alt text</label>' . "\n";
            echo '<br>' . "\n";
            echo '<textarea id="alt_' . $i . '" name="media_alt[]" rows="2" cols="60" maxlength="1500"></textarea>' . "\n";
            echo '</fieldset>' . "\n";
        }
        echo '</fieldset>' . "\n";
        echo '<button type="submit">Post</button>' . "\n";
    }
    echo '</div>' . "\n";
}

echo '</form>' . "\n";
html_foot();
