<?php
declare(strict_types=1);
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/email.php';
require_once '../includes/functions.php';
require_once '../includes/pairing_algorithms.php';

// Allow stopping impersonation before the admin check — impersonated
// users have is_admin=0 and would be blocked by requireAdmin().
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stop_impersonating'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        stopImpersonating();
    }
    header('Location: index.php');
    exit;
}

requireAdmin();

$db = getDB();
$message = '';
$messageType = '';

// Handle cycle creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token for all POST requests
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    if (isset($_POST['create_cycle'])) {
        $name = trim($_POST['cycle_name']);
        $startDate = $_POST['start_date'];

        if ($name && $startDate) {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare("INSERT INTO cycles (name, start_date, registration_open) VALUES (?, ?, 1)");
                $stmt->execute([$name, $startDate]);
                $cycleId = $db->lastInsertId();

                // Send invitation emails to all existing users
                $stmt = $db->prepare("SELECT id, name, email FROM users WHERE email_confirmed = 1");
                $stmt->execute();
                $users = $stmt->fetchAll();

                foreach ($users as $user) {
                    $token = generateToken();
                    $tokenExpires = date('Y-m-d H:i:s', strtotime('+30 days'));
                    $stmt = $db->prepare("INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate, confirmation_token, confirmation_token_expires) VALUES (?, ?, 1, ?, ?)");
                    $stmt->execute([$cycleId, $user['id'], $token, $tokenExpires]);

                    $emailBody = getCycleInvitationEmail($user['name'], $name, $token);
                    sendEmail($user['email'], 'New Exchange Cycle: ' . $name, $emailBody);
                    logEmail($user['id'], $cycleId, 'cycle_invitation');
                }

                $db->commit();

                $message = 'Cycle created and invitations sent!';
                $messageType = 'success';
            } catch (Exception $e) {
                $db->rollBack();
                $message = 'Failed to create cycle. Please try again.';
                $messageType = 'error';
            }
        } else {
            $message = 'Please fill in all fields.';
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['pair_users'])) {
        $cycleId = (int)$_POST['cycle_id'];
        if (pairParticipants($cycleId, $db)) {
            $message = 'Participants paired successfully!';
            $messageType = 'success';
        } else {
            $message = 'Pairing failed. There may be fewer than 2 confirmed participants.';
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['close_registration'])) {
        $cycleId = (int)$_POST['cycle_id'];
        $stmt = $db->prepare("UPDATE cycles SET registration_open = 0 WHERE id = ?");
        $stmt->execute([$cycleId]);
        $message = 'Registration closed for this cycle.';
        $messageType = 'success';
    }
    
    if (isset($_POST['reset_cycle'])) {
        $cycleId = (int)$_POST['cycle_id'];
        $stmt = $db->prepare("UPDATE cycles SET pairing_done = 0 WHERE id = ?");
        $stmt->execute([$cycleId]);
        $stmt = $db->prepare("DELETE FROM cycle_pairings WHERE cycle_id = ?");
        $stmt->execute([$cycleId]);
        $message = 'Cycle reset successfully.';
        $messageType = 'success';
    }
    
    if (isset($_POST['close_cycle'])) {
        $cycleId = (int)$_POST['cycle_id'];
        $stmt = $db->prepare("UPDATE cycles SET status = 'closed' WHERE id = ?");
        $stmt->execute([$cycleId]);
        $message = 'Cycle closed and archived.';
        $messageType = 'success';
    }
    
    if (isset($_POST['reopen_cycle'])) {
        $cycleId = (int)$_POST['cycle_id'];
        $stmt = $db->prepare("UPDATE cycles SET status = 'active' WHERE id = ?");
        $stmt->execute([$cycleId]);
        $message = 'Cycle reopened.';
        $messageType = 'success';
    }
    
    if (isset($_POST['update_user'])) {
        $userId = (int)$_POST['user_id'];

        // Prevent self-demotion
        if ($userId === (int)$_SESSION['user_id'] && !isset($_POST['is_admin'])) {
            $message = 'You cannot remove your own admin privileges. Ask another admin to do this.';
            $messageType = 'error';
        } else {
            $name = trim($_POST['name']);
            $email = trim($_POST['email']);
            $country = trim($_POST['country']);
            $postalAddress = trim($_POST['postal_address']);
            $acceptsAdultZines = isset($_POST['accepts_adult_zines']) ? 1 : 0;
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
            $emailConfirmed = isset($_POST['email_confirmed']) ? 1 : 0;

            if ($name && $email && $country && $postalAddress) {
                try {
                    $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, country = ?, postal_address = ?, accepts_adult_zines = ?, is_admin = ?, email_confirmed = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $country, $postalAddress, $acceptsAdultZines, $isAdmin, $emailConfirmed, $userId]);
                    $message = 'User updated successfully!';
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = 'Update failed. Email may already be in use.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Please fill in all required fields.';
                $messageType = 'error';
            }
        }
    }
    
    if (isset($_POST['delete_user'])) {
        $userId = (int)$_POST['user_id'];

        if ($userId === (int)$_SESSION['user_id']) {
            $message = 'You cannot delete your own account.';
            $messageType = 'error';
        } else {
            try {
                // Get user's uploaded images
                $stmt = $db->prepare("SELECT image_path FROM gallery WHERE user_id = ?");
                $stmt->execute([$userId]);
                $images = $stmt->fetchAll();

                // Delete image files
                foreach ($images as $image) {
                    $imagePath = '../' . $image['image_path'];
                    if (file_exists($imagePath)) {
                        unlink($imagePath);
                    }
                }

                // Delete from database (cascade will handle related records)
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);

                $message = 'User and all associated data deleted successfully!';
                $messageType = 'success';
            } catch (Exception $e) {
                error_log('Delete user failed: ' . $e->getMessage());
                $message = 'Delete failed.';
                $messageType = 'error';
            }
        }
    }
    
    if (isset($_POST['delete_cycle'])) {
        $cycleId = (int)$_POST['cycle_id'];
        
        try {
            // Get cycle's uploaded images
            $stmt = $db->prepare("SELECT image_path FROM gallery WHERE cycle_id = ?");
            $stmt->execute([$cycleId]);
            $images = $stmt->fetchAll();
            
            // Delete image files
            foreach ($images as $image) {
                $imagePath = '../' . $image['image_path'];
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            }
            
            // Delete from database (cascade will handle related records)
            $stmt = $db->prepare("DELETE FROM cycles WHERE id = ?");
            $stmt->execute([$cycleId]);
            
            $message = 'Cycle and all associated data deleted successfully!';
            $messageType = 'success';
        } catch (Exception $e) {
            error_log('Delete cycle failed: ' . $e->getMessage());
            $message = 'Delete failed.';
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['send_reminder'])) {
        $cycleId = (int)$_POST['cycle_id'];

        // Get users who haven't confirmed participation but want to participate
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.email
            FROM users u
            JOIN cycle_participations cp ON u.id = cp.user_id
            WHERE cp.cycle_id = ? AND cp.participation_confirmed = 0 AND cp.wants_to_participate = 1
        ");
        $stmt->execute([$cycleId]);
        $unconfirmedUsers = $stmt->fetchAll();

        // Get cycle info
        $stmt = $db->prepare("SELECT name FROM cycles WHERE id = ?");
        $stmt->execute([$cycleId]);
        $cycle = $stmt->fetch();

        $reminderCount = 0;
        foreach ($unconfirmedUsers as $user) {
            $emailBody = getParticipationReminderEmail($user['name'], $cycle['name']);
            if (sendEmail($user['email'], 'Participation Reminder: ' . $cycle['name'], $emailBody)) {
                $reminderCount++;
                logEmail($user['id'], $cycleId, 'participation_reminder');
            }
        }

        $message = "Reminders sent to {$reminderCount} user(s) who haven't confirmed participation.";
        $messageType = 'success';
    }

    if (isset($_POST['resend_pairing_emails'])) {
        $cycleId = (int)$_POST['cycle_id'];

        $stmt = $db->prepare("
            SELECT cp.user_id, u.name, u.email,
                   p.name as partner_name, p.email as partner_email, p.postal_address as partner_address, p.country as partner_country
            FROM cycle_pairings cp
            JOIN users u ON cp.user_id = u.id
            JOIN users p ON cp.partner_id = p.id
            WHERE cp.cycle_id = ?
        ");
        $stmt->execute([$cycleId]);
        $pairedUsers = $stmt->fetchAll();

        $emailCount = 0;
        foreach ($pairedUsers as $user) {
            $token = bin2hex(random_bytes(16));
            $tokenExpires = date('Y-m-d H:i:s', strtotime('+14 days'));
            $partnerInfo = "Email: " . $user['partner_email'] . "\n" . $user['partner_address'];
            $emailBody = getPairingEmail($user['name'], $user['partner_name'], $partnerInfo, $user['partner_country'], $token);
            if (sendEmail($user['email'], 'Your Exchange Pairing', $emailBody)) {
                $emailCount++;
                logEmail($user['user_id'], $cycleId, 'pairing_notification');

                // Store token for confirmation on all user's pairings
                $stmt = $db->prepare("UPDATE cycle_pairings SET confirmation_token = ?, confirmation_token_expires = ? WHERE cycle_id = ? AND user_id = ?");
                $stmt->execute([$token, $tokenExpires, $cycleId, $user['user_id']]);
            }
        }

        $message = "Pairing emails resent to {$emailCount} user(s).";
        $messageType = 'success';
    }
    
    if (isset($_POST['send_announcement'])) {
        $announcementId = (int)$_POST['announcement_id'];
        
        // Get announcement details
        $stmt = $db->prepare("SELECT a.title, a.content FROM announcements a WHERE a.id = ?");
        $stmt->execute([$announcementId]);
        $announcement = $stmt->fetch();
        
        if (!$announcement) {
            $message = 'Announcement not found.';
            $messageType = 'error';
        } else {
            // Get all registered users
            $stmt = $db->prepare("SELECT id, name, email FROM users WHERE email_confirmed = 1");
            $stmt->execute();
            $users = $stmt->fetchAll();

            $emailCount = 0;
            foreach ($users as $user) {
                $emailBody = getAnnouncementEmail($user['name'], $announcement['title'], $announcement['content']);
                if (sendEmail($user['email'], 'New Announcement: ' . $announcement['title'], $emailBody)) {
                    $emailCount++;
                    logEmail($user['id'], null, 'announcement_notification');
                }
            }

            $message = "Announcement sent to {$emailCount} registered users.";
            $messageType = 'success';
        }
    }
    
    // Handle resending confirmation email
    if (isset($_POST['resend_confirmation'])) {
        $userId = (int)$_POST['user_id'];

        // Get user details
        $stmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            $message = 'User not found.';
            $messageType = 'error';
        } else {
            // Generate new confirmation token
            $confirmationToken = bin2hex(random_bytes(16));
            $tokenExpires = date('Y-m-d H:i:s', strtotime('+48 hours'));

            // Update user's confirmation token
            $stmt = $db->prepare("UPDATE users SET email_confirmation_token = ?, email_token_expires = ? WHERE id = ?");
            $stmt->execute([$confirmationToken, $tokenExpires, $userId]);
            
            // Send confirmation email
            $emailBody = getRegistrationEmail($user['name'], $confirmationToken);
            if (sendEmail($user['email'], 'Confirm Your Email Address', $emailBody)) {
                logEmail($userId, null, 'email_confirmation');
                $message = 'Confirmation email has been resent to ' . htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') . '.';
                $messageType = 'success';
            } else {
                $message = 'Failed to resend confirmation email.';
                $messageType = 'error';
            }
        }
    }

    if (isset($_POST['manual_pair'])) {
        $cycleId = (int)$_POST['cycle_id'];
        $user1 = (int)$_POST['user_1'];
        $user2 = (int)$_POST['user_2'];

        if ($user1 > 0 && $user2 > 0 && $user1 !== $user2) {
            try {
                $db->beginTransaction();

                // Update pairings
                $stmt = $db->prepare("INSERT INTO cycle_pairings (cycle_id, user_id, partner_id) VALUES (?, ?, ?)");
                $stmt->execute([$cycleId, $user1, $user2]);
                $stmt->execute([$cycleId, $user2, $user1]);

                // Generate confirmation tokens
                $token1 = bin2hex(random_bytes(16));
                $token2 = bin2hex(random_bytes(16));
                $tokenExpires = date('Y-m-d H:i:s', strtotime('+14 days'));

                $stmt = $db->prepare("UPDATE cycle_pairings SET confirmation_token = ?, confirmation_token_expires = ? WHERE cycle_id = ? AND user_id = ?");
                $stmt->execute([$token1, $tokenExpires, $cycleId, $user1]);
                $stmt->execute([$token2, $tokenExpires, $cycleId, $user2]);

                $db->commit();

                // Send pairing notification emails
                $pairs = [
                    ['userId' => $user1, 'partnerId' => $user2, 'token' => $token1],
                    ['userId' => $user2, 'partnerId' => $user1, 'token' => $token2],
                ];

                $infoStmt = $db->prepare("
                    SELECT u.name, u.email, u.country,
                           p.name AS partner_name, p.email AS partner_email, p.postal_address AS partner_address, p.country AS partner_country
                    FROM users u
                    JOIN users p ON p.id = ?
                    WHERE u.id = ?
                ");

                foreach ($pairs as $pair) {
                    $infoStmt->execute([$pair['partnerId'], $pair['userId']]);
                    $info = $infoStmt->fetch();

                    if ($info) {
                        $partnerInfo = "Email: " . $info['partner_email'] . "\n" . $info['partner_address'];
                        $emailBody = getPairingEmail($info['name'], $info['partner_name'], $partnerInfo, $info['partner_country'], $pair['token']);
                        if (sendEmail($info['email'], 'Your Exchange Pairing', $emailBody)) {
                            logEmail($pair['userId'], $cycleId, 'pairing_notification');
                        }
                    }
                }

                $message = 'Manual pairing completed successfully!';
                $messageType = 'success';
            } catch (Exception $e) {
                $db->rollBack();
                error_log('Manual pairing failed: ' . $e->getMessage());
                $message = 'Manual pairing failed.';
                $messageType = 'error';
            }
        } else {
            $message = 'Please select two different users.';
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['impersonate_user'])) {
        $targetUserId = (int)$_POST['user_id'];
        
        if (startImpersonating($targetUserId)) {
            header('Location: ../index.php');
            exit;
        } else {
            $message = 'Cannot impersonate that user.';
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['stop_impersonating'])) {
        stopImpersonating();
        header('Location: index.php');
        exit;
    }
}

