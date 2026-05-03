<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/email.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        $db = getDB();
        
        // Check if email exists
        $stmt = $db->prepare("SELECT id, name, email_confirmed FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            if (!$user['email_confirmed']) {
                $error = 'Please confirm your email address before requesting a password reset.';
            } else {
                // Generate reset token
                $token = generateToken();
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                $stmt = $db->prepare("UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?");
                $stmt->execute([$token, $expires, $user['id']]);
                
                // Send reset email
                $emailBody = getPasswordResetEmail($user['name'], $token);
                if (sendEmail($email, 'Password Reset - Zine Exchange Club', $emailBody)) {
                    $success = 'Password reset link has been sent to your email. The link will expire in 1 hour.';
                } else {
                    $error = 'There was an error sending the reset email. Please try again.';
                }
            }
        } else {
            // Don't reveal if email exists or not for security
            $success = 'If an account with this email exists, a password reset link has been sent.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Zine Exchange Club</title>
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
            <h1>Forgot Password</h1>
            <p class="subtitle">Enter your email address to receive a password reset link</p>
            
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php else: ?>
                <form method="post" class="form">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    
                    <button type="submit" class="btn">Send Reset Link</button>
                </form>
            <?php endif; ?>
            
            <p class="text-center"><a href="login.php">Back to Login</a></p>
        </main>
        
        <?php require_once 'includes/footer.php'; ?>
    </div>
</body>
</html>
