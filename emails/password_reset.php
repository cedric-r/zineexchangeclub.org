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
        <p>Hello <?= $name ?>!</p>

        <p>We received a request to reset your password for the <?= SITE_TITLE ?>. If you didn't make this request, you can safely ignore this email.</p>

        <p>To reset your password, click the button below. This link will expire in 1 hour:</p>

        <a href='<?= $resetUrl ?>' class='button'>Reset Password</a>

        <p>If the button doesn't work, copy and paste this link into your browser:</p>
        <p><?= $resetUrl ?></p>

        <p>For security reasons, please don't share this link with anyone.</p>

        <p>The <?= SITE_TITLE ?> Team</p>
    </div>
</body>
</html>
