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
        <h2>Welcome to <?= SITE_TITLE ?>, <?= $name ?>!</h2>
        <p>Thank you for registering with the <?= SITE_TITLE ?>. We're excited to have you join our community of <?= CONTENT_TYPE ?> creators and enthusiasts.</p>

        <h3>How it works:</h3>
        <ol>
            <li><strong>Register:</strong> Tell us about yourself and your <?= CONTENT_TYPE ?></li>
            <li><strong>Join a cycle:</strong> When a new cycle starts, confirm your participation</li>
            <li><strong>Get paired:</strong> We'll match you with another participant</li>
            <li><strong>Exchange:</strong> Send your <?= CONTENT_TYPE ?> to your partner and receive one from someone else</li>
        </ol>

        <p><strong>Important:</strong> Please confirm your email address by clicking the button below:</p>

        <a href='<?= $confirmUrl ?>' class='button'>Confirm Email Address</a>

        <p>If the button doesn't work, copy and paste this link into your browser:</p>
        <p><?= $confirmUrl ?></p>

        <p>Once you've confirmed your email, you'll be able to participate in upcoming exchange cycles.</p>

        <p>Happy <?= CONTENT_TYPE ?> making!</p>
        <p>The <?= SITE_TITLE ?> Team</p>
    </div>
</body>
</html>
