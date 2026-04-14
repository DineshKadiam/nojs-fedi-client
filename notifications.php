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

$allowed_types = ['mention', 'reblog', 'favourite', 'follow', 'poll', 'update', 'status'];
$type_labels   = [
    'mention'   => '&#8618; Mentions',
    'reblog'    => '&#8635; Boosts',
    'favourite' => '&#9733; Favs',
    'follow'    => '&#43; Follows',
    'poll'      => '&#9745; Polls',
    'update'    => '&#9998; Edits',
    'status'    => '! Posts',
];
if (account_locked($a)) {
    $allowed_types[]                   = 'follow_request';
    $type_labels['follow_request']     = '&#63; Follow reqs';
}

$confirm_clear = !empty($_GET['confirm_clear']);

// Per-account filter stored in session (array of selected types). Default = all.
// When confirming a clear-all, bypass the filter so the user sees every pending
// notification before wiping them.
$selected_types = $_SESSION['accounts'][$a]['notif_filter'] ?? $allowed_types;
$selected_types = array_values(array_intersect($allowed_types, $selected_types));
$is_filtering   = !$confirm_clear && count($selected_types) > 0 && count($selected_types) < count($allowed_types);

$params = ['limit' => pref('posts_per_page', POSTS_PER_PAGE)];
if ($is_filtering) {
    $params['types'] = $selected_types;
}
if (!empty($_GET['max_id'])) {
    $params['max_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['max_id']);
}
if (!empty($_GET['min_id'])) {
    $params['min_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['min_id']);
}

$notifications = (!$confirm_clear && empty($selected_types))
    ? []
    : api_get($instance, $token, '/api/v1/notifications', $params);

html_head('Notifications');
echo '<section>' . "\n";
echo '<h1>Notifications</h1>' . "\n";

// Clear-all form (always visible, two-step confirmation)
echo '<form method="' . ($confirm_clear ? 'post' : 'get') . '" action="' . ($confirm_clear ? 'action.php' : 'notifications.php') . '" class="inline-form">' . "\n";
echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
if ($confirm_clear) {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">' . "\n";
    echo '<input type="hidden" name="action" value="clear_notifications">' . "\n";
    echo '<button type="submit" class="danger">Confirm clear ALL notifications?</button>' . "\n";
    echo ' <a href="' . url('notifications.php') . '">Cancel</a>' . "\n";
} else {
    echo '<input type="hidden" name="confirm_clear" value="1">' . "\n";
    echo '<button type="submit" class="danger">Clear all</button>' . "\n";
}
echo '</form>' . "\n";

// Filter form (checkboxes, saved in session per account).
// Hidden during clear-all confirmation so the user sees every pending notification.
if (!$confirm_clear):
echo '<form method="post" action="action.php" class="inline-form">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">' . "\n";
echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
echo '<input type="hidden" name="action" value="set_notif_filter">' . "\n";
foreach ($type_labels as $t => $label) {
    $checked = in_array($t, $selected_types, true) ? ' checked' : '';
    echo '<label><input type="checkbox" name="types[]" value="' . $t . '"' . $checked . '> ' . $label . '</label> ' . "\n";
}
echo '<button type="submit">Filter</button>' . "\n";
echo '</form>' . "\n";
endif;

if (isset($notifications['error'])) {
    html_error($notifications['error']);
} elseif (empty($notifications)) {
    echo '<p>No notifications.</p>' . "\n";
} else {
    foreach ($notifications as $notif) {
        html_notification($notif);
    }

    $oldest_id  = $notifications[count($notifications) - 1]['id'] ?? null;
    $newest_id  = isset($_GET['max_id']) ? ($notifications[0]['id'] ?? null) : null;

    html_pagination('notifications.php', [], $oldest_id, $newest_id);
}

echo '</section>' . "\n";
html_foot();
