<?php
/**
 * Tests for includes/email.php — email template rendering.
 *
 * Covers: renderEmailTemplate, getRegistrationEmail, getCycleInvitationEmail,
 *         getPairingEmail, getZinePostedEmail, getReminderEmail,
 *         getPasswordResetEmail, getParticipationReminderEmail,
 *         getAnnouncementEmail.
 *
 * sendEmail() requires an SMTP server and is excluded from unit tests.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/email.php';

// ── Helper: check an HTML email contains expected strings ───────────

function assertEmailContains(string $label, string $html, string $expected): void {
    assert_true("{$label}: contains \"{$expected}\"", str_contains($html, $expected));
}

function assertEmailNotContains(string $label, string $html, string $unexpected): void {
    assert_true("{$label}: does not contain \"{$unexpected}\"", !str_contains($html, $unexpected));
}

// ════════════════════════════════════════════════════════════════════
//  renderEmailTemplate
// ════════════════════════════════════════════════════════════════════

$html = renderEmailTemplate('registration', ['name' => 'Alice', 'confirmUrl' => 'http://test.local/confirm?token=abc']);
assert_true('renderEmailTemplate returns a non-empty string', is_string($html) && strlen($html) > 0);
assertEmailContains('renderEmailTemplate interpolates name', $html, 'Alice');
assertEmailContains('renderEmailTemplate inserts confirmUrl', $html, 'http://test.local/confirm?token=abc');
assertEmailContains('renderEmailTemplate resolves CONTENT_TYPE constant', $html, 'zine');
assertEmailContains('renderEmailTemplate resolves SITE_TITLE constant', $html, 'Test Zine Exchange Club');

// Render with empty vars
$htmlMinimal = renderEmailTemplate('registration', []);
assert_true('renderEmailTemplate works with empty vars array', is_string($htmlMinimal));

// ── Non-existent template ──────────────────────────────────────────
$missing = @renderEmailTemplate('nonexistent_template', ['name' => 'Test']);
// Should return a string (empty or error), not crash
assert_true('renderEmailTemplate handles missing template gracefully', is_string($missing));

// ════════════════════════════════════════════════════════════════════
//  getRegistrationEmail
// ════════════════════════════════════════════════════════════════════

$html = getRegistrationEmail('Alice', 'confirm-token-123');
assert_true('getRegistrationEmail returns non-empty HTML', strlen($html) > 0);
assertEmailContains('getRegistrationEmail has name', $html, 'Alice');
assertEmailContains('getRegistrationEmail has confirmation URL', $html, 'confirm-token-123');
assertEmailContains('getRegistrationEmail is HTML', $html, '<html');
assertEmailContains('getRegistrationEmail has button', $html, 'Confirm Email Address');

// ════════════════════════════════════════════════════════════════════
//  getCycleInvitationEmail
// ════════════════════════════════════════════════════════════════════

$html = getCycleInvitationEmail('Bob', 'Summer Swap 2024', 'invite-token-456');
assert_true('getCycleInvitationEmail returns non-empty HTML', strlen($html) > 0);
assertEmailContains('getCycleInvitationEmail has name', $html, 'Bob');
assertEmailContains('getCycleInvitationEmail has cycle name', $html, 'Summer Swap 2024');
assertEmailContains('getCycleInvitationEmail has confirm URL', $html, 'invite-token-456');
assertEmailContains('getCycleInvitationEmail has participation button', $html, 'Confirm Participation');

// ════════════════════════════════════════════════════════════════════
//  getPairingEmail
// ════════════════════════════════════════════════════════════════════

$partnerInfo = "Email: partner@test.com\n123 Partner St\nParis, France";
$html = getPairingEmail('Charlie', 'Diana', $partnerInfo, 'France', 'pair-token-789');
assert_true('getPairingEmail returns non-empty HTML', strlen($html) > 0);
assertEmailContains('getPairingEmail has recipient name', $html, 'Charlie');
assertEmailContains('getPairingEmail has partner name', $html, 'Diana');
assertEmailContains('getPairingEmail has partner country', $html, 'France');
assertEmailContains('getPairingEmail has partner email', $html, 'partner@test.com');
assertEmailContains('getPairingEmail has partner address', $html, '123 Partner St');
assertEmailContains('getPairingEmail includes confirm URL', $html, 'pair-token-789');
assertEmailContains('getPairingEmail has confirm button', $html, 'Confirm Pairing Received');

// XSS attempt in partner name
$htmlXss = getPairingEmail('Eve', '<script>alert("xss")</script>', 'Some address', 'US', 'token');
assertEmailNotContains('getPairingEmail escapes HTML in partner name', $htmlXss, '<script>');
assertEmailContains('getPairingEmail escapes partner name safely', $htmlXss, '&lt;script&gt;');

// ════════════════════════════════════════════════════════════════════
//  getZinePostedEmail
// ════════════════════════════════════════════════════════════════════

$html = getZinePostedEmail('Frank');
assert_true('getZinePostedEmail returns non-empty HTML', strlen($html) > 0);
assertEmailContains('getZinePostedEmail has name', $html, 'Frank');
assertEmailContains('getZinePostedEmail mentions zine is on its way', $html, 'on its Way');

// ════════════════════════════════════════════════════════════════════
//  getReminderEmail — both types
// ════════════════════════════════════════════════════════════════════

$htmlPost = getReminderEmail('Grace', 'post_zine');
assert_true('getReminderEmail returns non-empty HTML for post_zine', strlen($htmlPost) > 0);
assertEmailContains('getReminderEmail post_zine has name', $htmlPost, 'Grace');
assertEmailContains('getReminderEmail post_zine mentions reporting sending', $htmlPost, 'reported sending your');

$htmlReceive = getReminderEmail('Heidi', 'receive_zine');
assert_true('getReminderEmail returns non-empty HTML for receive_zine', strlen($htmlReceive) > 0);
assertEmailContains('getReminderEmail receive_zine has name', $htmlReceive, 'Heidi');
assertEmailContains('getReminderEmail receive_zine mentions receiving', $htmlReceive, 'received it');

// Each type has different content
assert_true('getReminderEmail post_zine and receive_zine differ', $htmlPost !== $htmlReceive);

// Invalid reminder type
$htmlInvalid = getReminderEmail('Ivan', 'unknown_type');
assert_true('getReminderEmail handles unknown type gracefully', is_string($htmlInvalid));
// When reminderType is neither, $message is undefined (notice suppressed)
// The template uses $message which would be null/undefined
assertEmailContains('getReminderEmail unknown type produces HTML', $htmlInvalid, 'Ivan');

// ════════════════════════════════════════════════════════════════════
//  getPasswordResetEmail
// ════════════════════════════════════════════════════════════════════

$html = getPasswordResetEmail('Judy', 'reset-token-abc123');
assert_true('getPasswordResetEmail returns non-empty HTML', strlen($html) > 0);
assertEmailContains('getPasswordResetEmail has name', $html, 'Judy');
assertEmailContains('getPasswordResetEmail has reset URL', $html, 'reset-token-abc123');
assertEmailContains('getPasswordResetEmail has reset button', $html, 'Reset Password');
assertEmailContains('getPasswordResetEmail mentions request', $html, 'request to reset');

// ════════════════════════════════════════════════════════════════════
//  getParticipationReminderEmail
// ════════════════════════════════════════════════════════════════════

$html = getParticipationReminderEmail('Karl', 'Autumn Exchange');
assert_true('getParticipationReminderEmail returns non-empty HTML', strlen($html) > 0);
assertEmailContains('getParticipationReminderEmail has name', $html, 'Karl');
assertEmailContains('getParticipationReminderEmail has cycle name', $html, 'Autumn Exchange');
assertEmailContains('getParticipationReminderEmail mentions confirm', $html, 'confirm');

// ════════════════════════════════════════════════════════════════════
//  getAnnouncementEmail
// ════════════════════════════════════════════════════════════════════

$html = getAnnouncementEmail('Laura', 'Important Update', 'The site will be down for maintenance on Saturday.');
assert_true('getAnnouncementEmail returns non-empty HTML', strlen($html) > 0);
assertEmailContains('getAnnouncementEmail has name', $html, 'Laura');
assertEmailContains('getAnnouncementEmail has title', $html, 'Important Update');
assertEmailContains('getAnnouncementEmail has content', $html, 'maintenance on Saturday');
assertEmailContains('getAnnouncementEmail has announcement URL', $html, 'announcements.php');
assertEmailContains('getAnnouncementEmail has view button', $html, 'View All Announcements');

// XSS attempt in announcement content
$htmlXss = getAnnouncementEmail('Mallory', 'Alert', '<img src=x onerror=alert(1)>');
assertEmailNotContains('getAnnouncementEmail escapes HTML tags (no raw <img>)', $htmlXss, '<img');
assertEmailContains('getAnnouncementEmail escapes with HTML entities', $htmlXss, '&lt;img');
