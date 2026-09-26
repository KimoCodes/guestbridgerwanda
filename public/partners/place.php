<?php
require_once __DIR__ . '/../../app/Config/database.php';
require_once __DIR__ . '/../../app/Domain/places.php';
require_once __DIR__ . '/../../app/Domain/place_content.php';

db_init();
$pdo = db_connect();

$place = null;
if (!empty($_GET['slug'])) {
    $place = places_get_by_slug($pdo, trim($_GET['slug']));
} elseif (!empty($_GET['id'])) {
    $place = places_get_by_id($pdo, (int) $_GET['id']);
}

if (!$place) {
    http_response_code(404);
    places_render_public_head('Place not found');
    places_render_header();
    echo '<main class="places-main"><div class="places-empty"><p>Place not found.</p><a href="' . htmlspecialchars(gb_url('partner_places.php'), ENT_QUOTES, 'UTF-8') . '" class="places-btn places-btn-primary">Back to all places</a></div></main>';
    places_render_footer();
    exit;
}

$gallery = places_gallery_for($pdo, (int) $place['id']);
$promo_banners = array_filter(place_banners_for($pdo, (int) $place['id']), static function ($b) {
    return !empty($b['is_active']);
});
$video = places_video_playback($place['video_url'] ?? null);
$video_has = $video !== null;
$video_attrs = places_video_data_attrs($video);
$place_id = (int) $place['id'];
$listing_img = places_resolve_listing_image($pdo, $place);
$hero_source = !empty($place['banner_image']) && !places_is_stock_placeholder($place['banner_image'])
    ? $place['banner_image']
    : $listing_img;
$hero_img = places_image_url($hero_source);
$highlights = places_selling_highlights();
$place_specialties = places_parse_list_field($place['specialties'] ?? '');
$place_amenities = places_parse_list_field($place['amenities'] ?? '');
$place_has_extended = trim($place['extended_about'] ?? '') !== '';

$error = null;
$generated_code = null;
$worker_name_value = '';
$hotel_ref_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post('place.php?slug=' . urlencode($place['slug']));
    $worker_name_value = trim($_POST['worker_name'] ?? '');
    $hotel_ref_value = trim($_POST['hotel_ref_code'] ?? '');
    $posted_place_id = (int) ($_POST['place_id'] ?? 0);

    if ($posted_place_id !== $place_id) {
        $error = 'Invalid place. Please refresh and try again.';
    } else {
        $result = places_create_referral($pdo, $place_id, $worker_name_value, $hotel_ref_value);
        if ($result['ok']) {
            $generated_code = $result['code'];
        } else {
            $error = $result['error'];
        }
    }
}

$meta_desc = places_excerpt($place['description'] ?? $place['name'], 160);
places_render_public_head($place['name'], $meta_desc, true);
places_render_header('detail');

