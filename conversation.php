<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    setFlashMessage('warning', 'Please log in to view messages.');
    redirect('login.php');
}

$userId = $_SESSION['user_id'];
$otherUserId = isset($_GET['user']) ? (int)$_GET['user'] : 0;

if ($otherUserId === 0 || $otherUserId === $userId) {
    setFlashMessage('error', 'Invalid conversation.');
    redirect('messages.php');
}

try {
    $db = getDB();
    
    // Get other user details
    $userStmt = $db->prepare("
        SELECT user_id, first_name, last_name, profile_photo, user_type 
        FROM users 
        WHERE user_id = ?
    ");
    $userStmt->execute([$otherUserId]);
    $otherUser = $userStmt->fetch();
    
    if (!$otherUser) {
        setFlashMessage('error', 'User not found.');
        redirect('messages.php');
    }
    
    // Mark messages as read
    $markReadStmt = $db->prepare("
        UPDATE messages 
        SET is_read = TRUE 
        WHERE sender_id = ? AND receiver_id = ? AND is_read = FALSE
    ");
    $markReadStmt->execute([$otherUserId, $userId]);
    
    // Get conversation messages
    $messagesStmt = $db->prepare("
        SELECT m.*, 
               s.first_name as sender_first_name,
               s.last_name as sender_last_name,
               s.profile_photo as sender_photo
        FROM messages m
        JOIN users s ON m.sender_id = s.user_id
        WHERE (m.sender_id = ? AND m.receiver_id = ?)
           OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.sent_at ASC
    ");
    $messagesStmt->execute([$userId, $otherUserId, $otherUserId, $userId]);
    $messages = $messagesStmt->fetchAll();
    
} catch (PDOException $e) {
    echo "<h2>DATABASE ERROR:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    echo "<br><strong>Error Code:</strong> " . $e->getCode();
    echo "<br><strong>Your user_id:</strong> $userId";
    echo "<br><strong>Other user_id:</strong> $otherUserId";
    exit;
}

// Handle sending new message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid security token.');
    } else {
        $messageText = trim($_POST['message_text'] ?? '');
        
        if (empty($messageText)) {
            setFlashMessage('error', 'Message cannot be empty.');
        } elseif (strlen($messageText) > 1000) {
            setFlashMessage('error', 'Message is too long (max 1000 characters).');
        } else {
            try {
                $insertStmt = $db->prepare("
                    INSERT INTO messages (sender_id, receiver_id, message_text, sent_at, is_read)
                    VALUES (?, ?, ?, NOW(), FALSE)
                ");
                $insertStmt->execute([$userId, $otherUserId, $messageText]);
                
                setFlashMessage('success', 'Message sent!');
                redirect('conversation.php?user=' . $otherUserId);
                
            } catch (PDOException $e) {
                echo "<h2>SEND MESSAGE ERROR:</h2>";
                echo "<pre>" . $e->getMessage() . "</pre>";
                exit;
            }
        }
    }
}

$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat with <?php echo escape($otherUser['first_name']); ?> - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .chat-container {
            height: 600px;
            display: flex;
            flex-direction: column;
        }
        .chat-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px 10px 0 0;
        }
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background-color: #f8f9fa;
        }
        .chat-input {
            padding: 20px;
            background-color: white;
            border-top: 1px solid #dee2e6;
            border-radius: 0 0 10px 10px;
        }
        .message-bubble {
            max-width: 70%;
            margin-bottom: 15px;
            padding: 12px 16px;
            border-radius: 18px;
            word-wrap: break-word;
        }
        .message-sent {
            background-color: #667eea;
            color: white;
            margin-left: auto;
            border-bottom-right-radius: 4px;
        }
        .message-received {
            background-color: white;
            color: #333;
            border-bottom-left-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        .message-time {
            font-size: 0.75rem;
            margin-top: 5px;
            opacity: 0.7;
        }
        .message-wrapper {
            display: flex;
            margin-bottom: 10px;
        }
        .message-wrapper.sent {
            justify-content: flex-end;
        }
        .message-wrapper.received {
            justify-content: flex-start;
        }
        .user-avatar-small {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 10px;
        }
        .no-messages {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-bridge me-2"></i><?php echo SITE_NAME; ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="browse-skills.php">Browse Skills</a>
                <a class="nav-link active" href="messages.php">Messages</a>
                <a class="nav-link" href="profile.php">Profile</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container my-4">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="mb-3">
                    <a href="messages.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Messages
                    </a>
                </div>

                <?php if ($flashMessage): ?>
                    <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show">
                        <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                        <?php echo escape($flashMessage['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card shadow chat-container">
                    <div class="chat-header">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <?php if ($otherUser['profile_photo']): ?>
                                    <img src="<?php echo escape($otherUser['profile_photo']); ?>" 
                                         alt="<?php echo escape($otherUser['first_name']); ?>"
                                         style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover;">
                                <?php else: ?>
                                    <div style="width: 50px; height: 50px; border-radius: 50%; background: white; display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-user text-primary"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="mb-0">
                                    <?php echo escape($otherUser['first_name'] . ' ' . $otherUser['last_name']); ?>
                                </h5>
                                <small>
                                    <?php 
                                    if ($otherUser['user_type'] === 'tutor') {
                                        echo 'Tutor';
                                    } elseif ($otherUser['user_type'] === 'learner') {
                                        echo 'Learner';
                                    } else {
                                        echo 'Tutor & Learner';
                                    }
                                    ?>
                                </small>
                            </div>
                            <div>
                                <a href="profile.php?id=<?php echo $otherUser['user_id']; ?>" 
                                   class="btn btn-light btn-sm">
                                    <i class="fas fa-user me-1"></i>View Profile
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="chat-messages" id="chatMessages">
                        <?php if (empty($messages)): ?>
                            <div class="no-messages">
                                <i class="fas fa-comments fa-3x mb-3"></i>
                                <h5>No messages yet</h5>
                                <p class="text-muted">Start the conversation by sending a message below!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <?php $isSent = ($message['sender_id'] == $userId); ?>
                                <div class="message-wrapper <?php echo $isSent ? 'sent' : 'received'; ?>">
                                    <?php if (!$isSent && $message['sender_photo']): ?>
                                        <img src="<?php echo escape($message['sender_photo']); ?>" 
                                             alt="<?php echo escape($message['sender_first_name']); ?>"
                                             class="user-avatar-small">
                                    <?php elseif (!$isSent): ?>
                                        <div class="user-avatar-small bg-secondary d-flex align-items-center justify-content-center">
                                            <i class="fas fa-user text-white small"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="message-bubble <?php echo $isSent ? 'message-sent' : 'message-received'; ?>">
                                        <div><?php echo nl2br(escape($message['message_text'])); ?></div>
                                        <div class="message-time">
                                            <?php echo date('M j, g:i A', strtotime($message['sent_at'])); ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($isSent): ?>
                                        <?php if (isset($_SESSION['profile_photo']) && $_SESSION['profile_photo']): ?>
                                            <img src="<?php echo escape($_SESSION['profile_photo']); ?>" 
                                                 alt="You"
                                                 class="user-avatar-small">
                                        <?php else: ?>
                                            <div class="user-avatar-small bg-secondary d-flex align-items-center justify-content-center">
                                                <i class="fas fa-user text-white small"></i>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="chat-input">
                        <form method="POST" action="" id="messageForm">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <div class="input-group">
                                <textarea class="form-control" name="message_text" 
                                          id="messageInput" rows="2" 
                                          placeholder="Type your message..." 
                                          required maxlength="1000"></textarea>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane me-2"></i>Send
                                </button>
                            </div>
                            <small class="text-muted">Press Enter to send, Shift+Enter for new line</small>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const chatMessages = document.getElementById('chatMessages');
        chatMessages.scrollTop = chatMessages.scrollHeight;
        
        document.getElementById('messageInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('messageForm').submit();
            }
        });
        
        document.getElementById('messageInput').focus();
    </script>
</body>
</html>