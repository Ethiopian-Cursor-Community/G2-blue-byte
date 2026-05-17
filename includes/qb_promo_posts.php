<?php
/**
 * User promo posts (sellers & organizers): CRUD helpers, homepage feed, likes/views.
 */
declare(strict_types=1);

function qb_promo_posts_ready(): bool {
    return function_exists('qb_table_exists') && qb_table_exists('promo_posts');
}

function qb_youtube_id_from_url(string $url): ?string {
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    $patterns = [
        '~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([a-zA-Z0-9_-]{11})~',
        '~youtube\.com/shorts/([a-zA-Z0-9_-]{11})~',
    ];
    foreach ($patterns as $re) {
        if (preg_match($re, $url, $m)) {
            return $m[1];
        }
    }

    return null;
}

function qb_youtube_thumb(string $videoId): string {
    return 'https://i.ytimg.com/vi/' . rawurlencode($videoId) . '/hqdefault.jpg';
}

/**
 * Short offer/discount line for promo cards (title + description). Optional explicit line: "OFFER: …" in text.
 */
function qb_promo_offer_hint(string $title, string $description): string {
    $blob = trim($title . "\n" . $description);
    if ($blob === '') {
        return '';
    }
    if (preg_match('/\bOFFER\s*:\s*(.+)$/im', $blob, $m)) {
        $line = trim((string) preg_replace('/\s+/', ' ', $m[1]));

        return $line !== '' && strlen($line) <= 96 ? $line : (strlen($line) > 96 ? substr($line, 0, 93) . '…' : '');
    }
    if (preg_match('/\b(?:up to\s*)?(\d{1,3}\s*%\s*(?:off|OFF))\b/i', $blob, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/\b(save\s+\$?\d[\d,]*)\b/i', $blob, $m)) {
        return ucfirst(strtolower($m[1]));
    }
    if (preg_match('/\b(\d{1,3}\s*%\s*markdown)\b/i', $blob, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/\b(stacked\s+offers?|extra\s+\d{1,3}\s*%|buy\s+\d+\s*get\s+\d+)\b/i', $blob, $m)) {
        return trim($m[0]);
    }

    return '';
}

/** True if media_url looks like http(s) stream or YouTube, not a local uploads path. */
function qb_promo_external_media_url(?string $url): bool {
    if ($url === null || $url === '') {
        return false;
    }
    return (bool) preg_match('~^https?://~i', $url);
}

/** Whitelist of moderation tag slugs stored as JSON in moderation_tags. */
function qb_promo_moderation_tag_whitelist(): array {
    return [
        'family_friendly',
        'flash_sale',
        'new_arrival',
        'event_highlight',
        'limited_time',
        'featured_request',
    ];
}

/**
 * Normalize POST checkboxes into sorted JSON array string, or null if empty.
 */
function qb_promo_normalize_moderation_tags_from_post(array $post): ?string {
    $raw = $post['moderation_tags'] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }
    $allowed = array_flip(qb_promo_moderation_tag_whitelist());
    $out = [];
    foreach ($raw as $t) {
        $t = is_string($t) ? trim($t) : '';
        if ($t !== '' && isset($allowed[$t])) {
            $out[] = $t;
        }
    }
    $out = array_values(array_unique($out));
    sort($out);
    if ($out === []) {
        return null;
    }

    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/** @return int|null seconds 0–7200, or null if unset */
function qb_promo_parse_video_duration_seconds(mixed $raw): ?int {
    if ($raw === null || $raw === '') {
        return null;
    }
    $n = (int) $raw;
    if ($n < 0) {
        $n = 0;
    }
    if ($n > 7200) {
        $n = 7200;
    }

    return $n;
}

/** @return list<string> */
function qb_promo_decode_moderation_tags(?string $json): array {
    if ($json === null || $json === '') {
        return [];
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return [];
    }
    $allowed = array_flip(qb_promo_moderation_tag_whitelist());
    $out = [];
    foreach ($d as $t) {
        if (is_string($t) && isset($allowed[$t])) {
            $out[] = $t;
        }
    }

    return array_values(array_unique($out));
}

function qb_promo_moderation_tag_label(string $slug): string {
    $map = [
        'family_friendly' => 'Family-friendly',
        'flash_sale' => 'Flash sale',
        'new_arrival' => 'New arrival',
        'event_highlight' => 'Event highlight',
        'limited_time' => 'Limited time',
        'featured_request' => 'Featured request',
    ];

    return $map[$slug] ?? $slug;
}

/** Format seconds as M:SS or H:MM:SS for display. */
function qb_promo_format_duration(int $sec): string {
    if ($sec <= 0) {
        return '0:00';
    }
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }

    return sprintf('%d:%02d', $m, $s);
}

/** Admin rejection reason codes → labels (shown to creators). */
function qb_promo_rejection_codes(): array {
    return [
        'policy' => 'Does not meet content policy',
        'quality' => 'Quality / clarity',
        'media' => 'Media issue (format, size, or broken link)',
        'spam' => 'Spam or misleading',
        'duplicate' => 'Duplicate submission',
        'other' => 'Other (see note)',
    ];
}

function qb_promo_max_submissions_per_24h(): int {
    $v = qb_setting_get_int('promo_daily_submission_limit', 10);
    if ($v < 1) {
        $v = 1;
    }
    if ($v > 100) {
        $v = 100;
    }
    return $v;
}

/** Hostnames (lowercase) blocked in promo video/image URLs. */
function qb_promo_blocked_url_hosts(): array {
    return [
        'bit.ly',
        'tinyurl.com',
        'adf.ly',
        'malware.example.invalid',
    ];
}

function qb_promo_url_has_blocked_host(string $url): bool {
    $url = trim($url);
    if ($url === '' || !qb_promo_external_media_url($url)) {
        return false;
    }
    $p = @parse_url($url);
    $host = isset($p['host']) ? strtolower((string) $p['host']) : '';
    if ($host === '') {
        return false;
    }
    foreach (qb_promo_blocked_url_hosts() as $b) {
        if ($host === $b || ($b !== '' && substr($host, -strlen('.' . $b)) === '.' . $b)) {
            return true;
        }
    }

    return false;
}

function qb_promo_count_recent_submissions(string $ownerType, int $ownerId): int {
    if (!qb_promo_posts_ready() || $ownerId <= 0) {
        return 0;
    }
    $ot = $ownerType === 'organization' ? 'organization' : 'seller';
    try {
        $r = db()->fetchOne(
            "SELECT COUNT(*) AS c FROM promo_posts
             WHERE owner_type = ? AND owner_id = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
               AND status NOT IN ('draft','withdrawn')",
            [$ot, $ownerId]
        );

        return (int) ($r['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** Another post by same owner with same file hash (uploads). */
function qb_promo_find_duplicate_media(string $ownerType, int $ownerId, ?string $sha256, ?int $excludeId = null): ?int {
    if (!qb_promo_posts_ready() || $ownerId <= 0 || $sha256 === null || strlen($sha256) !== 64) {
        return null;
    }
    if (!qb_has_column('promo_posts', 'media_sha256')) {
        return null;
    }
    $ot = $ownerType === 'organization' ? 'organization' : 'seller';
    try {
        $sql = 'SELECT id FROM promo_posts WHERE owner_type = ? AND owner_id = ? AND media_sha256 = ?';
        $params = [$ot, $ownerId, $sha256];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $row = db()->fetchOne($sql, $params);

        return $row ? (int) ($row['id'] ?? 0) : null;
    } catch (Throwable $e) {
        return null;
    }
}

function qb_promo_post_reports_ready(): bool {
    return qb_table_exists('promo_post_reports');
}

function qb_promo_post_get(int $id): ?array {
    if (!qb_promo_posts_ready() || $id <= 0) {
        return null;
    }
    try {
        $r = db()->fetchOne('SELECT * FROM promo_posts WHERE id = ?', [$id]);

        return $r ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function qb_promo_post_get_for_owner(int $id, string $ownerType, int $ownerId): ?array {
    $row = qb_promo_post_get($id);
    if (!$row) {
        return null;
    }
    $ot = $ownerType === 'organization' ? 'organization' : 'seller';
    if (($row['owner_type'] ?? '') !== $ot || (int) ($row['owner_id'] ?? 0) !== $ownerId) {
        return null;
    }

    return $row;
}

/**
 * Compact HTML for admin queue preview (text snippet, image, YouTube iframe, or video).
 */
function qb_promo_admin_media_preview_html(array $row): string {
    $ctype = (string) ($row['content_type'] ?? 'text');
    $media = trim((string) ($row['media_url'] ?? ''));
    $thumb = trim((string) ($row['thumbnail_url'] ?? ''));

    if ($ctype === 'text') {
        $d = trim(strip_tags((string) ($row['description'] ?? '')));
        if ($d === '') {
            $d = '(no description)';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            $snippet = mb_strlen($d) > 140 ? mb_substr($d, 0, 137) . '…' : $d;
        } else {
            $snippet = strlen($d) > 140 ? substr($d, 0, 137) . '…' : $d;
        }

        return '<div class="qb-admin-promo-prev qb-admin-promo-prev--text">' . qb_esc_html($snippet) . '</div>';
    }

    $mediaPub = $media !== '' ? (qb_promo_external_media_url($media) ? $media : qb_public_upload_url($media)) : '';
    $thumbPub = $thumb !== '' ? (qb_promo_external_media_url($thumb) ? $thumb : qb_public_upload_url($thumb)) : '';

    if ($ctype === 'image' && $mediaPub !== '') {
        return '<div class="qb-admin-promo-prev"><img src="' . htmlspecialchars($mediaPub, ENT_QUOTES, 'UTF-8') . '" alt="" class="qb-admin-promo-prev__img" loading="lazy" decoding="async"/></div>';
    }

    if ($ctype === 'video') {
        $yt = $media !== '' ? qb_youtube_id_from_url($media) : null;
        if ($yt !== null) {
            $embed = 'https://www.youtube-nocookie.com/embed/' . htmlspecialchars($yt, ENT_QUOTES, 'UTF-8') . '?rel=0';

            return '<div class="qb-admin-promo-prev"><iframe class="qb-admin-promo-prev__iframe" src="' . $embed . '" loading="lazy" title="" allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';
        }
        if ($mediaPub !== '' && (preg_match('~\.mp4(\?|$)~i', $media) || strpos($media, 'uploads/') === 0)) {
            return '<div class="qb-admin-promo-prev"><video class="qb-admin-promo-prev__video" controls muted playsinline preload="metadata" src="' . htmlspecialchars($mediaPub, ENT_QUOTES, 'UTF-8') . '"></video></div>';
        }
        if ($thumbPub !== '') {
            return '<div class="qb-admin-promo-prev"><img src="' . htmlspecialchars($thumbPub, ENT_QUOTES, 'UTF-8') . '" alt="" class="qb-admin-promo-prev__img" loading="lazy" decoding="async"/></div>';
        }
    }

    return '<span class="text-muted text-xs">—</span>';
}

/**
 * Save optional thumbnail (jpg/png) for promo post under uploads/promo_posts/thumbs/.
 *
 * @return array{error:?string,path:?string}
 */
function qb_save_promo_post_thumbnail(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['error' => null, 'path' => null];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'Thumbnail upload failed.', 'path' => null];
    }
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['error' => 'Invalid thumbnail.', 'path' => null];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return ['error' => 'Thumbnail must be JPG or PNG.', 'path' => null];
    }
    if (($file['size'] ?? 0) > QB_UPLOAD_MAX_IMAGE_BYTES) {
        return ['error' => 'Thumbnail is too large.', 'path' => null];
    }
    $ext = $mime === 'image/jpeg' ? 'jpg' : 'png';
    $dir = qb_uploads_fs_root() . DIRECTORY_SEPARATOR . 'promo_posts' . DIRECTORY_SEPARATOR . 'thumbs';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['error' => 'Could not create upload folder.', 'path' => null];
    }
    $name = 'thumb_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['error' => 'Could not save thumbnail.', 'path' => null];
    }

    return ['error' => null, 'path' => 'uploads/promo_posts/thumbs/' . $name];
}

/**
 * Save image or MP4 under uploads/promo_posts/{ownerKey}/.
 *
 * @return array{error:?string,path:?string,type?:string}
 */
function qb_save_promo_post_media(array $file, string $ownerKey): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload failed.', 'path' => null, 'type' => null];
    }
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['error' => 'Invalid upload.', 'path' => null, 'type' => null];
    }
    $ownerKey = preg_replace('/[^a-z0-9_-]/i', '', $ownerKey);
    if ($ownerKey === '') {
        $ownerKey = 'misc';
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $type = null;
    $ext = null;
    $max = QB_UPLOAD_MAX_IMAGE_BYTES;
    if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
        $type = 'image';
        $ext = $mime === 'image/jpeg' ? 'jpg' : 'png';
    } elseif ($mime === 'video/mp4') {
        $type = 'video';
        $ext = 'mp4';
        $max = QB_UPLOAD_MAX_VIDEO_BYTES;
    }
    if ($type === null || $ext === null) {
        return ['error' => 'Use JPG, PNG, or MP4 only.', 'path' => null, 'type' => null];
    }
    if (($file['size'] ?? 0) > $max) {
        return ['error' => 'File is too large.', 'path' => null, 'type' => null];
    }
    $dir = qb_uploads_fs_root() . DIRECTORY_SEPARATOR . 'promo_posts' . DIRECTORY_SEPARATOR . $ownerKey;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['error' => 'Could not create upload folder.', 'path' => null, 'type' => null];
    }
    $name = 'post_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['error' => 'Could not save file.', 'path' => null, 'type' => null];
    }
    $rel = 'uploads/promo_posts/' . $ownerKey . '/' . $name;
    $sha = @hash_file('sha256', $dest);

    return ['error' => null, 'path' => $rel, 'type' => $type, 'sha256' => is_string($sha) ? $sha : null];
}

