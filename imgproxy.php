<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';

if (!is_logged_in()) {
    http_response_code(403);
    exit;
}

$tw = min(2000, max(1, (int) ($_GET['w'] ?? 800)));
$th = min(2000, max(1, (int) ($_GET['h'] ?? 267)));
$u  = $_GET['u'] ?? '';

if (!filter_var($u, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $u)) {
    http_response_code(400);
    exit;
}

if (!function_exists('imagecreatefromstring')) {
    header('Location: ' . $u);
    exit;
}

$ch = curl_init($u);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_USERAGENT      => SMOLFEDI_USER_AGENT,
]);
$data      = curl_exec($ch);
$http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ct        = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if (!$data || $http_code !== 200) {
    http_response_code(502);
    exit;
}

if (!preg_match('/^image\/(jpeg|png|gif|webp)/i', $ct)) {
    http_response_code(415);
    exit;
}

$src = @imagecreatefromstring($data);
unset($data);
if (!$src) {
    http_response_code(422);
    exit;
}

$sw = imagesx($src);
$sh = imagesy($src);

// Cover crop: scale so the image fills tw×th completely, then center-crop the excess
$scale = max($tw / $sw, $th / $sh);
$src_w = (int) round($tw / $scale);
$src_h = (int) round($th / $scale);
$src_x = (int) round(($sw - $src_w) / 2);
$src_y = (int) round(($sh - $src_h) / 2);

$dst = imagecreatetruecolor($tw, $th);
imagecopyresampled($dst, $src, 0, 0, $src_x, $src_y, $tw, $th, $src_w, $src_h);
imagedestroy($src);

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
imagejpeg($dst, null, 85);
imagedestroy($dst);
