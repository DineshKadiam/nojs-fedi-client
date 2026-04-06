<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$_SESSION['theme'] = ($_SESSION['theme'] ?? 'light') === 'dark' ? 'light' : 'dark';

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
exit;
