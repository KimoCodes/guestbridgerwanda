<?php
/**
 * Shared layout helper functions for consistent navigation and page structure.
 */

function get_nav_items($user, $pending_requests = 0) {
    $is_manager = ($user['role'] ?? '') === 'manager';
    $platform_admin = is_platform_admin($user);
    $simple_mode = is_staff_simple_mode($user);
    $items = [];

    // Core sections with icons and grouped structure
    $items['referrals'] = [
        'label' => 'Referrals',
        'icon' => 'link',
        'items' => [
            [
                'href' => 'create_referral.php',
                'label' => 'Create Referral',
                'description' => 'Generate a new referral for a partner',
                'priority' => 'primary',
            ],
            [
                'href' => 'history.php',
                'label' => 'Referral History',
                'description' => 'View all referrals by date and partner',
            ],
            [
                'href' => 'view_referral.php',
                'label' => 'View Referral',
                'description' => 'QR codes, WhatsApp sharing, and referral details',
            ],
            [
                'href' => 'verify_referral.php',
                'label' => 'Verify Referral',
                'description' => 'Scan QR or enter code to verify a guest referral',
            ],
            [
                'href' => 'partner_places.php',
                'label' => 'Partner Places (public)',
                'description' => 'Public hotel & place directory for guest referral codes',
            ],
        ],
    ];

    $items['network'] = [
        'label' => 'Network',
        'icon' => 'people',
        'badge' => $pending_requests > 0 ? $pending_requests : null,
        'items' => [
            [
                'href' => 'partners.php',
                'label' => 'Partner Directory',
                'description' => 'Browse and manage partner businesses',
            ],
            [
                'href' => 'partnerships.php',
                'label' => 'Partnerships',
                'description' => 'Handle incoming and outgoing partnership requests',
                'priority' => $pending_requests > 0 ? 'warning' : '',
                'badge' => $pending_requests > 0 ? $pending_requests : null,
            ],
            [
                'href' => 'staff.php',
                'label' => 'Staff Members',
                'description' => 'Manage referral staff records',
            ],
            [
                'href' => 'staff_earnings.php',
                'label' => 'Staff Earnings',
                'description' => 'View staff performance and commission earnings',
            ],
            [
                'href' => 'staff_identities.php',
                'label' => 'Staff Identities',
                'description' => 'Manage staff referral identity codes',
            ],
            [
                'href' => 'performance.php',
                'label' => 'Performance',
                'description' => 'Referral performance and leaderboard',
            ],
            [
                'href' => 'my_identity.php',
                'label' => 'My Identity',
                'description' => 'View your referral identity code and stats',
            ],
            [
                'href' => 'guest_benefits.php',
                'label' => 'Guest Benefits',
                'description' => 'Configure benefits for referred guests',
            ],
        ],
    ];

    $items['finance'] = [
        'label' => 'Finance',
        'icon' => 'cash-stack',
        'items' => [
            [
                'href' => 'record_transaction.php',
                'label' => 'Record Transaction',
                'description' => 'Record guest spending for a referral',
                'priority' => 'primary',
            ],
            [
                'href' => 'commissions.php',
                'label' => 'Commissions',
                'description' => 'Pending, confirmed, and reconciled commission entries',
            ],
            [
                'href' => 'wallet.php',
                'label' => 'Wallet Overview',
                'description' => 'Monthly earning and owing summary',
            ],
            [
                'href' => 'payments.php',
                'label' => 'Payment Journal',
                'description' => 'Record and audit settlement payments',
            ],
            [
                'href' => 'reconciliation.php',
                'label' => 'Reconciliation',
                'description' => 'Confirm commissions before settlement',
            ],
            [
                'href' => 'transactions.php',
                'label' => 'Transactions',
                'description' => 'Detailed transaction audit trail',
            ],
            [
                'href' => 'billing.php',
                'label' => 'Billing & Invoices',
                'description' => 'Invoices, receipts, and payment tracking',
            ],
            [
                'href' => 'settlement_summary.php',
                'label' => 'Settlement Reports',
                'description' => 'Monthly partner review sheets',
            ],
        ],
    ];

    $items['insights'] = [
        'label' => 'Insights',
        'icon' => 'graph-up',
        'items' => [
            [
                'href' => 'analytics.php',
                'label' => 'Analytics',
                'description' => 'Conversion rates, partner and staff performance',
            ],
            [
                'href' => 'statements.php',
                'label' => 'Monthly Statements',
                'description' => 'View and generate monthly financial statements',
            ],
        ],
    ];

    if ($is_manager || is_super_admin($user)) {
        $management_items = [
            [
                'href' => 'revenue_model.php',
                'label' => 'Revenue Model',
                'description' => 'Simulate platform fees and review waivers',
            ],
            [
                'href' => 'incentives.php',
                'label' => 'Staff Incentives',
                'description' => 'Approve referral reward points',
            ],
        ];
        if ($platform_admin) {
            $management_items = array_merge([
                [
                    'href' => 'admin_finance.php',
                    'label' => 'Platform Finance',
                    'description' => 'Pilot network-wide debt and payment monitoring',
                ],
                [
                    'href' => 'pilot_pricing_packet.php',
                    'label' => 'Pricing Configuration',
                    'description' => 'Fees, featured placements, settlements, seasonality',
                ],
                [
                    'href' => 'regional_scaling.php',
                    'label' => 'Regional Scaling',
                    'description' => 'City readiness, contracts, and regional expansion',
                ],
            ], $management_items);
        }
        $items['management'] = [
            'label' => 'Management',
            'icon' => 'tools',
            'items' => $management_items,
        ];
    }

    if (is_super_admin($user)) {
        $items['admin'] = [
            'label' => 'Super Admin',
            'icon' => 'shield',
            'items' => [
                [
                    'href' => 'admin/index.php',
                    'label' => 'Admin Dashboard',
                    'description' => 'Platform-wide overview and controls',
                    'priority' => 'primary',
                ],
                [
                    'href' => 'admin/businesses.php',
                    'label' => 'Manage Businesses',
                    'description' => 'View and manage all registered businesses',
                ],
                [
                    'href' => 'admin/users.php',
                    'label' => 'Manage Users',
                    'description' => 'View and manage all user accounts and roles',
                ],
                [
                    'href' => 'admin/referrals.php',
                    'label' => 'All Referrals',
                    'description' => 'Platform-wide referral tracking',
                ],
                [
                    'href' => 'admin/transactions.php',
                    'label' => 'Transactions',
                    'description' => 'All financial transactions across the platform',
                ],
                [
                    'href' => 'admin/commissions.php',
                    'label' => 'Commissions',
                    'description' => 'All commission records and summaries',
                ],
                [
                    'href' => 'admin/settlements.php',
                    'label' => 'Settlements',
                    'description' => 'Payment verification and settlement tracking',
                ],
                [
                    'href' => 'admin/disputes.php',
                    'label' => 'Disputes',
                    'description' => 'Manage and resolve platform disputes',
                ],
                [
                    'href' => 'admin/audit_logs.php',
                    'label' => 'Audit Logs',
                    'description' => 'Full audit trail across all businesses',
                ],
                [
                    'href' => 'admin/settings.php',
                    'label' => 'System Settings',
                    'description' => 'Configure revenue, seasonality, and platform settings',
                ],
            ],
        ];
    }

    if ($simple_mode) {
        unset($items['finance'], $items['insights'], $items['management']);
        $items['network']['items'] = array_values(array_filter(
            $items['network']['items'],
            static fn($item) => in_array($item['href'], ['partners.php', 'partnerships.php'], true)
        ));
    }

    $items['settings'] = [
        'label' => 'Account',
        'icon' => 'gear',
        'items' => [
            [
                'href' => 'settings.php',
                'label' => 'Settings',
                'description' => 'Profile and business account settings',
            ],
        ],
    ];

    return $items;
}

