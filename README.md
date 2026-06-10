# Zine Exchange Club

A zine exchange coordination system built in vanilla PHP. This platform allows zine creators to register, participate in exchange cycles, get paired with other participants, and track the exchange process.

## Features

- **User Registration**: Users can register with their personal information
- **Email Confirmation**: Email verification for new registrations
- **Cycle Management**: Administrators can create exchange cycles
- **Plugin-Based Pairing**: Multiple pairing algorithms including random, country-based, and zine-type matching
- **Process Tracking**: Participants can track their progress through each exchange cycle
- **Email Notifications**: Automated emails at each stage of the process
- **Gallery**: Photo gallery of received zines
- **Admin Dashboard**: Complete admin interface for managing users, cycles, and monitoring progress
- **Reminder System**: Crontab scripts to remind users about posting and receiving zines
- **Mobile Responsive**: Airy, modern design that works on all devices
- **Bot Protection**: Question-based captcha on registration, login, and password reset forms with configurable retry limit

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher / MariaDB 10.2 or higher
- SMTP relay server (configured in config.php)
- Web server (Apache recommended)

## Installation

### 1. Database Setup

Create a MySQL database and import the schema:

```bash
mysql -u your_username -p zine_exchange_club < schema.sql
```

Or manually run the SQL commands in `schema.sql`.

### 2. Configuration

Edit `config.php` and update the following settings:

```php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'zine_exchange_club');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Email/SMTP configuration
define('SMTP_HOST', '192.168.233.9');
define('SMTP_PORT', 25);
define('SMTP_FROM', 'zine@zineexchangeclub.org');
define('SMTP_FROM_NAME', 'Zine Exchange Club');
define('SITE_URL', 'http://yourdomain.com');

// Site configuration
define('SITE_TITLE', 'Zine Exchange Club');
define('CONTENT_TYPE', 'zine'); // Type of content exchanged: 'zine' or 'postcard'

// Pairing algorithm configuration
define('PAIRING_ALGORITHM', 'random'); // Options: 'country_priority', 'random', 'sequential', 'zine_type', 'country_zine_type'

// Admin configuration
define('ADMIN_EMAIL', 'admin@zineexchangeclub.org');

// Captcha configuration
define('CAPTCHA_MAX_RETRIES', 3); // Max failed attempts before blocking form
```

### 3. File Permissions

Set appropriate permissions:

```bash
# Make scripts executable
chmod +x scripts/reminder-posting.php
chmod +x scripts/reminder-receiving.php

# Make uploads directory writable
mkdir uploads
chmod 755 uploads
```

### 4. Create Admin User

You'll need to manually create an admin user in the database. Run this SQL:

```sql
INSERT INTO users (name, email, password, postal_address, accepts_adult_zines, country, is_admin, email_confirmed)
VALUES (
    'Admin Name',
    'admin@zineexchangeclub.org',
    '$2y$10$your_hashed_password_here',
    'Admin Address',
    0,
    'Your Country',
    1,
    1
);
```

Generate a password hash using PHP:
```php
<?php
echo password_hash('your_password', PASSWORD_DEFAULT);
```

### 5. Configure Crontab

Set up the reminder scripts in your crontab:

```bash
crontab -e
```

Add these lines (adjust paths as needed):

```
0 9 * * * /usr/bin/php /path/to/zineexchangeclub.org/scripts/reminder-posting.php
0 10 * * * /usr/bin/php /path/to/zineexchangeclub.org/scripts/reminder-receiving.php
```

### 6. Web Server Configuration

Ensure your web server is configured to serve PHP files. For Apache, ensure mod_php is enabled.

The `.htaccess` file includes:
- Security headers
- Directory browsing disabled
- Protection of sensitive files
- Compression enabled
- Upload size limits

## Testing

The test suite uses plain PHP CLI scripts — no PHPUnit required. Each test script outputs `[PASS]` or `[FAIL]` lines; a run is green when every line is `[PASS]`.

### Requirements

- PHP 7.4+ (CLI)
- No database or SMTP server needed — tests use SQLite in-memory

