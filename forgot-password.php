<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/email.php';
require_once 'includes/captcha.php';

$error = '';
$success = '';

// Rate limiting: max 3 password reset requests per 15 minutes
$rateLimited = isset($_SESSION['reset_blocked_until']) && time() < $_SESSION['reset_blocked_until'];

if ($rateLimited) {
    $error = 'Too many password reset requests. Please try again later.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    $_SESSION['reset_attempts'] = ($_SESSION['reset_attempts'] ?? 0) + 1;
    if ($_SESSION['reset_attempts'] >= 3) {
        $_SESSION['reset_blocked_until'] = time() + 900;
        $error = 'Too many password reset requests. Please try again later.';
    } else {
        $email = trim($_POST['email'] ?? '');

    if (!isCaptchaVerified()) {
        $error = 'Please complete the captcha verification.';
    } elseif (empty($email)) {
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
                error_log("Forgot password request for unconfirmed email: {$email}");
                $success = 'If an account with this email exists, a password reset link has been sent.';
            } else {
                // Generate reset token
                $token = generateToken();
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                $stmt = $db->prepare("UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?");
                $stmt->execute([$token, $expires, $user['id']]);
                
                // Send reset email
                $emailBody = getPasswordResetEmail($user['name'], $token);
                if (sendEmail($email, 'Password Reset - ' . SITE_TITLE, $emailBody)) {
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
}

// Fresh captcha question on every page load; preserve state during POST for verification
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    initCaptcha();
}
$captchaData = getCaptchaQuestion();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo SITE_TITLE; ?></title>
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
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    
                    <div id="captcha-container" class="form-group">
                        <label>Verify you are human</label>
                        <p class="captcha-question"><strong><?php echo htmlspecialchars($captchaData['question'] ?? 'No question available.'); ?></strong></p>
                        <input type="text" class="captcha-answer" placeholder="Enter your answer" autocomplete="off">
                        <div class="captcha-status"></div>
                    </div>

                    <button type="submit" class="btn">Send Reset Link</button>
                </form>
            <?php endif; ?>
            
            <p class="text-center"><a href="login.php">Back to Login</a></p>
        </main>
        
        <?php require_once 'includes/footer.php'; ?>
    <script src="js/captcha.js"></script>
    </div>
</body>
</html>
