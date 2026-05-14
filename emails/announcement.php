<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>New Announcement</title>
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
            <h1>New Announcement</h1>
        </div>
        <div class='content'>
            <h2>Hello <?= $name ?>!</h2>

            <p>A new announcement has been posted to the <?= SITE_TITLE ?>:</p>

            <div style='background: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <h3 style='margin: 0 0 10px 0; color: #2c3e50;'><?= $announcementTitle ?></h3>
            </div>

            <div style='background: white; padding: 15px; border-radius: 5px; margin: 0 0 20px 0;'>
                <p style='margin: 0;'><?= $announcementContent ?></p>
            </div>

            <p>You can view this and all other announcements by clicking the button below:</p>

            <a href='<?= $announcementsUrl ?>' class='button'>View All Announcements</a>

            <p>This notification was sent to all registered members of the <?= SITE_TITLE ?>.</p>
        </div>
        <div class='footer'>
            <p>This email was sent to you because you're registered for the <?= SITE_TITLE ?>.</p>
        </div>
    </div>
</body>
</html>
