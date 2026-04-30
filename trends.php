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

// Handle pin/unpin locally
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_pin') {
    csrf_verify();
    $tag_name = lower_ascii(preg_replace('/[^A-Za-z0-9_\p{L}]/u', '', $_POST['tag'] ?? ''));
    if ($tag_name !== '') {
        $pinned = $_SESSION['accounts'][$a]['pinned_tags'] ?? [];
        if (in_array($tag_name, $pinned, true)) {
            $pinned = array_values(array_filter($pinned, fn($t) => $t !== $tag_name));
        } else {
            $pinned[] = $tag_name;
        }
        $_SESSION['accounts'][$a]['pinned_tags'] = $pinned;
    }
    redirect('trends.php?a=' . $a);
}

$tags          = get_trending_tags($instance, $token, $a);
$followed_tags = get_followed_tags($instance, $token, $a);
$followed_set  = array_flip(array_column($followed_tags, 'name'));
$pinned        = $_SESSION['accounts'][$a]['pinned_tags'] ?? [];

html_head('Trends');
echo '<h1>Trending tags</h1>' . "\n";

if (empty($tags)) {
    echo '<p>No trending tags at this time.</p>' . "\n";
} else {
    echo '<ol>' . "\n";
    foreach ($tags as $tag) {
        $name = $tag['name'] ?? '';
        if ($name === '') {
            continue;
        }
        $uses        = (int) ($tag['history'][0]['uses'] ?? 0);
        $accs        = (int) ($tag['history'][0]['accounts'] ?? 0);
        $is_pinned   = in_array($name, $pinned, true);
        $is_followed = isset($followed_set[$name]);

        echo '<li>' . "\n";
        echo '<a href="' . url('tag.php', ['tag' => $name]) . '">#' . h($name) . '</a>';
        echo "\n";

        echo '<form method="post" action="' . url('trends.php') . '" class="inline">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">' . "\n";
        echo '<input type="hidden" name="action" value="toggle_pin">' . "\n";
        echo '<input type="hidden" name="tag" value="' . h($name) . '">' . "\n";
        echo '<button type="submit"' . ($is_pinned ? ' class="active"' : '') . '>' . ($is_pinned ? 'Unpin' : 'Pin') . '</button>' . "\n";
        echo '</form>' . "\n";

        echo '<form method="post" action="action.php" class="inline">' . "\n";
        echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">' . "\n";
        echo '<input type="hidden" name="a" value="' . $a . '">' . "\n";
        echo '<input type="hidden" name="action" value="' . ($is_followed ? 'tag_unfollow' : 'tag_follow') . '">' . "\n";
        echo '<input type="hidden" name="tag" value="' . h($name) . '">' . "\n";
        echo '<button type="submit"' . ($is_followed ? ' class="active"' : '') . '>' . ($is_followed ? 'Unfollow' : 'Follow') . '</button>' . "\n";
        echo '</form>' . "\n";

        if ($uses > 0) {
            echo ' - ' . $uses . ($uses === 1 ? ' post' : ' posts');
            if ($accs > 0) {
                echo ', ' . $accs . ($accs === 1 ? ' account' : ' accounts');
            }
        }

        echo '</li>' . "\n";
    }
    echo '</ol>' . "\n";
}

html_foot();
