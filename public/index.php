<?php
require_once __DIR__ . '/../app/Config/config.php';
$user = current_user();
if ($user) {
    gb_redirect('dashboard.php');
}
gb_redirect('partner_places.php');
