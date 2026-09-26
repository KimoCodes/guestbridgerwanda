<?php
$acc = $c['account'];
$biz = $c['business'];
$img = !empty($acc['profile_image']) ? places_image_url($acc['profile_image']) : '';
?>
<form method="post" enctype="multipart/form-data" class="settings-form">
    <?php echo csrf_field(); ?>
    <?php settings_card_open('Profile & identity', 'Your login identity and hotel branding.'); ?>
    <div class="settings-grid-2">
        <div class="settings-field">
            <label for="name">Display name</label>
            <input type="text" id="name" name="name" required value="<?php echo sh($acc['name'] ?? ''); ?>">
        </div>
        <div class="settings-field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required value="<?php echo sh($acc['email'] ?? ''); ?>">
            <small class="text-muted">Verification: <?php echo !empty($acc['email_verified']) ? 'Verified' : 'Pending'; ?></small>
        </div>
        <div class="settings-field">
            <label for="phone">Phone</label>
            <input type="tel" id="phone" name="phone" value="<?php echo sh($acc['phone'] ?? ''); ?>">
        </div>
        <div class="settings-field">
            <label for="language">Language</label>
            <select id="language" name="language">
                <option value="en"<?php echo ($acc['language'] ?? 'en') === 'en' ? ' selected' : ''; ?>>English</option>
                <option value="fr"<?php echo ($acc['language'] ?? '') === 'fr' ? ' selected' : ''; ?>>Français</option>
                <option value="rw"<?php echo ($acc['language'] ?? '') === 'rw' ? ' selected' : ''; ?>>Kinyarwanda</option>
            </select>
        </div>
    </div>
    <div class="settings-field">
        <label>Hotel / business name</label>
        <div class="settings-readonly"><?php echo sh($biz['name'] ?? $c['user']['business_name']); ?></div>
    </div>
    <div class="settings-field">
        <label for="address">Business address</label>
        <input type="text" id="address" name="address" value="<?php echo sh($biz['address'] ?? ''); ?>">
    </div>
    <div class="settings-field">
        <label for="profile_image">Profile image / logo</label>
        <?php if ($img): ?><p><img src="<?php echo $img; ?>" alt="" style="max-height:64px;border-radius:8px;"></p><?php endif; ?>
        <input type="file" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/webp">
    </div>
    <div class="settings-actions">
        <button type="submit" name="update_profile" value="1" class="btn btn-primary">Save profile</button>
    </div>
    <?php settings_card_close(); ?>
</form>
