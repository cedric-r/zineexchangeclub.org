<?php
/**
 * Test: process.php photo-upload gating with multiple pairings.
 * Mirrors the exact queries used by process.php.
 *
 * Scenario: closed cycle, user paired with 2 partners, received both,
 * uploads one photo -> cycle must STILL appear and offer an upload form
 * (one more photo owed). After uploading a second photo -> hidden.
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

// ── process.php main participation query (current version) ──
$participationQuery = "
    SELECT cp.*, c.name as cycle_name, c.start_date, c.pairing_done, c.registration_open
    FROM cycle_participations cp
    JOIN cycles c ON cp.cycle_id = c.id
    WHERE cp.user_id = ?
      AND (
          c.status = 'active'
          OR (c.status = 'closed'
              AND EXISTS (SELECT 1 FROM cycle_pairings cp2 WHERE cp2.cycle_id = cp.cycle_id AND cp2.user_id = cp.user_id AND cp2.zine_received = 1)
              AND (SELECT COUNT(*) FROM gallery g WHERE g.cycle_id = cp.cycle_id AND g.user_id = cp.user_id)
                  < (SELECT COUNT(*) FROM cycle_pairings cp2 WHERE cp2.cycle_id = cp.cycle_id AND cp2.user_id = cp.user_id AND cp2.zine_received = 1))
      )
    ORDER BY c.start_date DESC
";

function fetchParticipations(PDO $db, string $query, int $userId): array {
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

// ── Step 1: no photos yet — cycle visible ──
$participations = fetchParticipations($db, $participationQuery, 1);
assert_equal('no photos: cycle visible', 1, count($participations));
assert_equal('no photos: owes 2 photos', true, countGalleryPhotos($db, 1, 1) < countReceivedPairings($db, 1, 1));

// ── Step 2: upload ONE photo (as Cedric did for Rob) — cycle must stay visible ──
$db->exec("INSERT INTO gallery (cycle_id, user_id, image_path, caption) VALUES (1, 1, 'uploads/one.jpg', 'From Rob.')");
$participations = fetchParticipations($db, $participationQuery, 1);
assert_equal('one photo of two received: cycle STILL visible (the bug)', 1, count($participations));
assert_equal('one photo of two received: still owes one', true, countGalleryPhotos($db, 1, 1) < countReceivedPairings($db, 1, 1));

// Upload-form gate logic from process.php ($needsPhotoMap)
$needsPhoto = countGalleryPhotos($db, 1, 1) < countReceivedPairings($db, 1, 1);
assert_equal('upload form shown after first photo', true, $needsPhoto);

// ── Step 3: upload SECOND photo — cycle now complete, hidden ──
$db->exec("INSERT INTO gallery (cycle_id, user_id, image_path, caption) VALUES (1, 1, 'uploads/two.jpg', 'From Kenneth.')");
$participations = fetchParticipations($db, $participationQuery, 1);
assert_equal('two photos for two received: cycle hidden', 0, count($participations));

// Single-pairing sanity: partner Bob alone in another cycle
$db->exec("INSERT INTO cycles (id, name, start_date, registration_open, pairing_done, status) VALUES (2, 'Cycle 2', '2026-02-01', 0, 1, 'closed')");
$db->exec("INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate, participation_confirmed) VALUES (2, 2, 1, 1)");
$db->exec("INSERT INTO cycle_pairings (cycle_id, user_id, partner_id, zine_received) VALUES (2, 2, 1, 1)");
$bobVisible = fetchParticipations($db, $participationQuery, 2);
assert_equal('single pairing, no photo: visible', 1, count($bobVisible));
$db->exec("INSERT INTO gallery (cycle_id, user_id, image_path, caption) VALUES (2, 2, 'uploads/bob.jpg', NULL)");
assert_equal('single pairing, one photo: hidden', 0, count(fetchParticipations($db, $participationQuery, 2)));

// No-received sanity: closed cycle where user received nothing stays hidden
$db->exec("INSERT INTO cycles (id, name, start_date, registration_open, pairing_done, status) VALUES (3, 'Cycle 3', '2026-03-01', 0, 1, 'closed')");
$db->exec("INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate, participation_confirmed) VALUES (3, 3, 1, 1)");
$db->exec("INSERT INTO cycle_pairings (cycle_id, user_id, partner_id, zine_received) VALUES (3, 3, 1, 0)");
assert_equal('received nothing: hidden regardless of photos', 0, count(fetchParticipations($db, $participationQuery, 3)));
