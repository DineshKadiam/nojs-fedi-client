<?php
declare(strict_types=1);

// Fetch and cache instance configuration in the session (per account)
function get_instance_config(string $instance, string $token, int $a): array {
    $cached = $_SESSION['accounts'][$a]['instance_config'] ?? null;
    if ($cached && isset($cached['supported_mime_types'])) {
        return $cached;
    }
    $data = api_get($instance, $token, '/api/v1/instance');
    $mime_types = $data['configuration']['statuses']['supported_mime_types'] ?? ['text/plain'];
    if (!is_array($mime_types) || empty($mime_types)) {
        $mime_types = ['text/plain'];
    }
    $config = [
        'max_characters'        => (int) ($data['configuration']['statuses']['max_characters']
                                    ?? $data['max_toot_chars']
                                    ?? 500),
        'max_media_attachments' => (int) ($data['configuration']['statuses']['max_media_attachments']
                                    ?? 4),
        'supported_mime_types'  => $mime_types,
    ];
    $_SESSION['accounts'][$a]['instance_config'] = $config;
    return $config;
}

// Fetch the user's filters, cached in session for 300 seconds
function get_filters(string $instance, string $token, int $a): array {
    $cache = $_SESSION['accounts'][$a]['filters_cache'] ?? null;
    if ($cache && (time() - $cache['ts']) < 300) {
        return $cache['data'];
    }
    $data    = api_get($instance, $token, '/api/v1/filters');
    $filters = (is_array($data) && !isset($data['error'])) ? $data : [];
    $_SESSION['accounts'][$a]['filters_cache'] = ['data' => $filters, 'ts' => time()];
    return $filters;
}

// Fetch the user's lists, cached in session for 600 seconds
function get_user_lists(string $instance, string $token, int $a): array {
    $cache = $_SESSION['accounts'][$a]['lists_cache'] ?? null;
    if ($cache && (time() - $cache['ts']) < 600) {
        return $cache['data'];
    }
    $data  = api_get($instance, $token, '/api/v1/lists');
    $lists = (is_array($data) && !isset($data['error'])) ? $data : [];
    $_SESSION['accounts'][$a]['lists_cache'] = ['data' => $lists, 'ts' => time()];
    return $lists;
}

// Upload a media file; returns the media attachment array (with 'id')
function api_upload_media(string $instance, string $token, string $file_path, string $mime_type, string $alt = ''): array {
    $url  = rtrim($instance, '/') . '/api/v2/media';
    $ch   = curl_init();
    $file = new CURLFile($file_path, $mime_type, basename($file_path));

    $fields = ['file' => $file];
    if ($alt !== '') {
        $fields['description'] = $alt;
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT * 2);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
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
    return is_array($decoded) ? $decoded : ['error' => 'Invalid JSON'];
}

function api_get(string $instance, string $token, string $endpoint, array $params = []): array {
    $url = rtrim($instance, '/') . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    return _api_request('GET', $url, $token);
}

// Like api_get but also returns the next max_id parsed from the Link response header
function api_get_linked(string $instance, string $token, string $endpoint, array $params = []): array {
    $url = rtrim($instance, '/') . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
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

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['data' => ['error' => $err], 'next_max_id' => null];
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

// Send a POST or PUT with a pre-encoded body string (for repeated params like media_ids[])
function _api_request_raw(string $method, string $url, string $token, string $body): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
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
    $result = curl_exec($ch);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($err) {
        return ['error' => $err];
    }
    $decoded = json_decode($result, true);
    return is_array($decoded) ? $decoded : ['error' => 'Invalid JSON'];
}

function _api_request(string $method, string $url, string $token, array $data = []): array {
    $ch = curl_init();

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'User-Agent: ' . SMOLFEDI_USER_AGENT,
    ];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

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

// Register app on a Mastodon-compatible instance (no token needed)
function api_register_app(string $instance): array {
    $url = rtrim($instance, '/') . '/api/v1/apps';
    $ch  = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_name'   => APP_NAME,
        'redirect_uris' => OAUTH_REDIRECT_URI,
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
function api_token_exchange(string $instance, string $client_id, string $client_secret, string $code): array {
    $url = rtrim($instance, '/') . '/oauth/token';
    $ch  = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri'  => OAUTH_REDIRECT_URI,
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
