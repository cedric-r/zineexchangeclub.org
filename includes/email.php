<?php
// Email sending utility using SMTP without authentication

require_once __DIR__ . '/../config.php';

function sendEmail($to, $subject, $body, $html = true) {
    $boundary = md5(time());
    
    // Build headers as a single string
    $headers = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\n";
    $headers .= "To: " . $to . "\r\n";
    $headers .= "Subject: " . $subject . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "Message-ID: <" . md5(uniqid()) . "@" . parse_url(SITE_URL, PHP_URL_HOST) . ">\r\n";
    
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
    // Read EHLO response (may be multiple lines)
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

// Email templates
function getRegistrationEmail($name, $confirmationToken) {
    $confirmUrl = SITE_URL . '/confirm-email.php?token=' . $confirmationToken;
    
    return "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .button { display: inline-block; padding: 12px 24px; background: #4a90e2; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
            .button:hover { background: #357abd; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>Welcome to Zine Exchange Club, $name!</h2>
            <p>Thank you for registering with the Zine Exchange Club. We're excited to have you join our community of zine creators and enthusiasts.</p>
            
            <h3>How it works:</h3>
            <ol>
                <li><strong>Register:</strong> Tell us about yourself and your zine</li>
                <li><strong>Join a cycle:</strong> When a new cycle starts, confirm your participation</li>
                <li><strong>Get paired:</strong> We'll match you with another participant</li>
                <li><strong>Exchange:</strong> Send your zine to your partner and receive one from someone else</li>
            </ol>
            
            <p><strong>Important:</strong> Please confirm your email address by clicking the button below:</p>
            
            <a href='$confirmUrl' class='button'>Confirm Email Address</a>
            
            <p>If the button doesn't work, copy and paste this link into your browser:</p>
            <p>$confirmUrl</p>
            
            <p>Once you've confirmed your email, you'll be able to participate in upcoming exchange cycles.</p>
            
            <p>Happy zine making!</p>
            <p>The Zine Exchange Club Team</p>
        </div>
    </body>
    </html>
    ";
}

function getCycleInvitationEmail($name, $cycleName, $confirmationToken) {
    $confirmUrl = SITE_URL . '/confirm-participation.php?token=' . $confirmationToken;
    
    return "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .button { display: inline-block; padding: 12px 24px; background: #4a90e2; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
            .button:hover { background: #357abd; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>New Exchange Cycle: $cycleName</h2>
            <p>Hello $name!</p>
            
            <p>A new exchange cycle is starting! Would you like to participate in the <strong>$cycleName</strong> cycle?</p>
            
            <p>If you'd like to join this cycle, please confirm your participation by clicking the button below:</p>
            
            <a href='$confirmUrl' class='button'>Confirm Participation</a>
            
            <p>If the button doesn't work, copy and paste this link into your browser:</p>
            <p>$confirmUrl</p>
            
            <p>If you don't want to participate in this cycle, simply ignore this email. Your account will remain active for future cycles.</p>
            
            <p>Happy zine exchanging!</p>
            <p>The Zine Exchange Club Team</p>
        </div>
    </body>
    </html>
    ";
}

function getPairingEmail($name, $partnerName, $partnerAddress, $confirmationToken) {
    $confirmUrl = SITE_URL . '/confirm-pairing.php?token=' . $confirmationToken;
    
    return "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .button { display: inline-block; padding: 12px 24px; background: #4a90e2; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
            .button:hover { background: #357abd; }
            .address-box { background: #f5f5f5; padding: 15px; border-left: 4px solid #4a90e2; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>You've Been Paired!</h2>
            <p>Hello $name!</p>
            
            <p>Great news! You've been paired with another participant for this exchange cycle. Here are the details:</p>
            
            <div class='address-box'>
                <h3>Your Exchange Partner: $partnerName</h3>
                <p>Send your zine to this address:</p>
                <p>" . nl2br(htmlspecialchars($partnerAddress)) . "</p>
            </div>
            
            <p><strong>Next steps:</strong></p>
            <ol>
                <li>Prepare your zine for mailing</li>
                <li>Send it to the address above</li>
                <li>Log in to the site and report when you've sent your zine</li>
                <li>Wait to receive a zine from another participant</li>
                <li>Report when you've received your zine</li>
            </ol>
            
            <p>Please confirm that you've received this pairing information by clicking the button below:</p>
            
            <a href='$confirmUrl' class='button'>Confirm Pairing Received</a>
            
            <p>If the button doesn't work, copy and paste this link into your browser:</p>
            <p>$confirmUrl</p>
            
            <p>Happy zine exchanging!</p>
            <p>The Zine Exchange Club Team</p>
        </div>
    </body>
    </html>
    ";
}

function getZinePostedEmail($name) {
    return "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>A Zine is on its Way to You!</h2>
            <p>Hello $name!</p>
            
            <p>Great news! A zine has been posted to you and should arrive soon.</p>
            
            <p><strong>What to do:</strong></p>
            <ol>
                <li>Keep an eye on your mailbox</li>
                <li>When you receive the zine, log in to the site</li>
                <li>Report that you've received your zine</li>
            </ol>
            
            <p>Enjoy your new zine!</p>
            <p>The Zine Exchange Club Team</p>
        </div>
    </body>
    </html>
    ";
}

function getReminderEmail($name, $reminderType) {
    if ($reminderType === 'post_zine') {
        $message = "This is a reminder that you haven't yet reported sending your zine to your exchange partner. Please send your zine as soon as possible and log in to the site to report that you've sent it.";
    } elseif ($reminderType === 'receive_zine') {
        $message = "This is a reminder that you haven't yet reported receiving your zine. If you've received it, please log in to the site and report it. If you haven't received it yet, please wait a bit longer - international mail can take time.";
    }
    
    return "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>Reminder: Zine Exchange Club</h2>
            <p>Hello $name!</p>
            
            <p>$message</p>
            
            <p>If you have any questions or issues, please don't hesitate to contact us.</p>
            
            <p>The Zine Exchange Club Team</p>
        </div>
    </body>
    </html>
    ";
}

function getPasswordResetEmail($name, $token) {
    $resetUrl = SITE_URL . '/reset-password.php?token=' . $token;
    
    return "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .button { display: inline-block; padding: 12px 24px; background: #4a90e2; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
            .button:hover { background: #357abd; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>Password Reset Request</h2>
            <p>Hello $name!</p>
            
            <p>We received a request to reset your password for the Zine Exchange Club. If you didn't make this request, you can safely ignore this email.</p>
            
            <p>To reset your password, click the button below. This link will expire in 1 hour:</p>
            
            <a href='$resetUrl' class='button'>Reset Password</a>
            
            <p>If the button doesn't work, copy and paste this link into your browser:</p>
            <p>$resetUrl</p>
            
            <p>For security reasons, please don't share this link with anyone.</p>
            
            <p>The Zine Exchange Club Team</p>
        </div>
    </body>
    </html>
    ";
}

function getParticipationReminderEmail($name, $cycleName) {
    $confirmUrl = SITE_URL . '/confirm-participation.php';
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Participation Reminder</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #2c3e50; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f8f9fa; }
            .button { display: inline-block; padding: 12px 30px; background: #3498db; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
            .button:hover { background: #2980b9; }
            .footer { text-align: center; padding: 20px; color: #7f8c8d; font-size: 0.9em; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Participation Reminder</h1>
            </div>
            <div class='content'>
                <h2>Hello $name!</h2>
                
                <p>This is a friendly reminder that you haven't yet confirmed your participation in the <strong>$cycleName</strong> exchange cycle.</p>
                
                <p>If you'd like to participate in this cycle and exchange zines with fellow creators, please confirm your participation by clicking the button below:</p>
                
                <a href='$confirmUrl' class='button'>Confirm My Participation</a>
                
                <p>If you don't wish to participate in this cycle, no action is needed. You simply won't be included in the pairing.</p>
                
                <p><strong>Important:</strong> Participation confirmation is required to be paired with another participant for the exchange.</p>
                
                <p>If you have any questions, feel free to contact us.</p>
                
                <p>Happy zine making!</p>
                
                <p>The Zine Exchange Club Team</p>
            </div>
            <div class='footer'>
                <p>This email was sent to you because you're registered for the Zine Exchange Club.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}
