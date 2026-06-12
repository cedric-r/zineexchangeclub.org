<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .password-box { background: #f5f5f5; border: 1px solid #ddd; padding: 15px; font-family: monospace; font-size: 18px; text-align: center; margin: 20px 0; border-radius: 4px; }
        .button { display: inline-block; padding: 12px 24px; background: #4a90e2; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        .button:hover { background: #357abd; }
    </style>
</head>
<body>
    <div class='container'>
        <h2>Your <?= htmlspecialchars($site_title) ?> account has been migrated</h2>
        <p>Hi <?= htmlspecialchars($name) ?>,</p>
        <p>As part of a system update, your <?= htmlspecialchars($site_title) ?> account has been migrated to a new platform. Your account details and address have been preserved.</p>

        <p><strong>You need to log in using the temporary password below:</strong></p>

        <div class='password-box'><?= htmlspecialchars($temp_password) ?></div>

        <p>Once logged in, please change your password from your profile page.</p>

        <p><a href='<?= htmlspecialchars($site_url) ?>/login.php' class='button'>Log In Now</a></p>

        <p>If the button doesn't work, copy and paste this link into your browser:</p>
        <p><?= htmlspecialchars($site_url) ?>/login.php</p>

        <p>After logging in, you can update your password by visiting your profile page.</p>

        <p>Sorry for the inconvenience, and thanks for being part of the community!</p>
        <p>The <?= htmlspecialchars($site_title) ?> Team</p>
    </div>
</body>
</html>
