<?php
require_once 'config.php';

$email = 'anita@test.com';
$newPassword = 'test123';

// Generate new hash
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

echo "<h2>Password Reset Tool</h2>";
echo "Email: $email<br>";
echo "New Password: $newPassword<br>";
echo "New Hash: $newHash<br><br>";

try {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
    $stmt->execute([$newHash, $email]);
    
    echo "<strong style='color: green;'>✅ Password updated successfully!</strong><br><br>";
    echo "Now try logging in with:<br>";
    echo "Email: anita@test.com<br>";
    echo "Password: test123<br>";
    
} catch (PDOException $e) {
    echo "<strong style='color: red;'>❌ Error: " . $e->getMessage() . "</strong>";
}
?>