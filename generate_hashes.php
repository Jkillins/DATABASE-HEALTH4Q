<?php
// Temporary script to generate password hashes
// Run once then delete this file

// All sample users will use password: password123
$password = 'password123';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

echo "Use this hash for all sample users:\n";
echo $hash . "\n\n";
echo "UPDATE statement:\n";
echo "UPDATE users SET password = '$hash';\n";
?>
