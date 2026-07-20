<?php
declare(strict_types=1);

// Fetch and cache instance configuration in the session (per account)
function get_instance_config(string $instance, string $token, int $a): array {
    $cached = $_SESSION['accounts'][$a]['instance_config'] ?? null;
    if ($cached && (time() - ($cached['ts'] ?? 0)) < CACHE_TTL
        && isset($cached['supported_mime_types'])
        && array_key_exists('image_size_limit', $cached)
        && array_key_exists('translation_enabled', $cached)) {
        return $cached;
    }
    $v1 = api_get($instance, $token, '/api/v1/instance');
    $v2 = api_get($instance, $token, '/api/v2/instance');

    $mime_types = $v1['configuration']['statuses']['supported_mime_types'] ?? ['text/plain'];
    if (!is_array($mime_types) || empty($mime_types)) {
        $mime_types = ['text/plain'];
    }

    // Media size limits: try v2 first, then v1
    $media_v2 = $v2['configuration']['media_attachments'] ?? [];
    $media_v1 = $v1['configuration']['media_attachments'] ?? [];
    $image_size_limit = ($media_v2['image_size_limit'] ?? $media_v1['image_size_limit'] ?? null);
    $video_size_limit = ($media_v2['video_size_limit'] ?? $media_v1['video_size_limit'] ?? null);

    $config = [
        'max_characters'        => (int) ($v1['configuration']['statuses']['max_characters']
                                    ?? $v1['max_toot_chars']
                                    ?? 500),
        'max_media_attachments' => (int) ($v1['configuration']['statuses']['max_media_attachments']
                                    ?? 4),
        'supported_mime_types'  => $mime_types,
        'image_size_limit'      => $image_size_limit !== null ? (int) $image_size_limit : null,
        'video_size_limit'      => $video_size_limit  !== null ? (int) $video_size_limit  : null,
        'translation_enabled'   => !empty($v2['configuration']['translation']['enabled']),
        'ts'                    => time(),
    ];
    $_SESSION['accounts'][$a]['instance_config'] = $config;
    return $config;
}

// Fetch the user's filters, cached in session
function get_filters(string $instance, string $token, int $a): array {
    $cache = $_SESSION['accounts'][$a]['filters_cache'] ?? null;
    if ($cache && (time() - $cache['ts']) < CACHE_TTL) {
        return $cache['data'];
    }
    $data    = api_get($instance, $token, '/api/v1/filters');
    $filters = (is_array($data) && !isset($data['error'])) ? $data : [];
    $_SESSION['accounts'][$a]['filters_cache'] = ['data' => $filters, 'ts' => time()];
    return $filters;
}

// Fetch the user's blocked domains, cached in session.
// Also records whether the instance supports the endpoint (GoToSocial returns 404).
function get_blocked_domains(string $instance, string $token, int $a): array {
    $cache = $_SESSION['accounts'][$a]['domain_blocks_cache'] ?? null;
    if ($cache && (time() - $cache['ts']) < CACHE_TTL) {
        return $cache['data'];
    }
    $data      = api_get($instance, $token, '/api/v1/domain_blocks', ['limit' => 200]);
    $supported = is_array($data) && !isset($data['error']);
    $domains   = $supported ? $data : [];
    $_SESSION['accounts'][$a]['domain_blocks_cache'] = [
        'data'      => $domains,
        'supported' => $supported,
        'ts'        => time(),
    ];
    return $domains;
}

function domain_blocks_supported(string $instance, string $token, int $a): bool {
    if (!isset($_SESSION['accounts'][$a]['domain_blocks_cache'])) {
        get_blocked_domains($instance, $token, $a);
    }
    return !empty($_SESSION['accounts'][$a]['domain_blocks_cache']['supported']);
}

