<?php
/**
 * Test: process.php pairing pre-fetch returns ALL pairings for doubled-up users.
 * Mirrors the exact queries used by process.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';

$db = getDB();
createTestSchema($db);

// Seed: cycle, 3 users, user 1 paired with users 2 AND 3
$db->exec("INSERT INTO cycles (id, name, start_date, registration_open, pairing_done, status) VALUES (1, 'Cycle 1', '2026-01-01', 0, 1, 'active')");
$db->exec("INSERT INTO users (id, name, email, password, postal_address, country) VALUES (1, 'Alice', 'a@test.com', 'x', 'Addr 1', 'France'), (2, 'Bob', 'b@test.com', 'x', 'Addr 2', 'USA'), (3, 'Carol', 'c@test.com', 'x', 'Addr 3', 'UK')");
$db->exec("INSERT INTO cycle_participations (cycle_id, user_id, wants_to_participate, participation_confirmed) VALUES (1, 1, 1, 1), (1, 2, 1, 1), (1, 3, 1, 1)");
$db->exec("INSERT INTO cycle_pairings (cycle_id, user_id, partner_id) VALUES (1, 1, 2), (1, 2, 1), (1, 1, 3), (1, 3, 1)");

// Alice confirmed only her pairing with Bob; Carol sent her zine
$db->exec("UPDATE cycle_pairings SET pairing_confirmed = 1 WHERE cycle_id = 1 AND user_id = 1 AND partner_id = 2");
$db->exec("UPDATE cycle_pairings SET zine_sent = 1, zine_sent_date = '2026-02-01' WHERE cycle_id = 1 AND user_id = 3");

// ── process.php main participation query ──
$stmt = $db->prepare("
    SELECT cp.*, c.name as cycle_name, c.start_date, c.pairing_done, c.registration_open
    FROM cycle_participations cp
    JOIN cycles c ON cp.cycle_id = c.id
    WHERE cp.user_id = ?
      AND c.status = 'active'
    ORDER BY c.start_date DESC
");
$stmt->execute([1]);
$participations = $stmt->fetchAll();
assert_equal('process.php: user has one participation', 1, count($participations));

// ── process.php pairing pre-fetch ──
$allPairings = [];
foreach ($participations as $p) {
    $pairStmt = $db->prepare("
        SELECT cp.*, u.name as partner_name, u.email as partner_email,
               u.postal_address as partner_address, u.country as partner_country
        FROM cycle_pairings cp
        JOIN users u ON cp.partner_id = u.id
        WHERE cp.cycle_id = ? AND cp.user_id = ?
        ORDER BY u.name
    ");
    $pairStmt->execute([$p['cycle_id'], 1]);
    $allPairings[$p['cycle_id']] = $pairStmt->fetchAll();
}

$pairings = $allPairings[1] ?? [];
assert_equal('process.php: doubled-up user gets 2 pairings', 2, count($pairings));
assert_true('process.php: pairing rows have distinct partner names', $pairings[0]['partner_name'] !== $pairings[1]['partner_name']);
$names = array_column($pairings, 'partner_name');
sort($names);
assert_equal('process.php: partners are Bob and Carol', ['Bob', 'Carol'], $names);

// ── admin/index.php current query shape (per-participation aggregate) ──
$stmt = $db->prepare("
    SELECT cp.*, u.name, u.email, u.country,
           EXISTS (SELECT 1 FROM cycle_pairings cp2 WHERE cp2.cycle_id = cp.cycle_id AND cp2.user_id = cp.user_id AND cp2.pairing_confirmed = 1) AS pairing_confirmed,
           EXISTS (SELECT 1 FROM cycle_pairings cp2 WHERE cp2.cycle_id = cp.cycle_id AND cp2.user_id = cp.user_id AND cp2.zine_sent = 1) AS zine_sent,
           (SELECT MAX(cp2.zine_sent_date) FROM cycle_pairings cp2 WHERE cp2.cycle_id = cp.cycle_id AND cp2.user_id = cp.user_id) AS zine_sent_date,
           EXISTS (SELECT 1 FROM cycle_pairings cp2 WHERE cp2.cycle_id = cp.cycle_id AND cp2.user_id = cp.user_id AND cp2.zine_received = 1) AS zine_received,
           (SELECT MAX(cp2.zine_received_date) FROM cycle_pairings cp2 WHERE cp2.cycle_id = cp.cycle_id AND cp2.user_id = cp.user_id) AS zine_received_date
    FROM cycle_participations cp
    JOIN users u ON cp.user_id = u.id
    WHERE cp.cycle_id = ?
    ORDER BY u.name
");
$stmt->execute([1]);
$rows = $stmt->fetchAll();
assert_equal('admin: current query returns 1 row per user (aggregated)', 3, count($rows));

// ── proposed per-pairing admin query ──
$stmt = $db->prepare("
    SELECT cp.*, u.name, u.country, pu.name AS partner_name
    FROM cycle_pairings cp
    JOIN users u ON cp.user_id = u.id
    JOIN users pu ON cp.partner_id = pu.id
    WHERE cp.cycle_id = ?
    ORDER BY u.name, pu.name
");
$stmt->execute([1]);
$rows = $stmt->fetchAll();
assert_equal('admin (new): one row per pairing', 4, count($rows));
$aliceRows = array_values(array_filter($rows, fn($r) => $r['user_id'] === 1));
assert_equal('admin (new): Alice has 2 rows (Bob + Carol)', 2, count($aliceRows));
assert_true('admin (new): per-pairing status kept separate', ($aliceRows[0]['pairing_confirmed'] + $aliceRows[1]['pairing_confirmed']) === 1);