function render_nav_sidebar($user, $pending_requests = 0, $current_page = '', int $depth = 0) {
    $items = get_nav_items($user, $pending_requests);
    $is_manager = ($user['role'] ?? '') === 'manager';
    $prefix = $depth > 0 ? str_repeat('../', $depth) : '';
    $html = '';

    foreach ($items as $key => $section):
        $html .= '<div class="nav-section">';
        $html .= '<div class="nav-section-header">';

        // Icon mapping - using Lucide-style SVG
        $icons = [
            'referrals' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
            'network' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'finance' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
            'insights' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
            'management' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
            'settings' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
            'shield' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        ];

        $icon = $icons[$key] ?? '';
        $badge_html = isset($section['badge']) ? '<span class="nav-badge">' . intval($section['badge']) . '</span>' : '';
        $html .= '<div class="nav-section-icon">' . $icon . '</div>';
        $html .= '<span class="nav-section-label">' . htmlspecialchars($section['label']) . '</span>' . $badge_html;
        $html .= '</div>';

        $html .= '<div class="nav-section-items">';
        foreach ($section['items'] as $item):
            if (empty($item['href'])) {
                continue;
            }
            $is_active = ($current_page === $item['href']);
            $priority = $item['priority'] ?? '';
            $item_badge = $item['badge'] ?? null;

            $classes = ['nav-item-link'];
            if ($is_active) $classes[] = 'nav-item-active';
            if ($priority === 'primary') $classes[] = 'nav-item-primary';
            if ($priority === 'warning') $classes[] = 'nav-item-warning';

            $active_icon = $is_active ? '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>' : '';
            $item_badge_html = $item_badge ? '<span class="nav-badge nav-badge-sm">' . intval($item_badge) . '</span>' : '';

            $html .= '<a href="' . $prefix . htmlspecialchars($item['href']) . '" class="' . implode(' ', $classes) . '">';
            $html .= '<div class="nav-item-content">';
            $html .= '<span class="nav-item-label">' . htmlspecialchars($item['label']) . $item_badge_html . '</span>';
            $html .= '<span class="nav-item-desc">' . htmlspecialchars($item['description']) . '</span>';
            $html .= '</div>';
            $html .= '<div class="nav-item-indicator">' . $active_icon . '</div>';
            $html .= '</a>';
        endforeach;
        $html .= '</div>'; // .nav-section-items
        $html .= '</div>'; // .nav-section
    endforeach;

    return $html;
}

