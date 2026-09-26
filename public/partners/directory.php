<?php
require_once __DIR__ . '/../../app/Config/database.php';
require_once __DIR__ . '/../../app/Domain/places.php';

db_init();
$pdo = db_connect();
$places = places_list_active($pdo);
$place_count = count($places);

places_render_public_head('Discover Partner Places', 'Explore curated hotels, spas, and experiences across Rwanda — recommended by GuestBridge partner hotels.');
places_render_header('list');
?>
<section class="places-list-hero">
    <div class="places-list-hero-inner">
        <img src="<?php echo places_logo_path(); ?>" alt="<?php echo htmlspecialchars(APP_NAME); ?>" class="places-hero-logo" width="64" height="64">
        <h1>Discover Rwanda’s finest partner places</h1>
        <p>Hand-picked hotels, wellness retreats, and experiences trusted by leading hotels on the <?php echo htmlspecialchars(APP_NAME); ?> network.</p>
        <div class="places-hero-stats">
            <div class="places-hero-stat">
                <strong><?php echo (int) $place_count; ?></strong>
                <span>Partner places</span>
            </div>
            <div class="places-hero-stat">
                <strong>100%</strong>
                <span>Verified listings</span>
            </div>
            <div class="places-hero-stat">
                <strong>RWF</strong>
                <span>Local experiences</span>
            </div>
        </div>
    </div>
</section>

<main class="places-main" id="places-grid">
    <div class="places-section-head">
        <h2>Where would you like to go?</h2>
        <p>Browse our partner directory — each listing includes photos, video, and everything you need to plan a memorable visit.</p>
    </div>

    <?php if (empty($places)): ?>
        <div class="places-empty">
            <p><strong>No partner places published yet.</strong></p>
            <p>Check back soon as new destinations join the network.</p>
        </div>
    <?php else: ?>
        <div class="places-grid">
            <?php foreach ($places as $place): ?>
                <?php
                    $listing_img = places_resolve_listing_image($pdo, $place);
                    $card_img = !empty($place['banner_image']) && !places_is_stock_placeholder($place['banner_image'])
                        ? $place['banner_image']
                        : $listing_img;
                    $img = places_image_url($card_img);
                    $excerpt = places_excerpt($place['description'] ?? '', 120);
                ?>
                <a href="place.php?slug=<?php echo urlencode($place['slug']); ?>" class="places-card">
                    <div class="places-card-media">
                        <img src="<?php echo htmlspecialchars($img); ?>"
                             alt="<?php echo htmlspecialchars($place['name']); ?>"
                             loading="lazy">
                        <?php if (!empty($place['is_featured'])): ?>
                            <span class="places-card-badge places-card-badge-featured">★ Featured</span>
                        <?php endif; ?>
                        <span class="places-card-badge">📍 <?php echo htmlspecialchars($place['location']); ?></span>
                        <div class="places-card-overlay">
                            <span class="places-card-cta-text">View full experience →</span>
                        </div>
                    </div>
                    <div class="places-card-body">
                        <h2 class="places-card-name"><?php echo htmlspecialchars($place['name']); ?></h2>
                        <?php if (!empty($place['promo_tagline'])): ?>
                            <p class="places-card-tagline"><?php echo htmlspecialchars($place['promo_tagline']); ?></p>
                        <?php endif; ?>
                        <?php
                        $card_specialties = places_parse_list_field($place['specialties'] ?? '');
                        if (!empty($card_specialties)):
                        ?>
                        <ul class="places-card-specialties">
                            <?php foreach (array_slice($card_specialties, 0, 3) as $sp): ?>
                                <li><?php echo htmlspecialchars($sp); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                        <p class="places-card-location"><?php echo htmlspecialchars($place['location']); ?></p>
                        <?php if (!empty($place['price_range'])): ?>
                            <p class="places-card-price"><?php echo htmlspecialchars($place['price_range']); ?></p>
                        <?php endif; ?>
                        <?php if ($excerpt): ?>
                            <p class="places-card-excerpt"><?php echo htmlspecialchars($excerpt); ?></p>
                        <?php endif; ?>
                        <div class="places-card-footer">Explore photos, video &amp; details</div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php places_render_footer(); ?>
