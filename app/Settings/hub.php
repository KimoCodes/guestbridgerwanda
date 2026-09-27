<?php
/**
 * Unified Settings Control Panel — data, POST handlers, tab registry.
 */

require_once __DIR__ . '/../Domain/place_content.php';

function settings_allowed_tabs(array $user): array
{
    $is_manager = ($user['role'] ?? '') === 'manager';
    $simple = function_exists('is_staff_simple_mode') && is_staff_simple_mode($user);

    $tabs = [
        'account' => ['label' => 'Account', 'icon' => 'user'],
        'security' => ['label' => 'Security', 'icon' => 'shield'],
    ];

    if (!$simple) {
        $tabs['financial'] = ['label' => 'Payments', 'icon' => 'wallet'];
        $tabs['referrals'] = ['label' => 'Referrals', 'icon' => 'link'];
    }

    if ($is_manager) {
        $tabs['public'] = ['label' => 'Public listing', 'icon' => 'image'];
        if (!$simple) {
            $tabs['team'] = ['label' => 'Team & roles', 'icon' => 'users'];
            $tabs['analytics'] = ['label' => 'Analytics', 'icon' => 'chart'];
        }
    }

    $tabs['notifications'] = ['label' => 'Notifications', 'icon' => 'bell'];
    $tabs['privacy'] = ['label' => 'Privacy', 'icon' => 'lock'];
    $tabs['system'] = ['label' => 'System', 'icon' => 'sliders'];

    return $tabs;
}

function settings_financial_summary(PDO $pdo, int $business_id): array
{
    $month = date('Y-m');
    $earned = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM commissions WHERE owed_to_business_id = ? AND month = ?');
    $earned->execute([$business_id, $month]);
    $month_earned = (float) $earned->fetchColumn();

    $owed = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM commissions WHERE target_business_id = ? AND month = ?');
    $owed->execute([$business_id, $month]);
    $month_owed = (float) $owed->fetchColumn();

    $pending = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM commissions WHERE owed_to_business_id = ? AND status = ?');
    $pending->execute([$business_id, 'pending']);
    $pending_earned = (float) $pending->fetchColumn();

    $confirmed = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM commissions WHERE owed_to_business_id = ? AND status IN (\'confirmed\',\'reconciled\')');
    $confirmed->execute([$business_id]);
    $total_confirmed = (float) $confirmed->fetchColumn();

    $stmt = $pdo->prepare('SELECT t.*, b.name AS partner_name FROM transactions t
        LEFT JOIN businesses b ON b.id = CASE WHEN t.from_business_id = ? THEN t.to_business_id ELSE t.from_business_id END
        WHERE t.from_business_id = ? OR t.to_business_id = ?
        ORDER BY t.transaction_date DESC LIMIT 15');
    $stmt->execute([$business_id, $business_id, $business_id]);
    $transactions = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE business_id = ? ORDER BY created_at DESC LIMIT 10');
    $stmt->execute([$business_id]);
    $invoices = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM disputes WHERE business_id = ? ORDER BY created_at DESC LIMIT 5');
    $stmt->execute([$business_id]);
    $disputes = $stmt->fetchAll();

    return [
        'month_earned' => $month_earned,
        'month_owed' => $month_owed,
        'pending_earned' => $pending_earned,
        'total_confirmed' => $total_confirmed,
        'transactions' => $transactions,
        'invoices' => $invoices,
        'disputes' => $disputes,
    ];
}

function settings_referral_stats(PDO $pdo, int $business_id, int $user_id): array
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE source_business_id = ?');
    $stmt->execute([$business_id]);
    $outbound = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM place_referrals pr JOIN partner_places pp ON pp.id = pr.place_id WHERE pp.business_id = ?');
    $stmt->execute([$business_id]);
    $place_codes = (int) $stmt->fetchColumn();

    $code = settings_get_meta($user_id, 'business_referral_code', '');
    if ($code === '') {
        $code = 'GB-BIZ-' . strtoupper(substr(md5((string) $business_id), 0, 6));
    }

    $place = place_content_for_business($pdo, $business_id);
    $public_link = $place ? place_public_url($place) : '';

    return [
        'outbound_referrals' => $outbound,
        'place_referral_codes' => $place_codes,
        'referral_code' => $code,
        'referral_link' => $public_link ?: (rtrim(BASE_URL, '/') . '/partner_places.php'),
    ];
}

