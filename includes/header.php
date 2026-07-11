<?php if (isset($_SESSION['_impersonating_origin_id']) && isset($_SESSION['_impersonating_origin_name'])): ?>
<div style="background:#dc3545;color:#fff;padding:8px 16px;text-align:center;font-size:14px;position:sticky;top:0;z-index:9999;">
    <strong>Impersonating:</strong> <?php echo htmlspecialchars($_SESSION['user_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
    (ID: <?php echo (int)($_SESSION['user_id'] ?? 0); ?>)
    &nbsp;|&nbsp;
    <form method="post" action="<?php echo SITE_URL; ?>/admin/index.php" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        <button type="submit" name="stop_impersonating" 
                style="background:#fff;color:#dc3545;border:none;border-radius:3px;padding:2px 10px;cursor:pointer;font-size:13px;">
            Stop Impersonating
        </button>
    </form>
</div>
<?php endif; ?>
<header>
    <img src="<?php echo SITE_URL; ?>/zineexchangeclub_banner.png" alt="Banner" class="banner">
    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
        <span></span>
        <span></span>
        <span></span>
    </button>
</header>
