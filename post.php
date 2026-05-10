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
$config   = get_instance_config($instance, $token, $a);

$creds       = api_get($instance, $token, '/api/v1/accounts/verify_credentials');
$default_lang = $creds['source']['language'] ?? '';

$languages = ['af','ar','az','be','bg','bn','bs','ca','cs','cy','da','de','el','en','eo',
              'es','et','eu','fa','fi','fr','ga','gl','gu','he','hi','hr','hu','hy','id',
              'is','it','ja','ka','kk','km','kn','ko','ku','ky','lb','lt','lv','mk','ml',
              'mn','mr','ms','my','nl','nn','no','oc','pa','pl','ps','pt','ro','ru','sk',
              'sl','sq','sr','sv','sw','ta','te','th','tl','tr','uk','ur','uz','vi','zh'];

$id = preg_replace('/[^A-Za-z0-9]/', '', $_GET['id'] ?? '');

// Resolve a remote URL via the search API if no local id is known
if (!$id && !empty($_GET['url'])) {
    $remote_url = filter_var($_GET['url'], FILTER_VALIDATE_URL);
    if ($remote_url) {
        $search = api_get($instance, $token, '/api/v2/search', [
            'q'       => $remote_url,
            'resolve' => 'true',
            'limit'   => 1,
        ]);
        $id = $search['statuses'][0]['id'] ?? '';
        if (!$id) {
            html_head('Error');
            html_error(t('post_err_resolve'));
            html_foot();
            exit;
        }
    }
}

if (!$id) {
    html_head('Error');
    html_error(t('post_err_no_id'));
    html_foot();
    exit;
}

$status        = api_get($instance, $token, '/api/v1/statuses/' . $id);
$context       = api_get($instance, $token, '/api/v1/statuses/' . $id . '/context');
$custom_emojis = api_get($instance, $token, '/api/v1/custom_emojis');
$favourited_by = api_get($instance, $token, '/api/v1/statuses/' . $id . '/favourited_by', ['limit' => 40]);
$reblogged_by  = api_get($instance, $token, '/api/v1/statuses/' . $id . '/reblogged_by', ['limit' => 40]);
$history = !empty($status['edited_at'])
    ? api_get($instance, $token, '/api/v1/statuses/' . $id . '/history')
    : [];

if (isset($status['error'])) {
    html_head('Error');
    html_error($status['error']);
    html_foot();
    exit;
}

$csrf = csrf_token();

// Build a unified depth map for the whole thread
$ancestors   = $context['ancestors'] ?? [];
$descendants = $context['descendants'] ?? [];
$depth_map   = [];
foreach ($ancestors as $s) {
    $rid = $s['id'] ?? '';
    $pid = $s['in_reply_to_id'] ?? '';
    $depth_map[$rid] = isset($depth_map[$pid]) ? min(4, $depth_map[$pid] + 1) : 0;
}
$focal_depth    = min(4, ($depth_map[$status['in_reply_to_id'] ?? ''] ?? -1) + 1);
$depth_map[$id] = $focal_depth;
foreach ($descendants as $s) {
    $rid = $s['id'] ?? '';
    $pid = $s['in_reply_to_id'] ?? '';
    $depth_map[$rid] = min(4, ($depth_map[$pid] ?? $focal_depth) + 1);
}

$post_url    = $status['url'] ?? $status['uri'] ?? '';
$switch_back = $post_url ? 'search.php?q=' . rawurlencode($post_url) : '';
html_head(t('post_title'), $switch_back);

// Ancestors (thread above)
$ancestors = $context['ancestors'] ?? [];
if (!empty($ancestors)) {
    echo '<section>' . "\n";
    foreach ($ancestors as $ancestor) {
        $aid = $ancestor['id'] ?? '';
        html_post($ancestor, false, [], false, $depth_map[$aid] ?? 0);
    }
    echo '</section>' . "\n";
    echo '<hr>' . "\n";
}

// The post itself
html_post($status, true, $history, false, $focal_depth, true);

$use_static = pref('avatar_static', false);

// Favourited by
if (!empty($favourited_by) && !isset($favourited_by['error'])) {
    echo '<aside>' . "\n";
    echo '<strong>' . t('post_favourited_by') . '</strong>' . "\n";
    echo '<div class="avatar-list">' . "\n";
    foreach ($favourited_by as $acc) {
        $name    = $acc['display_name'] ?: ($acc['username'] ?? '');
        $address = $acc['acct'] ?? '';
        $title   = $name . ($address ? ' <@' . $address . '>' : '');
        $src     = $use_static ? ($acc['avatar_static'] ?? $acc['avatar'] ?? '') : ($acc['avatar'] ?? '');
        echo '<a href="' . url('profile.php', ['id' => $acc['id']]) . '">';
        echo '<img src="' . h($src) . '" alt="' . h($name) . '" title="' . h($title) . '" width="32" height="32">';
        echo '</a>' . "\n";
    }
    echo '</div>' . "\n";
    echo '</aside>' . "\n";
}

