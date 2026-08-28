<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/email.php';
require_once 'includes/functions.php';

requireLogin();

$csrfToken = generateCsrfToken();

$db = getDB();
$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    // If POST is empty but a file was attempted, post_max_size was likely exceeded
    if ($csrfToken === '' && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false && empty($_POST)) {
        $message = 'The uploaded file exceeds the maximum allowed size.';
        $messageType = 'error';
    } elseif (!validateCsrfToken($csrfToken)) {
        die('Invalid CSRF token.');
    }

    if (isset($_POST['confirm_participation'])) {
        $cycleId = (int)$_POST['cycle_id'];

        // User is authenticated via session — no token needed for in-page confirmation
        $stmt = $db->prepare("SELECT id FROM cycle_participations WHERE cycle_id = ? AND user_id = ? AND participation_confirmed = 0");
        $stmt->execute([$cycleId, $userId]);

        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE cycle_participations SET participation_confirmed = 1, confirmation_token = NULL WHERE cycle_id = ? AND user_id = ?");
            $stmt->execute([$cycleId, $userId]);
            $message = 'Participation confirmed!';
            $messageType = 'success';
        } else {
            $message = 'Invalid or expired confirmation token.';
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['want_to_participate'])) {
        $cycleId = (int)$_POST['cycle_id'];
        
        try {
            // Check if user already has a participation record for this cycle
            $stmt = $db->prepare("SELECT id FROM cycle_participations WHERE cycle_id = ? AND user_id = ?");
            $stmt->execute([$cycleId, $userId]);
            $existing = $stmt->fetch();
            
            if (!$existing) {
                // Create new participation record
                $stmt = $db->prepare("INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate) VALUES (?, ?, 1)");
                $stmt->execute([$cycleId, $userId]);
                $message = 'You have been added to the cycle! Please confirm your participation below.';
            } else {
                // Update existing record
                $stmt = $db->prepare("UPDATE cycle_participations SET wants_to_participate = 1 WHERE cycle_id = ? AND user_id = ?");
                $stmt->execute([$cycleId, $userId]);
                $message = 'Your participation interest has been updated!';
            }
            $messageType = 'success';
        } catch (Exception $e) {
            error_log('Failed to update participation: ' . $e->getMessage());
            $message = 'Failed to update participation.';
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['confirm_pairing'])) {
        $cycleId = (int)$_POST['cycle_id'];
        $pairingId = isset($_POST['pairing_id']) ? (int)$_POST['pairing_id'] : 0;

        if ($pairingId > 0) {
            // Confirm specific pairing
            $stmt = $db->prepare("SELECT id FROM cycle_pairings WHERE id = ? AND cycle_id = ? AND user_id = ? AND pairing_confirmed = 0");
            $stmt->execute([$pairingId, $cycleId, $userId]);

            if ($stmt->fetch()) {
                $stmt = $db->prepare("UPDATE cycle_pairings SET pairing_confirmed = 1, confirmation_token = NULL WHERE id = ?");
                $stmt->execute([$pairingId]);
                $message = 'Pairing confirmed!';
                $messageType = 'success';
            } else {
                $message = 'Pairing already confirmed or not found.';
                $messageType = 'error';
            }
        }
    }

    if (isset($_POST['report_sent'])) {
        $cycleId = (int)$_POST['cycle_id'];
        $pairingId = isset($_POST['pairing_id']) ? (int)$_POST['pairing_id'] : 0;

        if ($pairingId > 0) {
            $stmt = $db->prepare("UPDATE cycle_pairings SET zine_sent = 1, zine_sent_date = CURDATE() WHERE id = ? AND cycle_id = ? AND user_id = ?");
            $stmt->execute([$pairingId, $cycleId, $userId]);

            // Notify the recipient
            $stmt = $db->prepare("SELECT partner_id FROM cycle_pairings WHERE id = ?");
            $stmt->execute([$pairingId]);
            $pairing = $stmt->fetch();

            if ($pairing && $pairing['partner_id']) {
                $stmt = $db->prepare("SELECT name, email FROM users WHERE id = ?");
                $stmt->execute([$pairing['partner_id']]);
                $recipient = $stmt->fetch();

                if ($recipient) {
                    $emailBody = getZinePostedEmail($recipient['name']);
                    sendEmail($recipient['email'], 'A ' . CONTENT_TYPE . ' is on its way to you! - ' . SITE_TITLE, $emailBody);
                    logEmail($recipient['id'], $cycleId, 'zine_posted_notification');
                }
            }

            $message = ucfirst(CONTENT_TYPE) . ' sent reported successfully!';
            $messageType = 'success';
        }
    }

    if (isset($_POST['report_received'])) {
        $cycleId = (int)$_POST['cycle_id'];
        $pairingId = isset($_POST['pairing_id']) ? (int)$_POST['pairing_id'] : 0;

        if ($pairingId > 0) {
            $stmt = $db->prepare("UPDATE cycle_pairings SET zine_received = 1, zine_received_date = CURDATE() WHERE id = ? AND cycle_id = ? AND user_id = ?");
            $stmt->execute([$pairingId, $cycleId, $userId]);
            $message = ucfirst(CONTENT_TYPE) . ' received reported successfully!';
            $messageType = 'success';
        }
    }
    
    if (isset($_POST['upload_photo']) && isset($_FILES['photo'])) {
        $cycleId = (int)$_POST['cycle_id'];
        $caption = trim($_POST['caption'] ?? '');
        
        if ($_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
            finfo_close($finfo);
            
            if (in_array($mime, $allowedTypes)) {
                $extension = match($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    default => 'jpg',
                };
                $filename = bin2hex(random_bytes(16)) . '.' . $extension;
                $uploadPath = 'uploads/' . $filename;
                
                if (!is_dir('uploads')) {
                    mkdir('uploads', 0755, true);
                }
                
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadPath)) {
                    $stmt = $db->prepare("INSERT INTO gallery (cycle_id, user_id, image_path, caption) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$cycleId, $userId, $uploadPath, $caption]);
                    $message = 'Photo uploaded successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to upload photo.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Invalid file type. Please upload JPEG, PNG, GIF, or WebP images.';
                $messageType = 'error';
            }
        } else {
            $message = 'Error uploading file.';
            $messageType = 'error';
        }
    }
}