// Fetch the user's relay push connections (GoToSocial-specific), cached in session.
// Also records whether the instance supports the endpoint.
function get_relay_pushes(string $instance, string $token, int $a): array {
    $cache = $_SESSION['accounts'][$a]['relay_pushes_cache'] ?? null;
    if ($cache && (time() - $cache['ts']) < CACHE_TTL) {
        return $cache['data'];
    }
    $data      = api_get($instance, $token, '/api/v1/relay_pushes');
    $supported = is_array($data) && !isset($data['error']);
    $relays    = $supported ? $data : [];
    $_SESSION['accounts'][$a]['relay_pushes_cache'] = [
        'data'      => $relays,
        'supported' => $supported,
        'ts'        => time(),
    ];
    return $relays;
}

function relay_pushes_supported(string $instance, string $token, int $a): bool {
    if (!isset($_SESSION['accounts'][$a]['relay_pushes_cache'])) {
        get_relay_pushes($instance, $token, $a);
    }
    return !empty($_SESSION['accounts'][$a]['relay_pushes_cache']['supported']);
}

// Fetch the user's lists, cached in session
function get_user_lists(string $instance, string $token, int $a): array {
    $cache = $_SESSION['accounts'][$a]['lists_cache'] ?? null;
    if ($cache && (time() - $cache['ts']) < CACHE_TTL) {
        return $cache['data'];
    }
    $data  = api_get($instance, $token, '/api/v1/lists');
    $lists = (is_array($data) && !isset($data['error'])) ? $data : [];
    $_SESSION['accounts'][$a]['lists_cache'] = ['data' => $lists, 'ts' => time()];
    return $lists;
}

// Fetch instance announcements, cached in session
function get_announcements(string $instance, string $token, int $a): array {
    $cache = $_SESSION['accounts'][$a]['announcements_cache'] ?? null;
    if ($cache && (time() - $cache['ts']) < CACHE_TTL) {
        return $cache['data'];
    }
    $data = api_get($instance, $token, '/api/v1/announcements');
    $list = (is_array($data) && !isset($data['error'])) ? $data : [];
    $_SESSION['accounts'][$a]['announcements_cache'] = ['data' => $list, 'ts' => time()];
    return $list;
}

// Fetch the user's followed hashtags, cached in session
function get_followed_tags(string $instance, string $token, int $a): array {
    $cache = $_SESSION['accounts'][$a]['followed_tags_cache'] ?? null;
    if ($cache && (time() - $cache['ts']) < CACHE_TTL) {
        return $cache['data'];
    }
    $data = api_get($instance, $token, '/api/v1/followed_tags', ['limit' => 200]);
    $tags = (is_array($data) && !isset($data['error'])) ? $data : [];
    $_SESSION['accounts'][$a]['followed_tags_cache'] = ['data' => $tags, 'ts' => time()];
    return $tags;
}

// Fetch trending hashtags, cached in session.
function get_trending_tags(string $instance, string $token, int $a): array {
    $endpoint = '/api/v1/trends/tags?limit=20';
    $cache = $_SESSION['accounts'][$a]['trending_tags_cache'] ?? null;
    if ($cache && (time() - $cache['ts']) < CACHE_TTL) {
        _debug_log('GET cached', rtrim($instance, '/') . $endpoint, '', $cache['data']);
        return $cache['data'];
    }
    $data = api_get($instance, $token, '/api/v1/trends/tags', ['limit' => 20]);
    $tags = (is_array($data) && !isset($data['error'])) ? $data : [];
    $_SESSION['accounts'][$a]['trending_tags_cache'] = ['data' => $tags, 'ts' => time()];
    return $tags;
}

// Fetch trending statuses, cached in session.
function get_trending_statuses(string $instance, string $token, int $a): array {
    $endpoint = '/api/v1/trends/statuses?limit=20';
    $cache = $_SESSION['accounts'][$a]['trending_statuses_cache'] ?? null;
    if ($cache && (time() - $cache['ts']) < CACHE_TTL) {
        _debug_log('GET cached', rtrim($instance, '/') . $endpoint, '', $cache['data']);
        return $cache['data'];
    }
    $data     = api_get($instance, $token, '/api/v1/trends/statuses', ['limit' => 20]);
    $statuses = (is_array($data) && !isset($data['error'])) ? $data : [];
    $_SESSION['accounts'][$a]['trending_statuses_cache'] = ['data' => $statuses, 'ts' => time()];
    return $statuses;
}