// Reblogged by
if (!empty($reblogged_by) && !isset($reblogged_by['error'])) {
    echo '<aside>' . "\n";
    echo '<strong>' . t('post_boosted_by_section') . '</strong>' . "\n";
    echo '<div class="avatar-list">' . "\n";
    foreach ($reblogged_by as $acc) {
        $name    = $acc['display_name'] ?: ($acc['username'] ?? '');
        $address = $acc['acct'] ?? '';
        $title   = $name . ($address ? ' <@' . $address . '>' : '');
        $src     = $use_static ? ($acc['avatar_static'] ?? $acc['avatar'] ?? '') : ($acc['avatar'] ?? '');
        echo '<a href="' . url('profile.php', ['id' => $acc['id']]) . '">';
        echo '<img src="' . h($src) . '" alt="' . h($name) . '" title="' . h($title) . '" width="32" height="32">';
        echo '</a>' . "\n";
    }
    echo '</div>' . "\n";
    echo '</aside>' . "\n";
}

// Translation
if (!empty($config['translation_enabled'])) {
    $do_translate = ($_GET['translate'] ?? '') === '1';
    $trans_lang   = preg_replace('/[^a-zA-Z]/', '', $_GET['trans_lang'] ?? '');
    echo '<hr>' . "\n";
    if ($do_translate) {
        $params      = $trans_lang !== '' ? ['lang' => $trans_lang] : [];
        $translation = api_post($instance, $token, '/api/v1/statuses/' . $id . '/translate', $params);
        if (!isset($translation['error']) && isset($translation['content'])) {
            $src_lang = $translation['detected_source_language'] ?? '?';
            $tgt_lang = $trans_lang ?: 'auto';
            echo '<section>' . "\n";
            echo '<strong>' . t('post_translation_title', h($src_lang), h($tgt_lang)) . '</strong>' . "\n";
            if (!empty($translation['spoiler_text'])) {
                echo '<p><em>' . t('post_cw_label') . ' ' . h($translation['spoiler_text']) . '</em></p>' . "\n";
            }
            echo '<div>' . sanitize_post_html($translation['content']) . '</div>' . "\n";
            if (!empty($translation['provider'])) {
                echo '<small>' . t('post_translation_via', h($translation['provider'])) . '</small>' . "\n";
            }
            echo '</section>' . "\n";
        } else {
            echo '<p>' . t('post_translation_failed', h($translation['error'] ?? 'unknown error')) . '</p>' . "\n";
        }
    } else {
        echo '<form method="get" action="post.php">' . "\n";
        echo '<input type="hidden" name="id" value="' . h($id) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="translate" value="1">' . "\n";
        echo '<span class="inline-form">' . "\n";
        echo '<label for="trans_lang">' . t('post_translate_to') . '</label> ' . "\n";
        echo '<select id="trans_lang" name="trans_lang">' . "\n";
        echo '<option value="">' . t('post_translate_auto') . '</option>' . "\n";
        $post_lang = $status['language'] ?? '';
        foreach ($languages as $code) {
            if ($code === $post_lang) continue;
            $sel = ($code === $default_lang) ? ' selected' : '';
            echo '<option value="' . $code . '"' . $sel . '>' . $code . '</option>' . "\n";
        }
        echo '</select> ' . "\n";
        echo '<button type="submit">' . t('post_translate_btn') . '</button>' . "\n";
        echo '</span>' . "\n";
        echo '</form>' . "\n";
    }
}