// Get user's participations in active cycles (the "current work" section)
$stmt = $db->prepare("
    SELECT cp.*, c.name as cycle_name, c.start_date, c.pairing_done, c.registration_open
    FROM cycle_participations cp
    JOIN cycles c ON cp.cycle_id = c.id
    WHERE cp.user_id = ?
      AND c.status = 'active'
    ORDER BY c.start_date DESC
");
$stmt->execute([$userId]);
$participations = $stmt->fetchAll();

// Past cycles the user took part in (confirmed participation or actually paired),
// shown collapsed by default in the archive section
$stmt = $db->prepare("
    SELECT cp.*, c.name as cycle_name, c.start_date
    FROM cycle_participations cp
    JOIN cycles c ON cp.cycle_id = c.id
    WHERE cp.user_id = ?
      AND c.status = 'closed'
      AND (cp.participation_confirmed = 1
           OR EXISTS (SELECT 1 FROM cycle_pairings cp2 WHERE cp2.cycle_id = cp.cycle_id AND cp2.user_id = cp.user_id))
    ORDER BY c.start_date DESC
");
$stmt->execute([$userId]);
$pastCycles = $stmt->fetchAll();

// For each cycle (active + past), fetch all pairings
$allPairings = [];
foreach (array_merge($participations, $pastCycles) as $p) {
    $pairStmt = $db->prepare("
        SELECT cp.*, u.name as partner_name, u.email as partner_email,
               u.postal_address as partner_address, u.country as partner_country
        FROM cycle_pairings cp
        JOIN users u ON cp.partner_id = u.id
        WHERE cp.cycle_id = ? AND cp.user_id = ?
        ORDER BY u.name
    ");
    $pairStmt->execute([$p['cycle_id'], $userId]);
    $allPairings[$p['cycle_id']] = $pairStmt->fetchAll();
}

// For each past cycle, fetch the photos the user posted
$pastPhotos = [];
$photoStmt = $db->prepare("SELECT * FROM gallery WHERE cycle_id = ? AND user_id = ? ORDER BY created_at DESC");
foreach ($pastCycles as $p) {
    $photoStmt->execute([$p['cycle_id'], $userId]);
    $pastPhotos[$p['cycle_id']] = $photoStmt->fetchAll();
}

// Compute whether each cycle still needs a photo upload.
// - Active cycles: gate on number received (you can only photograph what you've
//   actually reported receiving).
// - Past (closed) cycles: gate on number of pairings, so anyone who took part
//   can still add a photo even if the received flag was never ticked (e.g. a
//   user who reported sending but never marked the inbound item received).
$needsPhotoMap = [];
$galleryCountStmt = $db->prepare("SELECT COUNT(*) FROM gallery WHERE cycle_id = ? AND user_id = ?");
foreach ($participations as $p) {
    $receivedCount = 0;
    foreach ($allPairings[$p['cycle_id']] ?? [] as $pairing) {
        if ($pairing['zine_received']) {
            $receivedCount++;
        }
    }
    $needsPhotoMap[$p['cycle_id']] = $receivedCount > 0;
    if ($needsPhotoMap[$p['cycle_id']]) {
        $galleryCountStmt->execute([$p['cycle_id'], $userId]);
        $needsPhotoMap[$p['cycle_id']] = (int)$galleryCountStmt->fetchColumn() < $receivedCount;
    }
}
foreach ($pastCycles as $p) {
    $pairingCount = count($allPairings[$p['cycle_id']] ?? []);
    if ($pairingCount > 0) {
        $galleryCountStmt->execute([$p['cycle_id'], $userId]);
        $needsPhotoMap[$p['cycle_id']] = (int)$galleryCountStmt->fetchColumn() < $pairingCount;
    } else {
        $needsPhotoMap[$p['cycle_id']] = false;
    }
}

// Get open cycles that user hasn't participated in yet
$stmt = $db->prepare("
    SELECT c.*, 
           CASE WHEN cp.id IS NOT NULL THEN 1 ELSE 0 END as has_participation
    FROM cycles c
    LEFT JOIN cycle_participations cp ON c.id = cp.cycle_id AND cp.user_id = ?
    WHERE c.status = 'active' AND c.registration_open = 1 AND cp.id IS NULL
    ORDER BY c.start_date DESC
");
$stmt->execute([$userId]);
$availableCycles = $stmt->fetchAll();

// Get user's zine info
$stmt = $db->prepare("SELECT * FROM zines WHERE user_id = ?");
$stmt->execute([$userId]);
$zine = $stmt->fetch();

// Get unseen announcement count
$unseenAnnouncementCount = getUnseenAnnouncementCount($db, $userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Process - <?php echo SITE_TITLE; ?></title>
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
            <a href="process.php" class="active">My Process</a>
            <a href="profile.php">My Profile</a>
            <a href="logout.php">Logout</a>
            <?php if (isAdmin()): ?>
                <a href="admin/index.php">Admin</a>
                <a href="admin/gallery.php">Gallery Mgmt</a>
            <?php endif; ?>
        </nav>
        
        <main>
            <h1>My Exchange Process</h1>
            <p class="welcome">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</p>
            
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            
            <?php if ($zine): ?>
                <section class="my-zine">
                    <h2>My <?php echo ucfirst(CONTENT_TYPE); ?></h2>
                    <p><strong>Theme:</strong> <?php echo htmlspecialchars($zine['theme']); ?></p>
                    <p><strong>Format:</strong> <?php echo htmlspecialchars($zine['format']); ?></p>
                </section>
            <?php endif; ?>
            
            <?php if (!empty($availableCycles)): ?>
                <section class="available-cycles">
                    <h2>Available Exchange Cycles</h2>
                    <p class="section-description">These cycles are currently open for registration. Click "I Want to Participate" to join:</p>
                    
                    <?php foreach ($availableCycles as $cycle): ?>
                        <div class="cycle-card">
                            <h3><?php echo htmlspecialchars($cycle['name']); ?></h3>
                            <p class="date">Starts: <?php echo date('F j, Y', strtotime($cycle['start_date'])); ?></p>
                            <p class="status">Status: <span class="open">Registration Open</span></p>
                            
                            <form method="post" class="participation-form">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="cycle_id" value="<?php echo $cycle['id']; ?>">
                                <button type="submit" name="want_to_participate" class="btn">I Want to Participate</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
            
            <?php if (empty($participations) && empty($availableCycles) && empty($pastCycles)): ?>
                <p class="empty-state">No exchange cycles are currently available. Check back later for new cycles!</p>
            <?php else: ?>
                <?php if (!empty($participations)): ?>
                <section class="participations">
                    <h2>My Participations</h2>
                    
                    <?php foreach ($participations as $p):
                        $pairings = $allPairings[$p['cycle_id']] ?? [];
                        $allConfirmed = !empty($pairings) && array_reduce($pairings, fn($c, $pair) => $c && $pair['pairing_confirmed'], true);
                    ?>
                        <div class="participation-card">
                            <h3><?php echo htmlspecialchars($p['cycle_name']); ?></h3>
                            <p class="date">Started: <?php echo date('F j, Y', strtotime($p['start_date'])); ?></p>

                            <div class="progress-steps">
                                <div class="step <?php echo $p['participation_confirmed'] ? 'completed' : 'pending'; ?>">
                                    <span class="step-icon"><?php echo $p['participation_confirmed'] ? '✓' : '○'; ?></span>
                                    <span class="step-label">Participation Confirmed</span>
                                    <?php if (!$p['participation_confirmed'] && $p['wants_to_participate']): ?>
                                        <form method="post" class="inline-form">
                                            <?php $csrf = generateCsrfToken(); ?>
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="cycle_id" value="<?php echo $p['cycle_id']; ?>">
                                            <button type="submit" name="confirm_participation" class="btn-small">Confirm</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($p['pairing_done'] && !empty($pairings)): ?>
                                <?php foreach ($pairings as $pairing): ?>
                                    <div class="partner-info">
                                        <h4>Exchange Partner: <?php echo htmlspecialchars($pairing['partner_name']); ?></h4>
                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($pairing['partner_email']); ?></p>
                                        <p><strong>Country:</strong> <?php echo htmlspecialchars($pairing['partner_country']); ?></p>
                                        <p><strong>Address:</strong></p>
                                        <p class="address"><?php echo nl2br(htmlspecialchars($pairing['partner_address'])); ?></p>

                                        <div class="progress-steps">
                                            <div class="step <?php echo $pairing['pairing_confirmed'] ? 'completed' : 'pending'; ?>">
                                                <span class="step-icon"><?php echo $pairing['pairing_confirmed'] ? '✓' : '○'; ?></span>
                                                <span class="step-label">Pairing Confirmed</span>
                                                <?php if ($p['pairing_done'] && !$pairing['pairing_confirmed']): ?>
                                                    <form method="post" class="inline-form">
                                                        <?php $csrf = generateCsrfToken(); ?>
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                        <input type="hidden" name="cycle_id" value="<?php echo $p['cycle_id']; ?>">
                                                        <input type="hidden" name="pairing_id" value="<?php echo $pairing['id']; ?>">
                                                        <button type="submit" name="confirm_pairing" class="btn-small">Confirm</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>

                                            <div class="step <?php echo $pairing['zine_sent'] ? 'completed' : 'pending'; ?>">
                                                <span class="step-icon"><?php echo $pairing['zine_sent'] ? '✓' : '○'; ?></span>
                                                <span class="step-label"><?php echo ucfirst(CONTENT_TYPE); ?> Sent to <?php echo htmlspecialchars($pairing['partner_name']); ?></span>
                                                <?php if ($p['pairing_done'] && !$pairing['zine_sent']): ?>
                                                    <form method="post" class="inline-form">
                                                        <?php $csrf = generateCsrfToken(); ?>
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                        <input type="hidden" name="cycle_id" value="<?php echo $p['cycle_id']; ?>">
                                                        <input type="hidden" name="pairing_id" value="<?php echo $pairing['id']; ?>">
                                                        <button type="submit" name="report_sent" class="btn-small">Report Sent</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>

                                            <div class="step <?php echo $pairing['zine_received'] ? 'completed' : 'pending'; ?>">
                                                <span class="step-icon"><?php echo $pairing['zine_received'] ? '✓' : '○'; ?></span>
                                                <span class="step-label"><?php echo ucfirst(CONTENT_TYPE); ?> Received from <?php echo htmlspecialchars($pairing['partner_name']); ?></span>
                                                <?php if (!$pairing['zine_received']): ?>
                                                    <form method="post" class="inline-form">
                                                        <?php $csrf = generateCsrfToken(); ?>
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                                        <input type="hidden" name="cycle_id" value="<?php echo $p['cycle_id']; ?>">
                                                        <input type="hidden" name="pairing_id" value="<?php echo $pairing['id']; ?>">
                                                        <button type="submit" name="report_received" class="btn-small">Report Received</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php elseif ($p['pairing_done'] && empty($pairings)): ?>
                                <p class="empty-state">Waiting for pairing assignment.</p>
                            <?php endif; ?>

                            <?php if ($needsPhotoMap[$p['cycle_id']] ?? false): ?>
                                <div class="upload-section">
                                    <h4>Upload Photo of Received <?php echo ucfirst(CONTENT_TYPE); ?></h4>
                                    <form method="post" enctype="multipart/form-data" class="form">
                                        <?php $csrf = generateCsrfToken(); ?>
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="cycle_id" value="<?php echo $p['cycle_id']; ?>">
                                        <div class="form-group">
                                            <label for="photo_<?php echo $p['cycle_id']; ?>">Photo</label>
                                            <input type="file" id="photo_<?php echo $p['cycle_id']; ?>" name="photo" accept="image/*" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="caption_<?php echo $p['cycle_id']; ?>">Caption (optional)</label>
                                            <input type="text" id="caption_<?php echo $p['cycle_id']; ?>" name="caption">
                                        </div>
                                        <button type="submit" name="upload_photo" class="btn-small">Upload Photo</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </section>
                <?php endif; ?>

                <?php if (!empty($pastCycles)): ?>
                <section class="past-cycles">
                    <h2>Past Cycles</h2>
                    <p class="section-description">Cycles you took part in. Expand to see your partner(s) and the photos you posted.</p>

                    <?php foreach ($pastCycles as $p):
                        $pairings = $allPairings[$p['cycle_id']] ?? [];
                        $photos = $pastPhotos[$p['cycle_id']] ?? [];
                        $needsPhoto = $needsPhotoMap[$p['cycle_id']] ?? false;
                    ?>
                        <details class="past-cycle"<?php echo $needsPhoto ? ' data-pending-photo' : ''; ?>>
                            <summary>
                                <span class="past-cycle-name"><?php echo htmlspecialchars($p['cycle_name']); ?></span>
                                <span class="past-cycle-date"><?php echo date('F Y', strtotime($p['start_date'])); ?></span>
                                <span class="past-cycle-count"><?php echo count($photos); ?> photo<?php echo count($photos) === 1 ? '' : 's'; ?></span>
                                <?php if ($needsPhoto): ?>
                                    <span class="badge-photo">photo pending</span>
                                <?php endif; ?>
                            </summary>
                            <div class="past-cycle-body">
                                <div class="partner-info">
                                    <h4>Exchange Partner<?php echo count($pairings) === 1 ? '' : 's'; ?></h4>
                                    <?php if (!empty($pairings)): ?>
                                        <?php foreach ($pairings as $pairing): ?>
                                            <p class="partner-line"><?php echo htmlspecialchars($pairing['partner_name']); ?><?php if ($pairing['partner_country']): ?> (<?php echo htmlspecialchars($pairing['partner_country']); ?>)<?php endif; ?></p>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="empty-state">No partner assigned.</p>
                                    <?php endif; ?>
                                </div>

                                <div class="past-photos">
                                    <h4>Photos I Posted</h4>
                                    <?php if (!empty($photos)): ?>
                                        <div class="gallery-grid gallery-grid--compact">
                                            <?php foreach ($photos as $photo): ?>
                                                <div class="gallery-item">
                                                    <a href="<?php echo htmlspecialchars($photo['image_path']); ?>" target="_blank" rel="noopener">
                                                        <img src="<?php echo htmlspecialchars($photo['image_path']); ?>" alt="<?php echo ucfirst(CONTENT_TYPE); ?> photo">
                                                    </a>
                                                    <div class="gallery-info">
                                                        <?php if ($photo['caption']): ?>
                                                            <p class="caption"><?php echo htmlspecialchars($photo['caption']); ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="empty-state">No photo posted yet.</p>
                                    <?php endif; ?>
                                </div>

                                <?php if ($needsPhoto): ?>
                                    <div class="upload-section">
                                        <h4>Upload Photo of Received <?php echo ucfirst(CONTENT_TYPE); ?></h4>
                                        <form method="post" enctype="multipart/form-data" class="form">
                                            <?php $csrf = generateCsrfToken(); ?>
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="cycle_id" value="<?php echo $p['cycle_id']; ?>">
                                            <div class="form-group">
                                                <label for="photo_<?php echo $p['cycle_id']; ?>">Photo</label>
                                                <input type="file" id="photo_<?php echo $p['cycle_id']; ?>" name="photo" accept="image/*" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="caption_<?php echo $p['cycle_id']; ?>">Caption (optional)</label>
                                                <input type="text" id="caption_<?php echo $p['cycle_id']; ?>" name="caption">
                                            </div>
                                            <button type="submit" name="upload_photo" class="btn-small">Upload Photo</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </section>
                <?php endif; ?>

        <!-- Contact Administrator Section -->
        <section class="contact-section">
            <div class="container">
                <div class="contact-card">
                    <h2>Need Help?</h2>
                    <div class="contact-box">
                        <h3>Contact Administrator</h3>
                        <p>If you have any questions or issues with the <?php echo CONTENT_TYPE; ?> exchange process, please contact the administrator:</p>
                        <div class="contact-details">
                            <p><strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars(ADMIN_EMAIL); ?>"><?php echo htmlspecialchars(ADMIN_EMAIL); ?></a></p>
                            <p><strong>Response Time:</strong> We'll respond to your inquiry within 24-48 hours.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

            <?php endif; ?>
        </main>
               
        <?php require_once 'includes/footer.php'; ?>
    </div>
</body>
</html>