// Fetch trending links, cached in session.
function get_trending_links(string $instance, string $token, int $a): array {
    $endpoint = '/api/v1/trends/links?limit=10';
    $cache = $_SESSION['accounts'][$a]['trending_links_cache'] ?? null;
    if ($cache && (time() - $cache['ts']) < CACHE_TTL) {
        _debug_log('GET cached', rtrim($instance, '/') . $endpoint, '', $cache['data']);
        return $cache['data'];
    }
    $data  = api_get($instance, $token, '/api/v1/trends/links', ['limit' => 10]);
    $links = (is_array($data) && !isset($data['error'])) ? $data : [];
    $_SESSION['accounts'][$a]['trending_links_cache'] = ['data' => $links, 'ts' => time()];
    return $links;
}

// Fetch suggested accounts, cached in session.
function get_suggestions(string $instance, string $token, int $a): array {
    $endpoint = '/api/v2/suggestions?limit=10';
    $cache = $_SESSION['accounts'][$a]['suggestions_cache'] ?? null;
    if ($cache && (time() - $cache['ts']) < CACHE_TTL) {
        _debug_log('GET cached', rtrim($instance, '/') . $endpoint, '', $cache['data']);
        return $cache['data'];
    }
    $data        = api_get($instance, $token, '/api/v2/suggestions', ['limit' => 10]);
    $suggestions = (is_array($data) && !isset($data['error'])) ? $data : [];
    $_SESSION['accounts'][$a]['suggestions_cache'] = ['data' => $suggestions, 'ts' => time()];
    return $suggestions;
}

// Upload a media file; returns the media attachment array (with 'id')
function api_upload_media(string $instance, string $token, string $file_path, string $mime_type, string $alt = ''): array {
    $url          = rtrim($instance, '/') . '/api/v2/media';
    $ch           = curl_init();
    $resp_headers = [];
    $file         = new CURLFile($file_path, $mime_type, basename($file_path));

    $fields = ['file' => $file];
    if ($alt !== '') {
        $fields['description'] = $alt;
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT * 2);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$resp_headers) {
        $resp_headers[] = $header;
        return strlen($header);
    });
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'User-Agent: ' . SMOLFEDI_USER_AGENT,
    ]);

    $raw       = curl_exec($ch);
    $err       = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        $result = ['error' => $err];
    } elseif ($http_code === 429) {
        $result = _rate_limit_error($resp_headers);
    } else {
        $decoded = json_decode($raw, true);
        $result  = is_array($decoded) ? $decoded : ['error' => 'Invalid JSON'];
    }

    _debug_log('POST', $url, 'file=' . basename($file_path) . ($alt !== '' ? '&description=' . urlencode($alt) : ''), $result);
    return $result;
}

function api_get(string $instance, string $token, string $endpoint, array $params = []): array {
    $url = rtrim($instance, '/') . $endpoint;
    if (!empty($params)) {
        $url .= '?' . preg_replace('/%5B\d+%5D/', '%5B%5D', http_build_query($params));
    }
    return _api_request('GET', $url, $token);
}

// Execute multiple GET requests in parallel. $requests is ['key' => [endpoint, params], ...].
// Returns ['key' => decoded_array, ...] in the same order.
function api_get_multi(string $instance, string $token, array $requests): array {
    $base    = rtrim($instance, '/');
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'User-Agent: ' . SMOLFEDI_USER_AGENT,
    ];
    $mh      = curl_multi_init();
    $handles = [];
    $urls    = [];

    foreach ($requests as $key => [$endpoint, $params]) {
        $url = $base . $endpoint;
        if (!empty($params)) {
            $url .= '?' . preg_replace('/%5B\d+%5D/', '%5B%5D', http_build_query($params));
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
        $urls[$key]    = $url;
    }

    do {
        curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh);
        }
    } while ($running > 0);

    $results = [];
    foreach ($handles as $key => $ch) {
        $curl_err = curl_errno($ch);
        $body     = curl_multi_getcontent($ch);
        if ($curl_err !== 0) {
            $results[$key] = ['error' => 'curl:' . $curl_err];
        } else {
            $decoded = json_decode((string) $body, true);
            $results[$key] = is_array($decoded) ? $decoded : ['error' => 'Invalid JSON response'];
        }
        _debug_log('GET', $urls[$key], '', $results[$key]);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $results;
}

