<?php
/**
 * Captcha verification using simple question/answer pairs
 */

function loadCaptchaQuestions() {
    $path = __DIR__ . '/../captcha.json';
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function initCaptcha() {
    $questions = loadCaptchaQuestions();
    if (empty($questions)) {
        return false;
    }

    $index = array_rand($questions);
    $_SESSION['captcha'] = [
        'question_index' => $index,
        'attempts_remaining' => CAPTCHA_MAX_RETRIES,
        'verified' => false,
    ];
    return true;
}

function getCaptchaQuestion() {
    if (!isset($_SESSION['captcha']) || !isset($_SESSION['captcha']['question_index'])) {
        return null;
    }
    $questions = loadCaptchaQuestions();
    $index = $_SESSION['captcha']['question_index'];
    if (!isset($questions[$index])) {
        return null;
    }
    return [
        'question' => $questions[$index]['question'],
        'attempts_remaining' => $_SESSION['captcha']['attempts_remaining'],
    ];
}

function verifyCaptchaAnswer($answer) {
    if (!isset($_SESSION['captcha']) || !isset($_SESSION['captcha']['question_index'])) {
        return ['valid' => false, 'error' => 'No captcha initialized.'];
    }

    if ($_SESSION['captcha']['verified']) {
        return ['valid' => true];
    }

    if ($_SESSION['captcha']['attempts_remaining'] <= 0) {
        return ['valid' => false, 'blocked' => true, 'error' => 'No attempts remaining.'];
    }

    $_SESSION['captcha']['attempts_remaining']--;

    $questions = loadCaptchaQuestions();
    $index = $_SESSION['captcha']['question_index'];
    $expected = $questions[$index]['answer'] ?? '';

    if (strcasecmp(trim($answer), trim($expected)) === 0) {
        $_SESSION['captcha']['verified'] = true;
        return ['valid' => true];
    }

    $remaining = $_SESSION['captcha']['attempts_remaining'];
    if ($remaining <= 0) {
        return ['valid' => false, 'blocked' => true, 'error' => 'No attempts remaining.'];
    }

    return [
        'valid' => false,
        'attempts_remaining' => $remaining,
        'error' => 'Incorrect answer. ' . $remaining . ' attempt(s) remaining.',
    ];
}

function isCaptchaVerified() {
    return isset($_SESSION['captcha']['verified']) && $_SESSION['captcha']['verified'] === true;
}

function resetCaptcha() {
    unset($_SESSION['captcha']);
}
