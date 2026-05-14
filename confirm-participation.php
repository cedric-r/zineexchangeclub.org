<?php
require_once 'config.php';
require_once 'includes/auth.php';

$token = $_GET['token'] ?? '';
$message = '';
$success = false;

if ($token) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT cp.id, cp.user_id, cp.cycle_id, c.name
        FROM cycle_participations cp
        JOIN cycles c ON cp.cycle_id = c.id
        WHERE cp.confirmation_token = ? AND cp.participation_confirmed = 0
    ");
    $stmt->execute([$token]);
    $participation = $stmt->fetch();

    if (!$participation) {
        $message = 'Invalid or expired confirmation token. The token may have already been used.';
    } elseif (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    } elseif ($participation['user_id'] != $_SESSION['user_id']) {
        $message = 'This confirmation link is for a different account. Please log in with the correct account.';
    } else {
        $stmt = $db->prepare("UPDATE cycle_participations SET participation_confirmed = 1, confirmation_token = NULL WHERE id = ?");
        $stmt->execute([$participation['id']]);
        $success = true;
        $message = 'Your participation in ' . htmlspecialchars($participation['name']) . ' has been confirmed!';
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
    <title>Participation Confirmation - Zine Exchange Club</title>
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
                <h1><?php echo $success ? 'Participation Confirmed!' : 'Confirmation Failed'; ?></h1>
                <p><?php echo htmlspecialchars($message); ?></p>
                <?php if ($success): ?>
                    <a href="process.php" class="btn">View My Process</a>
                <?php else: ?>
                    <a href="index.php" class="btn">Return Home</a>
                <?php endif; ?>
            </div>
        </main>

        <?php require_once 'includes/footer.php'; ?>
    </div>
</body>
</html>
