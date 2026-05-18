<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config.php';

echo "<h2>Debug Info:</h2>";
echo "Logged in: " . (isLoggedIn() ? 'YES' : 'NO') . "<br>";
echo "Session user_id: " . ($_SESSION['user_id'] ?? 'NOT SET') . "<br>";
echo "GET user parameter: " . ($_GET['user'] ?? 'NOT SET') . "<br>";

$userId = $_SESSION['user_id'] ?? 0;
$otherUserId = isset($_GET['user']) ? (int)$_GET['user'] : 0;

echo "Your user_id: $userId<br>";
echo "Other user_id: $otherUserId<br>";

if ($otherUserId === 0) {
    echo "<strong>ERROR: Other user ID is 0</strong><br>";
}

if ($otherUserId === $userId) {
    echo "<strong>ERROR: Trying to message yourself</strong><br>";
}

try {
    $db = getDB();
    echo "Database connected: YES<br>";
    
    $userStmt = $db->prepare("SELECT user_id, first_name, last_name FROM users WHERE user_id = ?");
    $userStmt->execute([$otherUserId]);
    $otherUser = $userStmt->fetch();
    
    if ($otherUser) {
        echo "Other user found: " . $otherUser['first_name'] . " " . $otherUser['last_name'] . "<br>";
    } else {
        echo "<strong>ERROR: User not found in database</strong><br>";
    }
    
} catch (PDOException $e) {
    echo "<strong>DATABASE ERROR: " . $e->getMessage() . "</strong><br>";
}
?>