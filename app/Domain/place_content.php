<?php
/**
 * Partner place public listing CMS (manager-controlled content for partner_places.php / place.php).
 */

require_once __DIR__ . '/places.php';

function place_content_for_business(PDO $pdo, int $business_id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM partner_places WHERE business_id = ? LIMIT 1');
    $stmt->execute([$business_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function place_content_get_or_create(PDO $pdo, int $business_id, array $business): array
{
    $place = place_content_for_business($pdo, $business_id);
    if ($place) {
        return $place;
    }

    $name = $business['name'] ?? 'Partner Place';
    $slug = places_slugify($name);
    $stmt = $pdo->prepare('SELECT id FROM partner_places WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
        $slug .= '-' . $business_id;
    }

    $stmt = $pdo->prepare('INSERT INTO partner_places (slug, name, location, description, featured_image, business_id, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $slug,
        $name,
        $business['city'] ?? 'Kigali',
        'Discover our hotel on the GuestBridge partner network.',
        'https://images.unsplash.com/photo-1566073771259-6a8506099f29?w=1200&h=800&fit=crop',
        $business_id,
        'inactive',
    ]);

    $id = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM partner_places WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function place_banners_for(PDO $pdo, int $place_id): array
{
    $stmt = $pdo->prepare('SELECT * FROM place_banners WHERE place_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$place_id]);
    return $stmt->fetchAll();
}

function place_upload_dir(int $business_id): string
{
    $dir = dirname(dirname(__DIR__)) . '/public/uploads/places/' . $business_id;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function place_parse_ini_size(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }
    $unit = strtolower(substr($value, -1));
    $num = (float) $value;
    if ($unit === 'g') {
        return (int) ($num * 1024 * 1024 * 1024);
    }
    if ($unit === 'm') {
        return (int) ($num * 1024 * 1024);
    }
    if ($unit === 'k') {
        return (int) ($num * 1024);
    }
    return (int) $num;
}

function place_max_video_upload_bytes(): int
{
    $cap = 50 * 1024 * 1024;
    $upload = place_parse_ini_size((string) ini_get('upload_max_filesize'));
    $post = place_parse_ini_size((string) ini_get('post_max_size'));
    return (int) min($cap, $upload, max(0, $post - 512 * 1024));
}

function place_upload_error_message(int $code): string
{
    $map = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds server upload_max_filesize (' . ini_get('upload_max_filesize') . ').',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds the form size limit.',
        UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Try again.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the file.',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by a PHP extension.',
    ];
    return $map[$code] ?? 'Upload failed (error code ' . $code . ').';
}

function place_detect_video_extension(array $file): ?string
{
    $by_mime = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        'application/mp4' => 'mp4',
        'application/octet-stream' => null,
    ];
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp !== '' && is_file($tmp)) {
        $mime = mime_content_type($tmp);
        if (isset($by_mime[$mime]) && $by_mime[$mime] !== null) {
            return $by_mime[$mime];
        }
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $detected = finfo_file($fi, $tmp);
                finfo_close($fi);
                if ($detected && isset($by_mime[$detected]) && $by_mime[$detected] !== null) {
                    return $by_mime[$detected];
                }
            }
        }
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (in_array($ext, ['mp4', 'webm', 'mov'], true)) {
        return $ext;
    }
    return null;
}

function place_handle_image_upload(int $business_id, string $field): ?string
{
    if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) {
        return null;
    }
    $mime = mime_content_type($f['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        return null;
    }
    $name = $field . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = place_upload_dir($business_id) . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        return null;
    }
    return 'uploads/places/' . $business_id . '/' . $name;
}

/**
 * Upload a hosted video file (MP4/WebM/MOV). Stored path is saved in partner_places.video_url.
 */
function place_handle_video_upload(int $business_id, string $field, ?string &$error = null): ?string
{
    $error = null;
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }
    $f = $_FILES[$field];
    if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $error = place_upload_error_message((int) $f['error']);
        }
        return null;
    }
    $max_bytes = place_max_video_upload_bytes();
    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = place_upload_error_message((int) $f['error']);
        return null;
    }
    if (($f['size'] ?? 0) > $max_bytes) {
        $error = 'Video is too large. Maximum for this server is about ' . round($max_bytes / 1024 / 1024, 1) . ' MB.';
        return null;
    }
    $ext = place_detect_video_extension($f);
    if ($ext === null) {
        $error = 'Unsupported video format. Use MP4 or WebM.';
        return null;
    }
    $name = 'video_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = place_upload_dir($business_id) . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        $error = 'Could not save video file on the server.';
        return null;
    }
    return 'uploads/places/' . $business_id . '/' . $name;
}

function place_save_video_url(PDO $pdo, int $place_id, int $business_id, ?string $video_url, int $user_id): bool
{
    $stmt = $pdo->prepare('SELECT id FROM partner_places WHERE id = ? AND business_id = ?');
    $stmt->execute([$place_id, $business_id]);
    if (!$stmt->fetch()) {
        return false;
    }
    $pdo->prepare('UPDATE partner_places SET video_url = ?, updated_at = NOW() WHERE id = ? AND business_id = ?')
        ->execute([$video_url !== '' && $video_url !== null ? $video_url : null, $place_id, $business_id]);
    log_audit($pdo, $business_id, $user_id, 'place_video_updated', 'partner_place', $place_id, null, [
        'video_url' => $video_url,
    ]);
    return true;
}

