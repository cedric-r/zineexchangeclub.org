#!/usr/bin/php
<?php
// Crontab script to remind users who haven't reported receiving their zine
// Run this script 2 weeks after zine was posted and then every week after that

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/email.php';

$db = getDB();

// Find users whose partner sent a zine more than 14 days ago but they haven't reported receiving
$stmt = $db->prepare("
    SELECT cp_recipient.user_id, cp_recipient.cycle_id, u.name, u.email, c.name as cycle_name, cp_sender.zine_sent_date
    FROM cycle_pairings cp_sender
    JOIN cycle_pairings cp_recipient ON cp_sender.user_id = cp_recipient.partner_id AND cp_sender.partner_id = cp_recipient.user_id
    JOIN users u ON cp_recipient.user_id = u.id
    JOIN cycles c ON cp_sender.cycle_id = c.id
    WHERE cp_sender.zine_sent = 1
    AND cp_sender.zine_sent_date IS NOT NULL
    AND cp_recipient.zine_received = 0
    AND cp_sender.zine_sent_date <= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    AND u.email_confirmed = 1
    AND cp_sender.cycle_id = cp_recipient.cycle_id
");
$stmt->execute();
$users = $stmt->fetchAll();

foreach ($users as $user) {
    // Check if we already sent a reminder this week
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM email_logs 
        WHERE user_id = ? AND cycle_id = ? AND email_type = 'reminder_receive'
        AND sent_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ");
    $stmt->execute([$user['user_id'], $user['cycle_id']]);
    $recentReminder = $stmt->fetchColumn();

    if (!$recentReminder) {
        $emailBody = getReminderEmail($user['name'], 'receive_zine');
        sendEmail($user['email'], 'Reminder: Report received ' . CONTENT_TYPE . ' - ' . SITE_TITLE, $emailBody);
        logEmail($user['user_id'], $user['cycle_id'], 'reminder_receive');
        echo "Reminder sent to: {$user['email']}\n";
    }
}

echo "Receiving reminder script completed.\n";
