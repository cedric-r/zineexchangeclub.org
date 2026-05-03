<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$db = getDB();
$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Get user info
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Get zine info
$stmt = $db->prepare("SELECT * FROM zines WHERE user_id = ?");
$stmt->execute([$userId]);
$zine = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_personal_info'])) {
        $name = trim($_POST['name'] ?? '');
        $postalAddress = trim($_POST['postal_address'] ?? '');
        $acceptsAdultZines = isset($_POST['accepts_adult_zines']) ? 1 : 0;
        $country = trim($_POST['country'] ?? '');
        
        if (empty($name) || empty($postalAddress) || empty($country)) {
            $message = 'All personal information fields are required.';
            $messageType = 'error';
        } else {
            try {
                $stmt = $db->prepare("UPDATE users SET name = ?, postal_address = ?, accepts_adult_zines = ?, country = ? WHERE id = ?");
                $stmt->execute([$name, $postalAddress, $acceptsAdultZines, $country, $userId]);
                
                // Refresh data
                $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                
                $message = 'Personal information updated successfully!';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Update failed. Please try again.';
                $messageType = 'error';
            }
        }
    }
    
    if (isset($_POST['update_zine_info'])) {
        $zineTheme = trim($_POST['zine_theme'] ?? '');
        $zineFormat = trim($_POST['zine_format'] ?? '');
        
        if (empty($zineTheme) || empty($zineFormat)) {
            $message = 'All zine information fields are required.';
            $messageType = 'error';
        } else {
            try {
                if ($zine) {
                    $stmt = $db->prepare("UPDATE zines SET theme = ?, format = ? WHERE user_id = ?");
                    $stmt->execute([$zineTheme, $zineFormat, $userId]);
                } else {
                    $stmt = $db->prepare("INSERT INTO zines (user_id, theme, format) VALUES (?, ?, ?)");
                    $stmt->execute([$userId, $zineTheme, $zineFormat]);
                }
                
                // Refresh data
                $stmt = $db->prepare("SELECT * FROM zines WHERE user_id = ?");
                $stmt->execute([$userId]);
                $zine = $stmt->fetch();
                
                $message = 'Zine information updated successfully!';
                $messageType = 'success';
            } catch (Exception $e) {
                $message = 'Update failed. Please try again.';
                $messageType = 'error';
            }
        }
    }
    
    if (isset($_POST['update_password'])) {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $message = 'All password fields are required.';
            $messageType = 'error';
        } elseif (!password_verify($currentPassword, $user['password'])) {
            $message = 'Current password is incorrect.';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $message = 'New password must be at least 8 characters long.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'New passwords do not match.';
            $messageType = 'error';
        } else {
            $hashedPassword = hashPassword($newPassword);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashedPassword, $userId]);
            
            $message = 'Password updated successfully!';
            $messageType = 'success';
        }
    }
}

// Get unseen announcement count
$unseenAnnouncementCount = getUnseenAnnouncementCount($db, $userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Zine Exchange Club</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <?php require_once 'includes/header.php'; ?>
        
        <nav>
            <a href="index.php">Home</a>
            <a href="announcements.php" class="nav-link-with-badge">
                    Announcements
                    <?php if ($unseenAnnouncementCount > 0): ?>
                        <span class="notification-badge"><?php echo $unseenAnnouncementCount; ?></span>
                    <?php endif; ?>
                </a>
            <a href="gallery.php">Gallery</a>
            <a href="process.php">My Process</a>
            <a href="profile.php" class="active">My Profile</a>
            <a href="logout.php">Logout</a>
            <?php if (isAdmin()): ?>
                <a href="admin/index.php">Admin</a>
            <?php endif; ?>
        </nav>
        
        <main>
            <h1>My Profile</h1>
            
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <section class="admin-section">
                <h2>Personal Information</h2>
                <form method="post" class="form">
                    <input type="hidden" name="update_personal_info" value="1">
                    
                    <div class="form-group">
                        <label for="name">Name *</label>
                        <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($user['name']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                        <small>Email cannot be changed</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="postal_address">Postal Address *</label>
                        <textarea id="postal_address" name="postal_address" required rows="4"><?php echo htmlspecialchars($user['postal_address']); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="country">Country *</label>
                        <input type="text" id="country" name="country" required value="<?php echo htmlspecialchars($user['country']); ?>">
                    </div>
                    
                    <div class="form-group checkbox">
                        <input type="checkbox" id="accepts_adult_zines" name="accepts_adult_zines" <?php echo $user['accepts_adult_zines'] ? 'checked' : ''; ?>>
                        <label for="accepts_adult_zines">I accept to receive adult-themed zines</label>
                    </div>
                    
                    <button type="submit" class="btn">Update Profile</button>
                </form>
            </section>
            
            <section class="admin-section">
                <h2>My Zine</h2>
                <form method="post" class="form">
                    <input type="hidden" name="update_zine_info" value="1">
                    
                    <div class="form-group">
                        <label for="zine_theme">Theme/Description *</label>
                        <textarea id="zine_theme" name="zine_theme" required rows="4"><?php echo htmlspecialchars($zine['theme'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="zine_format">Format *</label>
                        <select id="zine_format" name="zine_format" required>
                            <option value="">Select format...</option>
                            <option value="folded" <?php echo ($zine['format'] ?? '') === 'folded' ? 'selected' : ''; ?>>Folded</option>
                            <option value="stapled" <?php echo ($zine['format'] ?? '') === 'stapled' ? 'selected' : ''; ?>>Stapled</option>
                            <option value="bound" <?php echo ($zine['format'] ?? '') === 'bound' ? 'selected' : ''; ?>>Bound</option>
                            <option value="other" <?php echo ($zine['format'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn">Update Zine Info</button>
                </form>
            </section>
            
            <section class="admin-section">
                <h2>Change Password</h2>
                <form method="post" class="form">
                    <input type="hidden" name="update_password" value="1">
                    
                    <div class="form-group">
                        <label for="current_password">Current Password *</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password *</label>
                        <input type="password" id="new_password" name="new_password" required minlength="8">
                        <small>Minimum 8 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                    </div>
                    
                    <button type="submit" class="btn">Update Password</button>
                </form>
            </section>
        </main>
        
        <?php require_once 'includes/footer.php'; ?>
    </div>
</body>
</html>
