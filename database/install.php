<?php
require_once __DIR__ . '/../app/Config/database.php';

try {
    db_init();
    ensure_initial_data();
    echo '<h2>GuestBridge Rwanda installation complete.</h2>';
    echo '<p>The database tables are ready.</p>';
    echo '<p>Use <a href="../public/login.php">login.php</a> to sign in.</p>';
} catch (Exception $e) {
    echo '<h2>Installation error</h2>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}
