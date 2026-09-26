<?php
if (!$c['is_manager'] || empty($c['place'])): ?>
<p class="text-muted">Only hotel managers can edit the public partner listing.</p>
<?php return; endif;
$place = $c['place'];
$pid = (int) $place['id'];
$feat = places_image_url($place['featured_image'] ?? '');
$banner = !empty($place['banner_image']) ? places_image_url($place['banner_image']) : '';
?>
<?php settings_card_open('Public listing', 'Photos, video, and story guests see on partner_places.php and place.php.'); ?>
<p>Preview: <a href="<?php echo sh($c['place_public_url']); ?>" target="_blank" rel="noopener">Open public page ↗</a>
 · Directory: <a href="/guestbridgerwanda/partner_places.php" target="_blank" rel="noopener">partner_places.php</a></p>

<form method="post" enctype="multipart/form-data" class="settings-form">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="place_id" value="<?php echo $pid; ?>">
    <div class="settings-grid-2">
        <div class="settings-field">
            <label for="place_name">Listing name</label>
            <input type="text" id="place_name" name="place_name" required value="<?php echo sh($place['name'] ?? ''); ?>">
        </div>
        <div class="settings-field">
            <label for="place_location">Location</label>
            <input type="text" id="place_location" name="place_location" value="<?php echo sh($place['location'] ?? ''); ?>">
        </div>
        <div class="settings-field">
            <label for="place_status">Publish status</label>
            <select id="place_status" name="place_status">
                <option value="inactive"<?php echo ($place['status'] ?? '') === 'inactive' ? ' selected' : ''; ?>>Draft (hidden)</option>
                <option value="active"<?php echo ($place['status'] ?? '') === 'active' ? ' selected' : ''; ?>>Active (visible)</option>
            </select>
        </div>
        <div class="settings-field">
            <label for="promo_tagline">Promo tagline</label>
            <input type="text" id="promo_tagline" name="promo_tagline" maxlength="255" value="<?php echo sh($place['promo_tagline'] ?? ''); ?>">
        </div>
    </div>
    <div class="settings-field">
        <label for="place_description">Short introduction</label>
        <textarea id="place_description" name="place_description" rows="3" placeholder="A brief welcome paragraph guests see first."><?php echo sh($place['description'] ?? ''); ?></textarea>
    </div>
    <div class="settings-field">
        <label for="place_extended_about">More about your hotel</label>
        <textarea id="place_extended_about" name="place_extended_about" rows="5" placeholder="History, awards, what makes your property unique, nearby attractions…"><?php echo sh($place['extended_about'] ?? ''); ?></textarea>
    </div>
    <div class="settings-grid-2">
        <div class="settings-field">
            <label for="place_specialties">Specialties &amp; signature experiences</label>
            <textarea id="place_specialties" name="place_specialties" rows="4" placeholder="One per line, e.g.&#10;Lake-view suites&#10;Farm-to-table dining&#10;Conference &amp; events"><?php echo sh($place['specialties'] ?? ''); ?></textarea>
            <small class="text-muted">One item per line (shown as tags on your public page).</small>
        </div>
        <div class="settings-field">
            <label for="place_amenities">Amenities &amp; services</label>
            <textarea id="place_amenities" name="place_amenities" rows="4" placeholder="One per line, e.g.&#10;Free Wi‑Fi&#10;Airport shuttle&#10;Spa &amp; pool"><?php echo sh($place['amenities'] ?? ''); ?></textarea>
        </div>
    </div>
    <div class="settings-grid-2">
        <div class="settings-field">
            <label for="place_price_range">Price guide (optional)</label>
            <input type="text" id="place_price_range" name="place_price_range" maxlength="120"
                   placeholder="e.g. From RWF 85,000 / night" value="<?php echo sh($place['price_range'] ?? ''); ?>">
        </div>
        <div class="settings-field">
            <label for="place_hours_info">Opening / reception hours</label>
            <input type="text" id="place_hours_info" name="place_hours_info" maxlength="500"
                   placeholder="e.g. Open 24/7 · Check-in 2pm" value="<?php echo sh($place['hours_info'] ?? ''); ?>">
        </div>
        <div class="settings-field">
            <label for="place_contact_phone">Public phone</label>
            <input type="tel" id="place_contact_phone" name="place_contact_phone" maxlength="80"
                   value="<?php echo sh($place['contact_phone'] ?? ''); ?>">
        </div>
        <div class="settings-field">
            <label for="place_contact_email">Public email</label>
            <input type="email" id="place_contact_email" name="place_contact_email" maxlength="255"
                   value="<?php echo sh($place['contact_email'] ?? ''); ?>">
        </div>
        <div class="settings-field">
            <label for="place_website_url">Website</label>
            <input type="url" id="place_website_url" name="place_website_url" maxlength="500"
                   placeholder="https://yourhotel.com" value="<?php echo sh($place['website_url'] ?? ''); ?>">
        </div>
    </div>
    <div class="settings-grid-2">
        <div class="settings-field">
            <label>Featured image</label>
            <?php if ($feat): ?><p><img src="<?php echo sh($feat); ?>" alt="" style="max-height:100px;border-radius:8px;"></p><?php endif; ?>
            <input type="file" name="featured_upload" accept="image/jpeg,image/png,image/webp">
            <input type="hidden" name="featured_image" value="<?php echo sh($place['featured_image'] ?? ''); ?>">
        </div>
        <div class="settings-field">
            <label>Hero banner image</label>
            <?php if ($banner): ?><p><img src="<?php echo sh($banner); ?>" alt="" style="max-height:80px;border-radius:8px;"></p><?php endif; ?>
            <input type="file" name="banner_upload" accept="image/jpeg,image/png,image/webp">
            <input type="hidden" name="banner_image" value="<?php echo sh($place['banner_image'] ?? ''); ?>">
        </div>
    </div>
    <div class="settings-grid-2">
        <div class="settings-field">
            <label><input type="checkbox" name="is_featured" value="1"<?php echo !empty($place['is_featured']) ? ' checked' : ''; ?>> Featured on directory</label>
        </div>
        <div class="settings-field">
            <label for="sort_order">Sort order</label>
            <input type="number" id="sort_order" name="sort_order" value="<?php echo (int) ($place['sort_order'] ?? 0); ?>">
        </div>
    </div>
    <button type="submit" name="save_place_content" value="1" class="btn btn-primary">Save listing</button>
