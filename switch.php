<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';

$a = isset($_GET['a']) ? (int) $_GET['a'] : -1;

if ($a === -1 || !isset($_SESSION['accounts'][$a])) {
    redirect('login.php');
}

redirect('index.php?a=' . $a);
