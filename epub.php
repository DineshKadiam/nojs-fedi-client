<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/api.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/html.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
$ctx      = require_login();
$a        = current_a();
$instance = $ctx['instance'];
$token    = $ctx['access_token'];

// ── Parameters ────────────────────────────────────────────────────────────────
$type = $_GET['type'] ?? 'home';
if (!in_array($type, ['home', 'local', 'federated', 'list', 'tag'], true)) {
    $type = 'home';
}

$epub_entries          = max(10, min(EPUB_MAX_ENTRIES, (int) pref('epub_entries', 40)));
$epub_img_max_w        = max(100, min(1200, (int) pref('epub_img_max_width', 600)));
$epub_include_cw       = (bool) pref('epub_include_cw', true);
$epub_include_sensitive = (bool) pref('epub_include_sensitive', true);

// ── Endpoint, title, file slug ────────────────────────────────────────────────
$extra_params = [];
$export_title = '';
$file_slug    = 'timeline';

switch ($type) {
    case 'home':
        $endpoint     = '/api/v1/timelines/home';
        $export_title = t('home_timeline');
        $file_slug    = 'home';
        break;
    case 'local':
        $endpoint     = '/api/v1/timelines/public';
        $extra_params = ['local' => 'true'];
        $export_title = t('local_timeline');
        $file_slug    = 'local';
        break;
    case 'federated':
        $endpoint     = '/api/v1/timelines/public';
        $export_title = t('federated_timeline');
        $file_slug    = 'federated';
        break;
    case 'list':
        $list_id = preg_replace('/[^A-Za-z0-9]/', '', $_GET['list_id'] ?? '');
        if ($list_id === '') {
            http_response_code(400);
            exit(t('epub_error_missing_param'));
        }
        $endpoint     = '/api/v1/timelines/list/' . $list_id;
        $list_info    = api_get($instance, $token, '/api/v1/lists/' . $list_id);
        $export_title = $list_info['title'] ?? 'List';
        $file_slug    = 'list-' . $list_id;
        break;
    case 'tag':
        $tag = lower_ascii(preg_replace('/[^A-Za-z0-9_\p{L}]/u', '', $_GET['tag'] ?? ''));
        if ($tag === '') {
            http_response_code(400);
            exit(t('epub_error_missing_param'));
        }
        $endpoint     = '/api/v1/timelines/tag/' . rawurlencode($tag);
        $export_title = '#' . $tag;
        $file_slug    = 'tag-' . $tag;
        break;
}

// ── Fetch statuses (follow Link pagination until we have enough) ───────────────
$statuses   = [];
$page_limit = min($epub_entries, 40);
$result     = api_get_linked($instance, $token, $endpoint, array_merge($extra_params, ['limit' => $page_limit]));

if (!empty($result['data']) && !isset($result['data']['error'])) {
    $statuses = $result['data'];
}
while (count($statuses) < $epub_entries && $result['next_max_id'] !== null) {
    $remaining = $epub_entries - count($statuses);
    $result    = api_get_linked($instance, $token, $endpoint, array_merge($extra_params, [
        'limit'  => min($remaining, 40),
        'max_id' => $result['next_max_id'],
    ]));
    if (empty($result['data']) || isset($result['data']['error'])) break;
    $statuses = array_merge($statuses, $result['data']);
}
$statuses = array_slice($statuses, 0, $epub_entries);

// ── Deduplicate by actual status ID (reblog.id for boosts) ────────────────────
$seen_ids = [];
$unique   = [];
foreach ($statuses as $s) {
    $actual_id = isset($s['reblog']) ? ($s['reblog']['id'] ?? $s['id'] ?? '') : ($s['id'] ?? '');
    if ($actual_id === '' || isset($seen_ids[$actual_id])) continue;
    $seen_ids[$actual_id] = true;
    $unique[] = $s;
}
$statuses = $unique;

