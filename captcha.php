<?php
/**
 * Captcha API endpoint
 * GET  ?action=question  — returns current captcha question
 * POST ?action=verify    — checks the submitted answer
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/captcha.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'question') {
    if (!isset($_SESSION['captcha'])) {
        initCaptcha();
    }
    $question = getCaptchaQuestion();
    if ($question === null) {
        echo json_encode(['error' => 'No captcha questions available.']);
        exit;
    }
    echo json_encode($question);
    exit;
}

if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $answer = $input['answer'] ?? '';
    echo json_encode(verifyCaptchaAnswer($answer));
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request.']);