function place_is_uploaded_video(?string $url): bool
{
    if ($url === null || trim($url) === '') {
        return false;
    }
    return (bool) preg_match('#^uploads/places/\d+/video_#', trim($url));
}

function place_content_save(PDO $pdo, int $place_id, int $business_id, array $data, int $user_id): bool
{
    $stmt = $pdo->prepare('SELECT id FROM partner_places WHERE id = ? AND business_id = ?');
    $stmt->execute([$place_id, $business_id]);
    if (!$stmt->fetch()) {
        return false;
    }

    $pdo->prepare('UPDATE partner_places SET name = ?, location = ?, description = ?, featured_image = ?, video_url = ?,
        banner_image = ?, promo_tagline = ?, specialties = ?, amenities = ?, extended_about = ?, price_range = ?,
        contact_phone = ?, contact_email = ?, website_url = ?, hours_info = ?, status = ?, is_featured = ?, sort_order = ?,
        updated_at = NOW()
        WHERE id = ? AND business_id = ?')->execute([
        $data['name'],
        $data['location'],
        $data['description'],
        $data['featured_image'],
        $data['video_url'] ?: null,
        $data['banner_image'] ?: null,
        $data['promo_tagline'] ?: null,
        $data['specialties'] ?: null,
        $data['amenities'] ?: null,
        $data['extended_about'] ?: null,
        $data['price_range'] ?: null,
        $data['contact_phone'] ?: null,
        $data['contact_email'] ?: null,
        $data['website_url'] ?: null,
        $data['hours_info'] ?: null,
        $data['status'],
        (int) $data['is_featured'],
        (int) $data['sort_order'],
        $place_id,
        $business_id,
    ]);

    log_audit($pdo, $business_id, $user_id, 'place_content_updated', 'partner_place', $place_id, null, [
        'name' => $data['name'],
        'status' => $data['status'],
    ]);

    return true;
}

function place_gallery_add(PDO $pdo, int $place_id, int $business_id, string $image_url, ?string $caption, int $user_id): bool
{
    $stmt = $pdo->prepare('SELECT id FROM partner_places WHERE id = ? AND business_id = ?');
    $stmt->execute([$place_id, $business_id]);
    if (!$stmt->fetch() || $image_url === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM place_gallery WHERE place_id = ?');
    $stmt->execute([$place_id]);
    $order = (int) $stmt->fetchColumn() + 1;
    $pdo->prepare('INSERT INTO place_gallery (place_id, image_url, caption, sort_order) VALUES (?, ?, ?, ?)')
        ->execute([$place_id, $image_url, $caption, $order]);
    log_audit($pdo, $business_id, $user_id, 'place_gallery_added', 'place_gallery', (int) $pdo->lastInsertId());
    return true;
}

function place_gallery_delete(PDO $pdo, int $gallery_id, int $business_id, int $user_id): bool
{
    $stmt = $pdo->prepare('SELECT g.id FROM place_gallery g JOIN partner_places p ON p.id = g.place_id WHERE g.id = ? AND p.business_id = ?');
    $stmt->execute([$gallery_id, $business_id]);
    if (!$stmt->fetch()) {
        return false;
    }
    $pdo->prepare('DELETE FROM place_gallery WHERE id = ?')->execute([$gallery_id]);
    log_audit($pdo, $business_id, $user_id, 'place_gallery_deleted', 'place_gallery', $gallery_id);
    return true;
}

function place_banner_add(PDO $pdo, int $place_id, int $business_id, array $row, int $user_id): bool
{
    $stmt = $pdo->prepare('SELECT id FROM partner_places WHERE id = ? AND business_id = ?');
    $stmt->execute([$place_id, $business_id]);
    if (!$stmt->fetch() || empty($row['image_url'])) {
        return false;
    }
    $pdo->prepare('INSERT INTO place_banners (place_id, title, image_url, link_url, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([
            $place_id,
            $row['title'] ?? null,
            $row['image_url'],
            $row['link_url'] ?? null,
            (int) ($row['sort_order'] ?? 0),
            !empty($row['is_active']) ? 1 : 0,
        ]);
    log_audit($pdo, $business_id, $user_id, 'place_banner_added', 'place_banner', (int) $pdo->lastInsertId());
    return true;
}

function place_banner_delete(PDO $pdo, int $banner_id, int $business_id, int $user_id): bool
{
    $stmt = $pdo->prepare('SELECT b.id FROM place_banners b JOIN partner_places p ON p.id = b.place_id WHERE b.id = ? AND p.business_id = ?');
    $stmt->execute([$banner_id, $business_id]);
    if (!$stmt->fetch()) {
        return false;
    }
    $pdo->prepare('DELETE FROM place_banners WHERE id = ?')->execute([$banner_id]);
    log_audit($pdo, $business_id, $user_id, 'place_banner_deleted', 'place_banner', $banner_id);
    return true;
}

function place_public_url(array $place): string
{
    $base = rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL, '/');
    return $base . '/place.php?slug=' . urlencode($place['slug']);
}
