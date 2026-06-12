<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
    </style>
</head>
<body>
    <div class='container'>
        <h2>A <?= ucfirst(CONTENT_TYPE) ?> is on its Way to You!</h2>
        <p>Hello <?= $name ?>!</p>

        <p>Great news! A <?= CONTENT_TYPE ?> has been posted to you and should arrive soon.</p>

        <p><strong>What to do:</strong></p>
        <ol>
            <li>Keep an eye on your mailbox</li>
            <li>When you receive the <?= CONTENT_TYPE ?>, log in to the site</li>
            <li>Report that you've received your <?= CONTENT_TYPE ?></li>
        </ol>

        <p>Enjoy your new <?= CONTENT_TYPE ?>!</p>
        <p>The <?= SITE_TITLE ?> Team</p>
    </div>
</body>
</html>
