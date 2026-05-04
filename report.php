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

$account_id = preg_replace('/[^A-Za-z0-9]/', '', $_GET['id'] ?? '');
$status_id  = preg_replace('/[^A-Za-z0-9]/', '', $_GET['status_id'] ?? '');
if (!$account_id) {
    html_head('Error');
    html_error('No account ID provided.');
    html_foot();
    exit;
}

$account = api_get($instance, $token, '/api/v1/accounts/' . $account_id);
if (isset($account['error'])) {
    html_head('Error');
    html_error($account['error']);
    html_foot();
    exit;
}

$acct     = $account['acct'] ?? '';
$is_remote = str_contains($acct, '@');
$csrf     = csrf_token();

html_head(t('report_title', $acct));
echo '<h1>' . t('report_title', h($acct)) . '</h1>' . "\n";
echo '<p>' . t('report_help') . '</p>' . "\n";

echo '<form method="post" action="action.php">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
echo '<input type="hidden" name="action" value="report">' . "\n";
echo '<input type="hidden" name="id" value="' . h($account_id) . '">' . "\n";
if ($status_id !== '') {
    echo '<input type="hidden" name="status_id" value="' . h($status_id) . '">' . "\n";
    echo '<p><small>' . t('report_status_id', h($status_id)) . '</small></p>' . "\n";
}

echo '<fieldset>' . "\n";
echo '<legend>' . t('report_reason_legend') . '</legend>' . "\n";
echo '<label for="comment">' . t('report_comment_label') . '</label><br>' . "\n";
echo '<textarea id="comment" name="comment" rows="6" cols="60" maxlength="1000"></textarea>' . "\n";
echo '</fieldset>' . "\n";

if ($is_remote) {
    echo '<fieldset>' . "\n";
    echo '<legend>' . t('report_forward_legend') . '</legend>' . "\n";
    echo '<label><input type="checkbox" name="forward" value="1"> ' . t('report_forward_label', h($acct)) . '</label>' . "\n";
    echo '</fieldset>' . "\n";
}

echo '<p><button type="submit" class="danger">' . t('report_send_btn') . '</button> ';
echo '<a href="' . url('profile.php', ['id' => $account_id]) . '">' . t('action_cancel') . '</a></p>' . "\n";
echo '</form>' . "\n";

html_foot();
