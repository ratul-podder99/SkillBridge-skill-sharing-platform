<?php
/**
 * Logout Handler
 * Destroys session and redirects to homepage
 */

session_start();
require_once 'config.php';

// Clear all session variables
$_SESSION = [];

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Clear remember me cookie if exists
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
}

// Redirect to homepage with success message
session_start(); // Start new session for flash message
setFlashMessage('success', 'You have been successfully logged out.');
redirect('index.php');
?>
