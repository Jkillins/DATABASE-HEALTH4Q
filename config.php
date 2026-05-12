<?php
/**
 * config.php - Session Management, Authentication & Configuration
 * Health4Q Medical Management System
 */

// Start the session to track logged-in users
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include the database helper functions
$db_path = __DIR__ . '/db.php';
if (file_exists($db_path)) {
    require_once $db_path;
} else {
    die("Error: db.php not found. Please ensure the database helper file exists.");
}

// ============ ROLE CONSTANTS ============
// Defines consistent names for roles used across the application
const ROLE_PATIENT = 'patient';
const ROLE_DOCTOR = 'doctor';
const ROLE_ASSISTANT = 'clinical_assistant';

// ============ SESSION MANAGEMENT ============

/**
 * Returns an array containing the current user's session data
 */
function getSession() {
    return [
        'is_logged_in' => isset($_SESSION['user_id']),
        'user_id' => $_SESSION['user_id'] ?? null,
        'first_name' => $_SESSION['first_name'] ?? null,
        'last_name' => $_SESSION['last_name'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'role_id' => $_SESSION['role_id'] ?? null,
    ];
}

/**
 * Checks if the user is currently authenticated
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getCurrentUserEmail() {
    return $_SESSION['email'] ?? null;
}

/**
 * Returns the full name of the logged-in user
 */
function getCurrentUserFullName() {
    return ($_SESSION['first_name'] ?? 'User') . ' ' . ($_SESSION['last_name'] ?? '');
}

function getCurrentRole() {
    return $_SESSION['role'] ?? null;
}

function getCurrentRoleId() {
    return $_SESSION['role_id'] ?? null;
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

/**
 * Restricts access to logged-in users only
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

/**
 * Restricts access based on a specific user role
 */
function requireRole($required_role) {
    requireLogin();
    if (!hasRole($required_role)) {
        http_response_code(403);
        header('Location: index.php?error=unauthorized');
        exit;
    }
}

/**
 * Populates session variables upon successful login
 * regenerates session ID to prevent session fixation
 */
function loginUser($user_id, $first_name, $last_name, $email, $role, $role_id = null) {
    session_regenerate_id(true); // Security best practice
    $_SESSION['user_id'] = $user_id;
    $_SESSION['first_name'] = $first_name;
    $_SESSION['last_name'] = $last_name;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = $role;
    $_SESSION['role_id'] = $role_id;
    $_SESSION['login_time'] = time();
}

/**
 * Clears session data and redirects to home
 */
function logoutUser() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

// ============ PASSWORD HASHING ============

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// ============ INPUT VALIDATION & SANITIZATION ============

/**
 * Recursively sanitizes input to prevent XSS attacks
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    // Added null coalescence to handle null inputs gracefully
    return htmlspecialchars(stripslashes(trim($input ?? '')), ENT_QUOTES, 'UTF-8');
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validatePassword($password) {
    return strlen($password ?? '') >= 6;
}

function isValidDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function isValidPhone($phone) {
    return preg_match('/^\+?[0-9\s\-\(\)]+$/', $phone ?? '');
}

// ============ RESPONSE HELPERS ============

function setErrorMessage($message) {
    $_SESSION['error'] = $message;
}

function setSuccessMessage($message) {
    $_SESSION['success'] = $message;
}

function getErrorMessage() {
    $msg = $_SESSION['error'] ?? null;
    unset($_SESSION['error']);
    return $msg;
}

function getSuccessMessage() {
    $msg = $_SESSION['success'] ?? null;
    unset($_SESSION['success']);
    return $msg;
}

/**
 * Sets a message and redirects the user immediately
 */
function redirectAfterMessage($url, $message, $type = 'success') {
    if ($type === 'error') {
        setErrorMessage($message);
    } else {
        setSuccessMessage($message);
    }
    header('Location: ' . $url);
    exit;
}

// ============ COMMON HEADERS ============
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');

?>