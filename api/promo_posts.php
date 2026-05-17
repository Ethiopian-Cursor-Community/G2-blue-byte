<?php
/**
 * JSON API: homepage promo feed, view count, authenticated likes.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

startSession();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if (($_GET['feed'] ?? '') === 'homepage') {
        if (!qb_promo_posts_ready()) {
            jsonSuccess(['posts' => []], 'OK');
        }
        $sort = (string) ($_GET['sort'] ?? 'newest');
        $posts = qb_promo_posts_homepage_feed(36, $sort === 'fair' ? 'fair' : 'newest');
        $uid = isLoggedIn() ? (int) ($_SESSION['app_user_id'] ?? 0) : 0;
        $out = [];
        foreach ($posts as $p) {
            $id = (int) ($p['id'] ?? 0);
            $tagJson = isset($p['moderation_tags']) ? (string) $p['moderation_tags'] : '';
            $tags = qb_promo_decode_moderation_tags($tagJson !== '' ? $tagJson : null);
            $vd = isset($p['video_duration_seconds']) ? (int) $p['video_duration_seconds'] : 0;
            $out[] = [
                'id' => $id,
                'title' => (string) ($p['title'] ?? ''),
                'description' => (string) ($p['description'] ?? ''),
                'content_type' => (string) ($p['content_type'] ?? 'text'),
                'media_url' => (string) ($p['media_url'] ?? ''),
                'thumbnail_url' => (string) ($p['thumbnail_url'] ?? ''),
                'owner_type' => (string) ($p['owner_type'] ?? 'seller'),
                'view_count' => (int) ($p['view_count'] ?? 0),
                'like_count' => (int) ($p['like_count'] ?? 0),
                'is_sponsored' => !empty($p['is_sponsored']),
                'owner_label' => (string) ($p['owner_label'] ?? ''),
                'liked' => $uid > 0 && qb_promo_post_user_liked($id, $uid),
                'moderation_tags' => $tags,
                'video_duration_seconds' => $vd > 0 ? $vd : null,
            ];
        }
        jsonSuccess(['posts' => $out], 'OK');
    }
    jsonError('Unknown feed.', 404);
}

if ($method !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$raw = file_get_contents('php://input');
$body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($body)) {
    $body = [];
}
$action = (string) ($body['action'] ?? $_POST['action'] ?? '');

if ($action === 'view') {
    $postId = (int) ($body['post_id'] ?? 0);
    if ($postId <= 0) {
        jsonError('Invalid post.');
    }
    $seen = $_SESSION['qb_promo_views'] ?? [];
    if (!is_array($seen)) {
        $seen = [];
    }
    if (!isset($seen[$postId])) {
        qb_promo_post_record_view($postId);
        $seen[$postId] = 1;
        $_SESSION['qb_promo_views'] = $seen;
    }
    $row = db()->fetchOne('SELECT view_count FROM promo_posts WHERE id = ?', [$postId]);
    jsonSuccess(['view_count' => (int) ($row['view_count'] ?? 0)], 'OK');
}

if ($action === 'like') {
    if (!isLoggedIn()) {
        jsonError('Sign in to like promos.', 401);
    }
    if (!qb_csrf_verify($body['csrf'] ?? null)) {
        jsonError('Session expired — refresh and try again.', 403);
    }
    $postId = (int) ($body['post_id'] ?? 0);
    if ($postId <= 0) {
        jsonError('Invalid post.');
    }
    $uid = (int) ($_SESSION['app_user_id'] ?? 0);
    $r = qb_promo_post_toggle_like($postId, $uid);
    if (!$r['ok']) {
        jsonError($r['error'] ?? 'Could not update like.');
    }
    jsonSuccess([
        'liked' => $r['liked'],
        'like_count' => $r['like_count'],
    ], 'OK');
}

if ($action === 'report') {
    if (!qb_csrf_verify($body['csrf'] ?? null)) {
        jsonError('Session expired — refresh and try again.', 403);
    }
    $postId = (int) ($body['post_id'] ?? 0);
    if ($postId <= 0) {
        jsonError('Invalid post.');
    }
    $reason = (string) ($body['reason'] ?? 'other');
    if (!in_array($reason, ['spam', 'inappropriate', 'misleading', 'copyright', 'other'], true)) {
        $reason = 'other';
    }
    $note = trim((string) ($body['body'] ?? ''));
    $uid = isLoggedIn() ? (int) ($_SESSION['app_user_id'] ?? 0) : 0;
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ipHash = hash('sha256', 'promo_report|' . $ip . '|' . (defined('DB_NAME') ? DB_NAME : 'qr'));
    $r = qb_promo_post_submit_report($postId, $uid > 0 ? $uid : null, $ipHash, $reason, $note);
    if (!$r['ok']) {
        jsonError($r['error'] ?? 'Could not submit report.');
    }
    jsonSuccess([], 'Report received. Thank you.');
}

jsonError('Unknown action.', 400);
