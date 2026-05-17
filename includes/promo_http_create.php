<?php
/**
 * Shared POST handler for seller / organizer promotion forms (create, update, draft, withdraw, appeal).
 *
 * @return array{ok:bool,error?:string,id?:int}
 */
function qb_promo_process_create_form(string $ownerType, int $ownerId, string $uploadKey): array {
    return qb_promo_process_promo_form($ownerType, $ownerId, $uploadKey);
}

/**
 * @return array{ok:bool,error?:string,id?:int}
 */
function qb_promo_process_promo_form(string $ownerType, int $ownerId, string $uploadKey): array {
    if (!qb_csrf_verify($_POST['csrf'] ?? null)) {
        return ['ok' => false, 'error' => 'Session expired. Refresh the page and try again.'];
    }
    if ($ownerId <= 0) {
        return ['ok' => false, 'error' => 'Invalid account.'];
    }
    $ot = $ownerType === 'organization' ? 'organization' : 'seller';

    $action = (string) ($_POST['promo_action'] ?? 'save');
    $postId = (int) ($_POST['promo_post_id'] ?? 0);

    if ($action === 'withdraw' && $postId > 0) {
        return qb_promo_post_withdraw($postId, $ot, $ownerId);
    }
    if ($action === 'appeal' && $postId > 0) {
        $msg = (string) ($_POST['appeal_message'] ?? '');

        return qb_promo_post_submit_appeal($postId, $ot, $ownerId, $msg);
    }

    $saveAs = (string) ($_POST['save_as'] ?? 'submit');
    $isDraft = ($saveAs === 'draft');

    $contentType = (string) ($_POST['content_type'] ?? 'text');
    if (!in_array($contentType, ['text', 'image', 'video'], true)) {
        $contentType = 'text';
    }
    $target = (string) ($_POST['target'] ?? 'homepage');
    if (!in_array($target, ['homepage', 'store', 'category'], true)) {
        $target = 'homepage';
    }

    $mediaUrl = null;
    $thumbUrl = null;
    $mediaSha = null;
    $cleanup = static function (?string $path): void {
        if ($path !== null && $path !== '' && strpos($path, 'uploads/') === 0) {
            qb_delete_upload_file($path);
        }
    };

    $existing = null;
    if ($postId > 0) {
        $existing = qb_promo_post_get_for_owner($postId, $ot, $ownerId);
        if (!$existing) {
            return ['ok' => false, 'error' => 'Promo not found or not editable.'];
        }
    }

    if ($contentType === 'image') {
        if (!empty($_FILES['promo_media']['tmp_name'])) {
            $up = qb_save_promo_post_media($_FILES['promo_media'], $uploadKey);
            if ($up['error']) {
                return ['ok' => false, 'error' => $up['error']];
            }
            if (($up['type'] ?? '') !== 'image') {
                $cleanup($up['path'] ?? null);

                return ['ok' => false, 'error' => 'Image upload must be JPG or PNG.'];
            }
            $mediaUrl = $up['path'];
            $mediaSha = $up['sha256'] ?? null;
        } elseif ($existing && ($existing['content_type'] ?? '') === 'image') {
            $mediaUrl = $existing['media_url'] ?? null;
            $mediaSha = $existing['media_sha256'] ?? null;
        } elseif (!$isDraft) {
            return ['ok' => false, 'error' => 'Please choose an image to upload.'];
        }
    } elseif ($contentType === 'video') {
        $source = (string) ($_POST['video_source'] ?? 'upload');
        if ($source === 'url') {
            $url = trim((string) ($_POST['video_url'] ?? ''));
            if ($url === '') {
                if ($isDraft && $existing) {
                    $mediaUrl = $existing['media_url'] ?? null;
                    $thumbUrl = $existing['thumbnail_url'] ?? null;
                } elseif (!$isDraft) {
                    return ['ok' => false, 'error' => 'Paste a YouTube or .mp4 URL.'];
                }
            } else {
                $mediaUrl = $url;
                if (!empty($_FILES['promo_thumbnail']['tmp_name'])) {
                    $th = qb_save_promo_post_thumbnail($_FILES['promo_thumbnail']);
                    if ($th['error']) {
                        return ['ok' => false, 'error' => $th['error']];
                    }
                    if (!empty($th['path'])) {
                        $thumbUrl = $th['path'];
                    }
                } elseif ($existing) {
                    $thumbUrl = $existing['thumbnail_url'] ?? null;
                }
            }
        } else {
            if (!empty($_FILES['promo_media']['tmp_name'])) {
                if (empty($_FILES['promo_thumbnail']['tmp_name']) && (!$existing || empty($existing['thumbnail_url']))) {
                    return ['ok' => false, 'error' => 'Please upload a poster image (JPG/PNG) for your video.'];
                }
                $up = qb_save_promo_post_media($_FILES['promo_media'], $uploadKey);
                if ($up['error']) {
                    return ['ok' => false, 'error' => $up['error']];
                }
                if (($up['type'] ?? '') !== 'video') {
                    $cleanup($up['path'] ?? null);

                    return ['ok' => false, 'error' => 'Video upload must be an MP4 file.'];
                }
                $mediaUrl = $up['path'];
                $mediaSha = $up['sha256'] ?? null;
                if (!empty($_FILES['promo_thumbnail']['tmp_name'])) {
                    $th = qb_save_promo_post_thumbnail($_FILES['promo_thumbnail']);
                    if ($th['error']) {
                        $cleanup($mediaUrl);

                        return ['ok' => false, 'error' => $th['error']];
                    }
                    if (!empty($th['path'])) {
                        $thumbUrl = $th['path'];
                    }
                } else {
                    $thumbUrl = $existing['thumbnail_url'] ?? null;
                }
            } elseif ($existing && ($existing['content_type'] ?? '') === 'video') {
                $mediaUrl = $existing['media_url'] ?? null;
                $thumbUrl = $existing['thumbnail_url'] ?? null;
                $mediaSha = $existing['media_sha256'] ?? null;
            } elseif (!$isDraft) {
                return ['ok' => false, 'error' => 'Please upload an MP4 video.'];
            }
        }
    }

    if ($contentType === 'text') {
        $mediaUrl = null;
        $thumbUrl = null;
        $mediaSha = null;
    }

    $input = [
        'owner_type' => $ot,
        'owner_id' => $ownerId,
        'content_type' => $contentType,
        'title' => $_POST['title'] ?? '',
        'description' => $_POST['description'] ?? '',
        'target' => $target,
        'expires_at' => $_POST['expires_at'] ?? '',
        'media_url' => $mediaUrl,
        'thumbnail_url' => $thumbUrl,
        'is_sponsored' => $ot === 'organization' ? 1 : 0,
        'moderation_tags' => qb_promo_normalize_moderation_tags_from_post($_POST),
        'video_duration_seconds' => $_POST['video_duration_seconds'] ?? null,
        'is_draft' => $isDraft,
        'media_sha256' => $mediaSha,
    ];

    if (!$isDraft) {
        $countsTowardLimit = ($postId <= 0) || ($existing && (($existing['status'] ?? '') === 'draft'));
        if ($countsTowardLimit && qb_promo_count_recent_submissions($ot, $ownerId) >= qb_promo_max_submissions_per_24h()) {
            return ['ok' => false, 'error' => 'Daily submission limit reached (' . qb_promo_max_submissions_per_24h() . '). Try again tomorrow or save as draft.'];
        }
    }

    if ($postId > 0) {
        $r = qb_promo_post_update_row($postId, $ot, $ownerId, $input, $isDraft);
        if (!$r['ok']) {
            $cleanup($mediaUrl);
            $cleanup($thumbUrl);
        }

        return $r['ok'] ? ['ok' => true, 'id' => (int) ($r['id'] ?? $postId)] : $r;
    }

    $created = qb_promo_post_create($input);
    if (!$created['ok']) {
        $cleanup($mediaUrl);
        $cleanup($thumbUrl);
    }

    return $created;
}
