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
    html_error(t('profile_edit_err_load', $creds['error']));
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

html_head(t('profile_edit_title'));
echo '<h1>' . t('profile_edit_title') . '</h1>' . "\n";
echo '<form method="post" action="action.php" enctype="multipart/form-data">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
echo '<input type="hidden" name="action" value="profile_update">' . "\n";

// Identity
echo '<fieldset>' . "\n";
echo '<legend>' . t('profile_edit_identity_legend') . '</legend>' . "\n";
echo '<label for="display_name">' . t('profile_edit_display_name') . '</label>' . "\n";
echo '<input type="text" id="display_name" name="display_name" value="' . h($display_name) . '">' . "\n";
echo '<label for="note">' . t('profile_edit_bio') . '</label>' . "\n";
echo '<textarea id="note" name="note" rows="5" cols="60">' . h($note) . '</textarea>' . "\n";
html_emoji_picker(is_array($custom_emojis) ? $custom_emojis : []);
echo '</fieldset>' . "\n";

// Avatar & header
echo '<fieldset>' . "\n";
echo '<legend>' . t('profile_edit_avatar_legend') . '</legend>' . "\n";
if ($avatar_url) {
    echo '<p><img src="' . h($avatar_url) . '" alt="' . t('profile_edit_current_avatar') . '" width="64" height="64"></p>' . "\n";
}
echo '<label for="avatar">' . t('profile_edit_new_avatar') . '</label>' . "\n";
echo '<input type="file" id="avatar" name="avatar" accept="image/*">' . "\n";
if ($header_url) {
    echo '<p><img src="' . h($header_url) . '" alt="' . t('profile_edit_current_header') . '" class="img-preview"></p>' . "\n";
}
echo '<label for="header">' . t('profile_edit_new_header') . '</label>' . "\n";
echo '<input type="file" id="header" name="header" accept="image/*">' . "\n";
echo '</fieldset>' . "\n";

// Posting defaults
echo '<fieldset>' . "\n";
echo '<legend>' . t('profile_edit_posting_legend') . '</legend>' . "\n";
echo '<span class="inline-form">' . "\n";
echo '<label for="def_privacy">' . t('profile_edit_default_vis') . '</label> ' . "\n";
echo '<select id="def_privacy" name="source[privacy]">' . "\n";
foreach (['public' => t('vis_public'), 'unlisted' => t('vis_unlisted'), 'private' => t('vis_followers_only'), 'direct' => t('vis_direct')] as $val => $label) {
    $sel = ($val === $def_privacy) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>' . "\n";
}
echo '</select>' . "\n";
echo ' &nbsp; ' . "\n";
echo '<label for="def_language">' . t('profile_edit_default_lang') . '</label> ' . "\n";
echo '<select id="def_language" name="source[language]">' . "\n";
echo '<option value=""></option>' . "\n";
foreach ($languages as $code) {
    $sel = ($code === $def_language) ? ' selected' : '';
    echo '<option value="' . $code . '"' . $sel . '>' . $code . '</option>' . "\n";
}
echo '</select>' . "\n";
echo '</span>' . "\n";
echo '<label><input type="checkbox" name="source[sensitive]" value="1"' . ($def_sensitive ? ' checked' : '') . '> ' . t('profile_edit_sensitive') . '</label>' . "\n";
echo '</fieldset>' . "\n";

// Account flags
echo '<fieldset>' . "\n";
echo '<legend>' . t('profile_edit_account_legend') . '</legend>' . "\n";
echo '<label><input type="checkbox" name="locked" value="1"' . ($locked ? ' checked' : '') . '> ' . t('profile_edit_locked') . '</label>' . "\n";
echo '<label><input type="checkbox" name="bot" value="1"' . ($bot ? ' checked' : '') . '> ' . t('profile_edit_bot') . '</label>' . "\n";
if ($discoverable !== null) {
    echo '<label><input type="checkbox" name="discoverable" value="1"' . ($discoverable ? ' checked' : '') . '> ' . t('profile_edit_discoverable') . '</label>' . "\n";
}
echo '</fieldset>' . "\n";

// Metadata fields
echo '<fieldset>' . "\n";
echo '<legend>' . t('profile_edit_fields_legend') . '</legend>' . "\n";
for ($i = 0; $i < 4; $i++) {
    $fname  = $raw_fields[$i]['name']  ?? '';
    $fvalue = strip_tags($raw_fields[$i]['value'] ?? '');
    echo '<span class="inline-form">' . "\n";
    echo '<label for="fn_' . $i . '">' . t('profile_edit_field_name') . '</label> ';
    echo '<input type="text" id="fn_' . $i . '" name="fields_attributes[' . $i . '][name]" maxlength="255" value="' . h($fname) . '">';
    echo ' <label for="fv_' . $i . '">' . t('profile_edit_field_value') . '</label> ';
    echo '<input type="text" id="fv_' . $i . '" name="fields_attributes[' . $i . '][value]" maxlength="255" value="' . h($fvalue) . '">';
    echo '</span>' . "\n";
    echo '<br>' . "\n";
}
echo '</fieldset>' . "\n";

echo '<button type="submit" class="active">' . t('profile_edit_save_btn') . '</button>' . "\n";
echo '</form>' . "\n";
html_foot();