### Running Tests

```bash
# Full suite
for f in tests/*.php; do php "$f"; done

# Individual test file
php tests/AuthTest.php
php tests/CaptchaTest.php
```

### Test Architecture

- `tests/bootstrap.php` creates a temporary SQLite-based `config.php` in the project root (config.php is in `.gitignore`, so this is safe) and provides assertion helpers (`assert_equal`, `assert_true`, `assert_throws`).
- Database-dependent tests use an in-memory SQLite database with the full schema created by `createTestSchema()`.
- Session-dependent tests manipulate `$_SESSION` directly.
- Pairing algorithm tests verify two-way symmetric pairings, edge cases (0/1 participants, unconfirmed users), and algorithm-specific behaviour (same-country pairing, sequential ordering, etc.).
- Email template tests assert HTML content, variable interpolation, and XSS escaping without sending emails.

## Directory Structure

```
zineexchangeclub.org/
├── admin/
│   └── index.php              # Admin dashboard
├── css/
│   └── style.css             # Main stylesheet
├── includes/
│   ├── auth.php              # Authentication functions
│   ├── captcha.php           # Captcha question selection and verification
│   └── email.php             # Email sending functions
├── js/
│   └── captcha.js            # Frontend captcha verification with AJAX
├── scripts/
│   ├── crontab-example.txt   # Example crontab configuration
│   ├── reminder-posting.php  # Reminder for posting zines
│   └── reminder-receiving.php # Reminder for receiving zines
├── uploads/                  # Gallery images (create this directory)
├── tests/                    # Test suite (plain PHP CLI scripts)
│   ├── bootstrap.php         # Shared test infrastructure + SQLite helper
│   ├── AuthTest.php          # Authentication function tests
│   ├── CaptchaTest.php       # Captcha verification tests
│   ├── EmailTemplatesTest.php # Email template rendering tests
│   ├── FunctionsTest.php     # Announcement/pairing function tests
│   └── PairingAlgorithmsTest.php # All 6 pairing algorithm tests
├── .htaccess                 # Apache configuration
├── captcha.json              # Captcha question/answer pairs
├── captcha.php               # Captcha JSON API endpoint
├── config.php                # Main configuration file
├── confirm-email.php         # Email confirmation page
├── confirm-participation.php # Participation confirmation page
├── confirm-pairing.php       # Pairing confirmation page
├── gallery.php               # Gallery page
├── index.php                 # Home page
├── login.php                 # Login page
├── logout.php                # Logout handler
├── process.php               # User process tracking page
├── register.php              # Registration page
├── schema.sql                # Database schema
└── README.md                 # This file
```

## How It Works

### The Exchange Process

1. **Registration**: Users sign up and describe their zine (theme, format, construction type)
2. **Cycle Creation**: Admin creates a new exchange cycle with a start date
3. **Invitation**: Existing users receive email invitations to participate
4. **Confirmation**: Users confirm their participation for the cycle
5. **Pairing**: Admin pairs confirmed participants using the selected algorithm
6. **Pairing Notification**: Users receive emails with their partner's address
7. **Sending**: Users send their zine and report it on the site
8. **Notification**: Recipients are notified when a zine is posted to them
9. **Receiving**: Users report when they receive their zine
10. **Gallery**: Users can upload photos of received zines to the gallery

### Pairing Algorithms

The system features a plugin-based pairing system with multiple algorithms:

#### Available Algorithms:

1. **Random** (Default)
   - Completely random pairing of all participants
   - Maximum variety in exchanges

2. **Country Priority**
   - Prioritizes pairing participants within the same country
   - Falls back to cross-country pairing for remaining participants

3. **Sequential**
   - Pairs participants in order of their registration date
   - Predictable pairing order

4. **Zine Type**
   - Groups participants by zine format (folded, stapled, bound, other)
   - Prioritizes pairing similar zine types
   - Falls back to random pairing for remaining participants

5. **Country + Zine Type**
   - Highest priority: Same country AND same zine format
   - Falls back through: Same country → Same format → Random
   - Most specific matching algorithm

