<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';

$ctx = require_login();
csrf_verify();

$a        = current_a();
$instance = $ctx['instance'];
$token    = $ctx['access_token'];
$action   = $_POST['action'] ?? '';

// Determine redirect target (Post/Redirect/Get)
$referer = $_SERVER['HTTP_REFERER'] ?? 'index.php?a=' . $a;
$redirect_back = $referer;

switch ($action) {
    case 'favourite':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/favourite');
        }
        header('Location: ' . $redirect_back . ($id ? '#status-' . $id : ''));
        exit;

    case 'unfavourite':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/unfavourite');
        }
        header('Location: ' . $redirect_back . ($id ? '#status-' . $id : ''));
        exit;

    case 'reblog':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/reblog');
        }
        header('Location: ' . $redirect_back . ($id ? '#status-' . $id : ''));
        exit;

    case 'unreblog':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/unreblog');
        }
        header('Location: ' . $redirect_back . ($id ? '#status-' . $id : ''));
        exit;

    case 'bookmark':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/bookmark');
        }
        header('Location: ' . $redirect_back . ($id ? '#status-' . $id : ''));
        exit;

    case 'unbookmark':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/unbookmark');
        }
        header('Location: ' . $redirect_back . ($id ? '#status-' . $id : ''));
        exit;

    case 'follow':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/accounts/' . $id . '/follow');
            header('Location: profile.php?a=' . $a . '&id=' . $id);
            exit;
        }
        break;

    case 'unfollow':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/accounts/' . $id . '/unfollow');
            header('Location: profile.php?a=' . $a . '&id=' . $id);
            exit;
        }
        break;

    case 'post':
        $content    = trim($_POST['content'] ?? '');
        $visibility = $_POST['visibility'] ?? 'public';
        $spoiler    = trim($_POST['spoiler_text'] ?? '');
        $lang_raw   = $_POST['lang'] ?? '';
        $lang       = preg_match('/^[a-z]{2}$/', $lang_raw) ? $lang_raw : '';

        $allowed_visibilities = ['public', 'unlisted', 'private', 'direct'];
        if (!in_array($visibility, $allowed_visibilities, true)) {
            $visibility = 'public';
        }

        $content_type_raw = $_POST['content_type'] ?? '';
        $content_type  = str_starts_with($content_type_raw, 'text/') ? $content_type_raw : '';
        $sensitive     = !empty($_POST['sensitive']);

        // Upload media attachments
        $media_ids = [];
        $alt_texts = $_POST['media_alt'] ?? [];
        $files     = $_FILES['media'] ?? [];
        if (!empty($files['tmp_name'])) {
            foreach ($files['tmp_name'] as $i => $tmp) {
                if (empty($tmp) || $files['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $mime   = mime_content_type($tmp) ?: 'application/octet-stream';
                $alt    = trim($alt_texts[$i] ?? '');
                $result = api_upload_media($instance, $token, $tmp, $mime, $alt);
                if (!empty($result['id'])) {
                    $media_ids[] = $result['id'];
                }
            }
        }

        // Poll options
        $poll_options = array_values(array_filter(array_map(
            fn($o) => trim($o),
            (array) ($_POST['poll_options'] ?? [])
        )));
        $poll_expires  = (int) ($_POST['poll_expires_in'] ?? 86400);
        $poll_multiple = !empty($_POST['poll_multiple']);
        $poll_hide     = !empty($_POST['poll_hide_totals']);
        $has_poll      = count($poll_options) >= 2;

        if ($content || !empty($media_ids) || $has_poll) {
            if ($has_poll) {
                $body = http_build_query([
                    'status'     => $content,
                    'visibility' => $visibility,
                ]);
                if ($spoiler !== '') {
                    $body .= '&' . http_build_query(['spoiler_text' => $spoiler]);
                }
                if ($lang !== '') {
                    $body .= '&' . http_build_query(['language' => $lang]);
                }
                if ($content_type !== '') {
                    $body .= '&' . http_build_query(['content_type' => $content_type]);
                }
                foreach ($poll_options as $opt) {
                    $body .= '&poll[options][]=' . rawurlencode($opt);
                }
                $body .= '&poll[expires_in]=' . $poll_expires;
                $body .= '&poll[multiple]=' . ($poll_multiple ? 'true' : 'false');
                $body .= '&poll[hide_totals]=' . ($poll_hide ? 'true' : 'false');
                _api_request_raw('POST', rtrim($instance, '/') . '/api/v1/statuses', $token, $body);
            } elseif (!empty($media_ids)) {
                $body = http_build_query([
                    'status'     => $content,
                    'visibility' => $visibility,
                    'sensitive'  => $sensitive ? 'true' : 'false',
                ]);
                if ($spoiler !== '') {
                    $body .= '&' . http_build_query(['spoiler_text' => $spoiler]);
                }
                if ($lang !== '') {
                    $body .= '&' . http_build_query(['language' => $lang]);
                }
                if ($content_type !== '') {
                    $body .= '&' . http_build_query(['content_type' => $content_type]);
                }
                foreach ($media_ids as $mid) {
                    $body .= '&media_ids[]=' . rawurlencode($mid);
                }
                _api_request_raw('POST', rtrim($instance, '/') . '/api/v1/statuses', $token, $body);
            } else {
                $data = [
                    'status'     => $content,
                    'visibility' => $visibility,
                    'sensitive'  => $sensitive ? 'true' : 'false',
                ];
                if ($spoiler !== '') {
                    $data['spoiler_text'] = $spoiler;
                }
                if ($lang !== '') {
                    $data['language'] = $lang;
                }
                if ($content_type !== '') {
                    $data['content_type'] = $content_type;
                }
                api_post($instance, $token, '/api/v1/statuses', $data);
            }
        }
        header('Location: index.php?a=' . $a);
        exit;

    case 'reply':
        $in_reply_to = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        $content     = trim($_POST['content'] ?? '');
        $visibility  = $_POST['visibility'] ?? 'public';
        $lang_raw    = $_POST['lang'] ?? '';
        $lang        = preg_match('/^[a-z]{2}$/', $lang_raw) ? $lang_raw : '';
        $ct_raw      = $_POST['content_type'] ?? '';
        $content_type = str_starts_with($ct_raw, 'text/') ? $ct_raw : '';

        $allowed_visibilities = ['public', 'unlisted', 'private', 'direct'];
        if (!in_array($visibility, $allowed_visibilities, true)) {
            $visibility = 'public';
        }

        if ($content && $in_reply_to) {
            $data = [
                'status'         => $content,
                'in_reply_to_id' => $in_reply_to,
                'visibility'     => $visibility,
            ];
            if ($lang !== '') {
                $data['language'] = $lang;
            }
            if ($content_type !== '') {
                $data['content_type'] = $content_type;
            }
            api_post($instance, $token, '/api/v1/statuses', $data);
        }
        header('Location: post.php?a=' . $a . '&id=' . $in_reply_to);
        exit;

    case 'edit':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        header('Location: compose.php?a=' . $a . '&edit=' . $id);
        exit;

    case 'edit_save':
        $id           = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        $content      = trim($_POST['content'] ?? '');
        $spoiler      = trim($_POST['spoiler_text'] ?? '');
        $lang_raw     = $_POST['lang'] ?? '';
        $lang         = preg_match('/^[a-z]{2}$/', $lang_raw) ? $lang_raw : '';
        $ct_raw       = $_POST['content_type'] ?? '';
        $content_type = str_starts_with($ct_raw, 'text/') ? $ct_raw : '';
        $sensitive    = !empty($_POST['sensitive']);

        $sanitize_id = fn($v) => preg_replace('/[^A-Za-z0-9]/', '', $v);
        $all_ids     = array_filter(array_map($sanitize_id, (array) ($_POST['all_media_ids'] ?? [])));
        $remove_ids  = array_flip(array_filter(array_map($sanitize_id, (array) ($_POST['remove_media'] ?? []))));
        $kept_ids    = array_values(array_filter($all_ids, fn($mid) => !isset($remove_ids[$mid])));
        $alt_texts   = $_POST['media_alt'] ?? [];

        if ($id && $content) {
            $body = http_build_query([
                'status'    => $content,
                'sensitive' => $sensitive ? 'true' : 'false',
            ]);
            if ($spoiler !== '') {
                $body .= '&' . http_build_query(['spoiler_text' => $spoiler]);
            }
            if ($lang !== '') {
                $body .= '&' . http_build_query(['language' => $lang]);
            }
            if ($content_type !== '') {
                $body .= '&' . http_build_query(['content_type' => $content_type]);
            }
            foreach ($kept_ids as $mid) {
                $body .= '&media_ids[]=' . rawurlencode($mid);
                $alt = trim($alt_texts[$mid] ?? '');
                api_put($instance, $token, '/api/v1/media/' . $mid, ['description' => $alt]);
            }
            _api_request_raw('PUT', rtrim($instance, '/') . '/api/v1/statuses/' . $id, $token, $body);
        }
        header('Location: post.php?a=' . $a . '&id=' . $id);
        exit;

    case 'poll_vote':
        $poll_id   = preg_replace('/[^A-Za-z0-9]/', '', $_POST['poll_id'] ?? '');
        $status_id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['status_id'] ?? '');
        $choices   = $_POST['choices'] ?? [];
        if ($poll_id && is_array($choices)) {
            $query = '';
            foreach ($choices as $c) {
                $query .= '&choices[]=' . (int) $c;
            }
            _api_request_raw('POST', rtrim($instance, '/') . '/api/v1/polls/' . $poll_id . '/votes', $token, ltrim($query, '&'));
        }
        header('Location: ' . ($status_id ? 'post.php?a=' . $a . '&id=' . $status_id : $redirect_back));
        exit;

    case 'list_add':
        $list_id    = preg_replace('/[^A-Za-z0-9]/', '', $_POST['list_id'] ?? '');
        $account_id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['account_id'] ?? '');
        if ($list_id && $account_id) {
            _api_request_raw('POST', rtrim($instance, '/') . '/api/v1/lists/' . $list_id . '/accounts', $token, 'account_ids[]=' . rawurlencode($account_id));
        }
        header('Location: profile.php?a=' . $a . '&id=' . $account_id);
        exit;

    case 'list_remove':
        $list_id    = preg_replace('/[^A-Za-z0-9]/', '', $_POST['list_id'] ?? '');
        $account_id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['account_id'] ?? '');
        if ($list_id && $account_id) {
            api_delete_with_body($instance, $token, '/api/v1/lists/' . $list_id . '/accounts', 'account_ids[]=' . rawurlencode($account_id));
        }
        header('Location: profile.php?a=' . $a . '&id=' . $account_id);
        exit;

    case 'open_post':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        header('Location: post.php?a=' . $a . '&id=' . $id);
        exit;

    case 'pin':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/pin');
        }
        header('Location: ' . $redirect_back);
        exit;

    case 'unpin':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/unpin');
        }
        header('Location: ' . $redirect_back);
        exit;

    case 'clear_notifications':
        api_post($instance, $token, '/api/v1/notifications/clear');
        header('Location: notifications.php?a=' . $a);
        exit;

    case 'confirm_delete':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            $base = strtok($redirect_back, '#');
            $sep  = (strpos($base, '?') !== false) ? '&' : '?';
            header('Location: ' . $base . $sep . 'confirm_delete=' . $id . '#status-' . $id);
        } else {
            header('Location: ' . $redirect_back);
        }
        exit;

    case 'delete':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_delete($instance, $token, '/api/v1/statuses/' . $id);
        }
        header('Location: index.php?a=' . $a);
        exit;

    default:
        break;
}

header('Location: index.php?a=' . $a);
exit;