</form>
<?php settings_card_close(); ?>

<?php
$vid = $place['video_url'] ?? '';
$vid_is_file = function_exists('place_is_uploaded_video') && place_is_uploaded_video($vid);
$vid_playback = places_video_playback($vid);
$max_mb = round(place_max_video_upload_bytes() / 1024 / 1024, 1);
?>
<?php settings_card_open('Video tour', 'Saved separately so uploads are not dropped when saving other fields.'); ?>
<form method="post" enctype="multipart/form-data" class="settings-form">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="place_id" value="<?php echo $pid; ?>">
    <input type="hidden" name="video_url_current" value="<?php echo sh($vid); ?>">
    <p class="text-muted small mb-2">Server limit: about <strong><?php echo sh((string) $max_mb); ?> MB</strong> per file (PHP <code>upload_max_filesize</code>).</p>
    <?php if ($vid_playback): ?>
        <p class="mb-2">Current:
            <?php if ($vid_is_file): ?>
                <strong>Uploaded file</strong> — <a href="<?php echo sh($vid_playback['src']); ?>" target="_blank" rel="noopener">Open video</a>
            <?php else: ?>
                <strong>External embed</strong> — <code><?php echo sh($vid); ?></code>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <div class="settings-field">
        <label for="video_upload_only">Upload video (MP4 or WebM)</label>
        <input type="file" id="video_upload_only" name="video_upload" accept="video/mp4,video/webm,.mp4,.webm">
    </div>
    <div class="settings-field">
        <label for="video_external_url_only">Or YouTube / Vimeo URL</label>
        <input type="url" id="video_external_url_only" name="video_external_url" placeholder="https://www.youtube.com/watch?v=..."
               value="<?php echo $vid_is_file ? '' : sh($vid); ?>">
    </div>
    <div class="settings-actions">
        <button type="submit" name="save_place_video" value="1" class="btn btn-primary">Save video</button>
        <?php if ($vid !== ''): ?>
        <button type="submit" name="remove_place_video" value="1" class="btn btn-outline-danger" onclick="return confirm('Remove video from this listing?');">Remove video</button>
        <?php endif; ?>
    </div>
