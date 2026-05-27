<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'zine_exchange_club');
define('DB_USER', 'zine_user');
define('DB_PASS', 'change_this_password');

// Email/SMTP configuration
define('SMTP_HOST', '192.168.233.9');
define('SMTP_PORT', 25);
define('SMTP_FROM', 'zine@zineexchangeclub.org');
define('SMTP_FROM_NAME', 'Zine Exchange Club');
define('SITE_URL', 'http://localhost'); // Change to actual domain

// Site configuration
define('SITE_TITLE', 'Zine Exchange Club');

// Admin configuration
define('ADMIN_EMAIL', 'admin@zineexchangeclub.org');

// Pairing algorithm configuration
define('PAIRING_ALGORITHM', 'random'); // Options: 'country_priority', 'random', 'sequential', 'zine_type', 'country_zine_type'

// Captcha configuration
define('CAPTCHA_MAX_RETRIES', 3);

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// Database connection
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }
    return $pdo;
}
