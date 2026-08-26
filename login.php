<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/captcha.php';

if (isLoggedIn()) {
    header('Location: process.php');
    exit;
}

$error = '';

// Where to go after login: only local paths (no scheme/host) are honored
$next = $_REQUEST['next'] ?? '';
$next = ($next !== '' && $next[0] === '/' && ($next[1] ?? '/') !== '/') ? $next : 'process.php';

// Rate limiting: max 5 attempts per 15 minutes
$rateLimitKey = 'login_attempts';
if (isset($_SESSION['login_blocked_until']) && time() < $_SESSION['login_blocked_until']) {
    $error = 'Too many failed login attempts. Please try again later.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    if (!isCaptchaVerified()) {
        $error = 'Please complete the captcha verification.';
    } else {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $result = login($email, $password);

    if ($result['success']) {
        unset($_SESSION[$rateLimitKey]);
        unset($_SESSION['login_blocked_until']);
        header('Location: ' . $next);
        exit;
    } else {
        $_SESSION[$rateLimitKey] = ($_SESSION[$rateLimitKey] ?? 0) + 1;
        if ($_SESSION[$rateLimitKey] >= 5) {
            $_SESSION['login_blocked_until'] = time() + 900; // 15 minute block
        }
        $error = $result['message'];
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
    <title>Login - <?php echo SITE_TITLE; ?></title>
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
                <a href="logout.php">Logout</a>
            <?php else: ?>
                <a href="login.php" class="active">Login</a>
                <a href="register.php">Register</a>
            <?php endif; ?>
            <?php if (isAdmin()): ?>
                <a href="admin/index.php">Admin</a>
            <?php endif; ?>
        </nav>
        
        <main>
            <h1>Login</h1>
            
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="post" class="form">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <?php if ($next !== 'process.php'): ?><input type="hidden" name="next" value="<?php echo htmlspecialchars($next, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <div id="captcha-container" class="form-group">
                    <label>Verify you are human</label>
                    <p class="captcha-question"><strong><?php echo htmlspecialchars($captchaData['question'] ?? 'No question available.'); ?></strong></p>
                    <input type="text" class="captcha-answer" placeholder="Enter your answer" autocomplete="off">
                    <div class="captcha-status"></div>
                </div>

                <button type="submit" class="btn">Login</button>
            </form>
            
            <p class="text-center">Don't have an account? <a href="register.php">Register here</a></p>
            <p class="text-center"><a href="forgot-password.php">Forgot your password?</a></p>
        </main>
        
        <?php require_once 'includes/footer.php'; ?>
    <script src="js/captcha.js"></script>
    </div>
</body>
</html>
