<?php
/** @var array $ctx */
require_once __DIR__ . '/_helpers.php';
$c = $ctx;
$tab = $c['tab'];
?>
<div class="settings-hub">
    <?php echo render_breadcrumbs(['Settings' => false]); ?>
    <div class="gb-page-header settings-hub-header">
        <div>
            <h1 class="gb-page-title">Settings</h1>
            <p class="gb-page-description">Control center for <strong><?php echo htmlspecialchars($c['user']['business_name']); ?></strong> — account, payments, public listing, and team.</p>
        </div>
    </div>

    <?php if ($c['flash']): ?>
        <div class="alert alert-success settings-flash"><?php echo htmlspecialchars($c['flash']); ?></div>
    <?php endif; ?>
    <?php foreach ($c['errors'] as $err): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
    <?php endforeach; ?>

    <div class="settings-hub-layout">
        <nav class="settings-nav" aria-label="Settings sections">
            <?php foreach ($c['tabs'] as $key => $meta): ?>
                <a href="settings.php?tab=<?php echo urlencode($key); ?>"
                   class="settings-nav-link<?php echo $key === $tab ? ' is-active' : ''; ?>">
                    <?php echo htmlspecialchars($meta['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="settings-panel">
            <?php
            $tab_file = __DIR__ . '/' . preg_replace('/[^a-z]/', '', $tab) . '.php';
            if (is_file($tab_file)) {
                include $tab_file;
            } else {
                echo '<p class="text-muted">Section not available.</p>';
            }
            ?>
        </div>
    </div>
</div>
