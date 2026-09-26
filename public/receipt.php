<?php
/**
 * Secure Receipt Viewer
 * Serves receipt files with authorization checks.
 */
require_once __DIR__ . '/../app/Config/config.php';
require_once __DIR__ . '/../app/Config/database.php';
db_init();
require_login();

$user = current_user();
$pdo = db_connect();
$business_id = tenant_business_id($user);

$receipt_id = intval($_GET['id'] ?? 0);
if ($receipt_id <= 0) {
    http_response_code(400);
    exit;
}

$stmt = $pdo->prepare('SELECT r.*, ref.target_business_id, ref.source_business_id 
    FROM receipts r 
    JOIN referrals ref ON ref.id = r.referral_id 
    WHERE r.id = ?');
$stmt->execute([$receipt_id]);
$receipt = $stmt->fetch();

if (!$receipt) {
    http_response_code(404);
    exit;
}

// Auth check: must be source or target business, or platform admin
$is_target = (int) $receipt['target_business_id'] === $business_id;
$is_source = (int) $receipt['source_business_id'] === $business_id;
$is_admin = is_platform_admin($user);
if (!$is_target && !$is_source && !$is_admin) {
    http_response_code(403);
    exit;
}

$full_path = __DIR__ . '/../' . $receipt['stored_path'];
if (!file_exists($full_path)) {
    http_response_code(404);
    exit;
}

// Serve file
$mime = $receipt['file_type'] ?: mime_content_type($full_path);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full_path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($full_path);
exit;
