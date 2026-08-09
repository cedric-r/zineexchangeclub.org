#!/usr/bin/php
<?php
// Crontab script to remind users who haven't reported posting their zine
// Run this script 2 weeks after pairing and then every week after that

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/email.php';

$db = getDB();

// Find users who were paired more than 14 days ago but haven't reported sending
$stmt = $db->prepare("
    SELECT cp.user_id, cp.cycle_id, u.name, u.email, c.name as cycle_name, c.start_date
    FROM cycle_pairings cp
    JOIN users u ON cp.user_id = u.id
    JOIN cycles c ON cp.cycle_id = c.id
    WHERE c.pairing_done = 1
    AND c.status = 'active'
    AND cp.zine_sent = 0
    AND c.start_date <= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    AND u.email_confirmed = 1
");
$stmt->execute();
$users = $stmt->fetchAll();

foreach ($users as $user) {
    // Check if we already sent a reminder this week
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM email_logs 
        WHERE user_id = ? AND cycle_id = ? AND email_type = 'reminder_post'
        AND sent_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ");
    $stmt->execute([$user['user_id'], $user['cycle_id']]);
    $recentReminder = $stmt->fetchColumn();

    if (!$recentReminder) {
        $emailBody = getReminderEmail($user['name'], 'post_zine');
        sendEmail($user['email'], 'Reminder: Send your ' . CONTENT_TYPE . ' - ' . SITE_TITLE, $emailBody);
        logEmail($user['user_id'], $user['cycle_id'], 'reminder_post');
        echo "Reminder sent to: {$user['email']}\n";
    }
}

echo "Posting reminder script completed.\n";
