<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'includes/auth.php';

$token = $_GET['token'] ?? '';
$message = '';
$success = false;

if ($token) {
    $db = getDB();

    // Look up the token regardless of confirmed/expired state so we can
    // give precise feedback instead of a blanket "invalid token" error
    $stmt = $db->prepare("
        SELECT cp.id, cp.user_id, cp.cycle_id, cp.pairing_confirmed, cp.confirmation_token_expires, c.name
        FROM cycle_pairings cp
        JOIN cycles c ON cp.cycle_id = c.id
        WHERE cp.confirmation_token = ?
    ");
    $stmt->execute([$token]);
    $pairing = $stmt->fetch();
    $expired = $pairing && $pairing['confirmation_token_expires'] !== null
        && strtotime($pairing['confirmation_token_expires']) <= time();

    if (!$pairing) {
        $message = 'Invalid confirmation link. Please log in and confirm your pairing from the My Process page.';
    } elseif ($pairing['pairing_confirmed']) {
        $success = true;
        $message = 'Your pairing for ' . htmlspecialchars($pairing['name']) . ' has already been confirmed. No further action needed.';
    } elseif ($expired) {
        $message = 'This confirmation link has expired. Please log in and confirm your pairing from the My Process page.';
    } elseif (!isLoggedIn()) {
        header('Location: login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    } elseif ($pairing['user_id'] != $_SESSION['user_id']) {
        $message = 'This confirmation link is for a different account. Please log in with the correct account.';
    } else {
        $stmt = $db->prepare("UPDATE cycle_pairings SET pairing_confirmed = 1, confirmation_token = NULL WHERE id = ?");
        $stmt->execute([$pairing['id']]);
        $success = true;
        $message = 'Your pairing for ' . htmlspecialchars($pairing['name']) . ' has been confirmed!';
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
    <title>Pairing Confirmation - <?php echo SITE_TITLE; ?></title>
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
                <h1><?php echo $success ? 'Pairing Confirmed!' : 'Confirmation Failed'; ?></h1>
                <p><?php echo htmlspecialchars($message); ?></p>
                <?php if ($success): ?>
                    <a href="process.php" class="btn">View My Process</a>
                <?php else: ?>
                    <a href="login.php" class="btn">Log In</a>
                    <a href="index.php" class="btn">Return Home</a>
                <?php endif; ?>
            </div>
        </main>

        <?php require_once 'includes/footer.php'; ?>
    </div>
</body>
</html>
