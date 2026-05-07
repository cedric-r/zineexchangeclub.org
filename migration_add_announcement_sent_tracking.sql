-- Migration script to add announcement sent tracking
-- Run this script to add email sent tracking functionality to existing announcements

-- Add email_sent column to announcements table
ALTER TABLE announcements 
ADD COLUMN email_sent TINYINT(1) DEFAULT 0 AFTER content;

-- Add index for faster queries
CREATE INDEX idx_announcements_email_sent ON announcements(email_sent);

-- Update existing announcements to have email_sent = 0 (not sent yet)
UPDATE announcements SET email_sent = 0 WHERE email_sent IS NULL;

-- Table updated successfully
-- The system can now track which announcements have been sent to all users
-- This prevents duplicate email sending and allows for manual resend functionality