function settings_get_meta(int $user_id, string $key, string $default = ''): string
{
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT meta_value FROM user_meta WHERE user_id = ? AND meta_key = ?');
    $stmt->execute([$user_id, $key]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (string) $v : $default;
}

function settings_build_context(PDO $pdo, array $user): array
{
    $user_id = (int) $user['id'];
    $business_id = tenant_business_id($user);
    $business = get_business_settings($business_id);
    $is_manager = ($user['role'] ?? '') === 'manager';

    $place = $is_manager ? place_content_get_or_create($pdo, $business_id, $business) : null;
    $gallery = $place ? places_gallery_for($pdo, (int) $place['id']) : [];
    $banners = $place ? place_banners_for($pdo, (int) $place['id']) : [];

    return [
        'user' => $user,
        'user_id' => $user_id,
        'business_id' => $business_id,
        'business' => $business,
        'is_manager' => $is_manager,
        'tabs' => settings_allowed_tabs($user),
        'account' => get_user_settings($user_id),
        'business_settings' => $business,
        'preferences' => array_merge(get_system_preferences($user_id), [
            'timezone' => $user['timezone'] ?? 'Africa/Kigali',
            'ui_density' => $user['ui_density'] ?? 'default',
            'default_dashboard_view' => $user['default_dashboard_view'] ?? 'overview',
        ]),
        'notifications' => get_notification_preferences($user_id),
        'consents' => settings_get_consent_status($user_id),
        'login_history' => get_login_history($user_id, 15),
        'active_sessions' => get_active_sessions($user_id),
        'two_fa' => get_user_2fa_status($user_id),
        'financial' => settings_financial_summary($pdo, $business_id),
        'referrals' => settings_referral_stats($pdo, $business_id, $user_id),
        'staff' => get_staff_members($pdo, $business_id),
        'audit_logs' => get_audit_logs($pdo, $business_id, 25),
        'place' => $place,
        'place_gallery' => $gallery,
        'place_banners' => $banners,
        'place_public_url' => $place ? place_public_url($place) : '',
        'export_pending' => (function () use ($pdo, $user_id) {
            $s = $pdo->prepare('SELECT status FROM data_export_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1');
            $s->execute([$user_id]);
            return $s->fetchColumn() ?: null;
        })(),
    ];
}

function settings_handle_post(PDO $pdo, array $user, string $tab, array &$errors, string &$flash): string
{
    $user_id = (int) $user['id'];
    $business_id = tenant_business_id($user);
    $redirect_tab = $tab;

    if (isset($_POST['update_profile'])) {
        $name = sanitize_username($_POST['name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_phone($_POST['phone'] ?? '');
        $language = substr(preg_replace('/[^a-z]/', '', $_POST['language'] ?? 'en'), 0, 2) ?: 'en';
        if ($name === null || $email === '') {
            $errors[] = 'Name and a valid email are required.';
        } else {
            $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $chk->execute([$email, $user_id]);
            if ($chk->fetch()) {
                $errors[] = 'That email is already in use.';
            } else {
                $phone_db = $phone !== '' ? $phone : null;
                $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, language = ? WHERE id = ?')
                    ->execute([$name, $email, $phone_db, $language, $user_id]);
                $addr = trim($_POST['address'] ?? '');
                if ($addr !== '') {
                    $pdo->prepare('UPDATE businesses SET address = ? WHERE id = ?')->execute([$addr, $business_id]);
                }
                if (!empty($_FILES['profile_image']['tmp_name'])) {
                    $path = place_handle_image_upload($business_id, 'profile_image');
                    if ($path) {
                        $pdo->prepare('UPDATE users SET profile_image = ? WHERE id = ?')->execute([$path, $user_id]);
                        $place_row = place_content_for_business($pdo, $business_id);
                        if ($place_row) {
                            $pdo->prepare('UPDATE partner_places SET featured_image = ? WHERE id = ? AND business_id = ?')
                                ->execute([$path, (int) $place_row['id'], $business_id]);
                        }
                    }
                }
                log_audit($pdo, $business_id, $user_id, 'profile_updated', 'user', $user_id);
                $flash = 'Profile updated successfully.';
            }
        }
        $redirect_tab = 'account';
    }

    if (isset($_POST['change_password']) && empty($errors)) {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) {
            $errors[] = 'Current password is incorrect.';
        } elseif (!validate_password_strength($new)) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user_id]);
            log_audit($pdo, $business_id, $user_id, 'password_changed', 'user', $user_id);
            $flash = 'Password changed successfully.';
        }
        $redirect_tab = 'security';
    }

    if (isset($_POST['logout_all_sessions']) && empty($errors)) {
        invalidate_all_other_sessions($user_id, session_id());
        $flash = 'Other sessions have been signed out.';
        $redirect_tab = 'security';
    }

    if (isset($_POST['toggle_2fa']) && empty($errors)) {
        $status = get_user_2fa_status($user_id);
        $enable = empty($status['enabled']);
        toggle_user_2fa($user_id, $enable, $enable ? bin2hex(random_bytes(16)) : null);
        log_audit($pdo, $business_id, $user_id, $enable ? '2fa_enabled' : '2fa_disabled', 'user', $user_id);
        $flash = $enable ? 'Two-factor authentication enabled (placeholder — wire TOTP app in production).' : 'Two-factor authentication disabled.';
        $redirect_tab = 'security';
    }

    if (isset($_POST['update_financial']) && ($user['role'] ?? '') === 'manager' && empty($errors)) {
        $method = in_array($_POST['payout_method'] ?? '', ['mobile_money', 'bank', 'paypal'], true) ? $_POST['payout_method'] : 'mobile_money';
        $account = trim($_POST['payment_account'] ?? '');
        $currency = in_array($_POST['currency_pref'] ?? 'RWF', ['RWF', 'USD', 'EUR'], true) ? $_POST['currency_pref'] : 'RWF';
        $pdo->prepare('UPDATE businesses SET payout_method = ?, payment_account = ?, currency_pref = ? WHERE id = ?')
            ->execute([$method, $account, $currency, $business_id]);
        log_audit($pdo, $business_id, $user_id, 'financial_settings_updated', 'business', $business_id);
        $flash = 'Payment settings saved.';
        $redirect_tab = 'financial';
    }

    if (isset($_POST['update_notifications']) && empty($errors)) {
        save_notification_preferences($user_id, [
            'email_notifications' => !empty($_POST['email_notifications']),
            'sms_alerts' => !empty($_POST['sms_alerts']),
            'booking_alerts' => !empty($_POST['booking_alerts']),
            'payment_alerts' => !empty($_POST['payment_alerts']),
            'marketing_emails' => !empty($_POST['marketing_emails']),
            'system_announcements' => !empty($_POST['system_announcements']),
        ]);
        $flash = 'Notification preferences saved.';
        $redirect_tab = 'notifications';
    }

    if (isset($_POST['update_privacy']) && empty($errors)) {
        $public = !empty($_POST['public_profile']) ? 1 : 0;
        $marketing = !empty($_POST['marketing_consent']) ? 1 : 0;
        $pdo->prepare('UPDATE businesses SET public_profile = ?, marketing_consent = ? WHERE id = ?')
            ->execute([$public, $marketing, $business_id]);
        foreach (['terms', 'privacy', 'marketing'] as $ctype) {
            $granted = !empty($_POST['consent_' . $ctype]) ? 1 : 0;
            $pdo->prepare('INSERT INTO consents (user_id, consent_type, granted, granted_at) VALUES (?, ?, ?, NOW())
                ON CONFLICT (user_id, consent_type) DO UPDATE SET granted = EXCLUDED.granted, granted_at = NOW()')->execute([$user_id, $ctype, $granted]);
        }
        log_audit($pdo, $business_id, $user_id, 'privacy_updated', 'business', $business_id);
        $flash = 'Privacy settings saved.';
        $redirect_tab = 'privacy';
    }

    if (isset($_POST['request_data_export']) && empty($errors)) {
        $pdo->prepare('INSERT INTO data_export_requests (user_id, status) VALUES (?, ?)')->execute([$user_id, 'pending']);
        log_audit($pdo, $business_id, $user_id, 'data_export_requested', 'user', $user_id);
        $flash = 'Data export requested. You will be notified when ready.';
        $redirect_tab = 'privacy';
    }

    if (isset($_POST['download_data_export']) && empty($errors)) {
        $data = export_user_data($user_id);
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="guestbridge-export-' . $user_id . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    if (isset($_POST['request_account_deletion']) && empty($errors)) {
        $pdo->prepare('INSERT INTO account_deletion_requests (user_id, status) VALUES (?, ?)')->execute([$user_id, 'pending']);
        log_audit($pdo, $business_id, $user_id, 'account_deletion_requested', 'user', $user_id);
        $flash = 'Account deletion request submitted for review.';
        $redirect_tab = 'privacy';
    }

    if (isset($_POST['update_system']) && empty($errors)) {
        save_system_preference($user_id, 'theme', $_POST['theme'] ?? 'light');
        save_system_preference($user_id, 'language', $_POST['language'] ?? 'en');
        save_system_preference($user_id, 'timezone', $_POST['timezone'] ?? 'Africa/Kigali');
        save_system_preference($user_id, 'ui_density', $_POST['ui_density'] ?? 'default');
        save_system_preference($user_id, 'default_dashboard_view', $_POST['default_dashboard_view'] ?? 'overview');
        $pdo->prepare('UPDATE users SET theme = ?, timezone = ?, ui_density = ?, default_dashboard_view = ? WHERE id = ?')
            ->execute([$_POST['theme'] ?? 'light', $_POST['timezone'] ?? 'Africa/Kigali', $_POST['ui_density'] ?? 'default', $_POST['default_dashboard_view'] ?? 'overview', $user_id]);
        $flash = 'System preferences saved.';
        $redirect_tab = 'system';
    }

    if (isset($_POST['update_analytics']) && ($user['role'] ?? '') === 'manager' && empty($errors)) {
        $pdo->prepare('UPDATE businesses SET analytics_enabled = ?, report_export_format = ? WHERE id = ?')
            ->execute([!empty($_POST['analytics_enabled']) ? 1 : 0, $_POST['report_export_format'] ?? 'csv', $business_id]);
        $flash = 'Analytics preferences saved.';
        $redirect_tab = 'analytics';
    }

    if (isset($_POST['add_staff']) && ($user['role'] ?? '') === 'manager' && empty($errors)) {
        $sname = sanitize_username($_POST['staff_name'] ?? '');
        $srole = in_array($_POST['staff_role'] ?? '', ['manager', 'receptionist', 'concierge'], true) ? $_POST['staff_role'] : 'receptionist';
        $sphone = sanitize_phone($_POST['staff_phone'] ?? '');
        if ($sname) {
            $sid = settings_add_staff_member($pdo, $business_id, ['name' => $sname, 'phone' => $sphone, 'role' => $srole]);
            $pdo->prepare('INSERT INTO staff_permissions (staff_id) VALUES (?)')->execute([$sid]);
            log_audit($pdo, $business_id, $user_id, 'staff_added', 'staff', $sid);
            $flash = 'Staff member added.';
        }
        $redirect_tab = 'team';
    }

    if (isset($_POST['remove_staff_id']) && ($user['role'] ?? '') === 'manager' && empty($errors)) {
        $sid = (int) $_POST['remove_staff_id'];
        $chk = $pdo->prepare('SELECT id FROM staff WHERE id = ? AND business_id = ?');
        $chk->execute([$sid, $business_id]);
        if ($chk->fetch()) {
            settings_remove_staff_member($pdo, $sid);
            log_audit($pdo, $business_id, $user_id, 'staff_removed', 'staff', $sid);
            $flash = 'Staff member removed.';
        }
        $redirect_tab = 'team';
    }

    if (isset($_POST['save_place_content']) && ($user['role'] ?? '') === 'manager' && empty($errors)) {
        $place_id = (int) ($_POST['place_id'] ?? 0);
        $uploaded_featured = place_handle_image_upload($business_id, 'featured_upload');
        $uploaded_banner = place_handle_image_upload($business_id, 'banner_upload');
        $video_upload_error = null;
        $uploaded_video = place_handle_video_upload($business_id, 'video_upload', $video_upload_error);
        if ($video_upload_error !== null) {
            $errors[] = $video_upload_error;
        }
        $data = [
            'name' => trim($_POST['place_name'] ?? ''),
            'location' => trim($_POST['place_location'] ?? ''),
            'description' => trim($_POST['place_description'] ?? ''),
            'featured_image' => $uploaded_featured ?: trim($_POST['featured_image'] ?? ''),
            'video_url' => $uploaded_video
                ?: trim($_POST['video_external_url'] ?? '')
                ?: trim($_POST['video_url_current'] ?? ''),
            'banner_image' => $uploaded_banner ?: trim($_POST['banner_image'] ?? ''),
            'promo_tagline' => trim($_POST['promo_tagline'] ?? ''),
            'specialties' => trim($_POST['place_specialties'] ?? ''),
            'amenities' => trim($_POST['place_amenities'] ?? ''),
            'extended_about' => trim($_POST['place_extended_about'] ?? ''),
            'price_range' => substr(trim($_POST['place_price_range'] ?? ''), 0, 120),
            'contact_phone' => substr(trim($_POST['place_contact_phone'] ?? ''), 0, 80),
            'contact_email' => substr(trim($_POST['place_contact_email'] ?? ''), 0, 255),
            'website_url' => substr(trim($_POST['place_website_url'] ?? ''), 0, 500),
            'hours_info' => substr(trim($_POST['place_hours_info'] ?? ''), 0, 500),
            'status' => in_array($_POST['place_status'] ?? '', ['active', 'inactive'], true) ? $_POST['place_status'] : 'inactive',
            'is_featured' => !empty($_POST['is_featured']),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];
        if ($data['name'] === '' || $data['featured_image'] === '') {
            $errors[] = 'Place name and featured image are required.';
        } elseif (!place_content_save($pdo, $place_id, $business_id, $data, $user_id)) {
            $errors[] = 'Could not save listing. Check that this place belongs to your hotel.';
        } else {
            $flash = 'Public listing updated. ' . ($data['status'] === 'active' ? 'Your place is visible on the directory.' : 'Set status to Active to publish.');
        }
        $redirect_tab = 'public';
    }

    if ((isset($_POST['save_place_video']) || isset($_POST['remove_place_video'])) && ($user['role'] ?? '') === 'manager' && empty($errors)) {
        $place_id = (int) ($_POST['place_id'] ?? 0);
        if (!empty($_POST['remove_place_video'])) {
            if (place_save_video_url($pdo, $place_id, $business_id, '', $user_id)) {
                $flash = 'Video removed.';
            } else {
                $errors[] = 'Could not remove video for this listing.';
            }
        } else {
            $video_upload_error = null;
            $uploaded_video = place_handle_video_upload($business_id, 'video_upload', $video_upload_error);
            if ($video_upload_error !== null) {
                $errors[] = $video_upload_error;
            } else {
                $video_url = $uploaded_video
                    ?: trim($_POST['video_external_url'] ?? '')
                    ?: trim($_POST['video_url_current'] ?? '');
                if ($video_url === '' && $uploaded_video === null) {
                    $errors[] = 'Choose a video file or paste a YouTube/Vimeo link.';
                } elseif (place_save_video_url($pdo, $place_id, $business_id, $video_url, $user_id)) {
                    $flash = 'Video saved.';
                } else {
                    $errors[] = 'Could not save video for this listing.';
                }
            }
        }
        $redirect_tab = 'public';
    }

    if (isset($_POST['add_gallery_image']) && ($user['role'] ?? '') === 'manager' && empty($errors)) {
        $place_id = (int) ($_POST['place_id'] ?? 0);
        $url = place_handle_image_upload($business_id, 'gallery_upload');
        if (!$url) {
            $url = trim($_POST['gallery_image_url'] ?? '');
        }
        if (place_gallery_add($pdo, $place_id, $business_id, $url, trim($_POST['gallery_caption'] ?? ''), $user_id)) {
            $flash = 'Gallery image added.';
        } else {
            $errors[] = 'Could not add gallery image.';
        }
        $redirect_tab = 'public';
    }

    if (isset($_POST['delete_gallery_id']) && ($user['role'] ?? '') === 'manager' && empty($errors)) {
        place_gallery_delete($pdo, (int) $_POST['delete_gallery_id'], $business_id, $user_id);
        $flash = 'Gallery image removed.';
        $redirect_tab = 'public';
    }

    if (isset($_POST['add_banner']) && ($user['role'] ?? '') === 'manager' && empty($errors)) {
        $place_id = (int) ($_POST['place_id'] ?? 0);
        $url = place_handle_image_upload($business_id, 'banner_ad_upload');
        if (!$url) {
            $url = trim($_POST['banner_image_url'] ?? '');
        }
        if (place_banner_add($pdo, $place_id, $business_id, [
            'title' => trim($_POST['banner_title'] ?? ''),
            'image_url' => $url,
            'link_url' => trim($_POST['banner_link_url'] ?? ''),
            'sort_order' => (int) ($_POST['banner_sort'] ?? 0),
            'is_active' => !empty($_POST['banner_active']),
        ], $user_id)) {
            $flash = 'Promotional banner added.';
        } else {
            $errors[] = 'Banner image URL is required.';
        }
        $redirect_tab = 'public';
    }

    if (isset($_POST['delete_banner_id']) && ($user['role'] ?? '') === 'manager' && empty($errors)) {
        place_banner_delete($pdo, (int) $_POST['delete_banner_id'], $business_id, $user_id);
        $flash = 'Banner removed.';
        $redirect_tab = 'public';
    }

    return $redirect_tab;
}

function settings_get_consent_status(int $user_id): array
{
    if (function_exists('get_consent_status')) {
        return get_consent_status($user_id);
    }
    $pdo = db_connect();
    $defaults = ['terms' => 0, 'privacy' => 0, 'marketing' => 0];
    $stmt = $pdo->prepare('SELECT consent_type, granted FROM consents WHERE user_id = ?');
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll() as $row) {
        $defaults[$row['consent_type']] = (int) $row['granted'];
    }
    return $defaults;
}

function settings_add_staff_member(PDO $pdo, int $business_id, array $data): int
{
    $stmt = $pdo->prepare('INSERT INTO staff (business_id, name, phone, role) VALUES (?, ?, ?, ?)');
    $stmt->execute([$business_id, $data['name'], $data['phone'] ?? '', $data['role'] ?? 'receptionist']);
    return (int) $pdo->lastInsertId();
}

function settings_remove_staff_member(PDO $pdo, int $staff_id): void
{
    $pdo->prepare('DELETE FROM staff WHERE id = ?')->execute([$staff_id]);
}
