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
    html_head(t('search_title'));
    html_error(t('search_err_invalid_hashtag'));
    html_foot();
    exit;
}

// @user@host — resolve directly via federation
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
    html_head(t('search_title'));
    html_error(t('search_err_account_not_found', h($handle)));
    html_foot();
    exit;
}

// @username (bare) — try local resolve, then search known accounts / followers / following
if (preg_match('/^@[A-Za-z0-9_.-]+$/', $q)) {
    $needle = strtolower(substr($q, 1));
    $host   = parse_url($instance, PHP_URL_HOST) ?? '';

    // 1. Exact local resolve
    $local = api_get($instance, $token, '/api/v2/search', [
        'q'       => $q . '@' . $host,
        'resolve' => 'true',
        'type'    => 'accounts',
        'limit'   => 1,
    ]);
    $account = $local['accounts'][0] ?? null;
    if ($account && isset($account['id'])) {
        $id = preg_replace('/[^A-Za-z0-9]/', '', $account['id']);
        redirect('profile.php?a=' . $a . '&id=' . rawurlencode($id));
    }

    // 2+3. Fire text search + followers + following in parallel
    $own_id  = $ctx['account']['id'] ?? '';
    $reqs    = [
        'search' => ['/api/v2/search', ['q' => $q, 'type' => 'accounts', 'limit' => 40]],
    ];
    if ($own_id !== '') {
        $reqs['followers'] = ['/api/v1/accounts/' . $own_id . '/followers', ['limit' => 80]];
        $reqs['following'] = ['/api/v1/accounts/' . $own_id . '/following', ['limit' => 80]];
    }
    $multi = api_get_multi($instance, $token, $reqs);

    $search_failed   = isset($multi['search']['error']);
    $search_accounts = is_array($multi['search']['accounts'] ?? null) ? $multi['search']['accounts'] : [];
    $followers_list  = is_array($multi['followers'] ?? null) && !isset($multi['followers']['error']) ? $multi['followers'] : [];
    $following_list  = is_array($multi['following'] ?? null) && !isset($multi['following']['error']) ? $multi['following'] : [];

    // Merge and deduplicate by id
    $pool = [];
    foreach (array_merge($search_accounts, $followers_list, $following_list) as $acc) {
        $aid = $acc['id'] ?? '';
        if ($aid !== '') {
            $pool[$aid] = $acc;
        }
    }

    // Filter: acct/username starts with needle OR display_name contains needle (Unicode-safe)
    $matches = array_values(array_filter($pool, function (array $acc) use ($needle): bool {
        return mb_stripos($acc['acct']         ?? '', $needle) === 0
            || mb_stripos($acc['username']     ?? '', $needle) === 0
            || mb_stripos($acc['display_name'] ?? '', $needle) !== false;
    }));

    // Single match — go straight to profile
    if (count($matches) === 1 && isset($matches[0]['id'])) {
        $id = preg_replace('/[^A-Za-z0-9]/', '', $matches[0]['id']);
        redirect('profile.php?a=' . $a . '&id=' . rawurlencode($id));
    }

    html_head(t('search_title'));
    if (!empty($matches)) {
        $use_static = pref('avatar_static', false);
        echo '<h1>' . t('search_results_for', h($q)) . '</h1>' . "\n";
        if ($search_failed) {
            echo '<p><em>' . t('search_timeout_partial') . '</em></p>' . "\n";
        }
        echo '<ul class="follow-list">' . "\n";
        foreach ($matches as $acc) {
            $name    = $acc['display_name'] ?: ($acc['username'] ?? '');
            $address = $acc['acct'] ?? '';
            $src     = $use_static ? ($acc['avatar_static'] ?? $acc['avatar'] ?? '') : ($acc['avatar'] ?? '');
            $acc_id  = preg_replace('/[^A-Za-z0-9]/', '', $acc['id'] ?? '');
            echo '<li>';
            if ($src) {
                echo '<img src="' . h($src) . '" alt="" width="32" height="32"> ';
            }
            echo h($name) . ' &lt;<a href="' . url('profile.php', ['id' => $acc_id]) . '">@' . h($address) . '</a>&gt;';
            echo '</li>' . "\n";
        }
        echo '</ul>' . "\n";
        echo '<p><small>' . t('search_accounts_note') . '</small></p>' . "\n";
    } elseif ($search_failed) {
        html_error(t('search_timeout'));
    } else {
        html_error(t('search_no_accounts', h($q)));
    }
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
    html_head(t('search_title'));
    html_error(t('search_nothing_found', h($q)));
    html_foot();
    exit;
}

