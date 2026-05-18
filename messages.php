<?php
session_start();
require_once 'config.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    setFlashMessage('warning', 'Please log in to view messages.');
    redirect('login.php');
}

$userId = $_SESSION['user_id'];

try {
    $db = getDB();
    
    // Get all conversations with last message
    $conversationsStmt = $db->prepare("
        SELECT 
            m.*,
            CASE 
                WHEN m.sender_id = ? THEN m.receiver_id 
                ELSE m.sender_id 
            END as other_user_id,
            CASE 
                WHEN m.sender_id = ? THEN receiver.first_name 
                ELSE sender.first_name 
            END as other_first_name,
            CASE 
                WHEN m.sender_id = ? THEN receiver.last_name 
                ELSE sender.last_name 
            END as other_last_name,
            CASE 
                WHEN m.sender_id = ? THEN receiver.profile_photo 
                ELSE sender.profile_photo 
            END as other_profile_photo,
            (SELECT COUNT(*) 
             FROM messages m2 
             WHERE m2.receiver_id = ? 
             AND m2.sender_id = other_user_id
             AND m2.is_read = FALSE) as unread_count
        FROM messages m
        JOIN users sender ON m.sender_id = sender.user_id
        JOIN users receiver ON m.receiver_id = receiver.user_id
        WHERE m.message_id IN (
            SELECT MAX(message_id)
            FROM messages
            WHERE sender_id = ? OR receiver_id = ?
            GROUP BY LEAST(sender_id, receiver_id), GREATEST(sender_id, receiver_id)
        )
        ORDER BY m.sent_at DESC
    ");
    
    $conversationsStmt->execute([
        $userId, $userId, $userId, $userId, $userId, $userId, $userId
    ]);
    $conversations = $conversationsStmt->fetchAll();
    
    // Count total unread messages
    $unreadStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM messages 
        WHERE receiver_id = ? AND is_read = FALSE
    ");
    $unreadStmt->execute([$userId]);
    $totalUnread = $unreadStmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Messages Error: " . $e->getMessage());
    $conversations = [];
    $totalUnread = 0;
}

$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .conversation-item {
            transition: background-color 0.2s;
            cursor: pointer;
            border-left: 3px solid transparent;
        }
        .conversation-item:hover {
            background-color: #f8f9fa;
        }
        .conversation-item.unread {
            background-color: #e7f3ff;
            border-left-color: #667eea;
        }
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
        }
        .unread-badge {
            background-color: #667eea;
            color: white;
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: bold;
        }
        .message-preview {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-bridge me-2"></i><?php echo SITE_NAME; ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="browse-skills.php">Browse Skills</a>
                <a class="nav-link active" href="messages.php">
                    Messages
                    <?php if ($totalUnread > 0): ?>
                        <span class="badge bg-danger ms-1"><?php echo $totalUnread; ?></span>
                    <?php endif; ?>
                </a>
                <a class="nav-link" href="profile.php">Profile</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container my-5">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="mb-1">
                            <i class="fas fa-envelope text-primary me-2"></i>Messages
                        </h2>
                        <p class="text-muted mb-0">
                            <?php echo $totalUnread > 0 ? "$totalUnread unread message" . ($totalUnread > 1 ? 's' : '') : 'All caught up!'; ?>
                        </p>
                    </div>
                </div>

                <!-- Flash Message -->
                <?php if ($flashMessage): ?>
                    <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show">
                        <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                        <?php echo escape($flashMessage['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Conversations List -->
                <div class="card shadow-sm">
                    <?php if (empty($conversations)): ?>
                        <div class="card-body text-center py-5">
                            <i class="fas fa-inbox fa-4x text-muted mb-4"></i>
                            <h4 class="text-muted mb-3">No Messages Yet</h4>
                            <p class="text-muted mb-4">
                                Start a conversation by contacting a tutor from their skill page!
                            </p>
                            <a href="browse-skills.php" class="btn btn-primary">
                                <i class="fas fa-search me-2"></i>Browse Skills
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($conversations as $conversation): ?>
                                <a href="conversation.php?user=<?php echo $conversation['other_user_id']; ?>" 
                                   class="list-group-item list-group-item-action conversation-item <?php echo $conversation['unread_count'] > 0 ? 'unread' : ''; ?>">
                                    <div class="d-flex align-items-start">
                                        <!-- Avatar -->
                                        <div class="me-3">
                                            <?php if ($conversation['other_profile_photo']): ?>
                                                <img src="<?php echo escape($conversation['other_profile_photo']); ?>" 
                                                     alt="<?php echo escape($conversation['other_first_name']); ?>"
                                                     class="user-avatar">
                                            <?php else: ?>
                                                <div class="user-avatar bg-secondary d-flex align-items-center justify-content-center">
                                                    <i class="fas fa-user fa-lg text-white"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Message Content -->
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start mb-1">
                                                <h6 class="mb-0 fw-bold">
                                                    <?php echo escape($conversation['other_first_name'] . ' ' . $conversation['other_last_name']); ?>
                                                </h6>
                                                <small class="text-muted">
                                                    <?php echo timeAgo($conversation['sent_at']); ?>
                                                </small>
                                            </div>
                                            
                                            <p class="mb-1 text-muted message-preview">
                                                <?php if ($conversation['sender_id'] == $userId): ?>
                                                    <span class="text-dark">You:</span>
                                                <?php endif; ?>
                                                <?php echo escape($conversation['message_text']); ?>
                                            </p>
                                            
                                            <?php if ($conversation['unread_count'] > 0): ?>
                                                <span class="unread-badge">
                                                    <?php echo $conversation['unread_count']; ?> new
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($conversations)): ?>
                    <div class="card mt-4 border-info">
                        <div class="card-body">
                            <h6 class="card-title">
                                <i class="fas fa-info-circle text-info me-2"></i>Tips
                            </h6>
                            <ul class="mb-0 small">
                                <li>Click on a conversation to view and reply</li>
                                <li>Unread messages are highlighted in blue</li>
                                <li>Be respectful and professional in all communications</li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>