6. **Geographic Proximity**
   - Prioritizes pairing within the same country
   - Falls back to same-region pairing (continent-level proximity)
   - Runs up to 50 randomized iterations to find the best configuration
   - Falls back to random when no geographic pairings are possible

#### Configuration:
Set the desired algorithm in `config.php`:
```php
define('PAIRING_ALGORITHM', 'random'); // Options: 'country_priority', 'random', 'sequential', 'zine_type', 'country_zine_type', 'geographic_proximity'
```

#### Adding New Algorithms:
New pairing algorithms can be added by implementing the `PairingAlgorithm` interface in `includes/pairing_algorithms.php` and updating the factory.

### Email Notifications

The system sends emails at these stages:
- Registration confirmation
- Cycle invitation
- Pairing notification
- Zine posted notification
- Reminders for posting (2 weeks after pairing, then weekly)
- Reminders for receiving (2 weeks after posting, then weekly)

## Security Considerations

- Passwords are hashed using PHP's `password_hash()`
- Email confirmation required for registration
- Session-based rate limiting on login (5 attempts per 15 min) and password reset (3 per 15 min)
- Question-based captcha on registration, login, and forgot-password forms prevents automated submissions
- Captcha verification is server-side authoritative (session flag, not hidden fields)
- Session security configured
- Sensitive files protected via .htaccess
- SQL injection prevention using prepared statements
- XSS prevention via output escaping

## Customization

### Content Type

The site uses `CONTENT_TYPE` to determine the terminology throughout all pages and emails. Set it in `config.php`:
```php
define('CONTENT_TYPE', 'zine'); // Options: 'zine' or 'postcard'
```
This changes all user-facing references from "zine" to "postcard" (e.g., "zine gallery" → "postcard gallery", "send your zine" → "send your postcard").

### Site Title

Change the site title by updating the `SITE_TITLE` constant in `config.php`:
```php
define('SITE_TITLE', 'Your Zine Exchange Name');
define('CONTENT_TYPE', 'zine');
```
This will update the site name throughout all pages and emails.

### Announcement Management

Administrators can send announcements to all registered members with the following options:

- **Automatic Sending**: When creating or editing an announcement, check "Send to all registered users" to immediately email all confirmed users
- **Manual Sending**: For announcements that weren't sent initially, administrators can click "Send to All Users" button next to each announcement
- **Email Tracking**: The system tracks which announcements have been sent to prevent duplicate emails
- **Email Content**: Uses the site title from `SITE_TITLE` configuration for consistent branding

### SMTP Configuration

Update SMTP settings in `config.php` to match your mail server.

### Styling

Edit `css/style.css` to customize appearance. The design uses:
- Modern, airy layout
- Mobile-first responsive design
- Clean typography
- Subtle animations

### Email Templates

Email templates are in `includes/email.php`. Customize the HTML to match your branding.

### Announcement Notifications

Administrators can send announcements to all registered members with the following options:

- **Automatic Sending**: When creating or editing an announcement, check "Send to all registered users" to immediately email all confirmed users
- **Manual Sending**: For announcements that weren't sent initially, administrators can click "Send to All Users" button next to each announcement
- **Email Tracking**: The system tracks which announcements have been sent to prevent duplicate emails
- **Email Content**: Uses the site title from `SITE_TITLE` configuration for consistent branding

### SMTP Configuration

Update SMTP settings in `config.php` to match your mail server.

## Troubleshooting

### Emails Not Sending

- Verify SMTP server is accessible
- Check PHP error logs
- Ensure SMTP_HOST and SMTP_PORT are correct
- Test SMTP connection manually

### File Uploads Failing

- Ensure `uploads/` directory exists and is writable
- Check PHP upload_max_filesize and post_max_size settings
- Verify file permissions

### Session Issues

- Check session save path permissions
- Ensure session cookie settings are correct
- Verify PHP session configuration

## License

This project is provided as-is for the Zine Exchange Club.

## Support

For issues or questions, contact the administrator at the email configured in `config.php`.
