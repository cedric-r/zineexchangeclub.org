-- Add token expiry tracking columns
-- Run: mysql -u zine_user -p zine_exchange_club < migration_add_token_expiry.sql

ALTER TABLE users ADD COLUMN email_token_expires DATETIME DEFAULT NULL AFTER email_confirmation_token;
ALTER TABLE cycle_participations ADD COLUMN confirmation_token_expires DATETIME DEFAULT NULL AFTER confirmation_token;

-- Set existing tokens to expire in 7 days (grace period for existing users)
UPDATE users SET email_token_expires = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE email_confirmation_token IS NOT NULL;
UPDATE cycle_participations SET confirmation_token_expires = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE confirmation_token IS NOT NULL;
