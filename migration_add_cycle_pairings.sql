-- Migration: Add cycle_pairings table for multi-partner support
-- Run this after the previous migration(s).
-- After verifying, existing paired_with_id data can be migrated with:
--   INSERT INTO cycle_pairings (cycle_id, user_id, partner_id, pairing_confirmed, confirmation_token, confirmation_token_expires, zine_sent, zine_sent_date, zine_received, zine_received_date)
--   SELECT cycle_id, user_id, paired_with_id, pairing_confirmed, confirmation_token, confirmation_token_expires, zine_sent, zine_sent_date, zine_received, zine_received_date
--   FROM cycle_participations WHERE paired_with_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS cycle_pairings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT NOT NULL,
    user_id INT NOT NULL,
    partner_id INT NOT NULL,
    pairing_confirmed TINYINT(1) DEFAULT 0,
    confirmation_token VARCHAR(64) DEFAULT NULL,
    confirmation_token_expires DATETIME DEFAULT NULL,
    zine_sent TINYINT(1) DEFAULT 0,
    zine_sent_date DATE DEFAULT NULL,
    zine_received TINYINT(1) DEFAULT 0,
    zine_received_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cycle_id) REFERENCES cycles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (partner_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_cycle_user (cycle_id, user_id),
    INDEX idx_cycle_partner (cycle_id, partner_id)
);
