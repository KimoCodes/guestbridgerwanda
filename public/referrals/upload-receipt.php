<?php
/**
 * Receipt Upload Handler
 * Handles EBM receipt uploads for referral redemption.
 * Returns JSON response.
 */
ob_start();
require_once __DIR__ . '/../../app/Config/config.php';
require_once __DIR__ . '/../../app/Config/database.php';
db_init();
require_login();

// Clear any output before headers
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

$referral_id = intval($_POST['referral_id'] ?? 0);
if ($referral_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid referral ID']);
    exit;
}

// CSRF check
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token. Please refresh and try again.']);
    exit;
}

// Verify referral exists and user has access
$stmt = $pdo->prepare('SELECT id, target_business_id, source_business_id, status FROM referrals WHERE id = ?');
$stmt->execute([$referral_id]);
$referral = $stmt->fetch();
if (!$referral) {
    http_response_code(404);
    echo json_encode(['error' => 'Referral not found']);
    exit;
}
if ((int) $referral['target_business_id'] !== $business_id && (int) $referral['source_business_id'] !== $business_id) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Validate file upload
if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['receipt']['error'] ?? 'unknown';
    http_response_code(400);
    echo json_encode(['error' => 'File upload failed (error: ' . $err . ')']);
    exit;
}

$file = $_FILES['receipt'];
$allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
$allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];
$max_size = 10 * 1024 * 1024; // 10MB

// Check file size
if ($file['size'] > $max_size) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large. Maximum size is 10MB.']);
    exit;
}

// Check MIME type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime_type = $finfo->file($file['tmp_name']);
if (!in_array($mime_type, $allowed_types, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Accepted: JPG, PNG, PDF']);
    exit;
}

// Check extension
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_extensions, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file extension.']);
    exit;
}

// Generate secure filename
$secure_name = bin2hex(random_bytes(16)) . '.' . $ext;
$year = date('Y');
$month = date('m');
$upload_dir = __DIR__ . '/../../uploads/receipts/' . $year . '/' . $month;
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}
$stored_path = 'uploads/receipts/' . $year . '/' . $month . '/' . $secure_name;
$full_path = __DIR__ . '/../../' . $stored_path;

// Move file
if (!move_uploaded_file($file['tmp_name'], $full_path)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file']);
    exit;
}

// Set restrictive permissions
chmod($full_path, 0640);

// Insert receipt record
$stmt = $pdo->prepare('INSERT INTO receipts 
    (referral_id, business_id, uploaded_by_user_id, original_filename, stored_path, file_type, file_size, notes) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([
    $referral_id,
    $business_id,
    (int) $user['id'],
    $file['name'],
    $stored_path,
    $mime_type,
    $file['size'],
    trim($_POST['notes'] ?? ''),
]);
$receipt_id = (int) $pdo->lastInsertId();

echo json_encode([
    'success' => true,
    'receipt_id' => $receipt_id,
    'filename' => $file['name'],
    'file_type' => $mime_type,
    'file_size' => $file['size'],
    'preview_url' => '/guestbridgerwanda/receipt.php?id=' . $receipt_id,
]);
