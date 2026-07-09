<?php
declare(strict_types=1);
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdmin();

$db = getDB();
$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    // Handle oversized uploads
    if ($csrfToken === '' && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false && empty($_POST)) {
        $message = 'The uploaded file exceeds the maximum allowed size.';
        $messageType = 'error';
    } elseif (!validateCsrfToken($csrfToken)) {
        die('Invalid CSRF token.');
    }

    if (isset($_POST['upload_photo']) && isset($_FILES['photo'])) {
        $cycleId = (int)$_POST['cycle_id'];
        $caption = trim($_POST['caption'] ?? '');

        if ($_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
            finfo_close($finfo);

            if (in_array($mime, $allowedTypes)) {
                $extension = match($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    default => 'jpg',
                };
                $filename = bin2hex(random_bytes(16)) . '.' . $extension;
                $uploadPath = '../uploads/' . $filename;

                if (!is_dir('../uploads')) {
                    mkdir('../uploads', 0755, true);
                }

                if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadPath)) {
                    $stmt = $db->prepare("INSERT INTO gallery (cycle_id, user_id, image_path, caption) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$cycleId, $userId, 'uploads/' . $filename, $caption]);
                    $message = 'Photo uploaded successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to upload photo.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Invalid file type. Please upload JPEG, PNG, GIF, or WebP images.';
                $messageType = 'error';
            }
        } else {
            $message = 'Error uploading file.';
            $messageType = 'error';
        }
    }

    if (isset($_POST['delete_photo'])) {
        $galleryId = (int)$_POST['gallery_id'];

        // Get image path before deleting
        $stmt = $db->prepare("SELECT image_path FROM gallery WHERE id = ?");
        $stmt->execute([$galleryId]);
        $image = $stmt->fetch();

        if ($image) {
            $imagePath = '../' . $image['image_path'];
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }

            $stmt = $db->prepare("DELETE FROM gallery WHERE id = ?");
            $stmt->execute([$galleryId]);

            $message = 'Photo deleted successfully.';
            $messageType = 'success';
        } else {
            $message = 'Photo not found.';
            $messageType = 'error';
        }
    }
}

// Get all cycles for the dropdown
$stmt = $db->prepare("SELECT id, name, status, start_date FROM cycles ORDER BY start_date DESC");
$stmt->execute();
$cycles = $stmt->fetchAll();

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

$csrf = generateCsrfToken();
$unseenAnnouncementCount = getUnseenAnnouncementCount($db, $_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery Management - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container">
        <?php require_once '../includes/header.php'; ?>

        <nav>
            <a href="../index.php">Home</a>
            <a href="../announcements.php" class="nav-link-with-badge">
                Announcements
                <?php if ($unseenAnnouncementCount > 0): ?>
                    <span class="notification-badge"><?php echo $unseenAnnouncementCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="../gallery.php">Gallery</a>
            <a href="../process.php">My Process</a>
            <a href="../profile.php">My Profile</a>
            <a href="../logout.php">Logout</a>
            <a href="index.php">Admin</a>
            <a href="gallery.php" class="active">Gallery Mgmt</a>
        </nav>

        <main>
            <h1>Gallery Management</h1>

            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <section class="admin-section">
                <h2>Upload Photo</h2>
                <form method="post" enctype="multipart/form-data" class="form">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <div class="form-group">
                        <label for="cycle_id">Cycle</label>
                        <select id="cycle_id" name="cycle_id" required>
                            <option value="">-- Select Cycle --</option>
                            <?php foreach ($cycles as $cycle): ?>
                                <option value="<?php echo $cycle['id']; ?>">
                                    <?php echo htmlspecialchars($cycle['name']); ?>
                                    (<?php echo $cycle['status'] === 'active' ? 'Active' : 'Closed'; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="photo">Photo</label>
                        <input type="file" id="photo" name="photo" accept="image/*" required>
                    </div>
                    <div class="form-group">
                        <label for="caption">Caption (optional)</label>
                        <input type="text" id="caption" name="caption" maxlength="255">
                    </div>
                    <button type="submit" name="upload_photo" class="btn">Upload Photo</button>
                </form>
            </section>

            <section class="admin-section">
                <h2>All Photos (<?php echo count($galleryItems); ?>)</h2>
                <?php if (empty($galleryItems)): ?>
                    <p class="empty-state">No photos in the gallery yet.</p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Cycle</th>
                                <th>Uploaded By</th>
                                <th>Caption</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($galleryItems as $item): ?>
                                <tr>
                                    <td>
                                        <a href="../<?php echo htmlspecialchars($item['image_path']); ?>" target="_blank">
                                            <img src="../<?php echo htmlspecialchars($item['image_path']); ?>"
                                                 alt="<?php echo ucfirst(CONTENT_TYPE); ?> photo"
                                                 style="width: 100px; height: 70px; object-fit: cover; border-radius: 4px;">
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['cycle_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['user_name']); ?></td>
                                    <td><?php echo $item['caption'] ? htmlspecialchars($item['caption']) : '-'; ?></td>
                                    <td><?php echo date('M j, Y', strtotime($item['created_at'])); ?></td>
                                    <td>
                                        <form method="post" class="inline-form" onsubmit="return confirm('Delete this photo? This cannot be undone.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="gallery_id" value="<?php echo $item['id']; ?>">
                                            <button type="submit" name="delete_photo" class="btn-small btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </main>

        <?php require_once '../includes/footer.php'; ?>
    </div>
</body>
</html>
