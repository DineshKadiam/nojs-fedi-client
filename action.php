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

        if ($content || !empty($media_ids)) {
            if (!empty($media_ids)) {
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
                foreach ($media_ids as $mid) {
                    $body .= '&media_ids[]=' . rawurlencode($mid);
                }
                _api_request_raw('POST', rtrim($instance, '/') . '/api/v1/statuses', $token, $body);
            } else {
                $data = [
                    'status'     => $content,
                    'visibility' => $visibility,
                ];
                if ($spoiler !== '') {
                    $data['spoiler_text'] = $spoiler;
                }
                if ($lang !== '') {
                    $data['language'] = $lang;
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

        $allowed_visibilities = ['public', 'unlisted', 'private', 'direct'];
        if (!in_array($visibility, $allowed_visibilities, true)) {
            $visibility = 'public';
        }

        if ($content && $in_reply_to) {
            api_post($instance, $token, '/api/v1/statuses', [
                'status'         => $content,
                'in_reply_to_id' => $in_reply_to,
                'visibility'     => $visibility,
            ]);
        }
        header('Location: post.php?a=' . $a . '&id=' . $in_reply_to);
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
