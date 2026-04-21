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
            html_error('Could not resolve this post on your instance.');
            html_foot();
            exit;
        }
    }
}

if (!$id) {
    html_head('Error');
    html_error('No post ID provided.');
    html_foot();
    exit;
}

$status        = api_get($instance, $token, '/api/v1/statuses/' . $id);
$context       = api_get($instance, $token, '/api/v1/statuses/' . $id . '/context');
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

html_head('Post');

// Ancestors (thread above)
$ancestors = $context['ancestors'] ?? [];
if (!empty($ancestors)) {
    echo '<section>' . "\n";
    foreach ($ancestors as $ancestor) {
        html_post($ancestor);
    }
    echo '</section>' . "\n";
    echo '<hr>' . "\n";
}

// The post itself
html_post($status, true, $history);

$use_static = pref('avatar_static', false);

// Favourited by
if (!empty($favourited_by) && !isset($favourited_by['error'])) {
    echo '<aside>' . "\n";
    echo '<strong>&#9733; Favourited by</strong>' . "\n";
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
    echo '<strong>&#8635; Boosted by</strong>' . "\n";
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
            echo '<strong>Translation (' . h($src_lang) . ' &#8594; ' . h($tgt_lang) . ')</strong>' . "\n";
            if (!empty($translation['spoiler_text'])) {
                echo '<p><em>CW: ' . h($translation['spoiler_text']) . '</em></p>' . "\n";
            }
            echo '<div>' . sanitize_post_html($translation['content']) . '</div>' . "\n";
            if (!empty($translation['provider'])) {
                echo '<small>via ' . h($translation['provider']) . '</small>' . "\n";
            }
            echo '</section>' . "\n";
        } else {
            echo '<p>Translation failed: ' . h($translation['error'] ?? 'unknown error') . '</p>' . "\n";
        }
    } else {
        echo '<form method="get" action="post.php">' . "\n";
        echo '<input type="hidden" name="id" value="' . h($id) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="translate" value="1">' . "\n";
        echo '<span class="inline-form">' . "\n";
        echo '<label for="trans_lang">Translate to</label> ' . "\n";
        echo '<select id="trans_lang" name="trans_lang">' . "\n";
        echo '<option value="">(auto)</option>' . "\n";
        $post_lang = $status['language'] ?? '';
        foreach ($languages as $code) {
            if ($code === $post_lang) continue;
            $sel = ($code === $default_lang) ? ' selected' : '';
            echo '<option value="' . $code . '"' . $sel . '>' . $code . '</option>' . "\n";
        }
        echo '</select> ' . "\n";
        echo '<button type="submit">Translate</button>' . "\n";
        echo '</span>' . "\n";
        echo '</form>' . "\n";
    }
}

// Reply form
echo '<hr>' . "\n";
echo '<section id="reply">' . "\n";
echo '<h2>Write a reply</h2>' . "\n";
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
echo '<textarea name="content" rows="4" cols="60" required placeholder="' . h($reply_prefix) . '">';
echo h($reply_prefix);
echo '</textarea>' . "\n";
echo '<br>' . "\n";
echo '<span class="inline-form">' . "\n";
$mime_types = $config['supported_mime_types'] ?? ['text/plain'];
if (count($mime_types) > 1) {
    $default_mime = $_SESSION['accounts'][$a]['default_content_type'] ?? 'text/plain';
    echo '<label for="content_type_reply">Format</label> ' . "\n";
    echo '<select id="content_type_reply" name="content_type">' . "\n";
    foreach ($mime_types as $mime) {
        $sel = ($mime === $default_mime) ? ' selected' : '';
        echo '<option value="' . h($mime) . '"' . $sel . '>' . h(substr($mime, 5)) . '</option>' . "\n";
    }
    echo '</select>' . "\n";
    echo ' &nbsp; ' . "\n";
}
echo '<label for="lang_reply">Language</label> ' . "\n";
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
echo '<label for="visibility_reply">Visibility</label> ' . "\n";
echo '<select id="visibility_reply" name="visibility">' . "\n";
foreach (['public' => 'Public', 'unlisted' => 'Unlisted', 'private' => 'Followers only', 'direct' => 'Direct'] as $val => $label) {
    $sel = ($val === $default_visibility) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>' . "\n";
}
echo '</select>' . "\n";
echo '</span>' . "\n";
echo '<br>' . "\n";
echo '<details>' . "\n";
echo '<summary>Add a media</summary>' . "\n";
echo '<fieldset>' . "\n";
echo '<legend>Media</legend>' . "\n";
echo '<label for="media_reply">File</label><br>' . "\n";
echo '<input type="file" id="media_reply" name="media_reply"><br>' . "\n";
echo '<label for="alt_reply">Alt text</label><br>' . "\n";
echo '<textarea id="alt_reply" name="media_alt_reply" rows="2" cols="60" maxlength="1500"></textarea><br>' . "\n";
echo '<label><input type="checkbox" name="sensitive" value="1"> Sensitive media</label>' . "\n";
echo '</fieldset>' . "\n";
echo '</details>' . "\n";
echo '<br>' . "\n";
echo '<button type="submit">Reply</button>' . "\n";
echo '</form>' . "\n";
echo '</section>' . "\n";

// Descendants (replies below)
$descendants = $context['descendants'] ?? [];
if (!empty($descendants)) {
    echo '<hr>' . "\n";
    echo '<section>' . "\n";
    echo '<h2>Replies</h2>' . "\n";
    foreach ($descendants as $reply) {
        html_post($reply);
    }
    echo '</section>' . "\n";
}

html_foot();
