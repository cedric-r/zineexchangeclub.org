-- Migration script to update database schema
-- Run this script to update from the original schema to the new schema

-- Add password reset fields to users table
ALTER TABLE users 
ADD COLUMN password_reset_token VARCHAR(64) DEFAULT NULL AFTER email_confirmation_token,
ADD COLUMN password_reset_expires DATETIME DEFAULT NULL AFTER password_reset_token;

-- Update zines table: remove name column and change construction_type to format
ALTER TABLE zines 
DROP COLUMN name,
CHANGE COLUMN construction_type format VARCHAR(50) NOT NULL;

-- Add status column to cycles table
ALTER TABLE cycles 
ADD COLUMN status ENUM('active', 'closed') DEFAULT 'active' AFTER pairing_done;
