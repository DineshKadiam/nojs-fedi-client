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

$languages = ['af','ar','az','be','bg','bn','bs','ca','cs','cy','da','de','el','en','eo',
              'es','et','eu','fa','fi','fr','ga','gl','gu','he','hi','hr','hu','hy','id',
              'is','it','ja','ka','kk','km','kn','ko','ku','ky','lb','lt','lv','mk','ml',
              'mn','mr','ms','my','nl','nn','no','oc','pa','pl','ps','pt','ro','ru','sk',
              'sl','sq','sr','sv','sw','ta','te','th','tl','tr','uk','ur','uz','vi','zh'];

html_head('New post');
echo '<section>' . "\n";
echo '<h1>New post</h1>' . "\n";
echo '<form method="post" action="action.php" enctype="multipart/form-data">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
echo '<input type="hidden" name="action" value="post">' . "\n";
echo '<fieldset>' . "\n";
echo '<legend>Compose</legend>' . "\n";
echo '<label for="spoiler">Content warning (optional)</label>' . "\n";
echo '<br>' . "\n";
echo '<input type="text" id="spoiler" name="spoiler_text" maxlength="100">' . "\n";
echo '<br>' . "\n";
echo '<label for="content">Content</label>' . "\n";
echo '<br>' . "\n";
echo '<textarea id="content" name="content" rows="8" cols="60" required maxlength="' . $max_chars . '"></textarea>' . "\n";
echo '<p><small>Max ' . $max_chars . ' characters</small></p>' . "\n";
echo '<br>' . "\n";
echo '<span class="inline-form">' . "\n";
$mime_types = $config['supported_mime_types'] ?? ['text/plain'];
if (count($mime_types) > 1) {
    $default_mime = $_SESSION['accounts'][$a]['default_content_type'] ?? 'text/plain';
    echo '<label for="content_type">Format</label> ' . "\n";
    echo '<select id="content_type" name="content_type">' . "\n";
    foreach ($mime_types as $mime) {
        $sel = ($mime === $default_mime) ? ' selected' : '';
        echo '<option value="' . h($mime) . '"' . $sel . '>' . h(substr($mime, 5)) . '</option>' . "\n";
    }
    echo '</select>' . "\n";
    echo ' &nbsp; ' . "\n";
}
echo '<label for="lang">Language</label> ' . "\n";
echo '<select id="lang" name="lang">' . "\n";
echo '<option value=""></option>' . "\n";
foreach ($languages as $code) {
    $sel = ($code === $default_lang) ? ' selected' : '';
    echo '<option value="' . $code . '"' . $sel . '>' . $code . '</option>' . "\n";
}
echo '</select>' . "\n";
echo ' &nbsp; ' . "\n";
echo '<label for="visibility">Visibility</label> ' . "\n";
echo '<select id="visibility" name="visibility">' . "\n";
echo '<option value="public">Public (P)</option>' . "\n";
echo '<option value="unlisted">Unlisted (U)</option>' . "\n";
echo '<option value="private">Followers only (F)</option>' . "\n";
echo '<option value="direct">Direct message (@)</option>' . "\n";
echo '</select>' . "\n";
echo '</span>' . "\n";
echo '</fieldset>' . "\n";
echo '<button type="submit">Post</button>' . "\n";
echo '<br><br>' . "\n";

echo '<fieldset>' . "\n";
echo '<legend>Media attachments (up to ' . $max_media . ')</legend>' . "\n";
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
echo '</form>' . "\n";
echo '</section>' . "\n";
html_foot();
