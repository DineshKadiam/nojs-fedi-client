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

$v1 = api_get($instance, $token, '/api/v1/instance');
$v2 = api_get($instance, $token, '/api/v2/instance');

// Merge v2 (richer, Mastodon 4+) over v1 (universal fallback)
$title       = $v2['title']       ?? $v1['title']       ?? '';
$version     = $v2['version']     ?? $v1['version']     ?? '';
$domain      = $v2['domain']      ?? $v1['uri']         ?? (parse_url($instance, PHP_URL_HOST) ?: $instance);
$description = $v2['description'] ?? $v1['description'] ?? ($v1['short_description'] ?? '');
$languages   = $v2['languages']   ?? $v1['languages']   ?? [];
$rules       = $v2['rules']       ?? $v1['rules']       ?? [];
$source_url  = $v2['source_url']  ?? '';

$thumbnail_raw = $v2['thumbnail']['url'] ?? $v1['thumbnail'] ?? '';
$thumbnail     = is_string($thumbnail_raw) ? $thumbnail_raw : '';

// Stats (v1 has total counts; v2 has active_month)
$stats        = $v1['stats'] ?? [];
$user_count   = isset($stats['user_count'])   ? (int) $stats['user_count']   : null;
$status_count = isset($stats['status_count']) ? (int) $stats['status_count'] : null;
$domain_count = isset($stats['domain_count']) ? (int) $stats['domain_count'] : null;
$active_month = isset($v2['usage']['users']['active_month'])
    ? (int) $v2['usage']['users']['active_month']
    : null;

// Contact
$email           = $v2['contact']['email']   ?? $v1['email']           ?? '';
$contact_account = $v2['contact']['account'] ?? $v1['contact_account'] ?? null;

// Registrations
if (isset($v2['registrations']['enabled'])) {
    $reg_enabled  = !empty($v2['registrations']['enabled']);
    $reg_approval = !empty($v2['registrations']['approval_required']);
} else {
    $reg_enabled  = isset($v1['registrations']) ? (bool) $v1['registrations'] : null;
    $reg_approval = !empty($v1['approval_required']);
}

html_head(t('instance_title'));
echo '<h1>' . t('instance_title') . '</h1>' . "\n";

if (isset($v1['error'])) {
    html_error($v1['error']);
    html_foot();
    exit;
}

// Header: thumbnail + title + domain
if ($thumbnail !== '') {
    echo '<p><img src="' . h($thumbnail) . '" alt="' . h($title) . '"></p>' . "\n";
}
echo '<h2>' . h($title) . '</h2>' . "\n";
echo '<p><strong>' . h($domain) . '</strong>';
if ($version !== '') {
    echo ' &mdash; ' . t('instance_version') . ' ' . h($version);
}
echo '</p>' . "\n";

if ($source_url !== '') {
    echo '<p><small>' . t('instance_source_url') . ' <a href="' . h($source_url) . '" rel="noopener noreferrer">' . h($source_url) . '</a></small></p>' . "\n";
}

// Description
if ($description !== '') {
    echo '<h2>' . t('instance_description') . '</h2>' . "\n";
    echo '<div>' . sanitize_post_html($description) . '</div>' . "\n";
}

// Statistics
if ($user_count !== null || $status_count !== null || $domain_count !== null || $active_month !== null) {
    echo '<h2>' . t('instance_stats') . '</h2>' . "\n";
    echo '<ul>' . "\n";
    if ($user_count !== null) {
        echo '<li>' . t('instance_users') . ' ' . number_format($user_count) . '</li>' . "\n";
    }
    if ($active_month !== null) {
        echo '<li>' . t('instance_active_month') . ' ' . number_format($active_month) . '</li>' . "\n";
    }
    if ($status_count !== null) {
        echo '<li>' . t('instance_posts') . ' ' . number_format($status_count) . '</li>' . "\n";
    }
    if ($domain_count !== null) {
        echo '<li>' . t('instance_domains') . ' ' . number_format($domain_count) . '</li>' . "\n";
    }
    echo '</ul>' . "\n";
}

// Languages
if (!empty($languages)) {
    echo '<p>' . t('instance_languages') . ' ' . h(implode(', ', $languages)) . '</p>' . "\n";
}

// Registrations
if ($reg_enabled !== null) {
    $reg_label = $reg_enabled
        ? ($reg_approval ? t('instance_reg_approval') : t('instance_reg_open'))
        : t('instance_reg_closed');
    echo '<p>' . t('instance_registrations') . ' ' . h($reg_label) . '</p>' . "\n";
}

// Contact
if ($email !== '' || is_array($contact_account)) {
    echo '<h2>' . t('instance_contact') . '</h2>' . "\n";
    if (is_array($contact_account) && !empty($contact_account['id'])) {
        $display  = $contact_account['display_name'] ?: ($contact_account['username'] ?? '');
        $acct     = $contact_account['acct'] ?? '';
        $admin_id = preg_replace('/[^A-Za-z0-9]/', '', $contact_account['id']);
        echo '<p>' . t('instance_admin') . ' ';
        echo '<a href="' . url('profile.php', ['id' => $admin_id]) . '">';
        echo render_emojis($display, $contact_account['emojis'] ?? []);
        if ($acct !== '') {
            echo ' <small>@' . h($acct) . '</small>';
        }
        echo '</a></p>' . "\n";
    }
    if ($email !== '') {
        echo '<p>' . t('instance_email') . ' <a href="mailto:' . h($email) . '">' . h($email) . '</a></p>' . "\n";
    }
}

// Rules
echo '<h2>' . t('instance_rules') . '</h2>' . "\n";
if (empty($rules)) {
    echo '<p>' . t('instance_no_rules') . '</p>' . "\n";
} else {
    echo '<ol>' . "\n";
    foreach ($rules as $rule) {
        $text = $rule['text'] ?? '';
        if ($text === '') continue;
        echo '<li>' . h($text) . '</li>' . "\n";
    }
    echo '</ol>' . "\n";
}

html_foot();
