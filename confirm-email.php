<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'includes/auth.php';

$token = $_GET['token'] ?? '';
$message = '';
$success = false;

if ($token) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, name FROM users WHERE email_confirmation_token = ? AND email_confirmed = 0 AND (email_token_expires IS NULL OR email_token_expires > NOW())");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user) {
        $stmt = $db->prepare("UPDATE users SET email_confirmed = 1, email_confirmation_token = NULL WHERE id = ?");
        $stmt->execute([$user['id']]);
        $success = true;
        $message = 'Your email has been confirmed! You can now log in and participate in exchange cycles.';
    } else {
        $message = 'Invalid or expired confirmation token. The token may have already been used.';
    }
} else {
    $message = 'No confirmation token provided.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Confirmation - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <?php require_once 'includes/header.php'; ?>
        
        <nav>
            <a href="index.php">Home</a>
            <a href="gallery.php">Gallery</a>
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
            <?php endif; ?>
        </nav>
        
        <main>
            <div class="message-box <?php echo $success ? 'success' : 'error'; ?>">
                <h1><?php echo $success ? 'Email Confirmed!' : 'Confirmation Failed'; ?></h1>
                <p><?php echo htmlspecialchars($message); ?></p>
                <?php if ($success): ?>
                    <a href="login.php" class="btn">Login Now</a>
                <?php else: ?>
                    <a href="index.php" class="btn">Return Home</a>
                <?php endif; ?>
            </div>
        </main>
        
        <?php require_once 'includes/footer.php'; ?>
    </div>
</body>
</html>
