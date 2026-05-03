<?php
/**
 * Helper functions for the Zine Exchange Club
 */

/**
 * Get count of unseen announcements for a user
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @return int Number of unseen announcements
 */
function getUnseenAnnouncementCount($db, $userId) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as count
        FROM announcements a
        LEFT JOIN announcement_views av ON a.id = av.announcement_id AND av.user_id = ?
        WHERE av.id IS NULL
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    return (int)$result['count'];
}

/**
 * Get unread announcements for navigation display
 * @param PDO $db Database connection
 * @param int $userId User ID
 * @return array Array of unread announcement titles
 */
function getUnreadAnnouncements($db, $userId) {
    $stmt = $db->prepare("
        SELECT a.title, a.created_at
        FROM announcements a
        LEFT JOIN announcement_views av ON a.id = av.announcement_id AND av.user_id = ?
        WHERE av.id IS NULL
        ORDER BY a.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
?>
