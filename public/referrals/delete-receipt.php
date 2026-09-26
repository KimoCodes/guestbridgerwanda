<?php
/**
 * Receipt Delete Handler
 * Removes a receipt and its file. Only allowed before redemption is finalized.
 */
ob_start();
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();

if (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json');

$user = current_user();
$pdo = db_connect();
$business_id = tenant_business_id($user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$receipt_id = intval($_POST['receipt_id'] ?? 0);
if ($receipt_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid receipt ID']);
    exit;
}

// CSRF check
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token. Please refresh and try again.']);
    exit;
}

$stmt = $pdo->prepare('SELECT r.*, ref.target_business_id, ref.status AS referral_status 
    FROM receipts r 
    JOIN referrals ref ON ref.id = r.referral_id 
    WHERE r.id = ?');
$stmt->execute([$receipt_id]);
$receipt = $stmt->fetch();

if (!$receipt) {
    http_response_code(404);
    echo json_encode(['error' => 'Receipt not found']);
    exit;
}

// Auth check
if ((int) $receipt['target_business_id'] !== $business_id && !is_platform_admin($user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Don't allow deletion after referral is settled/converted
if (in_array($receipt['referral_status'], ['settled', 'converted'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot delete receipt after referral is settled.']);
    exit;
}

// Delete file
$full_path = __DIR__ . '/../../' . $receipt['stored_path'];
if (file_exists($full_path)) {
    unlink($full_path);
}

// Delete record
$pdo->prepare('DELETE FROM receipts WHERE id = ?')->execute([$receipt_id]);

echo json_encode(['success' => true]);