// Like api_get but also returns the next max_id parsed from the Link response header
function api_get_linked(string $instance, string $token, string $endpoint, array $params = []): array {
    $url = rtrim($instance, '/') . $endpoint;
    if (!empty($params)) {
        $url .= '?' . preg_replace('/%5B\d+%5D/', '%5B%5D', http_build_query($params));
    }

    $ch             = curl_init();
    $resp_headers   = [];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'User-Agent: ' . SMOLFEDI_USER_AGENT,
    ]);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$resp_headers) {
        $resp_headers[] = $header;
        return strlen($header);
    });

    $body      = curl_exec($ch);
    $err       = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        $data = ['error' => $err];
        _debug_log('GET', $url, '', $data);
        return ['data' => $data, 'next_max_id' => null];
    }
    if ($http_code === 429) {
        $data = _rate_limit_error($resp_headers);
        _debug_log('GET', $url, '', $data);
        return ['data' => $data, 'next_max_id' => null];
    }

    $decoded = json_decode($body, true);
    $data    = is_array($decoded) ? $decoded : ['error' => 'Invalid JSON'];

    // Extract max_id from Link: <...?max_id=X...>; rel="next"
    $next_max_id = null;
    foreach ($resp_headers as $header) {
        if (stripos($header, 'Link:') === 0) {
            if (preg_match('/<[^>]*[?&]max_id=([^&>]+)[^>]*>;\s*rel="next"/', $header, $m)) {
                $next_max_id = preg_replace('/[^A-Za-z0-9]/', '', $m[1]);
            }
            break;
        }
    }

    _debug_log('GET', $url, '', $data);
    return ['data' => $data, 'next_max_id' => $next_max_id];
}

function api_post(string $instance, string $token, string $endpoint, array $data = []): array {
    $url = rtrim($instance, '/') . $endpoint;
    return _api_request('POST', $url, $token, $data);
}

function api_put(string $instance, string $token, string $endpoint, array $data = []): array {
    $url = rtrim($instance, '/') . $endpoint;
    return _api_request('PUT', $url, $token, $data);
}

function api_delete(string $instance, string $token, string $endpoint): array {
    $url = rtrim($instance, '/') . $endpoint;
    return _api_request('DELETE', $url, $token);
}

// DELETE with a pre-encoded body string (e.g. for list account removal)
function api_delete_with_body(string $instance, string $token, string $endpoint, string $body): array {
    $url = rtrim($instance, '/') . $endpoint;
    $ch  = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: ' . SMOLFEDI_USER_AGENT,
    ]);
    $result = curl_exec($ch);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return ['error' => $err];
    }
    $decoded = json_decode($result, true);
    return is_array($decoded) ? $decoded : [];
}

function _debug_log(string $method, string $url, string $post, array $result): void {
    if (!DEBUG_MODE) {
        return;
    }
    $_SESSION['debug'][] = [
        'ts'     => date('H:i:s'),
        'method' => $method,
        'url'    => $url,
        'post'   => $post,
        'result' => $result,
    ];
}

// Build a rate-limit error array from collected response headers
function _rate_limit_error(array $resp_headers): array {
    $limit = $remaining = $reset = null;
    foreach ($resp_headers as $h) {
        if (preg_match('/^X-RateLimit-Limit:\s*(\S+)/i', $h, $m))     $limit     = $m[1];
        if (preg_match('/^X-RateLimit-Remaining:\s*(\S+)/i', $h, $m)) $remaining = $m[1];
        if (preg_match('/^X-RateLimit-Reset:\s*(\S+)/i', $h, $m))     $reset     = $m[1];
    }
    $msg = 'Rate limited (429)';
    if ($reset !== null) {
        $ts   = strtotime($reset);
        $msg .= '. Resets at ' . ($ts ? date('H:i:s', $ts) : $reset);
    }
    if ($limit !== null || $remaining !== null) {
        $parts = [];
        if ($limit     !== null) $parts[] = 'limit: '     . $limit;
        if ($remaining !== null) $parts[] = 'remaining: ' . $remaining;
        $msg .= ' (' . implode(', ', $parts) . ')';
    }
    return ['error' => $msg];
}

