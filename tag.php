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

$tag = lower_ascii(preg_replace('/[^A-Za-z0-9_\p{L}]/u', '', $_GET['tag'] ?? ''));
if (!$tag) {
    html_head('Error');
    html_error('No tag specified.');
    html_foot();
    exit;
}

// Handle pin/unpin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_pin') {
    csrf_verify();
    $pinned = $_SESSION['accounts'][$a]['pinned_tags'] ?? [];
    if (in_array($tag, $pinned, true)) {
        $pinned = array_values(array_filter($pinned, fn($t) => $t !== $tag));
    } else {
        $pinned[] = $tag;
    }
    $_SESSION['accounts'][$a]['pinned_tags'] = $pinned;
    header('Location: tag.php?a=' . $a . '&tag=' . rawurlencode($tag));
    exit;
}

$params = ['limit' => pref('posts_per_page', POSTS_PER_PAGE)];
if (!empty($_GET['max_id'])) {
    $params['max_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['max_id']);
}
if (!empty($_GET['min_id'])) {
    $params['min_id'] = preg_replace('/[^A-Za-z0-9]/', '', $_GET['min_id']);
}

$statuses = api_get($instance, $token, '/api/v1/timelines/tag/' . rawurlencode($tag), $params);

$pinned     = $_SESSION['accounts'][$a]['pinned_tags'] ?? [];
$is_pinned  = in_array($tag, $pinned, true);
$pin_label  = $is_pinned ? 'Unpin' : 'Pin';

html_head('#' . $tag);
echo '<section>' . "\n";
echo '<h1>#' . h($tag) . '</h1>' . "\n";
echo '<form method="post" action="' . url('tag.php', ['tag' => $tag]) . '" style="display:inline">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">' . "\n";
echo '<input type="hidden" name="action" value="toggle_pin">' . "\n";
echo '<button type="submit"' . ($is_pinned ? ' class="active"' : '') . '>' . $pin_label . ' #' . h($tag) . '</button>' . "\n";
echo '</form>' . "\n";

if (isset($statuses['error'])) {
    html_error($statuses['error']);
} elseif (empty($statuses)) {
    echo '<p>No posts found for this tag.</p>' . "\n";
} else {
    foreach ($statuses as $status) {
        html_post($status);
    }

    $oldest_id = $statuses[count($statuses) - 1]['id'] ?? null;
    $newest_id = $statuses[0]['id'] ?? null;

    echo '<nav><ul>' . "\n";
    if ($oldest_id) {
        echo '<li><a href="' . url('tag.php', ['tag' => $tag, 'max_id' => $oldest_id]) . '">&larr; Older</a></li>' . "\n";
    }
    if ($newest_id && isset($_GET['max_id'])) {
        echo '<li><a href="' . url('tag.php', ['tag' => $tag, 'min_id' => $newest_id]) . '">Newer &rarr;</a></li>' . "\n";
    }
    echo '</ul></nav>' . "\n";
}

echo '</section>' . "\n";
html_foot();
