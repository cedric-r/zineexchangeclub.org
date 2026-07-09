<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$db = getDB();

// Get all gallery entries with user and cycle info
$stmt = $db->prepare("
    SELECT g.*, u.name as user_name, c.name as cycle_name
    FROM gallery g
    JOIN users u ON g.user_id = u.id
    JOIN cycles c ON g.cycle_id = c.id
    ORDER BY g.created_at DESC
");
$stmt->execute();
$galleryItems = $stmt->fetchAll();

// Get unseen announcement count for logged in users
$unseenAnnouncementCount = 0;
if (isLoggedIn()) {
    $unseenAnnouncementCount = getUnseenAnnouncementCount($db, $_SESSION['user_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <?php require_once 'includes/header.php'; ?>
        
        <nav>
            <a href="index.php">Home</a>
            <?php if (isLoggedIn()): ?>
                <a href="announcements.php" class="nav-link-with-badge">
                    Announcements
                    <?php if ($unseenAnnouncementCount > 0): ?>
                        <span class="notification-badge"><?php echo $unseenAnnouncementCount; ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            <a href="gallery.php" class="active">Gallery</a>
            <?php if (isLoggedIn()): ?>
                <a href="process.php">My Process</a>
                <a href="profile.php">My Profile</a>
                <a href="logout.php">Logout</a>
            <?php else: ?>
                <a href="login.php">Login</a>
                <a href="register.php">Register</a>
            <?php endif; ?>
            <?php if (isAdmin()): ?>
                <a href="admin/index.php">Admin</a>
                <a href="admin/gallery.php">Gallery Mgmt</a>
            <?php endif; ?>
        </nav>
        
        <main>
            <h1><?php echo ucfirst(CONTENT_TYPE); ?> Gallery</h1>
            <p class="subtitle">Photos of <?php echo CONTENT_TYPE; ?>s received by our participants</p>

            <?php if (empty($galleryItems)): ?>
                <p class="empty-state">No <?php echo CONTENT_TYPE; ?>s have been uploaded to the gallery yet. Check back soon!</p>
            <?php else: ?>
                <div class="gallery-grid">
                    <?php foreach ($galleryItems as $item): ?>
                        <div class="gallery-item">
                            <img src="<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo ucfirst(CONTENT_TYPE); ?> photo"
                                 onclick="openLightbox(this, '<?php echo htmlspecialchars($item['caption'] ?? ''); ?>', '<?php echo htmlspecialchars($item['user_name']); ?>', '<?php echo htmlspecialchars($item['cycle_name']); ?>')">
                            <div class="gallery-info">
                                <p class="by">by <?php echo htmlspecialchars($item['user_name']); ?></p>
                                <p class="cycle"><?php echo htmlspecialchars($item['cycle_name']); ?></p>
                                <?php if ($item['caption']): ?>
                                    <p class="caption"><?php echo htmlspecialchars($item['caption']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
        
        <?php require_once 'includes/footer.php'; ?>
    </div>

    <div id="lightbox" class="lightbox" onclick="closeLightbox()">
        <span class="lightbox-close">&times;</span>
        <img id="lightbox-img" src="" alt="">
        <div id="lightbox-caption" class="lightbox-caption"></div>
    </div>

    <script>
        function openLightbox(img, caption, userName, cycleName) {
            document.getElementById('lightbox-img').src = img.src;
            var parts = [];
            if (caption) parts.push(caption);
            parts.push('by ' + userName + ' &middot; ' + cycleName);
            document.getElementById('lightbox-caption').innerHTML = parts.join('<br>');
            document.getElementById('lightbox').classList.add('open');
        }

        function closeLightbox() {
            document.getElementById('lightbox').classList.remove('open');
        }
    </script>
</body>
</html>