// Send a POST or PUT with a pre-encoded body string (for repeated params like media_ids[])
function _api_request_raw(string $method, string $url, string $token, string $body): array {
    $ch           = curl_init();
    $resp_headers = [];
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$resp_headers) {
        $resp_headers[] = $header;
        return strlen($header);
    });
    if ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: ' . SMOLFEDI_USER_AGENT,
    ]);
    $raw       = curl_exec($ch);
    $err       = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        $result = ['error' => $err];
    } elseif ($http_code === 429) {
        $result = _rate_limit_error($resp_headers);
    } else {
        $decoded = json_decode($raw, true);
        $result  = is_array($decoded) ? $decoded : ['error' => 'Invalid JSON'];
    }

    _debug_log($method, $url, $body, $result);
    return $result;
}

function _api_request(string $method, string $url, string $token, array $data = []): array {
    $ch           = curl_init();
    $resp_headers = [];

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'User-Agent: ' . SMOLFEDI_USER_AGENT,
    ];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$resp_headers) {
        $resp_headers[] = $header;
        return strlen($header);
    });

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $body      = curl_exec($ch);
    $err       = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $post_body = (!empty($data) && in_array($method, ['POST', 'PUT'], true)) ? http_build_query($data) : '';

    if ($err) {
        $result = ['error' => $err];
    } elseif ($http_code === 429) {
        $result = _rate_limit_error($resp_headers);
    } else {
        $decoded = json_decode($body, true);
        $result  = is_array($decoded) ? $decoded : ['error' => 'Invalid JSON response'];
    }

    _debug_log($method, $url, $post_body, $result);
    return $result;
}

// PATCH /api/v1/accounts/update_credentials — multipart (supports avatar/header file upload)
// $postfields is a flat array with string keys like 'source[privacy]', 'fields_attributes[0][name]', etc.
function api_update_credentials(string $instance, string $token, array $postfields): array {
    $url          = rtrim($instance, '/') . '/api/v1/accounts/update_credentials';
    $ch           = curl_init();
    $resp_headers = [];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT * 2);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$resp_headers) {
        $resp_headers[] = $header;
        return strlen($header);
    });
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'User-Agent: ' . SMOLFEDI_USER_AGENT,
    ]);

    $raw       = curl_exec($ch);
    $err       = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        $result = ['error' => $err];
    } elseif ($http_code === 429) {
        $result = _rate_limit_error($resp_headers);
    } else {
        $decoded = json_decode($raw, true);
        $result  = is_array($decoded) ? $decoded : ['error' => 'Invalid JSON'];
    }

    $log_fields = array_filter($postfields, fn($v) => !($v instanceof CURLFile));
    _debug_log('PATCH', $url, http_build_query($log_fields), $result);
    return $result;
}

// Register app on a Mastodon-compatible instance (no token needed)
function api_register_app(string $instance, string $redirect_uri = OAUTH_REDIRECT_URI): array {
    $url = rtrim($instance, '/') . '/api/v1/apps';
    $ch  = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_name'   => APP_NAME,
        'redirect_uris' => $redirect_uri,
        'scopes'        => 'read write follow',
        'website'       => '',
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
        'User-Agent: ' . SMOLFEDI_USER_AGENT,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => $err];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['error' => 'Invalid JSON response'];
    }

    return $decoded;
}

// Exchange OAuth code for access token
function api_token_exchange(string $instance, string $client_id, string $client_secret, string $code, string $redirect_uri = OAUTH_REDIRECT_URI): array {
    $url = rtrim($instance, '/') . '/oauth/token';
    $ch  = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri'  => $redirect_uri,
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'scope'         => 'read write follow',
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
        'User-Agent: ' . SMOLFEDI_USER_AGENT,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['error' => $err];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['error' => 'Invalid JSON response'];
    }

    return $decoded;
}
