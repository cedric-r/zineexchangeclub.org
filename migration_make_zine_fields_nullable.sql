-- Migration: Make zine theme and format fields optional
-- Run: mysql -u zine_user -p zine_exchange_club < migration_make_zine_fields_nullable.sql

ALTER TABLE zines MODIFY theme TEXT DEFAULT NULL;
ALTER TABLE zines MODIFY format VARCHAR(50) DEFAULT NULL;
