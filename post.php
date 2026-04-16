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
echo '<section>' . "\n";

// Ancestors (thread above)
$ancestors = $context['ancestors'] ?? [];
if (!empty($ancestors)) {
    echo '<div>' . "\n";
    foreach ($ancestors as $ancestor) {
        html_post($ancestor);
    }
    echo '</div>' . "\n";
    echo '<hr>' . "\n";
}

// The post itself
html_post($status, true, $history);

// Favourited by
if (!empty($favourited_by) && !isset($favourited_by['error'])) {
    echo '<div>' . "\n";
    echo '<strong>&#9733; Favourited by</strong>' . "\n";
    echo '<div class="avatar-list">' . "\n";
    foreach ($favourited_by as $acct) {
        $name = $acct['display_name'] ?: ($acct['username'] ?? '');
        echo '<a href="' . url('profile.php', ['id' => $acct['id']]) . '" title="' . h($name) . '">';
        echo '<img src="' . h($acct['avatar']) . '" alt="' . h($name) . '" width="32" height="32">';
        echo '</a>' . "\n";
    }
    echo '</div>' . "\n";
    echo '</div>' . "\n";
}

// Reblogged by
if (!empty($reblogged_by) && !isset($reblogged_by['error'])) {
    echo '<div>' . "\n";
    echo '<strong>&#8635; Boosted by</strong>' . "\n";
    echo '<div class="avatar-list">' . "\n";
    foreach ($reblogged_by as $acct) {
        $name = $acct['display_name'] ?: ($acct['username'] ?? '');
        echo '<a href="' . url('profile.php', ['id' => $acct['id']]) . '" title="' . h($name) . '">';
        echo '<img src="' . h($acct['avatar']) . '" alt="' . h($name) . '" width="32" height="32">';
        echo '</a>' . "\n";
    }
    echo '</div>' . "\n";
    echo '</div>' . "\n";
}

// Reply form
echo '<hr>' . "\n";
echo '<div id="reply">' . "\n";
echo '<h2>Write a reply</h2>' . "\n";
echo '<form method="post" action="action.php">' . "\n";
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
echo '<button type="submit">Reply</button>' . "\n";
echo '</form>' . "\n";
echo '</div>' . "\n";

// Descendants (replies below)
$descendants = $context['descendants'] ?? [];
if (!empty($descendants)) {
    echo '<hr>' . "\n";
    echo '<div>' . "\n";
    foreach ($descendants as $reply) {
        html_post($reply);
    }
    echo '</div>' . "\n";
}

echo '</section>' . "\n";
html_foot();
