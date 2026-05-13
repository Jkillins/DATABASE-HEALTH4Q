<?php
/**
 * logout.php - Session Cleanup
 */

require_once 'config.php';

// Clear all session data
$_SESSION = [];
session_destroy();

// Redirect to homepage
header('Location: index.php?logged_out=1');
exit;
?>
