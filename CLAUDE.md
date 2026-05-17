# Zine Exchange Club

Vanilla PHP 8.3+ app (no framework) for managing zine exchange cycles among a community.

## Stack
- PHP 8.3+, MySQL, raw SMTP via `fsockopen` (no auth, relay at 192.168.233.9:25)
- Flat file structure — no router, no ORM, no Composer

## Key files
- `config.php` — DB creds, SMTP config, `PAIRING_ALGORITHM` constant, `getDB()`
- `admin/index.php` — Admin dashboard: create cycles, pair users, manage users, resend emails
- `process.php` — User-facing exchange progress (confirm, report sent/received, upload)
- `includes/pairing_algorithms.php` — Strategy pattern: 5 algorithms (random, country_priority, sequential, zine_type, country_zine_type), factory + `pairParticipants()` entry point
- `includes/email.php` — `sendEmail()`, `logEmail()`, `renderEmailTemplate()`, plus 8 `get*Email()` functions that render templates from `emails/`
- `emails/*.php` — Standalone HTML email templates (customisable). Each receives variables via `renderEmailTemplate()` extraction
- `schema.sql` — Full DB schema (users, cycles, cycle_participations, zines, gallery, email_logs, announcements)

## Key patterns
- `cycle_participations` is the central junction: tracks wants_to_participate, participation_confirmed, pairing_confirmed, paired_with_id, zine_sent, zine_received, confirmation_token
- Token-based confirmation flows: email confirmation and password reset validate tokens against DB; participation and pairing confirmations also store/validate tokens in `cycle_participations.confirmation_token`
- Pairing sets `paired_with_id` reciprocally on both users' participation records, then marks `cycles.pairing_done = 1`
- Admin actions are POST-based with named buttons (`pair_users`, `close_registration`, `send_reminder`, `resend_pairing_emails`, `close_cycle`, `reset_cycle`, etc.)
