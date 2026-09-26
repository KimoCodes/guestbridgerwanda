<?php
/**
 * CSRF and POST security helpers (included from config.php).
 */

if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('require_csrf_post')) {
    function require_csrf_post(?string $redirect = null): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        if (!verify_csrf($_POST['csrf_token'] ?? '')) {
            if (!headers_sent()) {
                http_response_code(403);
            }
            flash_set('Your session expired or the form was invalid. Please try again.');
            $target = $redirect ?: ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
            header('Location: ' . $target);
            exit;
        }
    }
}
