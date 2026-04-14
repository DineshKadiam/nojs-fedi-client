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
        redirect($redirect_back . ($id ? '#status-' . $id : ''));

    case 'unfavourite':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/unfavourite');
        }
        redirect($redirect_back . ($id ? '#status-' . $id : ''));

    case 'reblog':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/reblog');
        }
        redirect($redirect_back . ($id ? '#status-' . $id : ''));

    case 'unreblog':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/unreblog');
        }
        redirect($redirect_back . ($id ? '#status-' . $id : ''));

    case 'bookmark':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/bookmark');
        }
        redirect($redirect_back . ($id ? '#status-' . $id : ''));

    case 'unbookmark':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/unbookmark');
        }
        redirect($redirect_back . ($id ? '#status-' . $id : ''));

    case 'follow':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/accounts/' . $id . '/follow');
            redirect('profile.php?a=' . $a . '&id=' . $id);
        }
        break;

    case 'unfollow':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/accounts/' . $id . '/unfollow');
            redirect('profile.php?a=' . $a . '&id=' . $id);
        }
        break;

    case 'notify_on':
    case 'notify_off':
        $id      = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        $reblogs = !empty($_POST['reblogs']);
        if ($id) {
            api_post($instance, $token, '/api/v1/accounts/' . $id . '/follow', [
                'notify'  => $action === 'notify_on' ? 'true' : 'false',
                'reblogs' => $reblogs ? 'true' : 'false',
            ]);
        }
        redirect('profile.php?a=' . $a . '&id=' . $id);

    case 'follow_authorize':
    case 'follow_reject':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            $endpoint = $action === 'follow_authorize' ? 'authorize' : 'reject';
            api_post($instance, $token, '/api/v1/follow_requests/' . $id . '/' . $endpoint);
        }
        redirect('follow_requests.php?a=' . $a);

    case 'block':
    case 'unblock':
    case 'mute':
    case 'unmute':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/accounts/' . $id . '/' . $action);
            redirect('profile.php?a=' . $a . '&id=' . $id);
        }
        break;

    case 'block_domain':
    case 'unblock_domain':
        $id     = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        if ($domain !== '' && preg_match('/^[a-z0-9.\-]+$/', $domain)) {
            if ($action === 'block_domain') {
                api_post($instance, $token, '/api/v1/domain_blocks', ['domain' => $domain]);
            } else {
                api_delete_with_body($instance, $token, '/api/v1/domain_blocks', 'domain=' . rawurlencode($domain));
            }
            unset($_SESSION['accounts'][$a]['domain_blocks_cache']);
        }
        $back = $id ? 'profile.php?a=' . $a . '&id=' . $id : ($referer ?: 'prefs.php?a=' . $a);
        redirect($back);

    case 'mute_conversation':
    case 'unmute_conversation':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            $endpoint = $action === 'mute_conversation' ? 'mute' : 'unmute';
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/' . $endpoint);
        }
        redirect($redirect_back . ($id ? '#status-' . $id : ''));

    case 'open_report':
        // From profile: "id" is the account id, no account_id field.
        // From a post: "id" is the status id, "account_id" is the author id.
        $raw_account = preg_replace('/[^A-Za-z0-9]/', '', $_POST['account_id'] ?? '');
        $raw_id      = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($raw_account !== '') {
            $account_id = $raw_account;
            $status_id  = $raw_id;
        } else {
            $account_id = $raw_id;
            $status_id  = '';
        }
        $qs = 'a=' . $a . '&id=' . $account_id;
        if ($status_id !== '') {
            $qs .= '&status_id=' . $status_id;
        }
        redirect('report.php?' . $qs);

    case 'report':
        $id        = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        $status_id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['status_id'] ?? '');
        $comment   = trim($_POST['comment'] ?? '');
        $forward   = !empty($_POST['forward']);
        if ($id) {
            $body = 'account_id=' . rawurlencode($id);
            if ($comment !== '') {
                $body .= '&comment=' . rawurlencode(substr($comment, 0, 1000));
            }
            if ($status_id !== '') {
                $body .= '&status_ids[]=' . rawurlencode($status_id);
            }
            $body .= '&forward=' . ($forward ? 'true' : 'false');
            _api_request_raw('POST', rtrim($instance, '/') . '/api/v1/reports', $token, $body);
            redirect('profile.php?a=' . $a . '&id=' . $id);
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
        redirect('index.php?a=' . $a);

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
        redirect('post.php?a=' . $a . '&id=' . $in_reply_to);

    case 'edit':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        redirect('compose.php?a=' . $a . '&edit=' . $id);

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
        redirect('post.php?a=' . $a . '&id=' . $id);

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
        redirect($status_id ? 'post.php?a=' . $a . '&id=' . $status_id : $redirect_back);

    case 'list_add':
        $list_id    = preg_replace('/[^A-Za-z0-9]/', '', $_POST['list_id'] ?? '');
        $account_id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['account_id'] ?? '');
        if ($list_id && $account_id) {
            _api_request_raw('POST', rtrim($instance, '/') . '/api/v1/lists/' . $list_id . '/accounts', $token, 'account_ids[]=' . rawurlencode($account_id));
        }
        redirect('profile.php?a=' . $a . '&id=' . $account_id);

    case 'list_remove':
        $list_id    = preg_replace('/[^A-Za-z0-9]/', '', $_POST['list_id'] ?? '');
        $account_id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['account_id'] ?? '');
        if ($list_id && $account_id) {
            api_delete_with_body($instance, $token, '/api/v1/lists/' . $list_id . '/accounts', 'account_ids[]=' . rawurlencode($account_id));
        }
        redirect('profile.php?a=' . $a . '&id=' . $account_id);

    case 'open_post':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        redirect('post.php?a=' . $a . '&id=' . $id);

    case 'pin':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/pin');
        }
        redirect($redirect_back);

    case 'unpin':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_post($instance, $token, '/api/v1/statuses/' . $id . '/unpin');
        }
        redirect($redirect_back);

    case 'clear_notifications':
        api_post($instance, $token, '/api/v1/notifications/clear');
        redirect('notifications.php?a=' . $a);

    case 'set_notif_filter':
        $allowed = ['mention', 'reblog', 'favourite', 'follow', 'poll', 'update', 'status', 'follow_request'];
        $posted  = $_POST['types'] ?? [];
        if (!is_array($posted)) { $posted = []; }
        $selected = array_values(array_intersect($allowed, $posted));
        $_SESSION['accounts'][$a]['notif_filter'] = $selected;
        redirect('notifications.php?a=' . $a);

    case 'confirm_delete':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            $base = strtok($redirect_back, '#');
            $sep  = (strpos($base, '?') !== false) ? '&' : '?';
            redirect($base . $sep . 'confirm_delete=' . $id . '#status-' . $id);
        } else {
            redirect($redirect_back);
        }

    case 'delete':
        $id = preg_replace('/[^A-Za-z0-9]/', '', $_POST['id'] ?? '');
        if ($id) {
            api_delete($instance, $token, '/api/v1/statuses/' . $id);
        }
        redirect('index.php?a=' . $a);

    default:
        break;
}

redirect('index.php?a=' . $a);
