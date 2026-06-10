<?php
/**
 * Tests for includes/auth.php
 *
 * Covers: isLoggedIn, isAdmin, generateToken, generateCsrfToken,
 *         validateCsrfToken, hashPassword, login, getCurrentUser.
 *
 * Functions that call header()/exit() (requireLogin, requireAdmin, logout)
 * are not tested in CLI — they are structural entry-point guards.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();
createTestSchema($db);

// ── isLoggedIn ──────────────────────────────────────────────────────

resetSession();
assert_true('isLoggedIn returns false when no session set', isLoggedIn() === false);

$_SESSION['user_id'] = 7;
assert_true('isLoggedIn returns true when user_id is set', isLoggedIn() === true);

unset($_SESSION['user_id']);
assert_true('isLoggedIn returns false after unset', isLoggedIn() === false);

// ── isAdmin ─────────────────────────────────────────────────────────

resetSession();
assert_true('isAdmin returns false when no session', isAdmin() === false);

$_SESSION['is_admin'] = 1;
assert_true('isAdmin returns true when is_admin = 1', isAdmin() === true);

$_SESSION['is_admin'] = 0;
assert_true('isAdmin returns false when is_admin = 0', isAdmin() === false);

$_SESSION['is_admin'] = '1';
assert_true('isAdmin returns true with string "1"', isAdmin() === true);

// ── generateToken ───────────────────────────────────────────────────

$token1 = generateToken();
$token2 = generateToken();

assert_equal('generateToken returns 64-character hex string', 64, strlen($token1));
assert_true('generateToken output is valid hex', ctype_xdigit($token1));
assert_true('generateToken produces unique values on each call', $token1 !== $token2);

// ── generateCsrfToken / validateCsrfToken ───────────────────────────

resetSession();
$token = generateCsrfToken();
assert_true('generateCsrfToken returns a non-empty string', is_string($token) && strlen($token) > 0);

// Calling it again should return the SAME token (stored in session)
$token2 = generateCsrfToken();
assert_equal('generateCsrfToken is idempotent within a session', $token, $token2);

assert_true('validateCsrfToken accepts the correct token', validateCsrfToken($token));
assert_true('validateCsrfToken rejects a bad token', validateCsrfToken('bad-token') === false);
assert_true('validateCsrfToken rejects empty string', validateCsrfToken('') === false);

// Session without csrf_token
resetSession();
assert_true('validateCsrfToken returns false when no token in session', validateCsrfToken('anything') === false);

// ── hashPassword ────────────────────────────────────────────────────

$hash = hashPassword('my-secret-password');
assert_true('hashPassword returns a string', is_string($hash));
assert_true('hashPassword uses bcrypt', str_starts_with($hash, '$2y$'));
assert_true('hashPassword produces a verifiable hash', password_verify('my-secret-password', $hash));
assert_true('hashPassword does not reveal the plaintext', password_verify('wrong', $hash) === false);

// Different passwords produce different hashes
$hash2 = hashPassword('another-password');
assert_true('hashPassword produces unique hashes for different inputs', $hash !== $hash2);

// ── getCurrentUser ──────────────────────────────────────────────────

resetSession();
$result = getCurrentUser();
assert_equal('getCurrentUser returns null when not logged in', null, $result);

// Insert a user and fetch them
$aliceId = insertTestUser($db, ['name' => 'Alice', 'email' => 'alice@auth-test.local']);
$_SESSION['user_id'] = $aliceId;

$user = getCurrentUser();
assert_true('getCurrentUser returns an array for valid user', is_array($user));
assert_equal('getCurrentUser fetches name', 'Alice', $user['name']);
assert_equal('getCurrentUser fetches email', 'alice@auth-test.local', $user['email']);
assert_true('getCurrentUser includes is_admin column', array_key_exists('is_admin', $user));

// Non-existent user
$_SESSION['user_id'] = 99999;
$user = getCurrentUser();
assert_true('getCurrentUser returns false for non-existent user', $user === false);

// ── login ───────────────────────────────────────────────────────────

resetSession();
// Insert a user with a known password
$bobId = insertTestUser($db, [
    'name'            => 'Bob',
    'email'           => 'bob@auth-test.local',
    'password'        => password_hash('correct-horse-battery', PASSWORD_DEFAULT),
    'email_confirmed' => 1,
]);

// Insert an unconfirmed user
$charlieId = insertTestUser($db, [
    'name'            => 'Charlie',
    'email'           => 'charlie@auth-test.local',
    'password'        => password_hash('secret456', PASSWORD_DEFAULT),
    'email_confirmed' => 0,
]);

// Successful login
$result = login('bob@auth-test.local', 'correct-horse-battery');
assert_equal('login success for valid credentials', true, $result['success']);
assert_true('login sets user_id in session', isset($_SESSION['user_id']));
assert_equal('login sets correct user_id in session', $bobId, $_SESSION['user_id']);

// Wrong password
resetSession();
$result = login('bob@auth-test.local', 'wrong-password');
assert_equal('login fails for wrong password', false, $result['success']);
assert_true('login returns error message for wrong password', isset($result['message']));

// Non-existent email
resetSession();
$result = login('nobody@auth-test.local', 'irrelevant');
assert_equal('login fails for non-existent email', false, $result['success']);

// Unconfirmed email
resetSession();
$result = login('charlie@auth-test.local', 'secret456');
assert_equal('login fails for unconfirmed email', false, $result['success']);
assert_true('login mentions email confirmation in message', str_contains($result['message'] ?? '', 'confirm'));

// Empty email / password (edge case)
resetSession();
$result = login('', '');
assert_equal('login fails for empty credentials', false, $result['success']);

// Case sensitivity of email (DB query is case-sensitive by default, but test behaviour)
resetSession();
$result = login('BOB@auth-test.local', 'correct-horse-battery');
// SQLite with default collation is case-sensitive for TEXT, so this likely fails
// Just verify we get a consistent result
assert_true('login handles case-variant email without error', is_array($result));
