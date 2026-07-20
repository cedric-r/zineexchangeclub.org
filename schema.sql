-- Database schema for Zine Exchange Club

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    postal_address TEXT NOT NULL,
    accepts_adult_zines TINYINT(1) DEFAULT 0,
    country VARCHAR(100) NOT NULL,
    is_admin TINYINT(1) DEFAULT 0,
    email_confirmed TINYINT(1) DEFAULT 0,
    email_confirmation_token VARCHAR(64) DEFAULT NULL,
    email_token_expires DATETIME DEFAULT NULL,
    password_reset_token VARCHAR(64) DEFAULT NULL,
    password_reset_expires DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS zines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    theme TEXT DEFAULT NULL,
    format VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cycles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    start_date DATE NOT NULL,
    registration_open TINYINT(1) DEFAULT 1,
    pairing_done TINYINT(1) DEFAULT 0,
    status ENUM('active', 'closed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cycle_participations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT NOT NULL,
    user_id INT NOT NULL,
    wants_to_participate TINYINT(1) DEFAULT 1,
    participation_confirmed TINYINT(1) DEFAULT 0,
    pairing_confirmed TINYINT(1) DEFAULT 0,
    paired_with_id INT DEFAULT NULL,
    zine_sent TINYINT(1) DEFAULT 0,
    zine_sent_date DATE DEFAULT NULL,
    zine_received TINYINT(1) DEFAULT 0,
    zine_received_date DATE DEFAULT NULL,
    confirmation_token VARCHAR(64) DEFAULT NULL,
    confirmation_token_expires DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (cycle_id) REFERENCES cycles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (paired_with_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_cycle_user (cycle_id, user_id)
);

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
    UNIQUE KEY unique_pairing (cycle_id, user_id, partner_id)
);

CREATE TABLE IF NOT EXISTS gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT NOT NULL,
    user_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    caption TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cycle_id) REFERENCES cycles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    cycle_id INT,
    email_type VARCHAR(50) NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (cycle_id) REFERENCES cycles(id) ON DELETE SET NULL
);

CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_cycle_participations_cycle ON cycle_participations(cycle_id);
CREATE INDEX idx_cycle_participations_user ON cycle_participations(user_id);

-- Announcements table
CREATE TABLE announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NOT NULL,
    email_sent TINYINT(1) DEFAULT 0,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_announcements_created ON announcements(created_at DESC);
CREATE INDEX idx_announcements_email_sent ON announcements(email_sent);

-- Announcement views table to track which users have seen which announcements
CREATE TABLE announcement_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT NOT NULL,
    user_id INT NOT NULL,
    viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_announcement_user (announcement_id, user_id),
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_announcement_views_user ON announcement_views(user_id);
CREATE INDEX idx_announcement_views_announcement ON announcement_views(announcement_id);
