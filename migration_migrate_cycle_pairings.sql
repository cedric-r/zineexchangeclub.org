-- Migration: Migrate existing pairing data from cycle_participations to cycle_pairings
-- Run this AFTER migration_add_cycle_pairings.sql (the table already exists).
-- This is idempotent — safe to re-run.

-- Forward direction: A → B, copies A's status fields
INSERT INTO cycle_pairings (cycle_id, user_id, partner_id, pairing_confirmed, confirmation_token, confirmation_token_expires, zine_sent, zine_sent_date, zine_received, zine_received_date)
SELECT cp1.cycle_id, cp1.user_id, cp1.paired_with_id,
       cp1.pairing_confirmed,
       cp1.confirmation_token,
       cp1.confirmation_token_expires,
       cp1.zine_sent,
       cp1.zine_sent_date,
       cp1.zine_received,
       cp1.zine_received_date
FROM cycle_participations cp1
WHERE cp1.paired_with_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM cycle_pairings cp2
    WHERE cp2.cycle_id = cp1.cycle_id
      AND cp2.user_id = cp1.user_id
      AND cp2.partner_id = cp1.paired_with_id
  );

-- Reverse direction: B → A, copies B's status fields
INSERT INTO cycle_pairings (cycle_id, user_id, partner_id, pairing_confirmed, confirmation_token, confirmation_token_expires, zine_sent, zine_sent_date, zine_received, zine_received_date)
SELECT cp1.cycle_id, cp1.paired_with_id, cp1.user_id,
       cp2.pairing_confirmed,
       cp2.confirmation_token,
       cp2.confirmation_token_expires,
       cp2.zine_sent,
       cp2.zine_sent_date,
       cp2.zine_received,
       cp2.zine_received_date
FROM cycle_participations cp1
JOIN cycle_participations cp2
  ON cp2.cycle_id = cp1.cycle_id
 AND cp2.user_id = cp1.paired_with_id
 AND cp2.paired_with_id = cp1.user_id
WHERE cp1.paired_with_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM cycle_pairings cp3
    WHERE cp3.cycle_id = cp1.cycle_id
      AND cp3.user_id = cp1.paired_with_id
      AND cp3.partner_id = cp1.user_id
  );
