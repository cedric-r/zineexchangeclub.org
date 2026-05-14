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
        <p>Hello <?= $name ?>!</p>

        <p>Great news! You've been paired with another participant for this exchange cycle. Here are the details:</p>

        <div class='address-box'>
            <h3>Your Exchange Partner: <?= $partnerName ?></h3>
            <p><strong>Country:</strong> <?= htmlspecialchars($partnerCountry) ?></p>
            <p>Send your zine to this address:</p>
            <p><?= nl2br(htmlspecialchars($partnerAddress)) ?></p>
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

        <a href='<?= $confirmUrl ?>' class='button'>Confirm Pairing Received</a>

        <p>If the button doesn't work, copy and paste this link into your browser:</p>
        <p><?= $confirmUrl ?></p>

        <p>Happy zine exchanging!</p>
        <p>The <?= SITE_TITLE ?> Team</p>
    </div>
</body>
</html>
