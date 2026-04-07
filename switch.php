<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$a = isset($_GET['a']) ? (int) $_GET['a'] : -1;

if ($a === -1 || !isset($_SESSION['accounts'][$a])) {
    header('Location: login.php');
    exit;
}

header('Location: index.php?a=' . $a);
exit;
