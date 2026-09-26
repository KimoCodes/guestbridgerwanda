<?php
require_once __DIR__ . '/../../app/Config/config.php';
session_regenerate_id(true);
session_destroy();
// Clear the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
gb_redirect('index.php');
