<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Get next cycle info
$db = getDB();
$stmt = $db->prepare("SELECT * FROM cycles WHERE status = 'active' ORDER BY start_date ASC LIMIT 1");
$stmt->execute();
$nextCycle = $stmt->fetch();

// Get next cycle with open registration
$nextOpenCycle = null;
if ($nextCycle && !$nextCycle['registration_open']) {
    $stmt = $db->prepare("SELECT * FROM cycles WHERE start_date > ? AND registration_open = 1 AND status = 'active' ORDER BY start_date ASC LIMIT 1");
    $stmt->execute([$nextCycle['start_date']]);
    $nextOpenCycle = $stmt->fetch();
} else if ($nextCycle && $nextCycle['registration_open']) {
    // If next cycle has open registration, show it as both next and open
    $nextOpenCycle = null;
} else if (!$nextCycle) {
    // If no next cycle found, look for any cycle with open registration
    $stmt = $db->prepare("SELECT * FROM cycles WHERE registration_open = 1 AND status = 'active' ORDER BY start_date ASC LIMIT 1");
    $stmt->execute();
    $nextOpenCycle = $stmt->fetch();
}

// Get unseen announcement count for logged in users
$unseenAnnouncementCount = 0;
if (isLoggedIn()) {
    $unseenAnnouncementCount = getUnseenAnnouncementCount($db, $_SESSION['user_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <?php require_once 'includes/header.php'; ?>
        
        <nav>
            <a href="index.php" class="active">Home</a>
            <?php if (isLoggedIn()): ?>
                <a href="announcements.php" class="nav-link-with-badge">
                    Announcements
                    <?php if ($unseenAnnouncementCount > 0): ?>
                        <span class="notification-badge"><?php echo $unseenAnnouncementCount; ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
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
            <section class="hero">
                <h1>Welcome to the <?php echo SITE_TITLE; ?></h1>
                <p>Connect with fellow photography zine makers and exchange your creations with enthusiasts around the world.</p>
                <?php if (!isLoggedIn()): ?>
                    <a href="register.php" class="btn btn-large">Join the Club</a>
                <?php else: ?>
                    <a href="process.php" class="btn btn-large">View My Process</a>
                <?php endif; ?>
            </section>
            
            <?php if ($nextCycle): ?>
                <section class="next-cycle">
                    <h2>Next Exchange Cycle</h2>
                    <p><strong><?php echo htmlspecialchars($nextCycle['name']); ?></strong></p>
                    <p>Start Date: <?php echo date('F j, Y', strtotime($nextCycle['start_date'])); ?></p>
                    <?php if ($nextCycle['registration_open']): ?>
                        <p class="status open">Registration is now open!</p>
                    <?php else: ?>
                        <p class="status closed">Registration is closed</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            
            <?php if ($nextOpenCycle): ?>
                <section class="next-cycle">
                    <h2>Registration Open for Next Cycle</h2>
                    <p><strong><?php echo htmlspecialchars($nextOpenCycle['name']); ?></strong></p>
                    <p>Start Date: <?php echo date('F j, Y', strtotime($nextOpenCycle['start_date'])); ?></p>
                    <p class="status open">Registration is now open!</p>
                    <?php if (!isLoggedIn()): ?>
                        <a href="register.php" class="btn">Register Now</a>
                    <?php else: ?>
                        <a href="process.php" class="btn">View My Process</a>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            
            <section class="how-it-works">
                <h2>How It Works</h2>
                <div class="process-cards">
                    <div class="process-card">
                        <div class="card-number">1</div>
                        <h3>Register</h3>
                        <p>Sign up and tell us about yourself and the photography zine you create. Describe your zine's theme, format, and construction type.</p>
                    </div>
                    
                    <div class="arrow">→</div>
                    
                    <div class="process-card">
                        <div class="card-number">2</div>
                        <h3>Join a Cycle</h3>
                        <p>When a new exchange cycle starts, confirm your participation. Existing users will receive an invitation email.</p>
                    </div>
                    
                    <div class="arrow">→</div>
                    
                    <div class="process-card">
                        <div class="card-number">3</div>
                        <h3>Get Paired</h3>
                        <p>We'll pair you with another participant using a round-robin system, prioritizing matches from the same or nearby countries.</p>
                    </div>
                    
                    <div class="arrow">→</div>
                    
                    <div class="process-card">
                        <div class="card-number">4</div>
                        <h3>Send & Receive</h3>
                        <p>Send your zine to your paired partner and receive one from another participant. Report your progress on the site.</p>
                    </div>
                    
                    <div class="arrow">→</div>
                    
                    <div class="process-card">
                        <div class="card-number">5</div>
                        <h3>Share</h3>
                        <p>Upload photos of the zine you received to our gallery and see what others in the community have created.</p>
                    </div>
                </div>
            </section>
            
            <section class="about">
                <h2>About the <?php echo SITE_TITLE; ?></h2>
                <p>The <?php echo SITE_TITLE; ?> is a community for photography zine makers to share their work with fellow enthusiasts around the world. Our exchange cycles bring creators together, fostering connections and spreading the joy of independent publishing.</p>
                <p>Whether you make photocopied zines, hand-bound artist books, or anything in between, there's a place for you here. Join us and become part of a global network of zine creators!</p>
            </section>
        </main>
        
        <?php require_once 'includes/footer.php'; ?>
    </div>
</body>
</html>
