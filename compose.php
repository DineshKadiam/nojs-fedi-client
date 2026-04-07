<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
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
echo '<label for="visibility">Visibility</label>' . "\n";
echo '<select id="visibility" name="visibility">' . "\n";
echo '<option value="public">Public</option>' . "\n";
echo '<option value="unlisted">Unlisted</option>' . "\n";
echo '<option value="private">Followers only</option>' . "\n";
echo '<option value="direct">Direct</option>' . "\n";
echo '</select>' . "\n";
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
