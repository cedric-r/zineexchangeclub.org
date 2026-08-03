<?php
declare(strict_types=1);

/**
 * ReminderScriptsTest — verifies the reminder-posting cron query semantics.
 *
 * SQLite has no CURDATE()/DATE_SUB(), so the exact query from
 * scripts/reminder-posting.php is reproduced with PHP-computed dates.
 * Semantics (14-day threshold, zine_sent filter, mutual pair rows)
 * must be identical.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';

$db = getDB();
createTestSchema($db);

// ── Fixtures ────────────────────────────────────────────────────────

$db->exec("INSERT INTO users (name, email, password, postal_address, country, email_confirmed) VALUES
    ('Alice', 'alice@test.local', 'x', 'addr', 'uk', 1),
    ('Bob',   'bob@test.local',   'x', 'addr', 'us', 1),
    ('Carol', 'carol@test.local', 'x', 'addr', 'fr', 1),
    ('Dave',  'dave@test.local',  'x', 'addr', 'de', 1)");

// Cycle paired 20 days ago — inside the 14+ day reminder window
$db->exec("INSERT INTO cycles (name, start_date, status, registration_open, pairing_done) VALUES
    ('Cycle 1', '" . date('Y-m-d', strtotime('-20 days')) . "', 'active', 0, 1)");

// Mutual pair rows (as created by pairing algorithms): Alice↔Bob, Carol↔Dave
$db->exec("INSERT INTO cycle_pairings (cycle_id, user_id, partner_id) VALUES
    (1, 1, 2), (1, 2, 1),
    (1, 3, 4), (1, 4, 3)");

// Alice reported sent (as process.php report_sent does); Bob, Carol, Dave have not
$db->exec("UPDATE cycle_pairings SET zine_sent = 1, zine_sent_date = '" . date('Y-m-d', strtotime('-2 days')) . "' WHERE cycle_id = 1 AND user_id = 1");

// Legacy data that should NOT influence the query: old participations table
$db->exec("INSERT INTO cycle_participations (cycle_id, user_id, paired_with_id, zine_sent) VALUES
    (1, 1, 2, 0), (1, 2, 1, 0), (1, 3, 4, 0), (1, 4, 3, 0)");

// ── The reminder-posting query (reproduced) ────────────────────────

$cutoff = date('Y-m-d', strtotime('-14 days'));
$stmt = $db->prepare("
    SELECT cp.user_id
    FROM cycle_pairings cp
    JOIN users u ON cp.user_id = u.id
    JOIN cycles c ON cp.cycle_id = c.id
    WHERE c.pairing_done = 1
    AND cp.zine_sent = 0
    AND c.start_date <= ?
    AND u.email_confirmed = 1
");
$stmt->execute([$cutoff]);
$reminded = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
sort($reminded);

assert_equal(
    'Reminder targets only users who have NOT reported sent',
    [2, 3, 4],
    $reminded
);
assert_true(
    'User who reported sent is never reminded',
    !in_array(1, $reminded, true)
);

// ── Multiple pairings: per-row independence ────────────────────────

// Bob gets a second partner (Alice already paired) — Bob↔Carol added later
$db->exec("INSERT INTO cycle_pairings (cycle_id, user_id, partner_id) VALUES (1, 2, 3), (1, 3, 2)");

// Bob reports sent for ONE pairing only (legacy per-cycle report would mark both)
$db->exec("UPDATE cycle_pairings SET zine_sent = 1, zine_sent_date = '" . date('Y-m-d', strtotime('-1 days')) . "' WHERE cycle_id = 1 AND user_id = 2 AND partner_id = 1");

$stmt = $db->prepare("
    SELECT cp.user_id, cp.partner_id
    FROM cycle_pairings cp
    JOIN users u ON cp.user_id = u.id
    JOIN cycles c ON cp.cycle_id = c.id
    WHERE c.pairing_done = 1
    AND cp.zine_sent = 0
    AND c.start_date <= ?
    AND u.email_confirmed = 1
    ORDER BY cp.user_id, cp.partner_id
");
$stmt->execute([$cutoff]);
$rows = array_map(fn($r) => [(int)$r['user_id'], (int)$r['partner_id']], $stmt->fetchAll());

assert_true(
    'Per-row reminder: Bob still reminded for second partner (2→3)',
    in_array([2, 3], $rows, true)
);
assert_true(
    'Per-row reminder: Bob not reminded for reported pairing (2→1)',
    !in_array([2, 1], $rows, true)
);

echo "ReminderScriptsTest completed.\n";