</form>
<?php settings_card_close(); ?>

<?php settings_card_open('Photo gallery', 'Add or remove gallery images on your public page.'); ?>
<form method="post" enctype="multipart/form-data" class="settings-form mb-3">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="place_id" value="<?php echo $pid; ?>">
    <div class="settings-grid-2">
        <div class="settings-field">
            <label>Upload image</label>
            <input type="file" name="gallery_upload" accept="image/jpeg,image/png,image/webp">
        </div>
        <div class="settings-field">
            <label for="gallery_image_url">Or image URL</label>
            <input type="url" id="gallery_image_url" name="gallery_image_url" placeholder="https://...">
        </div>
        <div class="settings-field">
            <label for="gallery_caption">Caption</label>
            <input type="text" id="gallery_caption" name="gallery_caption">
        </div>
    </div>
    <button type="submit" name="add_gallery_image" value="1" class="btn btn-outline-primary">Add to gallery</button>
</form>
<?php if (!empty($c['place_gallery'])): ?>
<div class="settings-media-grid">
    <?php foreach ($c['place_gallery'] as $img): ?>
    <div class="settings-thumb">
        <img src="<?php echo sh(places_image_url($img['image_url'])); ?>" alt="">
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="delete_gallery_id" value="<?php echo (int) $img['id']; ?>">
            <button type="submit" class="btn btn-sm btn-danger" title="Delete">×</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<p class="text-muted">No gallery images yet.</p>
<?php endif; ?>
<?php settings_card_close(); ?>

<?php settings_card_open('Promotional banners & ads', 'Optional banners on your detail page.'); ?>
<form method="post" enctype="multipart/form-data" class="settings-form mb-3">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="place_id" value="<?php echo $pid; ?>">
    <div class="settings-grid-2">
        <div class="settings-field">
            <label for="banner_title">Title</label>
            <input type="text" id="banner_title" name="banner_title">
        </div>
        <div class="settings-field">
            <label>Upload banner</label>
            <input type="file" name="banner_ad_upload" accept="image/jpeg,image/png,image/webp">
        </div>
        <div class="settings-field">
            <label for="banner_image_url">Or image URL</label>
            <input type="url" id="banner_image_url" name="banner_image_url">
        </div>
        <div class="settings-field">
            <label for="banner_link_url">Link URL</label>
            <input type="url" id="banner_link_url" name="banner_link_url">
        </div>
    </div>
    <label><input type="checkbox" name="banner_active" value="1" checked> Active</label>
    <button type="submit" name="add_banner" value="1" class="btn btn-outline-primary mt-2">Add banner</button>
</form>
<?php if (!empty($c['place_banners'])): ?>
<div class="settings-media-grid">
    <?php foreach ($c['place_banners'] as $b): ?>
    <div class="settings-thumb">
        <img src="<?php echo sh(places_image_url($b['image_url'])); ?>" alt="<?php echo sh($b['title'] ?? ''); ?>">
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="delete_banner_id" value="<?php echo (int) $b['id']; ?>">
            <button type="submit" class="btn btn-sm btn-danger">×</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php settings_card_close(); ?>
