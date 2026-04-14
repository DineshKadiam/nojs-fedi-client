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

$q = trim($_GET['q'] ?? '');

if ($q === '') {
    redirect('index.php?a=' . $a);
}

// #hashtag
if ($q[0] === '#') {
    $tag = lower_ascii(preg_replace('/[^A-Za-z0-9_\p{L}]/u', '', substr($q, 1)));
    if ($tag !== '') {
        redirect('tag.php?a=' . $a . '&tag=' . rawurlencode($tag));
    }
    html_head('Search');
    html_error('Invalid hashtag.');
    html_foot();
    exit;
}

// @user@host or @user (bare @username gets current instance appended)
if (preg_match('/^@[A-Za-z0-9_.-]+$/', $q)) {
    $host = parse_url($instance, PHP_URL_HOST) ?? '';
    $q    = $q . '@' . $host;
}
if (preg_match('/^@?[A-Za-z0-9_.-]+@[A-Za-z0-9._-]+$/', $q)) {
    $handle  = ($q[0] !== '@') ? '@' . $q : $q;
    $results = api_get($instance, $token, '/api/v2/search', [
        'q'       => $handle,
        'resolve' => 'true',
        'type'    => 'accounts',
        'limit'   => 1,
    ]);
    $account = $results['accounts'][0] ?? null;
    if ($account && isset($account['id'])) {
        $id = preg_replace('/[^A-Za-z0-9]/', '', $account['id']);
        redirect('profile.php?a=' . $a . '&id=' . rawurlencode($id));
    }
    html_head('Search');
    html_error('Account not found: ' . h($q));
    html_foot();
    exit;
}

// URL
if (preg_match('#^https?://#i', $q)) {
    $results = api_get($instance, $token, '/api/v2/search', [
        'q'       => $q,
        'resolve' => 'true',
        'limit'   => 1,
    ]);
    // Profile URL
    $account = $results['accounts'][0] ?? null;
    if ($account && isset($account['id'])) {
        $id = preg_replace('/[^A-Za-z0-9]/', '', $account['id']);
        redirect('profile.php?a=' . $a . '&id=' . rawurlencode($id));
    }
    // Post URL
    $status = $results['statuses'][0] ?? null;
    if ($status && isset($status['id'])) {
        $id = preg_replace('/[^A-Za-z0-9]/', '', $status['id']);
        redirect('post.php?a=' . $a . '&id=' . rawurlencode($id));
    }
    html_head('Search');
    html_error('Nothing found for: ' . h($q));
    html_foot();
    exit;
}

html_head('Search');
html_error('Unrecognised input. Enter a #hashtag, @user@host, or a URL.');
html_foot();
