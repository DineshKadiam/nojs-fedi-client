<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';

$a = isset($_GET['a']) ? (int) $_GET['a'] : -1;

if ($a === -1) {
    redirect('login.php');
}

if (!isset($_SESSION['accounts'][$a])) {
    redirect('login.php');
}

// Honor the return_to param when it points to a safe local page (no ID params).
$safe_pages = [
    'index.php', 'bookmarks.php', 'favourites.php', 'messages.php',
    'notifications.php', 'tag.php', 'follow_requests.php',
    'prefs.php', 'compose.php', 'search.php', 'trends.php',
];
$return_to  = $_GET['return_to'] ?? '';
$base_page  = strtok($return_to, '?');
if ($return_to === '' || !in_array($base_page, $safe_pages, true)
        || str_contains($return_to, '..') || preg_match('/[^A-Za-z0-9_.?=&%+\-]/', $return_to)) {
    $return_to = 'index.php';
}

$sep = str_contains($return_to, '?') ? '&' : '?';
redirect($return_to . $sep . 'a=' . $a);
