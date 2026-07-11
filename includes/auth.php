<?php
declare(strict_types=1);
// Authentication functions

require_once __DIR__ . '/../config.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

function requireAdmin() {
    if (!isAdmin()) {
        header('Location: /index.php');
        exit;
    }
}

function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT id, name, email, is_admin FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function login($email, $password) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, name, email, password, is_admin, email_confirmed FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        if (!$user['email_confirmed']) {
            return ['success' => false, 'message' => 'Please confirm your email address before logging in.'];
        }
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['is_admin'] = $user['is_admin'];
        session_regenerate_id(true);
        return ['success' => true];
    }
    
    return ['success' => false, 'message' => 'Invalid email or password.'];
}

function logout() {
    session_destroy();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    header('Location: /index.php');
    exit;
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Start impersonating a user. Saves the admin's original session
 * so it can be restored later.
 */
function startImpersonating(int $targetUserId): bool {
    if (!isAdmin()) {
        return false;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT id, name, is_admin, email_confirmed FROM users WHERE id = ?");
    $stmt->execute([$targetUserId]);
    $targetUser = $stmt->fetch();
    
    if (!$targetUser || !$targetUser['email_confirmed']) {
        return false;
    }
    
    // Don't allow impersonating other admins
    if ($targetUser['is_admin']) {
        return false;
    }
    
    // Save original session state (only if not already impersonating)
    if (!isset($_SESSION['_impersonating_origin_id'])) {
        $_SESSION['_impersonating_origin_id'] = $_SESSION['user_id'];
        $_SESSION['_impersonating_origin_name'] = $_SESSION['user_name'];
        $_SESSION['_impersonating_origin_admin'] = $_SESSION['is_admin'];
    }
    
    // Set session to target user
    $_SESSION['user_id'] = (int)$targetUser['id'];
    $_SESSION['user_name'] = $targetUser['name'];
    $_SESSION['is_admin'] = (int)$targetUser['is_admin'];
    
    return true;
}

/**
 * Check if the current session is impersonating another user.
 */
function isImpersonating(): bool {
    return isset($_SESSION['_impersonating_origin_id']);
}

/**
 * Stop impersonating and restore the original admin session.
 */
function stopImpersonating(): bool {
    if (!isImpersonating()) {
        return false;
    }
    
    $_SESSION['user_id'] = $_SESSION['_impersonating_origin_id'];
    $_SESSION['user_name'] = $_SESSION['_impersonating_origin_name'];
    $_SESSION['is_admin'] = $_SESSION['_impersonating_origin_admin'];
    
    unset($_SESSION['_impersonating_origin_id']);
    unset($_SESSION['_impersonating_origin_name']);
    unset($_SESSION['_impersonating_origin_admin']);
    
    return true;
}
