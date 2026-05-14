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
        <h2>New Exchange Cycle: <?= $cycleName ?></h2>
        <p>Hello <?= $name ?>!</p>

        <p>A new exchange cycle is starting! Would you like to participate in the <strong><?= $cycleName ?></strong> cycle?</p>

        <p>If you'd like to join this cycle, please confirm your participation by clicking the button below:</p>

        <a href='<?= $confirmUrl ?>' class='button'>Confirm Participation</a>

        <p>If the button doesn't work, copy and paste this link into your browser:</p>
        <p><?= $confirmUrl ?></p>

        <p>If you don't want to participate in this cycle, simply ignore this email. Your account will remain active for future cycles.</p>

        <p>Happy zine exchanging!</p>
        <p>The <?= SITE_TITLE ?> Team</p>
    </div>
</body>
</html>
