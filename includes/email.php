<?php
declare(strict_types=1);
// Email sending utility using SMTP without authentication

require_once __DIR__ . '/../config.php';

function sendEmail($to, $subject, $body, $html = true) {
    // Sanitize headers against CRLF injection
    $to = str_replace(["\r", "\n"], '', $to);
    $subject = str_replace(["\r", "\n"], '', $subject);

    $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\n";
    $headers .= "To: " . $to . "\r\n";
    $headers .= "Subject: " . $subject . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "Message-ID: <" . bin2hex(random_bytes(16)) . "@" . parse_url(SITE_URL, PHP_URL_HOST) . ">\r\n";

    if ($html) {
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    } else {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    }

    $smtp = fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 30);
    if (!$smtp) {
        error_log("SMTP connection failed: $errstr ($errno)");
        return false;
    }

    // Read greeting
    $response = fgets($smtp, 515);
    if (substr($response, 0, 3) != '220') {
        error_log("SMTP greeting failed: $response");
        fclose($smtp);
        return false;
    }

    // Send EHLO
    fwrite($smtp, "EHLO " . parse_url(SITE_URL, PHP_URL_HOST) . "\r\n");
    while ($line = fgets($smtp, 515)) {
        if (substr($line, 3, 1) == ' ') break;
    }

    // Send MAIL FROM
    fwrite($smtp, "MAIL FROM: <" . SMTP_FROM . ">\r\n");
    $response = fgets($smtp, 515);
    if (substr($response, 0, 3) != '250') {
        error_log("SMTP MAIL FROM failed: $response");
        fclose($smtp);
        return false;
    }

    // Send RCPT TO
    fwrite($smtp, "RCPT TO: <$to>\r\n");
    $response = fgets($smtp, 515);
    if (substr($response, 0, 3) != '250') {
        error_log("SMTP RCPT TO failed: $response");
        fclose($smtp);
        return false;
    }

    // Send DATA
    fwrite($smtp, "DATA\r\n");
    $response = fgets($smtp, 515);
    if (substr($response, 0, 3) != '354') {
        error_log("SMTP DATA failed: $response");
        fclose($smtp);
        return false;
    }

    // Dot-stuff the body to prevent SMTP injection
    $body = preg_replace('/^\./m', '..', $body);

    // Send headers and body
    fwrite($smtp, $headers . "\r\n");
    fwrite($smtp, $body . "\r\n.\r\n");
    $response = fgets($smtp, 515);
    if (substr($response, 0, 3) != '250') {
        error_log("SMTP message send failed: $response");
        fclose($smtp);
        return false;
    }

    // Quit
    fwrite($smtp, "QUIT\r\n");
    fclose($smtp);

    return true;
}

function logEmail($userId, $cycleId, $emailType) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO email_logs (user_id, cycle_id, email_type) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $cycleId, $emailType]);
}

function renderEmailTemplate($template, $vars = []) {
    foreach ($vars as $key => $value) {
        $$key = $value;
    }
    ob_start();
    include __DIR__ . '/../emails/' . $template . '.php';
    return ob_get_clean();
}

// Email templates

function getRegistrationEmail($name, $confirmationToken) {
    return renderEmailTemplate('registration', [
        'name' => $name,
        'confirmUrl' => SITE_URL . '/confirm-email.php?token=' . $confirmationToken,
    ]);
}

function getCycleInvitationEmail($name, $cycleName, $confirmationToken) {
    return renderEmailTemplate('cycle_invitation', [
        'name' => $name,
        'cycleName' => $cycleName,
        'confirmUrl' => SITE_URL . '/confirm-participation.php?token=' . $confirmationToken,
    ]);
}

function getPairingEmail($name, $partnerName, $partnerAddress, $partnerCountry, $confirmationToken) {
    return renderEmailTemplate('pairing', [
        'name' => $name,
        'partnerName' => $partnerName,
        'partnerAddress' => $partnerAddress,
        'partnerCountry' => $partnerCountry,
        'confirmUrl' => SITE_URL . '/confirm-pairing.php?token=' . $confirmationToken,
    ]);
}

function getZinePostedEmail($name) {
    return renderEmailTemplate('zine_posted', [
        'name' => $name,
    ]);
}

function getReminderEmail($name, $reminderType) {
    if ($reminderType === 'post_zine') {
        $message = "This is a reminder that you haven't yet reported sending your " . CONTENT_TYPE . " to your exchange partner. Please send your " . CONTENT_TYPE . " as soon as possible and log in to the site to report that you've sent it.";
    } elseif ($reminderType === 'receive_zine') {
        $message = "This is a reminder that you haven't yet reported receiving your " . CONTENT_TYPE . ". If you've received it, please log in to the site and report it. If you haven't received it yet, please wait a bit longer - international mail can take time.";
    }

    return renderEmailTemplate('reminder', [
        'name' => $name,
        'message' => $message,
    ]);
}

function getPasswordResetEmail($name, $token) {
    return renderEmailTemplate('password_reset', [
        'name' => $name,
        'resetUrl' => SITE_URL . '/reset-password.php?token=' . $token,
    ]);
}

function getParticipationReminderEmail($name, $cycleName) {
    return renderEmailTemplate('participation_reminder', [
        'name' => $name,
        'cycleName' => $cycleName,
        'confirmUrl' => SITE_URL . '/confirm-participation.php',
    ]);
}

function getAnnouncementEmail($name, $announcementTitle, $announcementContent) {
    return renderEmailTemplate('announcement', [
        'name' => $name,
        'announcementTitle' => $announcementTitle,
        'announcementContent' => nl2br(htmlspecialchars($announcementContent)),
        'announcementsUrl' => SITE_URL . '/announcements.php',
    ]);
}