$gallery_index = 1;
$trust_icons = ['🛡️', '👥', '✨', '📍'];
?>
<main class="places-main places-detail-main">
    <a href="<?php echo htmlspecialchars(gb_url('partner_places.php')); ?>" class="places-back-link">← Back to all partner places</a>

    <div class="places-detail-hero">
        <button type="button" class="places-detail-hero-img-btn"
                data-gallery-index="0"
                data-gallery-src="<?php echo htmlspecialchars($hero_img); ?>"
                data-gallery-caption="<?php echo htmlspecialchars($place['name']); ?> — Featured"
                aria-label="View full-size photo">
            <img src="<?php echo htmlspecialchars($hero_img); ?>" alt="<?php echo htmlspecialchars($place['name']); ?>">
        </button>
        <div class="places-detail-hero-overlay">
            <h1><?php echo htmlspecialchars($place['name']); ?></h1>
            <?php if (!empty($place['promo_tagline'])): ?>
                <p class="places-detail-tagline"><?php echo htmlspecialchars($place['promo_tagline']); ?></p>
            <?php endif; ?>
            <div class="places-detail-hero-meta">
                <span>📍 <?php echo htmlspecialchars($place['location']); ?></span>
                <span>· GuestBridge verified partner</span>
                <?php if ($video_has): ?>
                    <span>· Video tour available</span>
                <?php endif; ?>
                <?php if (count($gallery) > 0): ?>
                    <span>· <?php echo count($gallery) + 1; ?> photos</span>
                <?php endif; ?>
            </div>
            <div class="places-detail-hero-actions">
                <?php if (count($gallery) > 0 || true): ?>
                    <button type="button" class="places-btn places-btn-light"
                            data-gallery-index="0"
                            data-gallery-src="<?php echo htmlspecialchars($hero_img); ?>"
                            data-gallery-caption="<?php echo htmlspecialchars($place['name']); ?>">
                        🖼 View photos
                    </button>
                <?php endif; ?>
                <?php if ($video_has): ?>
                    <button type="button" class="places-btn places-btn-ghost"
                            <?php echo $video_attrs; ?>
                            data-video-title="<?php echo htmlspecialchars($place['name']); ?> video">
                        ▶ Watch video
                    </button>
                <?php endif; ?>
                <a href="#referral" class="places-btn places-btn-ghost">Staff referral</a>
            </div>
        </div>
    </div>

    <div class="places-trust-strip">
        <?php foreach ($highlights as $i => $item): ?>
            <div class="places-trust-item">
                <div class="places-trust-icon"><?php echo $trust_icons[$i] ?? '★'; ?></div>
                <div>
                    <strong><?php echo htmlspecialchars($item['title']); ?></strong>
                    <span><?php echo htmlspecialchars($item['text']); ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="places-detail-layout">
        <div class="places-detail-content">
            <section>
                <h2 class="places-section-title">About this place</h2>
                <?php if (!empty($place['description'])): ?>
                    <p class="places-about-text"><?php echo nl2br(htmlspecialchars($place['description'])); ?></p>
                <?php else: ?>
                    <p class="places-about-text">A distinguished partner on the GuestBridge network, offering memorable experiences for hotel guests and travellers exploring Rwanda.</p>
                <?php endif; ?>
            </section>

            <?php if (!empty($place_specialties)): ?>
            <section>
                <h2 class="places-section-title">Our specialties</h2>
                <ul class="places-specialty-tags">
                    <?php foreach ($place_specialties as $tag): ?>
                        <li><?php echo htmlspecialchars($tag); ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ($place_has_extended): ?>
            <section>
                <h2 class="places-section-title">More about us</h2>
                <div class="places-about-text places-extended-about"><?php echo nl2br(htmlspecialchars($place['extended_about'])); ?></div>
            </section>
            <?php endif; ?>

            <?php if (!empty($place_amenities)): ?>
            <section>
                <h2 class="places-section-title">Amenities &amp; services</h2>
                <ul class="places-amenities-list">
                    <?php foreach ($place_amenities as $item): ?>
                        <li><span class="places-amenity-check" aria-hidden="true">✓</span><?php echo htmlspecialchars($item); ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if (!empty($gallery) || $hero_img): ?>
            <section data-places-gallery>
                <h2 class="places-section-title">
                    Photo gallery
                    <span style="font-size:0.85rem;font-weight:500;color:var(--gb-muted);">Click any image to enlarge</span>
                </h2>
                <div class="places-gallery-grid">
                    <?php if (!empty($gallery)): ?>
                        <?php foreach ($gallery as $idx => $img): ?>
                            <?php $gurl = places_image_url($img['image_url']); ?>
                            <button type="button"
                                    class="places-gallery-tile<?php echo $idx === 0 ? ' places-gallery-featured' : ''; ?>"
                                    data-gallery-index="<?php echo (int) $gallery_index; ?>"
                                    data-gallery-src="<?php echo htmlspecialchars($gurl); ?>"
                                    data-gallery-caption="<?php echo htmlspecialchars($img['caption'] ?? $place['name']); ?>">
                                <img src="<?php echo htmlspecialchars($gurl); ?>"
                                     alt="<?php echo htmlspecialchars($img['caption'] ?? $place['name']); ?>"
                                     loading="lazy">
                                <span class="places-gallery-zoom" aria-hidden="true">⤢</span>
                            </button>
                            <?php $gallery_index++; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if (!empty($promo_banners)): ?>
            <section class="places-promo-banners">
                <h2 class="places-section-title">Offers &amp; highlights</h2>
                <div class="places-promo-grid">
                    <?php foreach ($promo_banners as $banner): ?>
                        <?php $bimg = places_image_url($banner['image_url']); ?>
                        <?php if (!empty($banner['link_url'])): ?>
                            <a href="<?php echo htmlspecialchars($banner['link_url']); ?>" class="places-promo-card" target="_blank" rel="noopener">
                                <img src="<?php echo htmlspecialchars($bimg); ?>" alt="<?php echo htmlspecialchars($banner['title'] ?? 'Promotion'); ?>" loading="lazy">
                                <?php if (!empty($banner['title'])): ?><span><?php echo htmlspecialchars($banner['title']); ?></span><?php endif; ?>
                            </a>
                        <?php else: ?>
                            <div class="places-promo-card">
                                <img src="<?php echo htmlspecialchars($bimg); ?>" alt="<?php echo htmlspecialchars($banner['title'] ?? 'Promotion'); ?>" loading="lazy">
                                <?php if (!empty($banner['title'])): ?><span><?php echo htmlspecialchars($banner['title']); ?></span><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($video_has): ?>
            <section>
                <h2 class="places-section-title">Experience it on video</h2>
                <?php if ($video['mode'] === 'file'): ?>
                    <video class="places-inline-video" controls playsinline preload="metadata"
                           src="<?php echo htmlspecialchars($video['src']); ?>"
                           title="<?php echo htmlspecialchars($place['name']); ?> video tour"></video>
                <?php else: ?>
                    <button type="button" class="places-video-preview"
                            <?php echo $video_attrs; ?>
                            data-video-title="<?php echo htmlspecialchars($place['name']); ?>">
                        <img src="<?php echo htmlspecialchars($hero_img); ?>" alt="">
                        <span class="places-video-play">
                            <span class="places-video-play-icon">▶</span>
                            <span>Play video tour</span>
                        </span>
                    </button>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <section class="places-referral-section" id="referral">
                <h2>Hotel staff referral</h2>
                <p style="color:var(--gb-muted);margin:0 0 1.25rem;max-width:560px;">
                    Working at a partner hotel? Generate a unique referral code for your guest in seconds.
                </p>

                <?php if ($generated_code): ?>
                    <div class="places-referral-panel">
                        <div class="places-alert places-alert-success">Referral code created successfully.</div>
                        <div class="places-code-result">
                            <div style="font-size:0.8rem;color:var(--gb-muted);text-transform:uppercase;letter-spacing:0.06em;">Your code</div>
                            <div class="code"><?php echo htmlspecialchars($generated_code); ?></div>
                            <p>Present this code to your guest for <strong><?php echo htmlspecialchars($place['name']); ?></strong>.</p>
                        </div>
                        <a href="place.php?slug=<?php echo urlencode($place['slug']); ?>" class="places-btn places-btn-primary" style="margin-top:1rem;display:inline-flex;">Generate another</a>
                    </div>
                <?php else: ?>
                    <div class="places-referral-panel">
                        <?php if ($error): ?>
                            <div class="places-alert places-alert-error"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <form method="post" action="place.php?slug=<?php echo urlencode($place['slug']); ?>#referral">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="place_id" value="<?php echo $place_id; ?>">
                            <label class="places-form-label" for="worker_name">Your name (waiter / staff)</label>
                            <input class="places-form-input" type="text" id="worker_name" name="worker_name"
                                   value="<?php echo htmlspecialchars($worker_name_value); ?>"
                                   placeholder="e.g. Jean Pierre" required autocomplete="name">
                            <label class="places-form-label" for="hotel_ref_code">Your hotel reference code</label>
                            <input class="places-form-input" type="text" id="hotel_ref_code" name="hotel_ref_code"
                                   value="<?php echo htmlspecialchars($hotel_ref_value); ?>"
                                   placeholder="e.g. PILOT-KIG-001" required>
                            <button type="submit" class="places-btn places-btn-primary" style="width:100%;justify-content:center;padding:0.9rem;">Generate referral code</button>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <aside>
            <div class="places-sidebar-card">
                <h3>Plan your visit</h3>
                <ul class="places-sidebar-list">
                    <li><span>Location</span><strong><?php echo htmlspecialchars($place['location']); ?></strong></li>
                    <?php if (!empty($place['price_range'])): ?>
                    <li><span>From</span><strong><?php echo htmlspecialchars($place['price_range']); ?></strong></li>
                    <?php endif; ?>
                    <?php if (!empty($place['hours_info'])): ?>
                    <li><span>Hours</span><strong><?php echo htmlspecialchars($place['hours_info']); ?></strong></li>
                    <?php endif; ?>
                    <?php if (!empty($place['contact_phone'])): ?>
                    <li><span>Phone</span><strong><a href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', $place['contact_phone'])); ?>"><?php echo htmlspecialchars($place['contact_phone']); ?></a></strong></li>
                    <?php endif; ?>
                    <?php if (!empty($place['contact_email'])): ?>
                    <li><span>Email</span><strong><a href="mailto:<?php echo htmlspecialchars($place['contact_email']); ?>"><?php echo htmlspecialchars($place['contact_email']); ?></a></strong></li>
                    <?php endif; ?>
                    <?php if (!empty($place['website_url'])): ?>
                    <li><span>Web</span><strong><a href="<?php echo htmlspecialchars($place['website_url']); ?>" target="_blank" rel="noopener">Visit website</a></strong></li>
                    <?php endif; ?>
                    <li><span>Network</span><strong><?php echo htmlspecialchars(APP_NAME); ?></strong></li>
                    <li><span>Listing</span><strong>Verified partner</strong></li>
                    <?php if ($video_has): ?>
                        <li><span>Media</span><strong>Video + gallery</strong></li>
                    <?php elseif (count($gallery) > 0): ?>
                        <li><span>Photos</span><strong><?php echo count($gallery) + 1; ?> images</strong></li>
                    <?php endif; ?>
                </ul>
                <p style="font-size:0.875rem;color:var(--gb-muted);margin:0 0 1rem;line-height:1.5;">
                    Recommended by Rwanda’s leading hotels for quality, location, and guest satisfaction.
                </p>
                <?php if ($video_has): ?>
                    <button type="button" class="places-btn places-btn-primary" style="width:100%;justify-content:center;margin-bottom:0.5rem;"
                            <?php echo $video_attrs; ?>
                            data-video-title="<?php echo htmlspecialchars($place['name']); ?>">
                        ▶ Watch video
                    </button>
                <?php endif; ?>
                <a href="#referral" class="places-btn places-btn-light" style="width:100%;justify-content:center;border:1px solid var(--gb-border);">Staff referral code</a>
            </div>
        </aside>
    </div>
</main>
<?php places_render_footer(); ?>
