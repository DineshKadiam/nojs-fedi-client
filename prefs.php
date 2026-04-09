<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $prefs = $_SESSION['prefs'] ?? [];

    // Theme
    $theme = $_POST['theme'] ?? 'light';
    $prefs['theme'] = in_array($theme, ['light', 'dark'], true) ? $theme : 'light';

    // Body max-width
    $max_width = (int) ($_POST['max_width'] ?? 800);
    $prefs['max_width'] = max(400, min(2000, $max_width));

    // Posts per page
    $ppp = (int) ($_POST['posts_per_page'] ?? POSTS_PER_PAGE);
    $prefs['posts_per_page'] = max(5, min(40, $ppp));

    // Timezone
    $tz = $_POST['timezone'] ?? 'UTC';
    $prefs['timezone'] = in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC';
    date_default_timezone_set($prefs['timezone']);

    // Default account
    $def_a = (int) ($_POST['default_account'] ?? array_key_first($_SESSION['accounts']));
    $prefs['default_account'] = isset($_SESSION['accounts'][$def_a]) ? $def_a : (int) array_key_first($_SESSION['accounts']);

    $_SESSION['prefs'] = $prefs;

    header('Location: prefs.php?a=' . (int) ($_POST['a'] ?? 0));
    exit;
}

$prefs     = $_SESSION['prefs'] ?? [];
$timezones = DateTimeZone::listIdentifiers();
$csrf      = csrf_token();

html_head('Preferences');
echo '<section>' . "\n";
echo '<h1>Preferences</h1>' . "\n";

echo '<form method="post" action="' . url('prefs.php') . '" class="inline-form">' . "\n";
echo '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">' . "\n";

// Theme
echo '<fieldset>' . "\n";
echo '<legend>Theme</legend>' . "\n";
$cur_theme = $prefs['theme'] ?? 'light';
echo '<label><input type="radio" name="theme" value="light"' . ($cur_theme === 'light' ? ' checked' : '') . '> Light</label>' . "\n";
echo '<label><input type="radio" name="theme" value="dark"' . ($cur_theme === 'dark' ? ' checked' : '') . '> Dark</label>' . "\n";
echo '</fieldset>' . "\n";

// Page size
echo '<fieldset>' . "\n";
echo '<legend>Page size</legend>' . "\n";
$cur_width = (int) ($prefs['max_width'] ?? 800);
$cur_ppp = (int) ($prefs['posts_per_page'] ?? POSTS_PER_PAGE);
echo '<label for="max_width">Width (400-2000px):</label>' . "\n";
echo '<input type="number" id="max_width" name="max_width" value="' . $cur_width . '" min="400" max="2000">' . "\n";
echo '<br>' . "\n";
echo '<label for="posts_per_page">Posts per page (5-40):</label>' . "\n";
echo '<input type="number" id="posts_per_page" name="posts_per_page" value="' . $cur_ppp . '" min="5" max="40">' . "\n";
echo '</fieldset>' . "\n";

// Timezone
echo '<fieldset>' . "\n";
echo '<legend>Timezone</legend>' . "\n";
$cur_tz = $prefs['timezone'] ?? 'UTC';
echo '<label for="timezone">Timezone:</label>' . "\n";
echo '<select id="timezone" name="timezone">' . "\n";
foreach ($timezones as $tz) {
    $selected = ($tz === $cur_tz) ? ' selected' : '';
    echo '<option value="' . h($tz) . '"' . $selected . '>' . h($tz) . '</option>' . "\n";
}
echo '</select>' . "\n";
echo '</fieldset>' . "\n";

// Default account (only shown when multiple accounts are logged in)
if (count($_SESSION['accounts']) > 1) {
    echo '<fieldset>' . "\n";
    echo '<legend>Default account</legend>' . "\n";
    $cur_def = (int) ($prefs['default_account'] ?? array_key_first($_SESSION['accounts']));
    echo '<label for="default_account">Account:</label>' . "\n";
    echo '<select id="default_account" name="default_account">' . "\n";
    foreach ($_SESSION['accounts'] as $i => $acc) {
        $host     = parse_url($acc['instance'], PHP_URL_HOST) ?? $acc['instance'];
        $label    = ($acc['account']['username'] ?? '?') . '@' . $host;
        $selected = ($i === $cur_def) ? ' selected' : '';
        echo '<option value="' . $i . '"' . $selected . '>' . h($label) . '</option>' . "\n";
    }
    echo '</select>' . "\n";
    echo '</fieldset>' . "\n";
}

echo '<p><button type="submit">Save preferences</button></p>' . "\n";
echo '</form>' . "\n";

echo '</section>' . "\n";
html_foot();