/**
 * @return list<array<string,mixed>>
 */
function qb_promo_posts_homepage_feed(int $limit = 24, string $sort = 'newest'): array {
    if (!qb_promo_posts_ready()) {
        return [];
    }
    $limit = max(1, min(60, $limit));
    $sort = $sort === 'fair' ? 'fair' : 'newest';
    $order = $sort === 'fair'
        ? 'p.is_sponsored DESC, MD5(CONCAT(DATE(NOW()), p.id, p.owner_type, p.owner_id)) ASC, p.created_at DESC'
        : 'p.is_sponsored DESC, p.created_at DESC';
    $sql = "SELECT p.*,
        CASE p.owner_type
          WHEN 'seller' THEN (SELECT s.market_name FROM sellers s WHERE s.id = p.owner_id LIMIT 1)
          WHEN 'organization' THEN (SELECT u.display_name FROM app_users u WHERE u.id = p.owner_id LIMIT 1)
        END AS owner_label
        FROM promo_posts p
        WHERE p.target = 'homepage'
          AND p.status = 'active'
          AND (p.expires_at IS NULL OR p.expires_at > NOW())
        ORDER BY {$order}
        LIMIT {$limit}";

    try {
        return db()->fetchAll($sql);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return list<array<string,mixed>>
 */
function qb_promo_posts_by_owner(string $ownerType, int $ownerId): array {
    if (!qb_promo_posts_ready() || $ownerId <= 0) {
        return [];
    }
    $ot = $ownerType === 'organization' ? 'organization' : 'seller';

    try {
        return db()->fetchAll(
            'SELECT * FROM promo_posts WHERE owner_type = ? AND owner_id = ? ORDER BY id DESC',
            [$ot, $ownerId]
        );
    } catch (Throwable $e) {
        return [];
    }
}

function qb_promo_post_user_liked(int $postId, int $appUserId): bool {
    if (!qb_table_exists('promo_post_likes') || $postId <= 0 || $appUserId <= 0) {
        return false;
    }
    $row = db()->fetchOne(
        'SELECT 1 FROM promo_post_likes WHERE post_id = ? AND app_user_id = ?',
        [$postId, $appUserId]
    );

    return $row !== null;
}

/**
 * @return array{ok:bool,liked?:bool,like_count?:int,error?:string}
 */
function qb_promo_post_toggle_like(int $postId, int $appUserId): array {
    if (!qb_promo_posts_ready() || !qb_table_exists('promo_post_likes')) {
        return ['ok' => false, 'error' => 'Unavailable.'];
    }
    if ($postId <= 0 || $appUserId <= 0) {
        return ['ok' => false, 'error' => 'Invalid request.'];
    }
    $post = db()->fetchOne('SELECT id, status FROM promo_posts WHERE id = ?', [$postId]);
    if (!$post || ($post['status'] ?? '') !== 'active') {
        return ['ok' => false, 'error' => 'Promo not found.'];
    }
    $exists = db()->fetchOne(
        'SELECT 1 FROM promo_post_likes WHERE post_id = ? AND app_user_id = ?',
        [$postId, $appUserId]
    );
    if ($exists) {
        db()->execute('DELETE FROM promo_post_likes WHERE post_id = ? AND app_user_id = ?', [$postId, $appUserId]);
        db()->execute('UPDATE promo_posts SET like_count = GREATEST(0, like_count - 1) WHERE id = ?', [$postId]);
        $liked = false;
    } else {
        try {
            db()->execute(
                'INSERT INTO promo_post_likes (post_id, app_user_id) VALUES (?, ?)',
                [$postId, $appUserId]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not save like.'];
        }
        db()->execute('UPDATE promo_posts SET like_count = like_count + 1 WHERE id = ?', [$postId]);
        $liked = true;
    }
    $cnt = db()->fetchOne('SELECT like_count FROM promo_posts WHERE id = ?', [$postId]);

    return ['ok' => true, 'liked' => $liked, 'like_count' => (int) ($cnt['like_count'] ?? 0)];
}

function qb_promo_post_record_view(int $postId): void {
    if (!qb_promo_posts_ready() || $postId <= 0) {
        return;
    }
    try {
        db()->execute(
            'UPDATE promo_posts SET view_count = view_count + 1 WHERE id = ? AND status = \'active\'',
            [$postId]
        );
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * @return array{ok:bool,id?:int,error?:string}
 */
function qb_promo_post_create(array $input): array {
    if (!qb_promo_posts_ready()) {
        return ['ok' => false, 'error' => 'Run install/migrate_promo_posts.php first.'];
    }
    $ownerType = ($input['owner_type'] ?? '') === 'organization' ? 'organization' : 'seller';
    $ownerId = (int) ($input['owner_id'] ?? 0);
    if ($ownerId <= 0) {
        return ['ok' => false, 'error' => 'Invalid owner.'];
    }
    $isDraft = !empty($input['is_draft']);
    $contentType = $input['content_type'] ?? 'text';
    if (!in_array($contentType, ['text', 'image', 'video'], true)) {
        $contentType = 'text';
    }
    $title = qb_sanitize_plain_text((string) ($input['title'] ?? ''), 200);
    $description = trim((string) ($input['description'] ?? ''));
    if (strlen($description) > 8000) {
        $description = substr($description, 0, 8000);
    }
    $target = $input['target'] ?? 'homepage';
    if (!in_array($target, ['homepage', 'store', 'category'], true)) {
        $target = 'homepage';
    }
    $expiresAt = null;
    if (!empty($input['expires_at'])) {
        $ts = strtotime((string) $input['expires_at']);
        if ($ts !== false) {
            $expiresAt = date('Y-m-d H:i:s', $ts);
        }
    }
    if ($expiresAt === null) {
        $days = qb_setting_get_int('promo_expiry_days', 7);
        $expiresAt = date('Y-m-d H:i:s', time() + ($days * 86400));
    }
    $mediaUrl = isset($input['media_url']) ? trim((string) $input['media_url']) : '';
    if ($mediaUrl === '') {
        $mediaUrl = null;
    } elseif (strlen($mediaUrl) > 512) {
        return ['ok' => false, 'error' => 'Media URL is too long.'];
    }
    $thumbUrl = isset($input['thumbnail_url']) ? trim((string) $input['thumbnail_url']) : '';
    if ($thumbUrl === '') {
        $thumbUrl = null;
    } elseif (strlen($thumbUrl) > 512) {
        return ['ok' => false, 'error' => 'Thumbnail URL is too long.'];
    }

    $mediaSha = null;
    if (isset($input['media_sha256']) && is_string($input['media_sha256'])) {
        $h = trim($input['media_sha256']);
        if (strlen($h) === 64 && preg_match('/^[a-f0-9]{64}$/i', $h)) {
            $mediaSha = strtolower($h);
        }
    }

    if ($title === '') {
        return ['ok' => false, 'error' => 'Title is required.'];
    }

    if (!$isDraft && $mediaUrl !== null && qb_promo_external_media_url($mediaUrl) && qb_promo_url_has_blocked_host($mediaUrl)) {
        return ['ok' => false, 'error' => 'This media URL uses a blocked shortlink or unsafe host. Use a direct link.'];
    }

    if ($isDraft) {
        if ($contentType === 'text') {
            $mediaUrl = null;
            $thumbUrl = null;
        } elseif ($contentType === 'image') {
            /* optional media for draft */
        } else {
            $yt = $mediaUrl !== null ? qb_youtube_id_from_url($mediaUrl) : null;
            if ($yt !== null) {
                $mediaUrl = 'https://www.youtube.com/watch?v=' . $yt;
                if ($thumbUrl === null) {
                    $thumbUrl = qb_youtube_thumb($yt);
                }
            }
        }
    } elseif ($contentType === 'text') {
        if (strlen(trim(strip_tags($description))) < 8) {
            return ['ok' => false, 'error' => 'Please enter a longer description (at least 8 characters).'];
        }
        $mediaUrl = null;
        $thumbUrl = null;
    } elseif ($contentType === 'image') {
        if ($mediaUrl === null || !qb_promo_external_media_url($mediaUrl)) {
            if ($mediaUrl === null || strpos($mediaUrl, 'uploads/') !== 0) {
                return ['ok' => false, 'error' => 'Image upload is required.'];
            }
        }
    } else {
        $yt = $mediaUrl !== null ? qb_youtube_id_from_url($mediaUrl) : null;
        if ($yt !== null) {
            $mediaUrl = 'https://www.youtube.com/watch?v=' . $yt;
            if ($thumbUrl === null) {
                $thumbUrl = qb_youtube_thumb($yt);
            }
        } elseif ($mediaUrl !== null && qb_promo_external_media_url($mediaUrl)) {
            if (!preg_match('~\.mp4(\?|$)~i', $mediaUrl) && $yt === null) {
                return ['ok' => false, 'error' => 'Video URL must be a YouTube link or direct .mp4 URL.'];
            }
        } elseif ($mediaUrl === null || strpos((string) $mediaUrl, 'uploads/') !== 0) {
            return ['ok' => false, 'error' => 'Upload a video or provide a valid video URL.'];
        }
        if ($thumbUrl === null && $mediaUrl !== null && strpos((string) $mediaUrl, 'uploads/') === 0) {
            return ['ok' => false, 'error' => 'Add a poster thumbnail for uploaded video (image).'];
        }
    }

    if (!$isDraft && $mediaSha !== null) {
        $dup = qb_promo_find_duplicate_media($ownerType, $ownerId, $mediaSha, null);
        if ($dup !== null) {
            return ['ok' => false, 'error' => 'This file matches another promo you already submitted (#' . $dup . '). Use a different file or edit that promo.'];
        }
    }

    $isSponsored = $ownerType === 'organization' ? 1 : (int) (!empty($input['is_sponsored']));

    $tagsJson = null;
    if (isset($input['moderation_tags']) && is_string($input['moderation_tags']) && $input['moderation_tags'] !== '') {
        $dec = qb_promo_decode_moderation_tags($input['moderation_tags']);
        $tagsJson = $dec === [] ? null : json_encode($dec, JSON_UNESCAPED_UNICODE);
    }

    $videoDur = null;
    if ($contentType === 'video') {
        $parsed = qb_promo_parse_video_duration_seconds($input['video_duration_seconds'] ?? null);
        if ($parsed !== null && $parsed > 0) {
            $videoDur = $parsed;
        }
    }

    $status = $isDraft ? 'draft' : 'pending';

    $cols = [
        'title', 'description', 'content_type', 'media_url', 'thumbnail_url',
        'owner_type', 'owner_id', 'target', 'status', 'expires_at', 'is_sponsored',
    ];
    $vals = [
        $title,
        $description !== '' ? $description : null,
        $contentType,
        $mediaUrl,
        $thumbUrl,
        $ownerType,
        $ownerId,
        $target,
        $status,
        $expiresAt,
        $isSponsored,
    ];
    if (qb_has_column('promo_posts', 'moderation_tags')) {
        $cols[] = 'moderation_tags';
        $vals[] = $tagsJson;
    }
    if (qb_has_column('promo_posts', 'video_duration_seconds')) {
        $cols[] = 'video_duration_seconds';
        $vals[] = $videoDur;
    }
    if (qb_has_column('promo_posts', 'media_sha256')) {
        $cols[] = 'media_sha256';
        $vals[] = $mediaSha;
    }
    if (qb_has_column('promo_posts', 'submitted_at')) {
        $cols[] = 'submitted_at';
        $vals[] = $isDraft ? null : date('Y-m-d H:i:s');
    }
    if (qb_has_column('promo_posts', 'video_transcode_status')) {
        $cols[] = 'video_transcode_status';
        $vals[] = (!$isDraft && $contentType === 'video') ? 'skipped' : null;
    }

    try {
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $id = db()->insert(
            'INSERT INTO promo_posts (' . implode(',', $cols) . ') VALUES (' . $ph . ')',
            $vals
        );

        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save promotion.'];
    }
}

/** Approve, reject, or set status (admin). Rejection codes use qb_promo_rejection_codes() keys. */
function qb_promo_post_set_status(
    int $id,
    string $status,
    int $adminUserId,
    ?string $rejectionCode = null,
    ?string $rejectionNote = null
): bool {
    if (!qb_promo_posts_ready() || $id <= 0) {
        return false;
    }
    if (!in_array($status, ['active', 'pending', 'rejected', 'draft', 'withdrawn', 'flagged'], true)) {
        return false;
    }
    $code = $rejectionCode !== null ? trim($rejectionCode) : '';
    $note = $rejectionNote !== null ? trim($rejectionNote) : '';
    if (strlen($note) > 2000) {
        $note = substr($note, 0, 2000);
    }
    $allowedCodes = array_keys(qb_promo_rejection_codes());
    if ($status === 'rejected' && $code !== '' && !in_array($code, $allowedCodes, true)) {
        $code = 'other';
    }

    try {
        if ($status === 'active') {
            db()->execute(
                'UPDATE promo_posts SET status = ?, reviewed_by = ?, reviewed_at = NOW(),
                 rejection_code = NULL, rejection_note = NULL, appeal_message = NULL, appealed_at = NULL
                 WHERE id = ?',
                [$status, $adminUserId, $id]
            );
        } elseif ($status === 'rejected') {
            db()->execute(
                'UPDATE promo_posts SET status = ?, reviewed_by = ?, reviewed_at = NOW(),
                 rejection_code = ?, rejection_note = ?
                 WHERE id = ?',
                [$status, $adminUserId, $code !== '' ? $code : null, $note !== '' ? $note : null, $id]
            );
        } else {
            db()->execute(
                'UPDATE promo_posts SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
                [$status, $adminUserId, $id]
            );
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return list<array<string,mixed>>
 */
function qb_promo_posts_pending_queue(): array {
    if (!qb_promo_posts_ready()) {
        return [];
    }
    try {
        return db()->fetchAll(
            "SELECT p.*,
            CASE p.owner_type
              WHEN 'seller' THEN (SELECT CONCAT('Seller: ', COALESCE(s.market_name,'#',s.id)) FROM sellers s WHERE s.id = p.owner_id LIMIT 1)
              WHEN 'organization' THEN (SELECT CONCAT('Org user: ', COALESCE(u.display_name,'#',u.id)) FROM app_users u WHERE u.id = p.owner_id LIMIT 1)
            END AS owner_label
            FROM promo_posts p
            WHERE p.status = 'pending'
            ORDER BY p.created_at ASC"
        );
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Public URL for spotlight slide media (uploads path or absolute http(s)).
 */
function qb_spotlight_resolve_media_url(array $row): string {
    $m = trim((string) ($row['media_url'] ?? ''));
    if ($m === '') {
        return '';
    }
    if (qb_promo_external_media_url($m)) {
        return $m;
    }

    return qb_public_upload_url($m);
}

/**
 * Admin event_promotions (buyer audience) + approved homepage community promos for one carousel.
 *
 * @return list<array<string,mixed>>
 */
function qb_fetch_homepage_spotlight_slides(): array {
    $admin = qb_fetch_active_promos_for('buyer');
    $slides = [];
    foreach ($admin as $r) {
        $r['_spotlight_source'] = 'admin';
        $slides[] = $r;
    }
    if (!qb_promo_posts_ready()) {
        return $slides;
    }
    try {
        $extra = db()->fetchAll(
            "SELECT id, title, description, content_type, media_url, thumbnail_url, is_sponsored, created_at
             FROM promo_posts
             WHERE status = 'active' AND target = 'homepage'
               AND (expires_at IS NULL OR expires_at > NOW())
               AND content_type IN ('text','image')
             ORDER BY is_sponsored DESC, created_at DESC
             LIMIT 24"
        );
    } catch (Throwable $e) {
        return $slides;
    }
    foreach ($extra as $p) {
        $slides[] = [
            'id' => 'pp_' . (int) ($p['id'] ?? 0),
            'title' => (string) ($p['title'] ?? ''),
            'description' => (string) ($p['description'] ?? ''),
            'media_url' => (string) ($p['media_url'] ?? ''),
            'thumbnail_url' => (string) ($p['thumbnail_url'] ?? ''),
            'media_type' => (string) ($p['content_type'] ?? 'image'),
            'marquee_text' => null,
            'sort_order' => 999,
            '_spotlight_source' => 'community',
        ];
    }

    return $slides;
}

/** First absolute image URL suitable for og:image from spotlight slides. */
function qb_spotlight_first_image_og_url(array $slides): string {
    foreach ($slides as $s) {
        $mt = (string) ($s['media_type'] ?? '');
        if ($mt === 'text') {
            continue;
        }
        if ($mt === 'video') {
            $u = trim((string) ($s['media_url'] ?? ''));
            $yt = qb_youtube_id_from_url($u);
            if ($yt !== null) {
                return qb_youtube_thumb($yt);
            }
            $th = trim((string) ($s['thumbnail_url'] ?? ''));
            if ($th !== '') {
                return qb_spotlight_resolve_media_url(['media_url' => $th]);
            }

            continue;
        }
        $raw = (string) ($s['media_url'] ?? '');
        $url = qb_spotlight_resolve_media_url($s);
        if ($url === '') {
            continue;
        }
        if (!qb_promo_external_media_url($raw)) {
            return $url;
        }
        if (preg_match('~\.(jpe?g|png|webp)(\\?|$)~i', $url)) {
            return $url;
        }
    }

    return '';
}

/**
 * @return array{ok:bool,error?:string}
 */
function qb_promo_post_update_row(int $postId, string $ownerType, int $ownerId, array $input, bool $isDraft): array {
    $row = qb_promo_post_get_for_owner($postId, $ownerType, $ownerId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Promo not found.'];
    }
    $st = (string) ($row['status'] ?? '');
    if (!in_array($st, ['draft', 'pending'], true)) {
        return ['ok' => false, 'error' => 'Only draft or pending promos can be edited.'];
    }
    $ot = $ownerType === 'organization' ? 'organization' : 'seller';
    $input['_update_id'] = $postId;
    $input['owner_type'] = $ot;
    $input['owner_id'] = $ownerId;
    $input['is_draft'] = $isDraft;

    $contentType = $input['content_type'] ?? 'text';
    if (!in_array($contentType, ['text', 'image', 'video'], true)) {
        $contentType = 'text';
    }
    $title = qb_sanitize_plain_text((string) ($input['title'] ?? ''), 200);
    $description = trim((string) ($input['description'] ?? ''));
    if (strlen($description) > 8000) {
        $description = substr($description, 0, 8000);
    }
    $target = $input['target'] ?? 'homepage';
    if (!in_array($target, ['homepage', 'store', 'category'], true)) {
        $target = 'homepage';
    }
    $expiresAt = null;
    if (!empty($input['expires_at'])) {
        $ts = strtotime((string) $input['expires_at']);
        if ($ts !== false) {
            $expiresAt = date('Y-m-d H:i:s', $ts);
        }
    } else {
        $expiresAt = $row['expires_at'] ?: null;
    }

    if ($expiresAt === null) {
        $days = qb_setting_get_int('promo_expiry_days', 7);
        $expiresAt = date('Y-m-d H:i:s', time() + ($days * 86400));
    }
    $mediaUrl = isset($input['media_url']) ? trim((string) $input['media_url']) : '';
    $mediaUrl = $mediaUrl === '' ? null : $mediaUrl;
    $thumbUrl = isset($input['thumbnail_url']) ? trim((string) $input['thumbnail_url']) : '';
    $thumbUrl = $thumbUrl === '' ? null : $thumbUrl;

    $mediaSha = null;
    if (isset($input['media_sha256']) && is_string($input['media_sha256'])) {
        $h = trim($input['media_sha256']);
        if (strlen($h) === 64 && preg_match('/^[a-f0-9]{64}$/i', $h)) {
            $mediaSha = strtolower($h);
        }
    }

    if ($title === '') {
        return ['ok' => false, 'error' => 'Title is required.'];
    }

    if (!$isDraft && $mediaUrl !== null && qb_promo_external_media_url($mediaUrl) && qb_promo_url_has_blocked_host($mediaUrl)) {
        return ['ok' => false, 'error' => 'This media URL uses a blocked shortlink or unsafe host.'];
    }

    if ($isDraft) {
        if ($contentType === 'text') {
            $mediaUrl = null;
            $thumbUrl = null;
        } elseif ($contentType === 'video' && $mediaUrl !== null) {
            $yt = qb_youtube_id_from_url($mediaUrl);
            if ($yt !== null) {
                $mediaUrl = 'https://www.youtube.com/watch?v=' . $yt;
                if ($thumbUrl === null) {
                    $thumbUrl = qb_youtube_thumb($yt);
                }
            }
        }
    } elseif ($contentType === 'text') {
        if (strlen(trim(strip_tags($description))) < 8) {
            return ['ok' => false, 'error' => 'Description must be at least 8 characters.'];
        }
        $mediaUrl = null;
        $thumbUrl = null;
    } elseif ($contentType === 'image') {
        if ($mediaUrl === null || (!qb_promo_external_media_url($mediaUrl) && strpos($mediaUrl, 'uploads/') !== 0)) {
            return ['ok' => false, 'error' => 'Image is required.'];
        }
    } else {
        $yt = $mediaUrl !== null ? qb_youtube_id_from_url($mediaUrl) : null;
        if ($yt !== null) {
            $mediaUrl = 'https://www.youtube.com/watch?v=' . $yt;
            if ($thumbUrl === null) {
                $thumbUrl = qb_youtube_thumb($yt);
            }
        } elseif ($mediaUrl !== null && qb_promo_external_media_url($mediaUrl)) {
            if (!preg_match('~\.mp4(\?|$)~i', $mediaUrl)) {
                return ['ok' => false, 'error' => 'Video URL must be YouTube or .mp4.'];
            }
        } elseif ($mediaUrl === null || strpos((string) $mediaUrl, 'uploads/') !== 0) {
            return ['ok' => false, 'error' => 'Video file or URL required.'];
        }
        if ($thumbUrl === null && $mediaUrl !== null && strpos((string) $mediaUrl, 'uploads/') === 0) {
            return ['ok' => false, 'error' => 'Poster image required for uploaded video.'];
        }
    }

    if (!$isDraft && $mediaSha !== null) {
        $dup = qb_promo_find_duplicate_media($ot, $ownerId, $mediaSha, $postId);
        if ($dup !== null) {
            return ['ok' => false, 'error' => 'This file matches another promo (#' . $dup . ').'];
        }
    }

    $tagsJson = null;
    if (isset($input['moderation_tags']) && is_string($input['moderation_tags']) && $input['moderation_tags'] !== '') {
        $dec = qb_promo_decode_moderation_tags($input['moderation_tags']);
        $tagsJson = $dec === [] ? null : json_encode($dec, JSON_UNESCAPED_UNICODE);
    }
    $videoDur = null;
    if ($contentType === 'video') {
        $parsed = qb_promo_parse_video_duration_seconds($input['video_duration_seconds'] ?? null);
        if ($parsed !== null && $parsed > 0) {
            $videoDur = $parsed;
        }
    }

    $newStatus = $isDraft ? 'draft' : 'pending';
    $isSponsored = $ot === 'organization' ? 1 : (int) (!empty($input['is_sponsored']));

    try {
        $sets = [
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'content_type' => $contentType,
            'media_url' => $mediaUrl,
            'thumbnail_url' => $thumbUrl,
            'target' => $target,
            'status' => $newStatus,
            'expires_at' => $expiresAt,
            'is_sponsored' => $isSponsored,
        ];
        if (qb_has_column('promo_posts', 'moderation_tags')) {
            $sets['moderation_tags'] = $tagsJson;
        }
        if (qb_has_column('promo_posts', 'video_duration_seconds')) {
            $sets['video_duration_seconds'] = $videoDur;
        }
        if (qb_has_column('promo_posts', 'media_sha256')) {
            $sets['media_sha256'] = $mediaSha;
        }
        if (qb_has_column('promo_posts', 'submitted_at')) {
            $sets['submitted_at'] = $isDraft ? null : date('Y-m-d H:i:s');
        }
        if (qb_has_column('promo_posts', 'video_transcode_status')) {
            $sets['video_transcode_status'] = (!$isDraft && $contentType === 'video') ? 'skipped' : null;
        }

        $sql = 'UPDATE promo_posts SET ';
        $parts = [];
        $bind = [];
        foreach ($sets as $k => $v) {
            $parts[] = $k . ' = ?';
            $bind[] = $v;
        }
        $sql .= implode(', ', $parts) . ' WHERE id = ? AND owner_type = ? AND owner_id = ?';
        $bind[] = $postId;
        $bind[] = $ot;
        $bind[] = $ownerId;

        db()->execute($sql, $bind);

        return ['ok' => true, 'id' => $postId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update promotion.'];
    }
}

/**
 * @return array{ok:bool,error?:string,id?:int}
 */
function qb_promo_post_withdraw(int $postId, string $ownerType, int $ownerId): array {
    $row = qb_promo_post_get_for_owner($postId, $ownerType, $ownerId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Promo not found.'];
    }
    if (($row['status'] ?? '') !== 'pending') {
        return ['ok' => false, 'error' => 'Only pending promos can be withdrawn.'];
    }
    try {
        db()->execute(
            'UPDATE promo_posts SET status = \'withdrawn\', updated_at = NOW() WHERE id = ?',
            [$postId]
        );

        return ['ok' => true, 'id' => $postId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not withdraw.'];
    }
}

/**
 * Resubmit rejected promo with appeal note (returns to pending).
 *
 * @return array{ok:bool,error?:string,id?:int}
 */
function qb_promo_post_submit_appeal(int $postId, string $ownerType, int $ownerId, string $appealMessage): array {
    $row = qb_promo_post_get_for_owner($postId, $ownerType, $ownerId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Promo not found.'];
    }
    if (($row['status'] ?? '') !== 'rejected') {
        return ['ok' => false, 'error' => 'Only rejected promos can be appealed.'];
    }
    $msg = trim($appealMessage);
    if (strlen($msg) < 8) {
        return ['ok' => false, 'error' => 'Please add a short appeal message (at least 8 characters).'];
    }
    if (strlen($msg) > 2000) {
        $msg = substr($msg, 0, 2000);
    }
    try {
        if (qb_has_column('promo_posts', 'appeal_message')) {
            db()->execute(
                'UPDATE promo_posts SET status = \'pending\', appeal_message = ?, appealed_at = NOW(),
                 submitted_at = COALESCE(submitted_at, NOW())
                 WHERE id = ?',
                [$msg, $postId]
            );
        } else {
            db()->execute(
                'UPDATE promo_posts SET status = \'pending\' WHERE id = ?',
                [$postId]
            );
        }

        return ['ok' => true, 'id' => $postId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not submit appeal.'];
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function qb_promo_post_submit_report(int $postId, ?int $reporterUserId, string $ipHash, string $reason, string $body): array {
    if (!qb_promo_posts_ready() || !qb_promo_post_reports_ready() || $postId <= 0) {
        return ['ok' => false, 'error' => 'Reporting unavailable.'];
    }
    $reasons = ['spam', 'inappropriate', 'misleading', 'copyright', 'other'];
    if (!in_array($reason, $reasons, true)) {
        $reason = 'other';
    }
    $body = trim($body);
    if (strlen($body) > 2000) {
        $body = substr($body, 0, 2000);
    }
    $post = qb_promo_post_get($postId);
    if (!$post) {
        return ['ok' => false, 'error' => 'Promo not found.'];
    }
    if (in_array(($post['status'] ?? ''), ['draft', 'withdrawn', 'rejected'], true)) {
        return ['ok' => false, 'error' => 'This promo cannot be reported.'];
    }

    try {
        $rid = ($reporterUserId !== null && $reporterUserId > 0) ? $reporterUserId : null;
        if ($rid !== null) {
            $dup = db()->fetchOne(
                'SELECT id FROM promo_post_reports WHERE post_id = ? AND reporter_app_user_id = ? LIMIT 1',
                [$postId, $rid]
            );
        } else {
            $dup = db()->fetchOne(
                'SELECT id FROM promo_post_reports WHERE post_id = ? AND reporter_ip_hash = ? LIMIT 1',
                [$postId, $ipHash]
            );
        }
        if ($dup) {
            return ['ok' => false, 'error' => 'You already reported this promo.'];
        }

        db()->execute(
            'INSERT INTO promo_post_reports (post_id, reporter_app_user_id, reporter_ip_hash, reason, body, status) VALUES (?,?,?,?,?,\'open\')',
            [$postId, $rid, $rid === null ? $ipHash : null, $reason, $body !== '' ? $body : null]
        );

        if (qb_has_column('promo_posts', 'report_count')) {
            db()->execute('UPDATE promo_posts SET report_count = report_count + 1 WHERE id = ?', [$postId]);
        }
        $st = (string) ($post['status'] ?? '');
        if ($st === 'active' || $st === 'pending') {
            db()->execute('UPDATE promo_posts SET status = \'flagged\' WHERE id = ? AND status IN (\'active\',\'pending\')', [$postId]);
        }

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not submit report.'];
    }
}

/**
 * @return list<array<string,mixed>>
 */
function qb_promo_posts_flagged_queue(): array {
    if (!qb_promo_posts_ready()) {
        return [];
    }
    try {
        return db()->fetchAll(
            "SELECT p.*,
            CASE p.owner_type
              WHEN 'seller' THEN (SELECT CONCAT('Seller: ', COALESCE(s.market_name,'#',s.id)) FROM sellers s WHERE s.id = p.owner_id LIMIT 1)
              WHEN 'organization' THEN (SELECT CONCAT('Org user: ', COALESCE(u.display_name,'#',u.id)) FROM app_users u WHERE u.id = p.owner_id LIMIT 1)
            END AS owner_label
            FROM promo_posts p
            WHERE p.status = 'flagged'
            ORDER BY p.report_count DESC, p.updated_at DESC, p.id DESC"
        );
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @param array{type?:string,q?:string} $filters
 * @return list<array<string,mixed>>
 */
function qb_promo_posts_pending_queue_filtered(array $filters = []): array {
    if (!qb_promo_posts_ready()) {
        return [];
    }
    $type = isset($filters['type']) ? (string) $filters['type'] : '';
    $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
    $w = ["p.status = 'pending'"];
    $params = [];
    if ($type !== '' && in_array($type, ['text', 'image', 'video'], true)) {
        $w[] = 'p.content_type = ?';
        $params[] = $type;
    }
    if ($q !== '') {
        $w[] = '(p.title LIKE ? OR p.description LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $where = implode(' AND ', $w);
    try {
        return db()->fetchAll(
            "SELECT p.*,
            CASE p.owner_type
              WHEN 'seller' THEN (SELECT CONCAT('Seller: ', COALESCE(s.market_name,'#',s.id)) FROM sellers s WHERE s.id = p.owner_id LIMIT 1)
              WHEN 'organization' THEN (SELECT CONCAT('Org user: ', COALESCE(u.display_name,'#',u.id)) FROM app_users u WHERE u.id = p.owner_id LIMIT 1)
            END AS owner_label
            FROM promo_posts p
            WHERE {$where}
            ORDER BY p.created_at ASC",
            $params
        );
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Detect expired active promotions, mark them as 'ended', and notify owners.
 */
function qb_promo_sync_expiry_notifications(): void {
    if (!qb_promo_posts_ready()) return;

    try {
        $expired = db()->fetchAll(
            "SELECT id, owner_type, owner_id, title FROM promo_posts 
             WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW() 
             LIMIT 50"
        );

        foreach ($expired as $p) {
            $id = (int)$p['id'];
            $ot = $p['owner_type'];
            $oid = (int)$p['owner_id'];

            db()->execute("UPDATE promo_posts SET status = 'ended' WHERE id = ?", [$id]);

            $userId = 0;
            if ($ot === 'seller') {
                $s = db()->fetchOne("SELECT app_user_id FROM sellers WHERE id = ?", [$oid]);
                $userId = (int)($s['app_user_id'] ?? 0);
            } else {
                $userId = $oid;
            }

            if ($userId > 0 && function_exists('createNotification')) {
                createNotification(
                    $userId,
                    'system',
                    'Promotion Expired: ' . $p['title'],
                    'Your promotion has expired. You can purchase additional days to keep it visible to buyers.',
                    'promotion_create.php?edit=' . $id
                );
            }
        }
    } catch (Throwable $e) {
        // Silently fail
    }
}