// Drop CW statuses when the pref says so (check the actual post, not the boost wrapper)
if (!$epub_include_cw) {
    $statuses = array_values(array_filter($statuses, function (array $s): bool {
        $post = $s['reblog'] ?? $s;
        return trim($post['spoiler_text'] ?? '') === '';
    }));
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function epub_fetch_image(string $url, int $max_width): array {
    if (!preg_match('#^https?://#i', $url)) return [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => SMOLFEDI_USER_AGENT,
    ]);
    $data      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype     = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if (!$data || $http_code !== 200) return [];

    $mime = strtolower(trim(explode(';', $ctype ?: '')[0]));
    if (!str_starts_with($mime, 'image/')) {
        // Detect from magic bytes
        if (str_starts_with($data, "\xFF\xD8\xFF"))                          $mime = 'image/jpeg';
        elseif (str_starts_with($data, "\x89PNG\r\n\x1A\n"))                $mime = 'image/png';
        elseif (str_starts_with($data, 'GIF8'))                              $mime = 'image/gif';
        elseif (str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') $mime = 'image/webp';
        else return [];
    }
    $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $ext = $ext_map[$mime] ?? null;
    if ($ext === null) return [];

    // Resize with GD if available and image is wider than max_width
    if ($max_width > 0 && extension_loaded('gd')) {
        $img = @imagecreatefromstring($data);
        if ($img !== false) {
            $w = imagesx($img);
            if ($w > $max_width) {
                $h       = imagesy($img);
                $new_h   = (int) round($h * $max_width / $w);
                $resized = imagescale($img, $max_width, $new_h, IMG_BICUBIC);
                if ($resized !== false) {
                    ob_start();
                    if ($mime === 'image/png') {
                        imagepng($resized);
                    } else {
                        imagejpeg($resized, null, 85);
                        $mime = 'image/jpeg';
                        $ext  = 'jpg';
                    }
                    $data = ob_get_clean();
                    imagedestroy($resized);
                }
            }
            imagedestroy($img);
        }
    }
    return ['data' => $data, 'mime' => $mime, 'ext' => $ext];
}

// Replace <img class="custom-emoji" alt=":shortcode:"> with the alt text
function epub_strip_custom_emoji(string $html): string {
    return preg_replace('/<img\b[^>]*\bclass="custom-emoji"[^>]*\balt="([^"]*)"[^>]*\/?>/i', '$1', $html);
}

function epub_render_poll(array $poll): string {
    $total = (int) ($poll['votes_count'] ?? 0);
    $out   = '<ul class="epub-poll">' . "\n";
    foreach (($poll['options'] ?? []) as $opt) {
        $title = htmlspecialchars($opt['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $vc    = $opt['votes_count'];
        if ($vc === null || $total === 0) {
            $bar = '?';
        } else {
            $pct    = (int) round((int) $vc * 100 / $total);
            $filled = (int) round($pct / 10);
            $bar    = str_repeat('&#x2588;', $filled) . str_repeat('&#x2591;', 10 - $filled) . ' ' . $pct . '%';
        }
        $out .= '<li>' . $title . ' ' . $bar . '</li>' . "\n";
    }
    $out .= '</ul>' . "\n";
    return $out;
}

// ── Build chapters ────────────────────────────────────────────────────────────
$chapters    = []; // ['id', 'file', 'title', 'xhtml']
$img_list    = []; // ['id', 'file', 'mime', 'data']
$img_counter = 0;

foreach ($statuses as $idx => $raw_status) {
    $status_num   = sprintf('%03d', $idx + 1);
    $chapter_id   = 's-' . $status_num;
    $chapter_file = $chapter_id . '.xhtml';

    $boosted_by = null;
    $status     = $raw_status;
    if (!empty($raw_status['reblog'])) {
        $boosted_by = $raw_status['account'];
        $status     = $raw_status['reblog'];
    }

    $account      = $status['account'] ?? [];
    $display_name = htmlspecialchars($account['display_name'] ?: ($account['username'] ?? ''), ENT_QUOTES, 'UTF-8');
    $acct         = htmlspecialchars($account['acct'] ?? '', ENT_QUOTES, 'UTF-8');
    $created      = $status['created_at'] ?? '';
    $ts           = $created ? strtotime($created) : 0;
    $human_date   = htmlspecialchars($ts ? fmt_datetime($ts) : $created, ENT_QUOTES, 'UTF-8');
    $iso8601      = htmlspecialchars($ts ? date('c', $ts) : $created, ENT_QUOTES, 'UTF-8');
    $spoiler      = trim($status['spoiler_text'] ?? '');
    $poll         = $status['poll'] ?? null;
    $attachments  = $status['media_attachments'] ?? [];
    $card         = $status['card'] ?? null;
    $url_raw      = $status['url'] ?? '';
    $language     = $status['language'] ?? '';
    $visibility   = $status['visibility'] ?? '';

    // Process content: sanitize, strip custom emoji, keep original external links
    $content = sanitize_post_html($status['content'] ?? '');
    $content = epub_strip_custom_emoji($content);

    $nav_title = '@' . ($account['acct'] ?? '') . ' · ' . ($ts ? date('Y-m-d', $ts) : $created);

    // ── XHTML document ────────────────────────────────────────────────────────
    $lang_attr = $language ? ' xml:lang="' . htmlspecialchars($language, ENT_QUOTES, 'UTF-8') . '"' : '';
    $x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $x .= '<!DOCTYPE html>' . "\n";
    $x .= '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"' . $lang_attr . '>' . "\n";
    $x .= '<head>' . "\n";
    $x .= '  <meta charset="UTF-8"/>' . "\n";
    $x .= '  <title>' . htmlspecialchars($nav_title, ENT_QUOTES, 'UTF-8') . '</title>' . "\n";
    $x .= '  <link rel="stylesheet" type="text/css" href="style.css"/>' . "\n";
    $x .= '</head>' . "\n";
    $x .= '<body>' . "\n";
    $x .= '<article epub:type="bodymatter">' . "\n";

    // Boost line
    if ($boosted_by) {
        $b_name = htmlspecialchars($boosted_by['display_name'] ?: ($boosted_by['username'] ?? ''), ENT_QUOTES, 'UTF-8');
        $b_acct = htmlspecialchars($boosted_by['acct'] ?? '', ENT_QUOTES, 'UTF-8');
        $x .= '<p class="epub-boost"><small>&#8635; ' . t('post_boosted_by') . ' ' . $b_name . ' @' . $b_acct . '</small></p>' . "\n";
    }

    // Author + timestamp header
    $vis_char = match($visibility) {
        'public'   => '(P)',
        'unlisted' => '(U)',
        'private'  => '(F)',
        'direct'   => '(@)',
        default    => '',
    };
    $x .= '<header>' . "\n";
    $x .= '<p><strong>' . $display_name . '</strong> <small>@' . $acct . '</small>';
    if ($vis_char) $x .= ' <small>' . $vis_char . '</small>';
    $x .= '</p>' . "\n";
    $x .= '<p><time datetime="' . $iso8601 . '">' . $human_date . '</time>';
    if ($url_raw && $url_raw !== '#') {
        $x .= ' <a href="' . htmlspecialchars($url_raw, ENT_QUOTES, 'UTF-8') . '">[&#128279;]</a>';
    }
    $x .= '</p>' . "\n";
    $x .= '</header>' . "\n";

    // Content warning (always shown inline — no toggle in static document)
    if ($spoiler !== '') {
        $x .= '<p class="epub-cw"><strong>' . t('post_cw_label') . '</strong> ';
        $x .= htmlspecialchars($spoiler, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";
    }

    // Post body
    $x .= '<div class="epub-content">' . "\n" . $content . "\n" . '</div>' . "\n";

    // Poll
    if ($poll) {
        $x .= epub_render_poll($poll);
    }

    // Media attachments: images embedded, video/audio as external links
    $sensitive = !empty($status['sensitive']);
    if (!empty($attachments) && ($epub_include_sensitive || !$sensitive)) {
        $x .= '<div class="epub-media">' . "\n";
        foreach (array_slice($attachments, 0, 4) as $media) {
            $mtype = $media['type'] ?? '';
            $murl  = $media['url'] ?? '';
            $alt   = htmlspecialchars($media['description'] ?? '', ENT_QUOTES, 'UTF-8');
            if (!$murl) continue;

            if ($mtype === 'image') {
                $img_counter++;
                $img_id  = 'i-' . sprintf('%03d', $img_counter);
                $fetched = epub_fetch_image($murl, $epub_img_max_w);
                if (!empty($fetched)) {
                    $img_file  = 'images/' . $img_id . '.' . $fetched['ext'];
                    $img_list[] = ['id' => $img_id, 'file' => $img_file, 'mime' => $fetched['mime'], 'data' => $fetched['data']];
                    $x .= '<figure>' . "\n";
                    $x .= '<img src="' . htmlspecialchars($img_file, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '"/>' . "\n";
                    if ($alt) $x .= '<figcaption>' . $alt . '</figcaption>' . "\n";
                    $x .= '</figure>' . "\n";
                } else {
                    $x .= '<p><a href="' . htmlspecialchars($murl, ENT_QUOTES, 'UTF-8') . '">[' . ($alt ?: t('epub_image_link')) . ']</a></p>' . "\n";
                }
            } elseif ($mtype === 'video' || $mtype === 'gifv') {
                $x .= '<p><a href="' . htmlspecialchars($murl, ENT_QUOTES, 'UTF-8') . '">[' . t('epub_video_link') . ']</a></p>' . "\n";
            } elseif ($mtype === 'audio') {
                $x .= '<p><a href="' . htmlspecialchars($murl, ENT_QUOTES, 'UTF-8') . '">[' . t('epub_audio_link') . ']</a></p>' . "\n";
            }
        }
        $x .= '</div>' . "\n";
    }

    // Link card (only when no media)
    if ($card && empty($attachments)) {
        $card_url  = $card['url'] ?? '';
        $card_ttl  = trim($card['title'] ?? '');
        if ($card_url && $card_ttl) {
            $card_desc = trim($card['description'] ?? '');
            $x .= '<aside class="epub-card">' . "\n";
            $x .= '<p><a href="' . htmlspecialchars($card_url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($card_ttl, ENT_QUOTES, 'UTF-8') . '</a></p>' . "\n";
            if ($card_desc) {
                $x .= '<p><small>' . htmlspecialchars($card_desc, ENT_QUOTES, 'UTF-8') . '</small></p>' . "\n";
            }
            $x .= '</aside>' . "\n";
        }
    }

    // Quoted post (inline blockquote, single level — no further recursion)
    $quote_raw     = $status['quote'] ?? null;
    $quoted_status = null;
    if (is_array($quote_raw)) {
        if (isset($quote_raw['quoted_status']) && is_array($quote_raw['quoted_status'])) {
            $quoted_status = $quote_raw['quoted_status'];
        } elseif (isset($quote_raw['id'])) {
            $quoted_status = $quote_raw;
        }
    }
    if (is_array($quoted_status)) {
        $q_acc    = $quoted_status['account'] ?? [];
        $q_name   = htmlspecialchars($q_acc['display_name'] ?: ($q_acc['username'] ?? ''), ENT_QUOTES, 'UTF-8');
        $q_acct   = htmlspecialchars($q_acc['acct'] ?? '', ENT_QUOTES, 'UTF-8');
        $q_ts     = strtotime($quoted_status['created_at'] ?? '') ?: 0;
        $q_date   = htmlspecialchars($q_ts ? date('Y-m-d', $q_ts) : ($quoted_status['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
        $q_sp     = trim($quoted_status['spoiler_text'] ?? '');
        $q_body   = epub_strip_custom_emoji(sanitize_post_html($quoted_status['content'] ?? ''));
        $x .= '<blockquote class="epub-quote">' . "\n";
        $x .= '<p><strong>' . $q_name . '</strong> <small>@' . $q_acct . ' · ' . $q_date . '</small></p>' . "\n";
        if ($q_sp !== '') {
            $x .= '<p><strong>' . t('post_cw_label') . '</strong> ' . htmlspecialchars($q_sp, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";
        }
        $x .= '<div>' . $q_body . '</div>' . "\n";
        $x .= '</blockquote>' . "\n";
    }

    $x .= '</article>' . "\n";
    $x .= '</body>' . "\n";
    $x .= '</html>' . "\n";

    $chapters[] = ['id' => $chapter_id, 'file' => $chapter_file, 'title' => $nav_title, 'xhtml' => $x];
}

// ── Metadata ──────────────────────────────────────────────────────────────────
$account_host  = parse_url($instance, PHP_URL_HOST) ?? $instance;
$account_label = '@' . ($ctx['account']['username'] ?? '?') . '@' . $account_host;
$export_date   = date('Y-m-d H:i');
$uid           = 'smolfedi-' . $type . '-' . time();
$modified_iso  = date('Y-m-d\TH:i:s\Z');

// ── cover.xhtml ───────────────────────────────────────────────────────────────
$cover_xhtml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<!DOCTYPE html>' . "\n"
    . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">' . "\n"
    . '<head><meta charset="UTF-8"/><title>' . htmlspecialchars($export_title, ENT_QUOTES, 'UTF-8') . '</title>'
    . '<link rel="stylesheet" type="text/css" href="style.css"/></head>' . "\n"
    . '<body epub:type="frontmatter">' . "\n"
    . '<section epub:type="titlepage">' . "\n"
    . '<h1>' . htmlspecialchars($export_title, ENT_QUOTES, 'UTF-8') . '</h1>' . "\n"
    . '<p>' . htmlspecialchars($account_label, ENT_QUOTES, 'UTF-8') . '</p>' . "\n"
    . '<p>' . htmlspecialchars($export_date, ENT_QUOTES, 'UTF-8') . '</p>' . "\n"
    . '<p>' . count($statuses) . ' ' . t('epub_status_count') . '</p>' . "\n"
    . '</section>' . "\n"
    . '</body>' . "\n"
    . '</html>' . "\n";

// ── nav.xhtml ─────────────────────────────────────────────────────────────────
$nav_items  = '<li><a href="cover.xhtml">' . htmlspecialchars(t('epub_cover'), ENT_QUOTES, 'UTF-8') . '</a></li>' . "\n";
foreach ($chapters as $ch) {
    $nav_items .= '<li><a href="' . $ch['file'] . '">' . htmlspecialchars($ch['title'], ENT_QUOTES, 'UTF-8') . '</a></li>' . "\n";
}
$nav_xhtml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<!DOCTYPE html>' . "\n"
    . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">' . "\n"
    . '<head><meta charset="UTF-8"/><title>' . htmlspecialchars(t('epub_nav_title'), ENT_QUOTES, 'UTF-8') . '</title>'
    . '<link rel="stylesheet" type="text/css" href="style.css"/></head>' . "\n"
    . '<body epub:type="frontmatter">' . "\n"
    . '<nav epub:type="toc" id="toc">' . "\n"
    . '<h1>' . htmlspecialchars(t('epub_nav_title'), ENT_QUOTES, 'UTF-8') . '</h1>' . "\n"
    . '<ol>' . "\n" . $nav_items . '</ol>' . "\n"
    . '</nav>' . "\n"
    . '</body>' . "\n"
    . '</html>' . "\n";

// ── toc.ncx (ePub 2 fallback) ─────────────────────────────────────────────────
$play_order = 1;
$nav_points = '<navPoint id="np-cover" playOrder="' . $play_order++ . '">' . "\n"
    . '  <navLabel><text>' . htmlspecialchars(t('epub_cover'), ENT_QUOTES, 'UTF-8') . '</text></navLabel>' . "\n"
    . '  <content src="cover.xhtml"/>' . "\n"
    . '</navPoint>' . "\n";
foreach ($chapters as $ch) {
    $nav_points .= '<navPoint id="np-' . $ch['id'] . '" playOrder="' . $play_order++ . '">' . "\n"
        . '  <navLabel><text>' . htmlspecialchars($ch['title'], ENT_QUOTES, 'UTF-8') . '</text></navLabel>' . "\n"
        . '  <content src="' . $ch['file'] . '"/>' . "\n"
        . '</navPoint>' . "\n";
}
$ncx = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">' . "\n"
    . '<head>' . "\n"
    . '  <meta name="dtb:uid" content="' . htmlspecialchars($uid, ENT_QUOTES, 'UTF-8') . '"/>' . "\n"
    . '  <meta name="dtb:depth" content="1"/>' . "\n"
    . '  <meta name="dtb:totalPageCount" content="0"/>' . "\n"
    . '  <meta name="dtb:maxPageNumber" content="0"/>' . "\n"
    . '</head>' . "\n"
    . '<docTitle><text>' . htmlspecialchars($export_title, ENT_QUOTES, 'UTF-8') . '</text></docTitle>' . "\n"
    . '<navMap>' . "\n" . $nav_points . '</navMap>' . "\n"
    . '</ncx>' . "\n";

// ── content.opf ───────────────────────────────────────────────────────────────
$manifest  = '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>' . "\n";
$manifest .= '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>' . "\n";
$manifest .= '<item id="css" href="style.css" media-type="text/css"/>' . "\n";
$manifest .= '<item id="cover" href="cover.xhtml" media-type="application/xhtml+xml"/>' . "\n";
foreach ($chapters as $ch) {
    $manifest .= '<item id="' . $ch['id'] . '" href="' . $ch['file'] . '" media-type="application/xhtml+xml"/>' . "\n";
}
foreach ($img_list as $img) {
    $manifest .= '<item id="' . $img['id'] . '" href="' . htmlspecialchars($img['file'], ENT_QUOTES, 'UTF-8') . '" media-type="' . $img['mime'] . '"/>' . "\n";
}

$spine  = '<itemref idref="cover"/>' . "\n";
foreach ($chapters as $ch) {
    $spine .= '<itemref idref="' . $ch['id'] . '"/>' . "\n";
}

$opf = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">' . "\n"
    . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n"
    . '  <dc:identifier id="uid">' . htmlspecialchars($uid, ENT_QUOTES, 'UTF-8') . '</dc:identifier>' . "\n"
    . '  <dc:title>' . htmlspecialchars($export_title, ENT_QUOTES, 'UTF-8') . '</dc:title>' . "\n"
    . '  <dc:creator>SmolFedi</dc:creator>' . "\n"
    . '  <dc:date>' . date('Y-m-d') . '</dc:date>' . "\n"
    . '  <dc:language>en</dc:language>' . "\n"
    . '  <meta property="dcterms:modified">' . $modified_iso . '</meta>' . "\n"
    . '</metadata>' . "\n"
    . '<manifest>' . "\n" . $manifest . '</manifest>' . "\n"
    . '<spine toc="ncx">' . "\n" . $spine . '</spine>' . "\n"
    . '</package>' . "\n";

// ── ePub stylesheet ───────────────────────────────────────────────────────────
$epub_css = <<<'CSS'
body { font-family: serif; font-size: 1em; line-height: 1.6; margin: 1em 2em; }
article { border-bottom: 1px solid #ccc; padding-bottom: 1.2em; margin-bottom: 1.2em; }
header { margin-bottom: 0.5em; }
header p { margin: 0.1em 0; }
time { color: #666; }
.epub-boost { color: #888; margin: 0 0 0.3em; }
.epub-cw { background: #f0f0f0; padding: 0.3em 0.6em; border-left: 3px solid #999; margin-bottom: 0.5em; }
.epub-content p:first-child { margin-top: 0; }
.epub-media figure { margin: 0.6em 0; }
.epub-media img { max-width: 100%; height: auto; }
.epub-media figcaption { font-size: 0.85em; color: #555; font-style: italic; }
.epub-poll { list-style: none; padding: 0; font-family: monospace; font-size: 0.9em; }
.epub-poll li { margin: 0.2em 0; }
.epub-quote { border-left: 3px solid #bbb; margin: 0.8em 0 0.8em 1em; padding-left: 0.8em; color: #444; }
.epub-quote p { margin: 0.2em 0; }
.epub-card { border: 1px solid #ccc; padding: 0.5em 0.8em; margin: 0.5em 0; }
.epub-card p { margin: 0.2em 0; }
CSS;

// ── Assemble ZIP ──────────────────────────────────────────────────────────────
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit(t('epub_error_no_zip'));
}

$tmp_file = tempnam(sys_get_temp_dir(), 'epub_');
$zip      = new ZipArchive();
$zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);

// mimetype must be the first entry in the ZIP and stored without compression
$zip->addFromString('mimetype', 'application/epub+zip');
$zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

$zip->addFromString('META-INF/container.xml',
    '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">' . "\n"
    . '  <rootfiles>' . "\n"
    . '    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>' . "\n"
    . '  </rootfiles>' . "\n"
    . '</container>' . "\n"
);

$zip->addFromString('OEBPS/content.opf',  $opf);
$zip->addFromString('OEBPS/nav.xhtml',    $nav_xhtml);
$zip->addFromString('OEBPS/toc.ncx',      $ncx);
$zip->addFromString('OEBPS/style.css',    $epub_css);
$zip->addFromString('OEBPS/cover.xhtml',  $cover_xhtml);

foreach ($chapters as $ch) {
    $zip->addFromString('OEBPS/' . $ch['file'], $ch['xhtml']);
}
foreach ($img_list as $img) {
    $zip->addFromString('OEBPS/' . $img['file'], $img['data']);
}

$zip->close();

// ── Send download response ────────────────────────────────────────────────────
$filename = 'smolfedi-' . preg_replace('/[^a-z0-9\-]/', '-', strtolower($file_slug)) . '-' . date('Y-m-d') . '.epub';

header('Content-Type: application/epub+zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmp_file));
header('Cache-Control: no-store');

readfile($tmp_file);
unlink($tmp_file);