function render_breadcrumbs($segments, int $depth = 0) {
    $prefix = $depth > 0 ? str_repeat('../', $depth) : '';
    $html = '<nav aria-label="breadcrumb" class="gb-breadcrumbs"><ol class="breadcrumb">';
    $html .= '<li class="breadcrumb-item"><a href="' . $prefix . 'dashboard.php">Home</a></li>';
    foreach ($segments as $label => $href) {
        if ($href === false) {
            $html .= '<li class="breadcrumb-item active" aria-current="page">' . htmlspecialchars($label) . '</li>';
        } else {
            $html .= '<li class="breadcrumb-item"><a href="' . $prefix . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a></li>';
        }
    }
    $html .= '</ol></nav>';
    return $html;
}

function render_page_header($title, $description = '', $actions = []) {
    $html = '<div class="gb-page-header">';
    $html .= '<div class="gb-page-header-text">';
    $html .= '<h1 class="gb-page-title">' . htmlspecialchars($title) . '</h1>';
    if ($description) {
        $html .= '<p class="gb-page-description">' . htmlspecialchars($description) . '</p>';
    }
    $html .= '</div>';
    if (!empty($actions)) {
        $html .= '<div class="gb-page-actions">';
        foreach ($actions as $action) {
            $style = $action['style'] ?? 'outline-secondary';
            $html .= '<a href="' . htmlspecialchars($action['href']) . '" class="btn btn-sm btn-' . htmlspecialchars($style) . '">' . htmlspecialchars($action['label']) . '</a>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

function render_stat_cards($cards) {
    $html = '<div class="gb-stat-grid">';
    foreach ($cards as $card) {
        $variant = $card['variant'] ?? 'default';
        $trend = $card['trend'] ?? null;
        $trend_html = '';
        if ($trend !== null) {
            $trend_class = $trend >= 0 ? 'stat-trend-up' : 'stat-trend-down';
            $trend_arrow = $trend >= 0 ? '↑' : '↓';
            $trend_html = '<span class="' . $trend_class . '">' . $trend_arrow . ' ' . abs($trend) . '%</span>';
        }
        $html .= '<div class="gb-stat-card gb-stat-' . htmlspecialchars($variant) . '">';
        $html .= '<div class="gb-stat-label">' . htmlspecialchars($card['label']) . '</div>';
        $html .= '<div class="gb-stat-value">' . htmlspecialchars($card['value']) . '</div>';
        if ($trend_html) {
            $html .= '<div class="gb-stat-trend">' . $trend_html . '</div>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

function render_app_shell_start(array $user, int $pending_requests, string $current_page, int $depth = 0): string
{
    $prefix = $depth > 0 ? str_repeat('../', $depth) : '';
    $notify_badge = $pending_requests > 0
        ? '<span class="topbar-badge-count">' . (int) $pending_requests . '</span>'
        : '';

    $html = '<div class="app-shell">';
    $html .= '<div class="sidebar-overlay" id="sidebarOverlay"></div>';
    $html .= '<aside class="app-sidebar" id="appSidebar">';
    $html .= '<a class="sidebar-brand" href="' . $prefix . 'dashboard.php">';
    $html .= '<div class="sidebar-brand-logo">GB</div>';
    $html .= '<div><span class="sidebar-brand-name">' . htmlspecialchars(APP_NAME) . '</span>';
    $html .= '<span class="sidebar-brand-tagline">Referral Network</span></div></a>';
    $html .= render_nav_sidebar($user, $pending_requests, $current_page, $depth);
    $html .= '</aside>';
    $html .= '<main class="app-main">';
    $html .= '<header class="app-topbar">';
    $html .= '<button type="button" class="topbar-toggle show-mobile-only" id="sidebarToggle" aria-label="Toggle navigation">';
    $html .= '<i data-lucide="panel-left"></i></button>';
    $html .= '<div class="topbar-search hide-mobile">';
    $html .= '<i data-lucide="search" class="topbar-search-icon" style="width:18px;height:18px;"></i>';
    $html .= '<input type="search" placeholder="Search referrals, partners…" aria-label="Search" data-gb-search>';
    $html .= '</div>';
    $html .= '<div class="topbar-actions">';
    $html .= '<a href="' . $prefix . 'create_referral.php" class="btn btn-primary btn-sm topbar-cta hide-mobile">';
    $html .= '<i data-lucide="plus" style="width:16px;height:16px;"></i> New Referral</a>';
    $html .= '<a href="' . $prefix . 'partnerships.php" class="topbar-action-btn hide-mobile" title="Notifications">';
    $html .= '<i data-lucide="bell" style="width:18px;height:18px;"></i>' . $notify_badge . '</a>';
    $html .= '<a href="' . $prefix . 'settings.php" class="topbar-action-btn hide-mobile" title="Settings">';
    $html .= '<i data-lucide="settings" style="width:18px;height:18px;"></i></a>';
    $html .= '<div class="topbar-user">';
    $html .= '<div class="topbar-user-info hide-mobile">';
    $html .= '<div class="topbar-user-name">' . htmlspecialchars($user['name']) . '</div>';
    $business_label = !empty($user['business_name']) ? htmlspecialchars($user['business_name']) : 'No business linked';
    $html .= '<div class="topbar-user-role">' . $business_label . ' · ' . htmlspecialchars(ucfirst($user['role'] ?? 'user')) . '</div>';
    $html .= '</div>';
    $html .= '<div class="topbar-user-avatar" title="' . htmlspecialchars($user['name']) . '">';
    $html .= htmlspecialchars(strtoupper(substr($user['name'], 0, 1)));
    $html .= '</div>';
    $html .= '<a href="' . $prefix . 'logout.php" class="topbar-action-btn hide-mobile" title="Logout">';
    $html .= '<i data-lucide="log-out" style="width:18px;height:18px;"></i></a>';
    $html .= '</div></div></header>';
    $html .= '<div class="app-content">';
    return $html;
}

function render_app_shell_end(): string
{
    return '</div></main></div>' . gb_render_app_scripts();
}