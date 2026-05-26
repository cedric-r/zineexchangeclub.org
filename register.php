<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/email.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $postalAddress = trim($_POST['postal_address'] ?? '');
    $acceptsAdultZines = isset($_POST['accepts_adult_zines']) ? 1 : 0;
    $country = trim($_POST['country'] ?? '');
    $zineTheme = trim($_POST['zine_theme'] ?? '');
    $zineFormat = trim($_POST['zine_format'] ?? '');
    
    // Validation
    if (empty($name) || empty($email) || empty($password) || empty($postalAddress) || 
        empty($country) || empty($zineTheme) || empty($zineFormat)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $db = getDB();
        
        // Check if email already exists
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            // Create user
            $confirmationToken = generateToken();
            $hashedPassword = hashPassword($password);
            
            try {
                $db->beginTransaction();

                $tokenExpires = date('Y-m-d H:i:s', strtotime('+48 hours'));
                $stmt = $db->prepare("INSERT INTO users (name, email, password, postal_address, accepts_adult_zines, country, email_confirmation_token, email_token_expires) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $email, $hashedPassword, $postalAddress, $acceptsAdultZines, $country, $confirmationToken, $tokenExpires]);
                $userId = $db->lastInsertId();
                
                // Create zine entry
                $stmt = $db->prepare("INSERT INTO zines (user_id, theme, format) VALUES (?, ?, ?)");
                $stmt->execute([$userId, $zineTheme, $zineFormat]);
                
                // Add user to existing open cycles with wants_to_participate=1
                $stmt = $db->prepare("SELECT id FROM cycles WHERE registration_open = 1 AND status = 'active'");
                $stmt->execute();
                $openCycles = $stmt->fetchAll();
                
                foreach ($openCycles as $cycle) {
                    $stmt = $db->prepare("INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE wants_to_participate = 1");
                    $stmt->execute([$cycle['id'], $userId]);
                }
                
                $db->commit();
                
                // Send confirmation email
                $emailBody = getRegistrationEmail($name, $confirmationToken);
                if (sendEmail($email, 'Confirm your email - Zine Exchange Club', $emailBody)) {
                    $success = 'Registration successful! Please check your email to confirm your account.';
                } else {
                    $success = 'Registration successful! However, there was an error sending the confirmation email. Please contact support.';
                }
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?php echo SITE_TITLE; ?></title>
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
                <a href="register.php" class="active">Register</a>
            <?php endif; ?>
            <?php if (isAdmin()): ?>
                <a href="admin/index.php">Admin</a>
            <?php endif; ?>
        </nav>
        
        <main>
            <h1>Register for <?php echo SITE_TITLE; ?></h1>
            
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success"><?php echo $success; ?></div>
            <?php else: ?>
                <form method="post" class="form">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <h2>Personal Information</h2>

                    <div class="form-group">
                        <label for="name">Name *</label>
                        <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" required minlength="8">
                        <small>Minimum 8 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                    </div>
                    
                    <div class="form-group">
                        <label for="postal_address">Postal Address *</label>
                        <textarea id="postal_address" name="postal_address" required rows="4"><?php echo htmlspecialchars($_POST['postal_address'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="country">Country *</label>
                        <input type="text" id="country" name="country" required value="<?php echo htmlspecialchars($_POST['country'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group checkbox">
                        <input type="checkbox" id="accepts_adult_zines" name="accepts_adult_zines" <?php echo isset($_POST['accepts_adult_zines']) ? 'checked' : ''; ?>>
                        <label for="accepts_adult_zines">I accept to receive adult-themed zines</label>
                    </div>
                    
                    <h2>Your Zine</h2>
                    
                    <div class="form-group">
                        <label for="zine_theme">Theme/Description *</label>
                        <textarea id="zine_theme" name="zine_theme" required rows="4"><?php echo htmlspecialchars($_POST['zine_theme'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="zine_format">Format *</label>
                        <select id="zine_format" name="zine_format" required>
                            <option value="">Select format...</option>
                            <option value="folded" <?php echo ($_POST['zine_format'] ?? '') === 'folded' ? 'selected' : ''; ?>>Folded</option>
                            <option value="stapled" <?php echo ($_POST['zine_format'] ?? '') === 'stapled' ? 'selected' : ''; ?>>Stapled</option>
                            <option value="bound" <?php echo ($_POST['zine_format'] ?? '') === 'bound' ? 'selected' : ''; ?>>Bound</option>
                            <option value="other" <?php echo ($_POST['zine_format'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn">Register</button>
                </form>
            <?php endif; ?>
        </main>
        
        <footer>
            <p>&copy; <?php echo date('Y'); ?> Zine Exchange Club</p>
        <?php require_once 'includes/footer.php'; ?>
    </div>
</body>
</html>
