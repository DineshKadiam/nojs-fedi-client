<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$a = isset($_GET['a']) ? (int) $_GET['a'] : -1;

unset($_SESSION['accounts'][$a]);

if (empty($_SESSION['accounts'])) {
    session_destroy();
    header('Location: login.php');
} else {
    $first = (int) array_key_first($_SESSION['accounts']);
    header('Location: index.php?a=' . $first);
}
exit;
