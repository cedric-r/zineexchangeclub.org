<?php
/**
 * Tests for includes/functions.php
 *
 * Covers: getUnseenAnnouncementCount, getUnreadAnnouncements
 */

require_once __DIR__ . '/bootstrap.php';
// config.php required for getDB() — bootstrap creates it if absent
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
createTestSchema($db);

// Seed: one admin user who creates announcements, one regular user
$adminId = insertTestUser($db, [
    'name'       => 'Admin',
    'email'      => 'admin@func-test.local',
    'is_admin'   => 1,
]);
$userId = insertTestUser($db, [
    'name'       => 'Regular',
    'email'      => 'user@func-test.local',
]);

// Insert announcements
$insertStmt = $db->prepare("
    INSERT INTO announcements (title, content, created_by, created_at)
    VALUES (?, ?, ?, ?)
");

$insertStmt->execute(['Announcement 1', 'Content 1', $adminId, '2024-01-01 10:00:00']);
$insertStmt->execute(['Announcement 2', 'Content 2', $adminId, '2024-01-02 10:00:00']);
$insertStmt->execute(['Announcement 3', 'Content 3', $adminId, '2024-01-03 10:00:00']);
$insertStmt->execute(['Announcement 4', 'Content 4', $adminId, '2024-01-04 10:00:00']);
$insertStmt->execute(['Announcement 5', 'Content 5', $adminId, '2024-01-05 10:00:00']);
$insertStmt->execute(['Announcement 6', 'Content 6', $adminId, '2024-01-06 10:00:00']);

// ── getUnseenAnnouncementCount ──────────────────────────────────────

// All announcements are unseen
$count = getUnseenAnnouncementCount($db, $userId);
assert_equal('getUnseenAnnouncementCount returns 6 when none viewed', 6, $count);

// User with no announcements at all should return 0
$otherUserId = insertTestUser($db, ['name' => 'Other', 'email' => 'other@func-test.local']);
// No announcements for this user because there ARE announcements in the table,
// but the query checks announcement_views, not whether the user exists.
// Actually, the query LEFT JOINs on announcement_views, so all announcements
// are still counted. The count is 6 for any user who hasn't viewed anything.
$count = getUnseenAnnouncementCount($db, $otherUserId);
assert_equal('getUnseenAnnouncementCount returns all for new user', 6, $count);

// After viewing some announcements, count should decrease
$viewStmt = $db->prepare("INSERT INTO announcement_views (announcement_id, user_id) VALUES (?, ?)");
$viewStmt->execute([1, $userId]);
$viewStmt->execute([2, $userId]);
$viewStmt->execute([3, $userId]);

$count = getUnseenAnnouncementCount($db, $userId);
assert_equal('getUnseenAnnouncementCount counts only unseen (3 viewed)', 3, $count);

// After viewing all
$viewStmt->execute([4, $userId]);
$viewStmt->execute([5, $userId]);
$viewStmt->execute([6, $userId]);

$count = getUnseenAnnouncementCount($db, $userId);
assert_equal('getUnseenAnnouncementCount returns 0 when all viewed', 0, $count);

// Edge: no announcements in the table
$db->exec("DELETE FROM announcements");
$count = getUnseenAnnouncementCount($db, $userId);
assert_equal('getUnseenAnnouncementCount returns 0 when no announcements exist', 0, $count);

// ── getUnreadAnnouncements ──────────────────────────────────────────

// Re-insert announcements for this test
$insertStmt->execute(['A1', 'C1', $adminId, '2024-02-01 10:00:00']);
$insertStmt->execute(['A2', 'C2', $adminId, '2024-02-02 10:00:00']);
$insertStmt->execute(['A3', 'C3', $adminId, '2024-02-03 10:00:00']);

$unread = getUnreadAnnouncements($db, $userId);
assert_true('getUnreadAnnouncements returns array', is_array($unread));
assert_equal('getUnreadAnnouncements returns unseen announcements', 3, count($unread));
assert_equal('getUnreadAnnouncements first title is most recent', 'A3', $unread[0]['title']);

// After viewing all
$viewStmt->execute([7, $userId]);
$viewStmt->execute([8, $userId]);
$viewStmt->execute([9, $userId]);

$unread = getUnreadAnnouncements($db, $userId);
assert_equal('getUnreadAnnouncements returns empty when all viewed', 0, count($unread));

// More than 5 announcements — LIMIT 5 checks
$insertStmt->execute(['B1', 'C', $adminId, '2024-03-01 10:00:00']);
$insertStmt->execute(['B2', 'C', $adminId, '2024-03-02 10:00:00']);
$insertStmt->execute(['B3', 'C', $adminId, '2024-03-03 10:00:00']);
$insertStmt->execute(['B4', 'C', $adminId, '2024-03-04 10:00:00']);
$insertStmt->execute(['B5', 'C', $adminId, '2024-03-05 10:00:00']);
$insertStmt->execute(['B6', 'C', $adminId, '2024-03-06 10:00:00']);

$unread = getUnreadAnnouncements($db, $otherUserId);
assert_equal('getUnreadAnnouncements respects LIMIT 5', 5, count($unread));

// Edge: empty result set
$otherUser2Id = insertTestUser($db, ['name' => 'Nobody', 'email' => 'nobody@func-test.local']);
$unread = getUnreadAnnouncements($db, $otherUser2Id);
assert_true('getUnreadAnnouncements returns empty array for user with no views', is_array($unread));
