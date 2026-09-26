<?php
require_once __DIR__ . '/../app/Config/database.php';
db_init();
$pdo = db_connect();

$secret = $_POST['secret'] ?? '';
$expected_secret = defined('PAYMENT_WEBHOOK_SECRET') ? PAYMENT_WEBHOOK_SECRET : '';
if ($expected_secret === '' || !hash_equals($expected_secret, $secret)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$reference = trim($_POST['reference'] ?? '');
$provider = trim($_POST['provider'] ?? '');
$status = trim($_POST['status'] ?? '');

if ($reference === '' || $provider === '' || !in_array($status, ['successful', 'failed', 'cancelled'], true)) {
    http_response_code(400);
    echo 'Invalid callback payload.';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM payment_attempts WHERE reference = ? AND provider = ? AND status = ?');
$stmt->execute([$reference, $provider, 'pending']);
$attempt = $stmt->fetch();

if (!$attempt) {
    http_response_code(404);
    echo 'Payment attempt not found.';
    exit;
}

$pdo->beginTransaction();
try {
    $update = $pdo->prepare('UPDATE payment_attempts SET status = ?, completed_at = NOW() WHERE id = ?');
    $update->execute([$status, $attempt['id']]);

    if ($status === 'successful') {
        record_debt_payment($pdo, (int)$attempt['debt_id'], (float)$attempt['amount'], $provider, $reference, 'Provider-confirmed payment', 'verified');
    }

    $pdo->commit();
    echo 'OK';
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo 'Callback failed.';
}
