<?php
/**
 * Test: process.php photo-upload gating + past-cycles archive queries.
 * Mirrors the exact queries used by process.php.
 *
 * Scenario: closed cycle, user paired with 2 partners, received both.
 * - The cycle appears under Past Cycles (collapsed archive), not under
 *   "My Participations" (active cycles only).
 * - ACTIVE cycles gate the upload form on photos < received.
 * - PAST (closed) cycles gate the upload form on photos < pairings, so a user
 *   who took part can still upload even if they never ticked "received".
 * - Past Cycles includes users who confirmed OR were paired; excludes
 *   interest-only (want_to_participate, no confirm, no pairing).
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';

$db = getDB();
createTestSchema($db);

// Seed: closed, fully-paired cycle; user 1 paired with users 2 AND 3
$db->exec("INSERT INTO cycles (id, name, start_date, registration_open, pairing_done, status) VALUES (1, 'Cycle 1', '2026-01-01', 0, 1, 'closed')");
$db->exec("INSERT INTO users (id, name, email, password, postal_address, country) VALUES (1, 'Alice', 'a@test.com', 'x', 'Addr 1', 'France'), (2, 'Bob', 'b@test.com', 'x', 'Addr 2', 'USA'), (3, 'Carol', 'c@test.com', 'x', 'Addr 3', 'UK')");
$db->exec("INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate, participation_confirmed) VALUES (1, 1, 1, 1), (1, 2, 1, 1), (1, 3, 1, 1)");
$db->exec("INSERT INTO cycle_pairings (cycle_id, user_id, partner_id, zine_received) VALUES (1, 1, 2, 1), (1, 2, 1, 0), (1, 1, 3, 1), (1, 3, 1, 0)");

// ── process.php active-only participation query ──
$activeQuery = "
    SELECT cp.*, c.name as cycle_name, c.start_date, c.pairing_done, c.registration_open
    FROM cycle_participations cp
    JOIN cycles c ON cp.cycle_id = c.id
    WHERE cp.user_id = ?
      AND c.status = 'active'
    ORDER BY c.start_date DESC
";

// ── process.php past-cycles archive query ──
$pastQuery = "
    SELECT cp.*, c.name as cycle_name, c.start_date
    FROM cycle_participations cp
    JOIN cycles c ON cp.cycle_id = c.id
    WHERE cp.user_id = ?
      AND c.status = 'closed'
      AND (cp.participation_confirmed = 1
           OR EXISTS (SELECT 1 FROM cycle_pairings cp2 WHERE cp2.cycle_id = cp.cycle_id AND cp2.user_id = cp.user_id))
    ORDER BY c.start_date DESC
";

function fetchRows(PDO $db, string $query, int $userId): array {
    $stmt = $db->prepare($query);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function countReceivedPairings(PDO $db, int $cycleId, int $userId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM cycle_pairings WHERE cycle_id = ? AND user_id = ? AND zine_received = 1");
    $stmt->execute([$cycleId, $userId]);
    return (int)$stmt->fetchColumn();
}

function countGalleryPhotos(PDO $db, int $cycleId, int $userId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM gallery WHERE cycle_id = ? AND user_id = ?");
    $stmt->execute([$cycleId, $userId]);
    return (int)$stmt->fetchColumn();
}

function countPairings(PDO $db, int $cycleId, int $userId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM cycle_pairings WHERE cycle_id = ? AND user_id = ?");
    $stmt->execute([$cycleId, $userId]);
    return (int)$stmt->fetchColumn();
}

// process.php PAST-cycle gate: pairingCount > 0 && photos < pairingCount
function pastNeedsPhoto(PDO $db, int $cycleId, int $userId): bool {
    $pairingCount = countPairings($db, $cycleId, $userId);
    return $pairingCount > 0 && countGalleryPhotos($db, $cycleId, $userId) < $pairingCount;
}

// process.php ACTIVE-cycle gate: received > 0 && photos < received
function activeNeedsPhoto(PDO $db, int $cycleId, int $userId): bool {
    $receivedCount = countReceivedPairings($db, $cycleId, $userId);
    return $receivedCount > 0 && countGalleryPhotos($db, $cycleId, $userId) < $receivedCount;
}

// ── Step 1: closed cycle must NOT appear in active participations ──
assert_equal('closed cycle not in active participations', 0, count(fetchRows($db, $activeQuery, 1)));

// ── Step 2: closed cycle appears in Past Cycles, photo pending (no uploads) ──
$past = fetchRows($db, $pastQuery, 1);
assert_equal('closed cycle in past cycles', 1, count($past));
assert_equal('no photos: past gate owes 2', true, pastNeedsPhoto($db, 1, 1));

// ── Step 3: upload ONE photo — still in past cycles, still owes one ──
$db->exec("INSERT INTO gallery (cycle_id, user_id, image_path, caption) VALUES (1, 1, 'uploads/one.jpg', 'From Rob.')");
$past = fetchRows($db, $pastQuery, 1);
assert_equal('one photo: still in past cycles (archive always shows)', 1, count($past));
assert_equal('one photo of two: past gate still owes one', true, pastNeedsPhoto($db, 1, 1));

// Photos in OTHER cycles must not leak into this cycle's count
$db->exec("INSERT INTO cycles (id, name, start_date, registration_open, pairing_done, status) VALUES (9, 'Other Cycle', '2025-01-01', 0, 1, 'closed')");
$db->exec("INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate, participation_confirmed) VALUES (9, 1, 1, 1)");
$db->exec("INSERT INTO gallery (cycle_id, user_id, image_path, caption) VALUES (9, 1, 'uploads/other.jpg', 'Different cycle.')");
assert_equal('cross-cycle photo does not count', 1, countGalleryPhotos($db, 1, 1));

// Photos per past cycle: only own photos for that cycle
$photoStmt = $db->prepare("SELECT * FROM gallery WHERE cycle_id = ? AND user_id = ? ORDER BY created_at DESC");
$photoStmt->execute([1, 1]);
$photos = $photoStmt->fetchAll();
assert_equal('past photos: only cycle-1 photos (not cycle 0)', 1, count($photos));
assert_equal('past photos: correct path', 'uploads/one.jpg', $photos[0]['image_path']);

// ── Step 4: upload SECOND photo — archive still shows, no longer pending ──
$db->exec("INSERT INTO gallery (cycle_id, user_id, image_path, caption) VALUES (1, 1, 'uploads/two.jpg', 'From Kenneth.')");
$needsPhoto = pastNeedsPhoto($db, 1, 1);
assert_equal('two photos for two pairings: no longer pending', false, $needsPhoto);
$pastIds = array_column(fetchRows($db, $pastQuery, 1), 'cycle_id');
assert_equal('fully photoed cycle still in past cycles', true, in_array(1, $pastIds));

// ── Membership rules ──
// Single-pairing sanity: Bob alone in another cycle
$db->exec("INSERT INTO cycles (id, name, start_date, registration_open, pairing_done, status) VALUES (2, 'Cycle 2', '2026-02-01', 0, 1, 'closed')");
$db->exec("INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate, participation_confirmed) VALUES (2, 2, 1, 1)");
$db->exec("INSERT INTO cycle_pairings (cycle_id, user_id, partner_id, zine_received) VALUES (2, 2, 1, 1)");
$bobIds = array_column(fetchRows($db, $pastQuery, 2), 'cycle_id');
assert_equal('paired+confirmed user in past cycles', true, in_array(2, $bobIds));

// Received nothing but confirmed + paired: still in past cycles
$db->exec("INSERT INTO cycles (id, name, start_date, registration_open, pairing_done, status) VALUES (3, 'Cycle 3', '2026-03-01', 0, 1, 'closed')");
$db->exec("INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate, participation_confirmed) VALUES (3, 3, 1, 1)");
$db->exec("INSERT INTO cycle_pairings (cycle_id, user_id, partner_id, zine_received) VALUES (3, 3, 1, 0)");
$carolIds = array_column(fetchRows($db, $pastQuery, 3), 'cycle_id');
assert_equal('received nothing: still in past cycles (took part)', true, in_array(3, $carolIds));

// Interest-only: wants_to_participate, NOT confirmed, NO pairing -> excluded
$db->exec("INSERT INTO users (id, name, email, password, postal_address, country) VALUES (4, 'Dave', 'd@test.com', 'x', 'Addr 4', 'DE')");
$db->exec("INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate, participation_confirmed) VALUES (3, 4, 1, 0)");
assert_equal('interest-only (no confirm, no pairing): excluded', 0, count(fetchRows($db, $pastQuery, 4)));

// Active cycle stays in active participations, never in past
$db->exec("INSERT INTO cycles (id, name, start_date, registration_open, pairing_done, status) VALUES (4, 'Cycle 4', '2026-04-01', 1, 0, 'active')");
$db->exec("INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate, participation_confirmed) VALUES (4, 1, 1, 1)");
assert_equal('active cycle in active participations', 1, count(fetchRows($db, $activeQuery, 1)));
$pastIds = array_column(fetchRows($db, $pastQuery, 1), 'cycle_id');
assert_equal('active cycle not in past cycles', false, in_array(4, $pastIds));

// ── Lauri regression: took part, sent, but received flag never ticked ──
// (She received the postcard very late; the inbound item was never marked
//  received, so the old received-count gate hid the upload form entirely.)
$db->exec("INSERT INTO cycles (id, name, start_date, registration_open, pairing_done, status) VALUES (5, 'Cycle 5', '2026-05-01', 0, 1, 'closed')");
$db->exec("INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate, participation_confirmed) VALUES (5, 5, 1, 1)");
$db->exec("INSERT INTO cycle_pairings (cycle_id, user_id, partner_id, zine_sent, zine_received) VALUES (5, 5, 2, 1, 0)");
$lauriIds = array_column(fetchRows($db, $pastQuery, 5), 'cycle_id');
assert_equal('lauri: closed cycle in past cycles', true, in_array(5, $lauriIds));
assert_equal('lauri: received flag is 0 (never ticked)', 0, countReceivedPairings($db, 5, 5));
assert_equal('lauri: past gate allows upload despite received=0 (the bug)', true, pastNeedsPhoto($db, 5, 5));
assert_equal('lauri: no photo uploaded yet', 0, countGalleryPhotos($db, 5, 5));

// The active-cycle gate stays strict: received=0 on an active cycle -> no form
assert_equal('active gate stays strict (received=0 -> no form)', false, activeNeedsPhoto($db, 5, 5));