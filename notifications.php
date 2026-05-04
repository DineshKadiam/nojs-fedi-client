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
    'mention'   => t('notif_filter_mentions'),
    'reblog'    => t('notif_filter_boosts'),
    'favourite' => t('notif_filter_favs'),
    'follow'    => t('notif_filter_follows'),
    'poll'      => t('notif_filter_polls'),
    'update'    => t('notif_filter_edits'),
    'status'    => t('notif_filter_posts'),
];
if (account_locked($a)) {
    $allowed_types[]                   = 'follow_request';
    $type_labels['follow_request']     = t('notif_filter_follow_reqs');
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

html_head(t('notifications_title'));
echo '<h1>' . t('notifications_title') . '</h1>' . "\n";

// Clear-all form (always visible, two-step confirmation)
echo '<form method="' . ($confirm_clear ? 'post' : 'get') . '" action="' . ($confirm_clear ? 'action.php' : 'notifications.php') . '" class="inline-form">' . "\n";
echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
if ($confirm_clear) {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">' . "\n";
    echo '<input type="hidden" name="action" value="clear_notifications">' . "\n";
    echo '<button type="submit" class="danger">' . t('notif_confirm_clear') . '</button>' . "\n";
    echo ' <a href="' . url('notifications.php') . '">' . t('action_cancel') . '</a>' . "\n";
} else {
    echo '<input type="hidden" name="confirm_clear" value="1">' . "\n";
    echo '<button type="submit" class="danger">' . t('notif_clear_all') . '</button>' . "\n";
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
echo '<button type="submit">' . t('action_filter') . '</button>' . "\n";
echo '</form>' . "\n";
endif;

if (isset($notifications['error'])) {
    html_error($notifications['error']);
} elseif (empty($notifications)) {
    echo '<p>' . t('notif_none') . '</p>' . "\n";
} else {
    foreach ($notifications as $notif) {
        html_notification($notif);
    }

    $oldest_id  = $notifications[count($notifications) - 1]['id'] ?? null;
    $newest_id  = isset($_GET['max_id']) ? ($notifications[0]['id'] ?? null) : null;

    html_pagination('notifications.php', [], $oldest_id, $newest_id);
}

html_foot();
