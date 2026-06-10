<?php
/**
 * Tests for includes/captcha.php
 *
 * Covers: loadCaptchaQuestions, initCaptcha, getCaptchaQuestion,
 *         verifyCaptchaAnswer, isCaptchaVerified, resetCaptcha
 *
 * Note: These tests modify $_SESSION directly. The captcha functions
 * operate on the session, so we resetSession() between scenarios.
 */

require_once __DIR__ . '/bootstrap.php';

// Ensure CAPTCHA_MAX_RETRIES is available for the source
if (!defined('CAPTCHA_MAX_RETRIES')) {
    define('CAPTCHA_MAX_RETRIES', 3);
}

require_once __DIR__ . '/../includes/captcha.php';

// ── loadCaptchaQuestions ────────────────────────────────────────────

$questions = loadCaptchaQuestions();
assert_true('loadCaptchaQuestions returns an array', is_array($questions));
assert_true('loadCaptchaQuestions returns non-empty array', count($questions) > 0);

// Each question has the expected structure
$first = $questions[0];
assert_true('question has "question" key', array_key_exists('question', $first));
assert_true('question has "answer" key', array_key_exists('answer', $first));

// ── initCaptcha ─────────────────────────────────────────────────────

resetSession();
$result = initCaptcha();
assert_true('initCaptcha returns true with valid captcha.json', $result === true);
assert_true('initCaptcha sets captcha in session', isset($_SESSION['captcha']));
assert_true('initCaptcha sets question_index', isset($_SESSION['captcha']['question_index']));
assert_true('initCaptcha sets attempts_remaining', isset($_SESSION['captcha']['attempts_remaining']));
assert_equal('initCaptcha initialises attempts to CAPTCHA_MAX_RETRIES', CAPTCHA_MAX_RETRIES, $_SESSION['captcha']['attempts_remaining']);
assert_true('initCaptcha sets verified to false', $_SESSION['captcha']['verified'] === false);

// initCaptcha twice should overwrite with a fresh state
$_SESSION['captcha']['verified'] = true;
initCaptcha();
assert_true('initCaptcha resets verified flag', $_SESSION['captcha']['verified'] === false);

// ── getCaptchaQuestion ──────────────────────────────────────────────

resetSession();
$q1 = getCaptchaQuestion();
assert_equal('getCaptchaQuestion returns null when no captcha initialised', null, $q1);

initCaptcha();
$q2 = getCaptchaQuestion();
assert_true('getCaptchaQuestion returns an array', is_array($q2));
assert_true('getCaptchaQuestion has "question" key', isset($q2['question']));
assert_true('getCaptchaQuestion has "attempts_remaining" key', isset($q2['attempts_remaining']));
assert_true('getCaptchaQuestion returns a non-empty question string', strlen($q2['question']) > 0);

// After consuming attempts, the question should reflect remaining count
$_SESSION['captcha']['attempts_remaining'] = 1;
$q3 = getCaptchaQuestion();
assert_equal('getCaptchaQuestion reflects updated attempts', 1, $q3['attempts_remaining']);

// ── verifyCaptchaAnswer ─────────────────────────────────────────────

resetSession();

// No captcha initialised
$r = verifyCaptchaAnswer('anything');
assert_equal('verifyCaptchaAnswer returns error when not initialised', false, $r['valid']);
assert_true('verifyCaptchaAnswer error mentions "No captcha"', str_contains($r['error'] ?? '', 'captcha'));

// Initialise and track what the answer is
initCaptcha();
$questions = loadCaptchaQuestions();
$idx = $_SESSION['captcha']['question_index'];
$correctAnswer = $questions[$idx]['answer'];

// Already verified
$_SESSION['captcha']['verified'] = true;
$r = verifyCaptchaAnswer('wrong');
assert_equal('verifyCaptchaAnswer returns valid=true when already verified', true, $r['valid']);

// Correct answer
resetSession();
initCaptcha();
$q = loadCaptchaQuestions();
$idx = $_SESSION['captcha']['question_index'];
$correct = $q[$idx]['answer'];

$r = verifyCaptchaAnswer($correct);
assert_equal('verifyCaptchaAnswer accepts correct answer', true, $r['valid']);
assert_true('verifyCaptchaAnswer sets verified flag', $_SESSION['captcha']['verified'] === true);

// Wrong answer
resetSession();
initCaptcha();
$r = verifyCaptchaAnswer('definitely-wrong-answer');
assert_equal('verifyCaptchaAnswer rejects wrong answer', false, $r['valid']);
assert_true('verifyCaptchaAnswer deducts an attempt', $_SESSION['captcha']['attempts_remaining'] === CAPTCHA_MAX_RETRIES - 1);
assert_true('verifyCaptchaAnswer returns attempts_remaining', isset($r['attempts_remaining']));

// Exhaust all attempts
resetSession();
initCaptcha();
for ($i = 0; $i < CAPTCHA_MAX_RETRIES; $i++) {
    $r = verifyCaptchaAnswer('wrong');
}
assert_equal('verifyCaptchaAnswer blocks after max retries', true, $r['blocked'] ?? false);

// Verify blocked state persists
$r = verifyCaptchaAnswer('still-wrong');
assert_true('verifyCaptchaAnswer remains blocked on subsequent calls', ($r['blocked'] ?? false) === true);

// ── isCaptchaVerified ───────────────────────────────────────────────

resetSession();
assert_true('isCaptchaVerified returns false when no captcha', isCaptchaVerified() === false);

$_SESSION['captcha']['verified'] = false;
assert_true('isCaptchaVerified returns false when not verified', isCaptchaVerified() === false);

$_SESSION['captcha']['verified'] = true;
assert_true('isCaptchaVerified returns true when verified', isCaptchaVerified() === true);

// ── resetCaptcha ────────────────────────────────────────────────────

resetSession();
$_SESSION['captcha'] = ['verified' => true, 'question_index' => 0, 'attempts_remaining' => 1];
resetCaptcha();
assert_true('resetCaptcha removes captcha from session', !isset($_SESSION['captcha']));

// Calling resetCaptcha when no captcha exists should not error
$_SESSION = []; // clear everything
resetCaptcha(); // should not throw
assert_true('resetCaptcha is no-op when no captcha set', true);
