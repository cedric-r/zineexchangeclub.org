<?php
require_once 'config.php';
require_once 'includes/auth.php';

requireLogin();

$db = getDB();
$message = '';
$messageType = '';

// Handle form submissions for admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isAdmin()) {
    if (isset($_POST['add_announcement'])) {
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        
        if ($title && $content) {
            try {
                $stmt = $db->prepare("INSERT INTO announcements (title, content, created_by) VALUES (?, ?, ?)");
                $stmt->execute([$title, $content, $_SESSION['user_id']]);
                $message = 'Announcement added successfully!';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Failed to add announcement.';
                $messageType = 'error';
            }
        } else {
            $message = 'Title and content are required.';
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['edit_announcement'])) {
        $announcementId = (int)$_POST['announcement_id'];
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        
        if ($title && $content) {
            try {
                $stmt = $db->prepare("UPDATE announcements SET title = ?, content = ? WHERE id = ?");
                $stmt->execute([$title, $content, $announcementId]);
                $message = 'Announcement updated successfully!';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Failed to update announcement.';
                $messageType = 'error';
            }
        } else {
            $message = 'Title and content are required.';
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['delete_announcement'])) {
        $announcementId = (int)$_POST['announcement_id'];
        
        try {
            $stmt = $db->prepare("DELETE FROM announcements WHERE id = ?");
            $stmt->execute([$announcementId]);
            $message = 'Announcement deleted successfully!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Failed to delete announcement.';
            $messageType = 'error';
        }
    }
}

// Get all announcements in reverse chronological order
$stmt = $db->prepare("
    SELECT a.*, u.name as created_by_name,
           (SELECT COUNT(*) FROM announcement_views av WHERE av.announcement_id = a.id AND av.user_id = ?) as has_viewed
    FROM announcements a 
    JOIN users u ON a.created_by = u.id 
    ORDER BY a.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$announcements = $stmt->fetchAll();

// Mark all announcements as viewed for this user
$announcementIds = array_column($announcements, 'id');
if (!empty($announcementIds)) {
    $placeholders = str_repeat('?,', count($announcementIds) - 1) . '?';
    $stmt = $db->prepare("
        INSERT IGNORE INTO announcement_views (announcement_id, user_id) 
        VALUES " . str_repeat('(?, ?),', count($announcementIds) - 1) . "(?, ?)
    ");
    $params = [];
    foreach ($announcementIds as $id) {
        $params[] = $id;
        $params[] = $_SESSION['user_id'];
    }
    $stmt->execute($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - Zine Exchange Club</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <?php require_once 'includes/header.php'; ?>
        
        <nav>
            <a href="index.php">Home</a>
            <a href="announcements.php" class="active">Announcements</a>
            <a href="gallery.php">Gallery</a>
            <a href="process.php">My Process</a>
            <a href="profile.php">My Profile</a>
            <a href="logout.php">Logout</a>
            <?php if (isAdmin()): ?>
                <a href="admin/index.php">Admin</a>
            <?php endif; ?>
        </nav>
        
        <main>
            <h1>Announcements</h1>
            
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if (isAdmin()): ?>
                <section class="admin-section">
                    <h2>Manage Announcements</h2>
                    
                    <!-- Add new announcement form -->
                    <div class="announcement-form">
                        <h3>Add New Announcement</h3>
                        <form method="post" class="form">
                            <div class="form-group">
                                <label for="title">Title *</label>
                                <input type="text" id="title" name="title" required placeholder="Enter announcement title">
                            </div>
                            <div class="form-group">
                                <label for="content">Content *</label>
                                <textarea id="content" name="content" required rows="6" placeholder="Enter announcement content"></textarea>
                            </div>
                            <button type="submit" name="add_announcement" class="btn">Add Announcement</button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
            
            <section class="announcements-list">
                <h2>Latest Announcements</h2>
                
                <?php if (empty($announcements)): ?>
                    <p class="empty-state">No announcements yet.</p>
                <?php else: ?>
                    <?php foreach ($announcements as $announcement): ?>
                        <article class="announcement">
                            <div class="announcement-header">
                                <h3><?php echo htmlspecialchars($announcement['title']); ?></h3>
                                <div class="announcement-meta">
                                    <span class="date">Posted: <?php echo date('F j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
                                    <span class="author">by <?php echo htmlspecialchars($announcement['created_by_name']); ?></span>
                                    <?php if ($announcement['updated_at'] !== $announcement['created_at']): ?>
                                        <span class="updated">Updated: <?php echo date('F j, Y g:i A', strtotime($announcement['updated_at'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="announcement-content">
                                <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
                            </div>
                            
                            <?php if (isAdmin()): ?>
                                <div class="announcement-actions">
                                    <button class="btn-small" onclick="editAnnouncement(<?php echo $announcement['id']; ?>, '<?php echo htmlspecialchars($announcement['title']); ?>', '<?php echo htmlspecialchars($announcement['content'], ENT_QUOTES); ?>')">Edit</button>
                                    <button class="btn-small btn-danger" onclick="deleteAnnouncement(<?php echo $announcement['id']; ?>, '<?php echo htmlspecialchars($announcement['title']); ?>')">Delete</button>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </main>
        
        <?php require_once 'includes/footer.php'; ?>
    </div>
    
    <?php if (isAdmin()): ?>
        <!-- Edit announcement modal -->
        <div id="editModal" class="modal" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Edit Announcement</h2>
                    <button class="modal-close" onclick="closeEditModal()">&times;</button>
                </div>
                <form method="post" class="form">
                    <input type="hidden" name="announcement_id" id="edit_announcement_id">
                    <div class="form-group">
                        <label for="edit_title">Title *</label>
                        <input type="text" id="edit_title" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_content">Content *</label>
                        <textarea id="edit_content" name="content" required rows="6"></textarea>
                    </div>
                    <button type="submit" name="edit_announcement" class="btn">Update Announcement</button>
                </form>
            </div>
        </div>
        
        <script>
            function editAnnouncement(id, title, content) {
                document.getElementById('edit_announcement_id').value = id;
                document.getElementById('edit_title').value = title;
                document.getElementById('edit_content').value = content;
                document.getElementById('editModal').style.display = 'block';
            }
            
            function closeEditModal() {
                document.getElementById('editModal').style.display = 'none';
            }
            
            function deleteAnnouncement(id, title) {
                if (confirm('Are you sure you want to delete the announcement "' + title + '"? This action cannot be undone.')) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = '<input type="hidden" name="announcement_id" value="' + id + '"><input type="hidden" name="delete_announcement" value="1">';
                    document.body.appendChild(form);
                    form.submit();
                }
            }
            
            // Close modal when clicking outside
            window.onclick = function(event) {
                const modal = document.getElementById('editModal');
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            }
        </script>
    <?php endif; ?>
</body>
</html>
