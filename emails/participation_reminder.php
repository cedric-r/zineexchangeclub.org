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
            <h2>Hello <?= $name ?>!</h2>

            <p>This is a friendly reminder that you haven't yet confirmed your participation in the <strong><?= $cycleName ?></strong> exchange cycle.</p>

            <p>If you'd like to participate in this cycle and exchange zines with fellow creators, Please confirm Your participation by clicking the button below:</p>

            <a href='<?= $confirmUrl ?>' class='button'>Confirm My Participation</a>

            <p>If you don't wish to participate in this cycle, No action is needed. You simply won't be included in the pairing.</p>

            <p><strong>Important:</strong> Participation confirmation is required to be paired with another participant for the exchange.</p>

            <p>If you have any questions, feel free to contact us.</p>

            <p>Happy zine making!</p>

            <p>The <?= SITE_TITLE ?> Team</p>
        </div>
        <div class='footer'>
            <p>This email was sent to you because you're registered for the <?= SITE_TITLE ?>.</p>
        </div>
    </div>
</body>
</html>