// Reply form
echo '<hr>' . "\n";
echo '<section id="reply">' . "\n";
echo '<h2>' . t('post_reply_title') . '</h2>' . "\n";
echo '<form method="post" action="action.php" enctype="multipart/form-data">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
echo '<input type="hidden" name="action" value="reply">' . "\n";
echo '<input type="hidden" name="id" value="' . h($id) . '">' . "\n";
$my_acct     = $creds['acct'] ?? '';
$mention_set = [];
$author_acct = $status['account']['acct'] ?? '';
if ($author_acct !== '' && $author_acct !== $my_acct) {
    $mention_set[$author_acct] = true;
}
foreach ($status['mentions'] ?? [] as $m) {
    $macct = $m['acct'] ?? '';
    if ($macct !== '' && $macct !== $my_acct) {
        $mention_set[$macct] = true;
    }
}
$reply_prefix = implode(' ', array_map(fn($acct) => '@' . $acct, array_keys($mention_set)));
if ($reply_prefix !== '') {
    $reply_prefix .= ' ';
}
$reply_spoiler = trim($status['spoiler_text'] ?? '');
echo '<label for="spoiler_reply">' . t('compose_cw_label') . '</label><br>' . "\n";
echo '<input type="text" id="spoiler_reply" name="spoiler_text" maxlength="100" value="' . h($reply_spoiler) . '"><br>' . "\n";
echo '<label for="content_reply">' . t('compose_content_label') . '</label><br>' . "\n";
echo '<textarea id="content_reply" name="content" rows="4" cols="60" required placeholder="' . h($reply_prefix) . '">';
echo h($reply_prefix);
echo '</textarea>' . "\n";
html_emoji_picker(is_array($custom_emojis) ? $custom_emojis : []);
echo '<br>' . "\n";
echo '<span class="inline-form">' . "\n";
$mime_types = $config['supported_mime_types'] ?? ['text/plain'];
if (count($mime_types) > 1) {
    $default_mime = $_SESSION['accounts'][$a]['default_content_type'] ?? 'text/plain';
    echo '<label for="content_type_reply">' . t('compose_format_label') . '</label> ' . "\n";
    echo '<select id="content_type_reply" name="content_type">' . "\n";
    foreach ($mime_types as $mime) {
        $sel = ($mime === $default_mime) ? ' selected' : '';
        echo '<option value="' . h($mime) . '"' . $sel . '>' . h(substr($mime, 5)) . '</option>' . "\n";
    }
    echo '</select>' . "\n";
    echo ' &nbsp; ' . "\n";
}
echo '<label for="lang_reply">' . t('compose_lang_label') . '</label> ' . "\n";
echo '<select id="lang_reply" name="lang">' . "\n";
echo '<option value=""></option>' . "\n";
foreach ($languages as $code) {
    $sel = ($code === $default_lang) ? ' selected' : '';
    echo '<option value="' . $code . '"' . $sel . '>' . $code . '</option>' . "\n";
}
echo '</select>' . "\n";
echo ' &nbsp; ' . "\n";
$vis_rank           = ['public' => 3, 'unlisted' => 2, 'private' => 1, 'direct' => 0];
$user_visibility    = $creds['source']['privacy'] ?? 'public';
$post_visibility    = $status['visibility'] ?? 'public';
$default_visibility = ($vis_rank[$post_visibility] ?? 3) < ($vis_rank[$user_visibility] ?? 3)
    ? $post_visibility
    : $user_visibility;
echo '<label for="visibility_reply">' . t('compose_visibility_label') . '</label> ' . "\n";
echo '<select id="visibility_reply" name="visibility">' . "\n";
foreach (['public' => t('vis_public'), 'unlisted' => t('vis_unlisted'), 'private' => t('vis_followers_only'), 'direct' => t('vis_direct')] as $val => $label) {
    $sel = ($val === $default_visibility) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>' . "\n";
}
echo '</select>' . "\n";
echo '</span>' . "\n";
echo '<br>' . "\n";
echo '<details>' . "\n";
echo '<summary>' . t('post_add_media') . '</summary>' . "\n";
echo '<fieldset>' . "\n";
echo '<legend>' . t('post_media_legend') . '</legend>' . "\n";
if (defined('MAX_IMAGE_WIDTH') && MAX_IMAGE_WIDTH > 0 && extension_loaded('gd')) {
    echo '<label><input type="checkbox" name="reduce_images" value="1" autocomplete="off"> ' . t('compose_reduce_images', MAX_IMAGE_WIDTH) . '</label><br>' . "\n";
}
echo '<label for="media_reply">' . t('post_media_file') . '</label><br>' . "\n";
echo '<input type="file" id="media_reply" name="media_reply"><br>' . "\n";
echo '<label for="alt_reply">' . t('compose_alt_label') . '</label><br>' . "\n";
echo '<textarea id="alt_reply" name="media_alt_reply" rows="2" cols="60" maxlength="1500"></textarea><br>' . "\n";
echo '<label><input type="checkbox" name="sensitive" value="1"> ' . t('compose_sensitive_label') . '</label>' . "\n";
echo '</fieldset>' . "\n";
echo '</details>' . "\n";
echo '<br>' . "\n";
echo '<button type="submit">' . t('action_reply_btn') . '</button>' . "\n";
echo '</form>' . "\n";
echo '</section>' . "\n";

// Descendants (replies below)
if (!empty($descendants)) {
    echo '<hr>' . "\n";
    echo '<section>' . "\n";
    echo '<h2>' . t('post_replies_title') . '</h2>' . "\n";
    foreach ($descendants as $reply) {
        $rid = $reply['id'] ?? '';
        html_post($reply, false, [], false, $depth_map[$rid] ?? min(4, $focal_depth + 1));
    }
    echo '</section>' . "\n";
}

html_foot();