// Plain text — search known accounts (followers/following + instance search) by name/username
{
    $needle  = $q;
    $own_id  = $ctx['account']['id'] ?? '';
    $reqs    = [
        'search' => ['/api/v2/search', ['q' => $q, 'type' => 'accounts', 'limit' => 40]],
    ];
    if ($own_id !== '') {
        $reqs['followers'] = ['/api/v1/accounts/' . $own_id . '/followers', ['limit' => 80]];
        $reqs['following'] = ['/api/v1/accounts/' . $own_id . '/following', ['limit' => 80]];
    }
    $multi = api_get_multi($instance, $token, $reqs);

    $search_failed   = isset($multi['search']['error']);
    $search_accounts = is_array($multi['search']['accounts'] ?? null) ? $multi['search']['accounts'] : [];
    $followers_list  = is_array($multi['followers'] ?? null) && !isset($multi['followers']['error']) ? $multi['followers'] : [];
    $following_list  = is_array($multi['following'] ?? null) && !isset($multi['following']['error']) ? $multi['following'] : [];

    $pool = [];
    foreach (array_merge($search_accounts, $followers_list, $following_list) as $acc) {
        $aid = $acc['id'] ?? '';
        if ($aid !== '') {
            $pool[$aid] = $acc;
        }
    }

    $matches = array_values(array_filter($pool, function (array $acc) use ($needle): bool {
        return mb_stripos($acc['acct']         ?? '', $needle) !== false
            || mb_stripos($acc['username']     ?? '', $needle) !== false
            || mb_stripos($acc['display_name'] ?? '', $needle) !== false;
    }));

    html_head(t('search_title'));
    if (count($matches) === 1 && isset($matches[0]['id'])) {
        $id = preg_replace('/[^A-Za-z0-9]/', '', $matches[0]['id']);
        redirect('profile.php?a=' . $a . '&id=' . rawurlencode($id));
    }
    if (!empty($matches)) {
        $use_static = pref('avatar_static', false);
        echo '<h1>' . t('search_results_for', h($q)) . '</h1>' . "\n";
        if ($search_failed) {
            echo '<p><em>' . t('search_timeout_partial') . '</em></p>' . "\n";
        }
        echo '<ul class="follow-list">' . "\n";
        foreach ($matches as $acc) {
            $name    = $acc['display_name'] ?: ($acc['username'] ?? '');
            $address = $acc['acct'] ?? '';
            $src     = $use_static ? ($acc['avatar_static'] ?? $acc['avatar'] ?? '') : ($acc['avatar'] ?? '');
            $acc_id  = preg_replace('/[^A-Za-z0-9]/', '', $acc['id'] ?? '');
            echo '<li>';
            if ($src) {
                echo '<img src="' . h($src) . '" alt="" width="32" height="32"> ';
            }
            echo h($name) . ' &lt;<a href="' . url('profile.php', ['id' => $acc_id]) . '">@' . h($address) . '</a>&gt;';
            echo '</li>' . "\n";
        }
        echo '</ul>' . "\n";
        echo '<p><small>' . t('search_accounts_note_plain') . '</small></p>' . "\n";
    } elseif ($search_failed) {
        html_error(t('search_timeout_plain'));
    } else {
        html_error(t('search_no_accounts_local', h($q)));
    }
    html_foot();
    exit;
}
html_foot();
