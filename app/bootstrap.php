<?php
/**
 * Central bootstrap — include this instead of 3-4 separate require_once lines.
 */
require_once __DIR__ . '/Config/config.php';
require_once __DIR__ . '/Config/database.php';
require_once __DIR__ . '/Config/security.php';
require_once __DIR__ . '/Config/tenant.php';
require_once __DIR__ . '/Config/mailer.php';
require_once __DIR__ . '/HTTP/helpers.php';
require_once __DIR__ . '/HTTP/middleware.php';
require_once __DIR__ . '/Domain/places.php';
require_once __DIR__ . '/Domain/place_content.php';
require_once __DIR__ . '/Settings/hub.php';
require_once __DIR__ . '/Services/ServiceContainer.php';

// Load settings view helpers
require_once __DIR__ . '/Views/settings/_helpers.php';
