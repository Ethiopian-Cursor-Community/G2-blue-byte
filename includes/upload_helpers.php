<?php
/**
 * Image uploads for products/profile (stored under /uploads).
 */

define('QB_UPLOAD_MAX_PNG_BYTES', 2 * 1024 * 1024);

/**
 * @return array{error:?string,ext:?string}
 */
function qb_validate_image_upload(array $file, int $maxBytes = QB_UPLOAD_MAX_PNG_BYTES): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload failed.', 'ext' => null];
    }
    if (($file['size'] ?? 0) > $maxBytes) {
        return ['error' => 'Image must be 2MB or smaller.', 'ext' => null];
    }
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['error' => 'Invalid upload.', 'ext' => null];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $map = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/avif' => 'avif',
    ];
    $ext = $map[$mime] ?? null;
    if ($ext === null) {
        return ['error' => 'Use JPG, JPEG, PNG, WEBP, GIF, BMP, or AVIF.', 'ext' => null];
    }

    return ['error' => null, 'ext' => $ext];
}

function qb_uploads_fs_root(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
}

function qb_public_upload_url(string $relativePath): string {
    $rel = str_replace('\\', '/', ltrim($relativePath, '/'));
    return rtrim(str_replace(' ', '%20', APP_URL), '/') . '/' . $rel;
}

/**
 * Save image under uploads/products/{sellerId}/ — returns relative path for DB.
 */
function qb_save_product_png(array $file, int $sellerId): array {
    $v = qb_validate_image_upload($file, QB_UPLOAD_MAX_PNG_BYTES);
    if ($v['error'] !== null) {
        return ['error' => $v['error'], 'path' => null];
    }
    $ext = (string) ($v['ext'] ?? 'png');
    $dir = qb_uploads_fs_root() . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . $sellerId;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['error' => 'Could not create upload folder.', 'path' => null];
    }
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['error' => 'Could not save file.', 'path' => null];
    }
    $rel = 'uploads/products/' . $sellerId . '/' . $name;
    return ['error' => null, 'path' => $rel];
}

/**
 * Save seller profile image under uploads/sellers/{sellerId}/ (unique name).
 */
function qb_save_seller_profile_png(array $file, int $sellerId): array {
    $v = qb_validate_image_upload($file, QB_UPLOAD_MAX_PNG_BYTES);
    if ($v['error'] !== null) {
        return ['error' => $v['error'], 'path' => null];
    }
    $ext = (string) ($v['ext'] ?? 'png');
    $dir = qb_uploads_fs_root() . DIRECTORY_SEPARATOR . 'sellers' . DIRECTORY_SEPARATOR . $sellerId;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['error' => 'Could not create upload folder.', 'path' => null];
    }
    $name = 'profile_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['error' => 'Could not save file.', 'path' => null];
    }
    $rel = 'uploads/sellers/' . $sellerId . '/' . $name;
    return ['error' => null, 'path' => $rel];
}

/**
 * Save buyer (or any app user) avatar image under uploads/users/{userId}/.
 */
function qb_save_user_avatar_png(array $file, int $userId): array {
    $v = qb_validate_image_upload($file, QB_UPLOAD_MAX_PNG_BYTES);
    if ($v['error'] !== null) {
        return ['error' => $v['error'], 'path' => null];
    }
    $ext = (string) ($v['ext'] ?? 'png');
    $dir = qb_uploads_fs_root() . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . $userId;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['error' => 'Could not create upload folder.', 'path' => null];
    }
    $name = 'avatar_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['error' => 'Could not save file.', 'path' => null];
    }
    $rel = 'uploads/users/' . $userId . '/' . $name;
    return ['error' => null, 'path' => $rel];
}

define('QB_UPLOAD_MAX_IMAGE_BYTES', 4 * 1024 * 1024);
define('QB_UPLOAD_MAX_VIDEO_BYTES', 20 * 1024 * 1024);

/**
 * Save JPG/PNG cover for an event under uploads/events/{eventId}/.
 */
function qb_save_event_cover(array $file, int $eventId): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload failed.', 'path' => null];
    }
    if (($file['size'] ?? 0) > QB_UPLOAD_MAX_IMAGE_BYTES) {
        return ['error' => 'Image must be 4MB or smaller.', 'path' => null];
    }
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['error' => 'Invalid upload.', 'path' => null];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
    if ($ext === null) {
        return ['error' => 'Only JPEG or PNG images are allowed for cover.', 'path' => null];
    }
    $dir = qb_uploads_fs_root() . DIRECTORY_SEPARATOR . 'events' . DIRECTORY_SEPARATOR . $eventId;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['error' => 'Could not create upload folder.', 'path' => null];
    }
    $name = 'cover_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['error' => 'Could not save file.', 'path' => null];
    }
    $rel = 'uploads/events/' . $eventId . '/' . $name;
    return ['error' => null, 'path' => $rel];
}

/**
 * Promo image (jpg/png) or video (mp4) under uploads/promos/.
 * @return array{error:?string,path:?string,type?:string}
 */
function qb_save_promo_media(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload failed.', 'path' => null, 'type' => null];
    }
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['error' => 'Invalid upload.', 'path' => null, 'type' => null];
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
    $dir = qb_uploads_fs_root() . DIRECTORY_SEPARATOR . 'promos';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['error' => 'Could not create upload folder.', 'path' => null, 'type' => null];
    }
    $name = 'promo_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['error' => 'Could not save file.', 'path' => null, 'type' => null];
    }
    $rel = 'uploads/promos/' . $name;
    return ['error' => null, 'path' => $rel, 'type' => $type];
}

function qb_delete_upload_file(?string $relativePath): void {
    if ($relativePath === null || $relativePath === '') {
        return;
    }
    $rel = str_replace(['..', '\\'], '', $relativePath);
    if (strpos($rel, 'uploads/') !== 0) {
        return;
    }
    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (is_file($full)) {
        @unlink($full);
    }
}
