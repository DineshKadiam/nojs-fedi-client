<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';

$ctx   = require_login();
$a     = current_a();
$token = $ctx['access_token'];

$creds         = api_get($ctx['instance'], $token, '/api/v1/accounts/verify_credentials');
if (isset($creds['error'])) {
    html_head('Error');
    html_error('Could not load profile: ' . $creds['error']);
    html_foot();
    exit;
}
$custom_emojis = api_get($ctx['instance'], $token, '/api/v1/custom_emojis');

$csrf = csrf_token();

// Plain-text bio: source.note is authoritative; fall back to stripping HTML from note
$display_name  = $creds['display_name'] ?? '';
$note          = $creds['source']['note'] ?? strip_tags(html_entity_decode($creds['note'] ?? '', ENT_QUOTES, 'UTF-8'));
$locked        = !empty($creds['locked']);
$bot           = !empty($creds['bot']);
$discoverable  = isset($creds['discoverable']) ? !empty($creds['discoverable']) : null;
$def_privacy   = $creds['source']['privacy']   ?? 'public';
$def_sensitive = !empty($creds['source']['sensitive']);
$def_language  = $creds['source']['language']  ?? '';
$avatar_url    = $creds['avatar']  ?? '';
$header_url    = $creds['header']  ?? '';

// Profile metadata fields: prefer source.fields (plain text values)
$raw_fields = $creds['source']['fields'] ?? $creds['fields'] ?? [];

$languages = ['af','ar','az','be','bg','bn','bs','ca','cs','cy','da','de','el','en','eo',
              'es','et','eu','fa','fi','fr','ga','gl','gu','he','hi','hr','hu','hy','id',
              'is','it','ja','ka','kk','km','kn','ko','ku','ky','lb','lt','lv','mk','ml',
              'mn','mr','ms','my','nl','nn','no','oc','pa','pl','ps','pt','ro','ru','sk',
              'sl','sq','sr','sv','sw','ta','te','th','tl','tr','uk','ur','uz','vi','zh'];

html_head('Edit profile');
echo '<h1>Edit profile</h1>' . "\n";
echo '<form method="post" action="action.php" enctype="multipart/form-data">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
echo '<input type="hidden" name="action" value="profile_update">' . "\n";

// Identity
echo '<fieldset>' . "\n";
echo '<legend>Identity</legend>' . "\n";
echo '<label for="display_name">Display name</label>' . "\n";
echo '<input type="text" id="display_name" name="display_name" value="' . h($display_name) . '">' . "\n";
echo '<label for="note">Bio</label>' . "\n";
echo '<textarea id="note" name="note" rows="5" cols="60">' . h($note) . '</textarea>' . "\n";
html_emoji_picker(is_array($custom_emojis) ? $custom_emojis : []);
echo '</fieldset>' . "\n";

// Avatar & header
echo '<fieldset>' . "\n";
echo '<legend>Avatar &amp; header</legend>' . "\n";
if ($avatar_url) {
    echo '<p><img src="' . h($avatar_url) . '" alt="Current avatar" width="64" height="64"></p>' . "\n";
}
echo '<label for="avatar">New avatar (optional, image only)</label>' . "\n";
echo '<input type="file" id="avatar" name="avatar" accept="image/*">' . "\n";
if ($header_url) {
    echo '<p><img src="' . h($header_url) . '" alt="Current header" class="img-preview"></p>' . "\n";
}
echo '<label for="header">New header (optional, image only)</label>' . "\n";
echo '<input type="file" id="header" name="header" accept="image/*">' . "\n";
echo '</fieldset>' . "\n";

// Posting defaults
echo '<fieldset>' . "\n";
echo '<legend>Posting defaults</legend>' . "\n";
echo '<span class="inline-form">' . "\n";
echo '<label for="def_privacy">Default visibility</label> ' . "\n";
echo '<select id="def_privacy" name="source[privacy]">' . "\n";
foreach (['public' => 'Public', 'unlisted' => 'Unlisted', 'private' => 'Followers only', 'direct' => 'Direct'] as $val => $label) {
    $sel = ($val === $def_privacy) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>' . "\n";
}
echo '</select>' . "\n";
echo ' &nbsp; ' . "\n";
echo '<label for="def_language">Default language</label> ' . "\n";
echo '<select id="def_language" name="source[language]">' . "\n";
echo '<option value=""></option>' . "\n";
foreach ($languages as $code) {
    $sel = ($code === $def_language) ? ' selected' : '';
    echo '<option value="' . $code . '"' . $sel . '>' . $code . '</option>' . "\n";
}
echo '</select>' . "\n";
echo '</span>' . "\n";
echo '<label><input type="checkbox" name="source[sensitive]" value="1"' . ($def_sensitive ? ' checked' : '') . '> Mark media as sensitive by default</label>' . "\n";
echo '</fieldset>' . "\n";

// Account flags
echo '<fieldset>' . "\n";
echo '<legend>Account</legend>' . "\n";
echo '<label><input type="checkbox" name="locked" value="1"' . ($locked ? ' checked' : '') . '> Locked (approve follower requests)</label>' . "\n";
echo '<label><input type="checkbox" name="bot" value="1"' . ($bot ? ' checked' : '') . '> Bot account</label>' . "\n";
if ($discoverable !== null) {
    echo '<label><input type="checkbox" name="discoverable" value="1"' . ($discoverable ? ' checked' : '') . '> Discoverable in directory/search</label>' . "\n";
}
echo '</fieldset>' . "\n";

// Metadata fields
echo '<fieldset>' . "\n";
echo '<legend>Profile fields (up to 4)</legend>' . "\n";
for ($i = 0; $i < 4; $i++) {
    $fname  = $raw_fields[$i]['name']  ?? '';
    $fvalue = strip_tags($raw_fields[$i]['value'] ?? '');
    echo '<span class="inline-form">' . "\n";
    echo '<label for="fn_' . $i . '">Name</label> ';
    echo '<input type="text" id="fn_' . $i . '" name="fields_attributes[' . $i . '][name]" maxlength="255" value="' . h($fname) . '">';
    echo ' <label for="fv_' . $i . '">Value</label> ';
    echo '<input type="text" id="fv_' . $i . '" name="fields_attributes[' . $i . '][value]" maxlength="255" value="' . h($fvalue) . '">';
    echo '</span>' . "\n";
    echo '<br>' . "\n";
}
echo '</fieldset>' . "\n";

echo '<button type="submit" class="active">Save profile</button>' . "\n";
echo '</form>' . "\n";
html_foot();