// Get all cycles
$stmt = $db->prepare("SELECT * FROM cycles ORDER BY start_date DESC");
$stmt->execute();
$cycles = $stmt->fetchAll();

// Separate active and closed cycles
$activeCycles = array_filter($cycles, function($c) { return $c['status'] === 'active'; });
$closedCycles = array_filter($cycles, function($c) { return $c['status'] === 'closed'; });

// Get all users
$stmt = $db->prepare("SELECT id, name, email, country, postal_address, accepts_adult_zines, is_admin, email_confirmed, created_at FROM users ORDER BY created_at DESC");
$stmt->execute();
$users = $stmt->fetchAll();

// Get unseen announcement count for admin
$unseenAnnouncementCount = getUnseenAnnouncementCount($db, $_SESSION['user_id']);

// The pairParticipants function is now defined in includes/pairing_algorithms.php
// and automatically uses the algorithm specified in PAIRING_ALGORITHM constant
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo generateCsrfToken(); ?>">
    <title>Admin - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="../css/style.css">
    <script src="../js/admin.js" defer></script>
</head>
<body>
    <div class="container">
        <?php require_once '../includes/header.php'; ?>
        
        <nav>
            <a href="../index.php">Home</a>
            <a href="../announcements.php" class="nav-link-with-badge">
                Announcements
                <?php if ($unseenAnnouncementCount > 0): ?>
                    <span class="notification-badge"><?php echo $unseenAnnouncementCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="../gallery.php">Gallery</a>
            <a href="../process.php">My Process</a>
            <a href="../profile.php">My Profile</a>
            <a href="../logout.php">Logout</a>
            <a href="index.php" class="active">Admin</a>
            <a href="gallery.php">Gallery Mgmt</a>
        </nav>
        
        <main>
            <h1>Admin Dashboard</h1>
            
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            
            <section class="admin-section">
                <h2>Create New Cycle</h2>
                <form method="post" class="form">
                    <?php $csrf = generateCsrfToken(); ?>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <div class="form-group">
                        <label for="cycle_name">Cycle Name</label>
                        <input type="text" id="cycle_name" name="cycle_name" required placeholder="e.g., Spring 2024 Exchange">
                    </div>
                    <div class="form-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" required>
                    </div>
                    <button type="submit" name="create_cycle" class="btn">Create Cycle & Send Invitations</button>
                </form>
            </section>
            
            <section class="admin-section">
                <h2>Active Cycles</h2>
                <?php if (empty($activeCycles)): ?>
                    <p class="empty-state">No active cycles.</p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Start Date</th>
                                <th>Registration</th>
                                <th>Pairing</th>
                                <th>Participants</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeCycles as $cycle): ?>
                                <?php
                                $stmt = $db->prepare("SELECT COUNT(*) FROM cycle_participations WHERE cycle_id = ?");
                                $stmt->execute([$cycle['id']]);
                                $totalParticipants = $stmt->fetchColumn();
                                
                                $stmt = $db->prepare("SELECT COUNT(*) FROM cycle_participations WHERE cycle_id = ? AND participation_confirmed = 1");
                                $stmt->execute([$cycle['id']]);
                                $confirmedParticipants = $stmt->fetchColumn();
                                
                                $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM cycle_pairings WHERE cycle_id = ? AND zine_sent = 1");
                                $stmt->execute([$cycle['id']]);
                                $sentCount = $stmt->fetchColumn();
                                
                                $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM cycle_pairings WHERE cycle_id = ? AND zine_received = 1");
                                $stmt->execute([$cycle['id']]);
                                $receivedCount = $stmt->fetchColumn();
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cycle['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo date('F j, Y', strtotime($cycle['start_date'])); ?></td>
                                    <td>
                                        <?php if ($cycle['registration_open']): ?>
                                            <span class="status open">Open</span>
                                        <?php else: ?>
                                            <span class="status closed">Closed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($cycle['pairing_done']): ?>
                                            <span class="status completed">Done</span>
                                        <?php else: ?>
                                            <span class="status pending">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $confirmedParticipants; ?> / <?php echo $totalParticipants; ?> confirmed<br>
                                        <small>Sent: <?php echo $sentCount; ?> | Received: <?php echo $receivedCount; ?></small>
                                    </td>
                                    <td>
                                        <?php if (!$cycle['pairing_done'] && $confirmedParticipants >= 2): ?>
                                            <form method="post" class="inline-form">
                                                <?php $csrf = generateCsrfToken(); ?>
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                                <button type="submit" name="pair_users" class="btn-small">Pair Users</button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <?php if ($cycle['registration_open']): ?>
                                            <form method="post" class="inline-form">
                                                <?php $csrf = generateCsrfToken(); ?>
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                                <button type="submit" name="close_registration" class="btn-small">Close Registration</button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <?php if (!$cycle['pairing_done']): ?>
                                        <form method="post" class="inline-form">
                                                <?php $csrf = generateCsrfToken(); ?>
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                            <button type="submit" name="send_reminder" class="btn-small">Send Reminder</button>
                                        </form>
                                        <?php endif; ?>

                                        <?php if ($cycle['pairing_done']): ?>
                                        <form method="post" class="inline-form">
                                                <?php $csrf = generateCsrfToken(); ?>
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                            <button type="submit" name="resend_pairing_emails" class="btn-small">Resend Pairing Emails</button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <?php if ($cycle['pairing_done']): ?>
                                        <?php
                                        // Get all participants for manual pairing
                                        // (includes already-paired users for odd-number support)
                                        $allStmt = $db->prepare("
                                            SELECT cp.user_id, u.name, u.email, u.country,
                                                   EXISTS (SELECT 1 FROM cycle_pairings cp2 WHERE cp2.cycle_id = cp.cycle_id AND cp2.user_id = cp.user_id) AS is_paired
                                            FROM cycle_participations cp
                                            JOIN users u ON cp.user_id = u.id
                                            WHERE cp.cycle_id = ? AND cp.participation_confirmed = 1
                                              AND cp.wants_to_participate = 1
                                            ORDER BY is_paired ASC, u.name
                                        ");
                                        $allStmt->execute([$cycle['id']]);
                                        $participants = $allStmt->fetchAll();

                                        $unpairedCount = 0;
                                        foreach ($participants as $p) {
                                            if (!$p['is_paired']) $unpairedCount++;
                                        }
                                        ?>
                                        <?php if ($unpairedCount >= 1 && count($participants) >= 2): ?>
                                        <form method="post" class="inline-form manual-pair-form">
                                            <?php $csrf = generateCsrfToken(); ?>
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                            <select name="user_1" required>
                                                <option value="">-- Select user 1 --</option>
                                                <?php foreach ($participants as $u): ?>
                                                    <option value="<?php echo $u['user_id']; ?>">
                                                        <?php echo htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                        (<?php echo htmlspecialchars($u['country'], ENT_QUOTES, 'UTF-8'); ?>)
                                                        <?php if ($u['is_paired']): ?>[paired]<?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span style="margin:0 4px;">↔</span>
                                            <select name="user_2" required>
                                                <option value="">-- Select user 2 --</option>
                                                <?php foreach ($participants as $u): ?>
                                                    <option value="<?php echo $u['user_id']; ?>">
                                                        <?php echo htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                        (<?php echo htmlspecialchars($u['country'], ENT_QUOTES, 'UTF-8'); ?>)
                                                        <?php if ($u['is_paired']): ?>[paired]<?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="manual_pair" class="btn-small">Manual Pair</button>
                                        </form>
                                        <?php elseif ($unpairedCount === 0): ?>
                                        <span class="status success">All participants paired</span>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <form method="post" class="inline-form">
                                                <?php $csrf = generateCsrfToken(); ?>
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                            <button type="submit" name="close_cycle" class="btn-small btn-danger">Close Cycle</button>
                                        </form>
                                        
                                        <button class="btn-small btn-danger" data-action="delete-cycle" data-cycle-id="<?php echo $cycle['id']; ?>" data-cycle-name="<?php echo htmlspecialchars($cycle['name'], ENT_QUOTES, 'UTF-8'); ?>">Delete</button>
                                        
                                        <form method="post" class="inline-form">
                                                <?php $csrf = generateCsrfToken(); ?>
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                            <button type="submit" name="reset_cycle" class="btn-small btn-danger">Reset</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
            
            <section class="admin-section">
                <h2>Archived Cycles</h2>
                <?php if (empty($closedCycles)): ?>
                    <p class="empty-state">No archived cycles.</p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Start Date</th>
                                <th>Participants</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($closedCycles as $cycle): ?>
                                <?php
                                $stmt = $db->prepare("SELECT COUNT(*) FROM cycle_participations WHERE cycle_id = ?");
                                $stmt->execute([$cycle['id']]);
                                $totalParticipants = $stmt->fetchColumn();
                                
                                $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM cycle_pairings WHERE cycle_id = ? AND zine_sent = 1");
                                $stmt->execute([$cycle['id']]);
                                $sentCount = $stmt->fetchColumn();
                                
                                $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM cycle_pairings WHERE cycle_id = ? AND zine_received = 1");
                                $stmt->execute([$cycle['id']]);
                                $receivedCount = $stmt->fetchColumn();
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cycle['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo date('F j, Y', strtotime($cycle['start_date'])); ?></td>
                                    <td>
                                        <?php echo $totalParticipants; ?> total<br>
                                        <small>Sent: <?php echo $sentCount; ?> | Received: <?php echo $receivedCount; ?></small>
                                    </td>
                                    <td>
                                        <form method="post" class="inline-form">
                                                <?php $csrf = generateCsrfToken(); ?>
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                            <button type="submit" name="reopen_cycle" class="btn-small">Reopen</button>
                                        </form>
                                        <button class="btn-small btn-danger" data-action="delete-cycle" data-cycle-id="<?php echo $cycle['id']; ?>" data-cycle-name="<?php echo htmlspecialchars($cycle['name'], ENT_QUOTES, 'UTF-8'); ?>">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
            
            <section class="admin-section">
                <h2>Users</h2>
                <?php if (empty($users)): ?>
                    <p class="empty-state">No users registered yet.</p>
                <?php else: ?>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Country</th>
                                <th>Email Confirmed</th>
                                <th>Admin</th>
                                <th>Registered</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr id="user-row-<?php echo $user['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-email="<?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-country="<?php echo htmlspecialchars($user['country'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-postal-address="<?php echo htmlspecialchars($user['postal_address'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-accepts-adult-zines="<?php echo $user['accepts_adult_zines']; ?>"
                                    data-is-admin="<?php echo $user['is_admin']; ?>"
                                    data-email-confirmed="<?php echo $user['email_confirmed']; ?>">
                                    <td><?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($user['country'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php if ($user['email_confirmed']): ?>
                                            <span class="status completed">Yes</span>
                                        <?php else: ?>
                                            <span class="status pending">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['is_admin']): ?>
                                            <span class="status completed">Yes</span>
                                        <?php else: ?>
                                            <span class="status pending">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <button class="btn-small" data-action="edit-user" data-user-id="<?php echo $user['id']; ?>">Edit</button>
                                        <?php if ($user['id'] !== (int)$_SESSION['user_id'] && !$user['is_admin']): ?>
                                            <form method="post" class="inline-form" style="display:inline;"
                                                  data-confirm="Impersonate <?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?>? You will see the site as they do. Click Stop Impersonating to return.">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="impersonate_user" class="btn-small">
                                                    Impersonate
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!$user['email_confirmed']): ?>
                                            <button class="btn-small" data-action="resend-confirmation" data-user-id="<?php echo $user['id']; ?>" data-email="<?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>">Resend Confirmation</button>
                                        <?php endif; ?>
                                        <button class="btn-small btn-danger" data-action="delete-user" data-user-id="<?php echo $user['id']; ?>" data-name="<?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?>">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
            
            <section class="admin-section">
                <h2>Cycle Progress Details</h2>
                <?php if (empty($cycles)): ?>
                    <p class="empty-state">No cycles to show.</p>
                <?php else: ?>
                    <?php foreach ($activeCycles as $cycle): ?>
                        <h3><?php echo htmlspecialchars($cycle['name'], ENT_QUOTES, 'UTF-8'); ?> <span class="status open">(Active)</span></h3>
                        <?php
                        // Pre-fetch all pairings (one row per pairing, per-partner status)
                        $pairStmt = $db->prepare("
                            SELECT cp.*, pu.name AS partner_name
                            FROM cycle_pairings cp
                            JOIN users pu ON cp.partner_id = pu.id
                            WHERE cp.cycle_id = ?
                            ORDER BY pu.name
                        ");
                        $pairStmt->execute([$cycle['id']]);
                        $pairingsByUser = [];
                        foreach ($pairStmt->fetchAll() as $row) {
                            $pairingsByUser[$row['user_id']][] = $row;
                        }

                        $stmt = $db->prepare("
                            SELECT cp.*, u.name, u.email, u.country
                            FROM cycle_participations cp
                            JOIN users u ON cp.user_id = u.id
                            WHERE cp.cycle_id = ?
                            ORDER BY u.name
                        ");
                        $stmt->execute([$cycle['id']]);
                        $participations = $stmt->fetchAll();
                        ?>
                        <?php if (empty($participations)): ?>
                            <p class="empty-state">No participants for this cycle.</p>
                        <?php else: ?>
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Country</th>
                                        <th>Wants to Participate</th>
                                        <th>Confirmed</th>
                                        <th>Paired With</th>
                                        <th>Acknowledged</th>
                                        <th><?php echo ucfirst(CONTENT_TYPE); ?> Sent</th>
                                        <th><?php echo ucfirst(CONTENT_TYPE); ?> Received</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($participations as $p): ?>
                                        <?php $pairs = $pairingsByUser[$p['user_id']] ?? []; ?>
                                        <?php if (empty($pairs)): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($p['country'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td>
                                                    <?php if ($p['wants_to_participate']): ?>
                                                        <span class="status completed">Yes</span>
                                                    <?php else: ?>
                                                        <span class="status pending">No</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($p['participation_confirmed']): ?>
                                                        <span class="status completed">Yes</span>
                                                    <?php else: ?>
                                                        <span class="status pending">No</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>-</td>
                                                <td><span class="status pending">No</span></td>
                                                <td><span class="status pending">No</span></td>
                                                <td><span class="status pending">No</span></td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($pairs as $pairing): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars($p['country'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td>
                                                        <?php if ($p['wants_to_participate']): ?>
                                                            <span class="status completed">Yes</span>
                                                        <?php else: ?>
                                                            <span class="status pending">No</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($p['participation_confirmed']): ?>
                                                            <span class="status completed">Yes</span>
                                                        <?php else: ?>
                                                            <span class="status pending">No</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($pairing['partner_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td>
                                                        <?php if ($pairing['pairing_confirmed']): ?>
                                                            <span class="status completed">Yes</span>
                                                        <?php else: ?>
                                                            <span class="status pending">No</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($pairing['zine_sent']): ?>
                                                            <span class="status completed">Yes</span>
                                                            <?php if ($pairing['zine_sent_date']): ?>
                                                                <small>(<?php echo date('M j', strtotime($pairing['zine_sent_date'])); ?>)</small>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="status pending">No</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($pairing['zine_received']): ?>
                                                            <span class="status completed">Yes</span>
                                                            <?php if ($pairing['zine_received_date']): ?>
                                                                <small>(<?php echo date('M j', strtotime($pairing['zine_received_date'])); ?>)</small>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="status pending">No</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <?php if (!empty($closedCycles)): ?>
                        <details class="collapsed-cycles">
                            <summary>Archived Cycles (<?php echo count($closedCycles); ?>)</summary>
                            <?php foreach ($closedCycles as $cycle): ?>
                                <h4><?php echo htmlspecialchars($cycle['name'], ENT_QUOTES, 'UTF-8'); ?> <span class="status closed">(Archived)</span></h4>
                                <?php
                                // Pre-fetch all pairings (one row per pairing, per-partner status)
                                $archPairStmt = $db->prepare("
                                    SELECT cp.*, pu.name AS partner_name
                                    FROM cycle_pairings cp
                                    JOIN users pu ON cp.partner_id = pu.id
                                    WHERE cp.cycle_id = ?
                                    ORDER BY pu.name
                                ");
                                $archPairStmt->execute([$cycle['id']]);
                                $archPairingsByUser = [];
                                foreach ($archPairStmt->fetchAll() as $row) {
                                    $archPairingsByUser[$row['user_id']][] = $row;
                                }

                                $stmt = $db->prepare("
                                    SELECT cp.*, u.name, u.email, u.country
                                    FROM cycle_participations cp
                                    JOIN users u ON cp.user_id = u.id
                                    WHERE cp.cycle_id = ?
                                    ORDER BY u.name
                                ");
                                $stmt->execute([$cycle['id']]);
                                $participations = $stmt->fetchAll();
                                ?>
                                <?php if (empty($participations)): ?>
                                    <p class="empty-state">No participants for this cycle.</p>
                                <?php else: ?>
                                    <table class="admin-table">
                                        <thead>
                                            <tr>
                                                <th>User</th>
                                                <th>Country</th>
                                                <th>Confirmed</th>
                                                <th>Paired With</th>
                                                <th>Acknowledged</th>
                                                <th><?php echo ucfirst(CONTENT_TYPE); ?> Sent</th>
                                                <th><?php echo ucfirst(CONTENT_TYPE); ?> Received</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($participations as $p): ?>
                                                <?php $pairs = $archPairingsByUser[$p['user_id']] ?? []; ?>
                                                <?php if (empty($pairs)): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($p['country'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td>
                                                            <?php if ($p['participation_confirmed']): ?>
                                                                <span class="status completed">Yes</span>
                                                            <?php else: ?>
                                                                <span class="status pending">No</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>-</td>
                                                        <td><span class="status pending">No</span></td>
                                                        <td><span class="status pending">No</span></td>
                                                        <td><span class="status pending">No</span></td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($pairs as $pairing): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                            <td><?php echo htmlspecialchars($p['country'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                            <td>
                                                                <?php if ($p['participation_confirmed']): ?>
                                                                    <span class="status completed">Yes</span>
                                                                <?php else: ?>
                                                                    <span class="status pending">No</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($pairing['partner_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                            <td>
                                                                <?php if ($pairing['pairing_confirmed']): ?>
                                                                    <span class="status completed">Yes</span>
                                                                <?php else: ?>
                                                                    <span class="status pending">No</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($pairing['zine_sent']): ?>
                                                                    <span class="status completed">Yes</span>
                                                                    <?php if ($pairing['zine_sent_date']): ?>
                                                                        <small>(<?php echo date('M j', strtotime($pairing['zine_sent_date'])); ?>)</small>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="status pending">No</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($pairing['zine_received']): ?>
                                                                    <span class="status completed">Yes</span>
                                                                    <?php if ($pairing['zine_received_date']): ?>
                                                                        <small>(<?php echo date('M j', strtotime($pairing['zine_received_date'])); ?>)</small>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="status pending">No</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>

        <!-- User Edit Modal -->
        <div id="userEditModal" class="modal" style="display: none;">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Edit User</h2>
                    <button class="modal-close" data-close-modal>&times;</button>
                </div>
                <form method="post" class="form">
                    <?php $csrf = generateCsrfToken(); ?>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="form-group">
                        <label for="edit_name">Name *</label>
                        <input type="text" id="edit_name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_email">Email *</label>
                        <input type="email" id="edit_email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_country">Country *</label>
                        <input type="text" id="edit_country" name="country" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_postal_address">Postal Address *</label>
                        <textarea id="edit_postal_address" name="postal_address" required></textarea>
                    </div>
                    <div class="form-group checkbox">
                        <input type="checkbox" id="edit_accepts_adult_zines" name="accepts_adult_zines">
                        <label for="edit_accepts_adult_zines">Accepts Adult <?php echo ucfirst(CONTENT_TYPE); ?>s</label>
                    </div>
                    <div class="form-group checkbox">
                        <input type="checkbox" id="edit_is_admin" name="is_admin">
                        <label for="edit_is_admin">Is Admin</label>
                    </div>
                    <div class="form-group checkbox">
                        <input type="checkbox" id="edit_email_confirmed" name="email_confirmed">
                        <label for="edit_email_confirmed">Email Confirmed</label>
                    </div>
                    <button type="submit" name="update_user" class="btn">Update User</button>
                </form>
            </div>
        </div>
        
        <?php require_once '../includes/footer.php'; ?>
    </div>
</body>
</html>
