<?php
/**
 * Test: confirm-participation.php token lookup logic + login.php next-validation.
 * Mirrors the exact query and branch conditions used by the pages.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';

$db = getDB();
createTestSchema($db);

// Seed: closed cycle, user 1 confirmed, user 2 unconfirmed (fresh token)
$db->exec("INSERT INTO cycles (id, name, start_date, status) VALUES (1, 'Cycle 1', '2026-01-01', 'closed')");
$db->exec("INSERT INTO users (id, name, email, password, postal_address, country) VALUES (1, 'Alice', 'a@test.com', 'x', 'A1', 'FR'), (2, 'Bob', 'b@test.com', 'x', 'A2', 'UK')");
$db->exec("INSERT INTO cycle_participations (cycle_id, user_id, participation_confirmed, confirmation_token, confirmation_token_expires)
           VALUES (1, 1, 1, 'usedtoken', '2026-02-01 00:00:00'), (1, 2, 0, 'freshtoken', '2026-02-01 00:00:00')");

// Mirror of the page's lookup query (no confirmed/expired pre-filter)
$query = "
    SELECT cp.id, cp.user_id, cp.cycle_id, cp.participation_confirmed, cp.confirmation_token_expires, c.name
    FROM cycle_participations cp
    JOIN cycles c ON cp.cycle_id = c.id
    WHERE cp.confirmation_token = ?
";
$time = strtotime('2026-01-15 00:00:00'); // simulate NOW

function lookupToken(PDO $db, string $q, string $token): ?array {
    $st = $db->prepare($q);
    $st->execute([$token]);
    $r = $st->fetch();
    return $r ?: null;
}
function isExpired(?array $p, int $time): bool {
    return $p !== null && $p['confirmation_token_expires'] !== null
        && strtotime($p['confirmation_token_expires']) <= $time;
}

// 1. Already-used token -> found + confirmed => friendly success branch
$p = lookupToken($db, $query, 'usedtoken');
assert_equal('used token still found (no pre-filter)', true, $p !== null);
assert_equal('used token shows already-confirmed', true, $p['participation_confirmed'] == 1);

// 2. Fresh token -> found + not confirmed + not expired => confirm branch
$p = lookupToken($db, $query, 'freshtoken');
assert_equal('fresh token found', true, $p !== null);
assert_equal('fresh token not confirmed', false, $p['participation_confirmed'] == 1);
assert_equal('fresh token not expired', false, isExpired($p, $time));

// 3. Unknown token -> not found => invalid branch
assert_equal('unknown token not found', true, lookupToken($db, $query, 'nope') === null);

// 4. Expired token -> found but expired => expired branch
$db->exec("UPDATE cycle_participations SET confirmation_token = 'expiredtoken', confirmation_token_expires = '2025-12-01 00:00:00' WHERE user_id = 2");
$p = lookupToken($db, $query, 'expiredtoken');
assert_equal('expired token found', true, $p !== null);
assert_equal('expired token flagged expired', true, isExpired($p, $time));

// ── login.php next-parameter validation (mirror of the guard) ──
function sanitizeNext(string $next): string {
    $next = ($next !== '' && $next[0] === '/' && ($next[1] ?? '/') !== '/') ? $next : 'process.php';
    return $next;
}
assert_equal('local next kept', '/confirm-participation.php?token=abc', sanitizeNext('/confirm-participation.php?token=abc'));
assert_equal('scheme URL rejected -> default', 'process.php', sanitizeNext('https://evil.com'));
assert_equal('protocol-relative rejected -> default', 'process.php', sanitizeNext('//evil.com'));
assert_equal('empty -> default', 'process.php', sanitizeNext(''));
assert_equal('bare word -> default', 'process.php', sanitizeNext('gallery.php'));
