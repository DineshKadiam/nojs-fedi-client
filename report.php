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

html_head('Report @' . $acct);
echo '<h1>Report @' . h($acct) . '</h1>' . "\n";
echo '<p>The report will be sent to the moderators of your instance.</p>' . "\n";

echo '<form method="post" action="action.php">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";
echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
echo '<input type="hidden" name="action" value="report">' . "\n";
echo '<input type="hidden" name="id" value="' . h($account_id) . '">' . "\n";
if ($status_id !== '') {
    echo '<input type="hidden" name="status_id" value="' . h($status_id) . '">' . "\n";
    echo '<p><small>Reporting specific post ID: ' . h($status_id) . '</small></p>' . "\n";
}

echo '<fieldset>' . "\n";
echo '<legend>Reason</legend>' . "\n";
echo '<label for="comment">Additional comments (optional, 1000 char max):</label><br>' . "\n";
echo '<textarea id="comment" name="comment" rows="6" cols="60" maxlength="1000"></textarea>' . "\n";
echo '</fieldset>' . "\n";

if ($is_remote) {
    echo '<fieldset>' . "\n";
    echo '<legend>Forward</legend>' . "\n";
    echo '<label><input type="checkbox" name="forward" value="1"> Forward this report to the remote instance (@' . h($acct) . ')</label>' . "\n";
    echo '</fieldset>' . "\n";
}

echo '<p><button type="submit" class="danger">Send report</button> ';
echo '<a href="' . url('profile.php', ['id' => $account_id]) . '">Cancel</a></p>' . "\n";
echo '</form>' . "\n";

html_foot();
