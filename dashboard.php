<?php
/**
 * User Dashboard
 * Central hub for logged-in users
 */

session_start();
require_once 'config.php';

// Redirect to login if not logged in
if (!isLoggedIn()) {
    setFlashMessage('warning', 'Please log in to access your dashboard.');
    redirect('login.php');
}

$userId = $_SESSION['user_id'];
$userType = $_SESSION['user_type'];

try {
    $db = getDB();
    
    // Get user details
    $userStmt = $db->prepare("
        SELECT * FROM users WHERE user_id = ?
    ");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    
    // Initialize stats
    $stats = [
        'skills_offered' => 0,
        'total_reviews' => 0,
        'average_rating' => 0,
        'unread_messages' => 0,
        'pending_reviews' => 0,
        'pending_enrollments' => 0,
        'active_students' => 0
    ];
    
    // Get tutor stats if user is a tutor
    if ($userType === 'tutor' || $userType === 'both') {
        $tutorStatsStmt = $db->prepare("
            SELECT 
                COUNT(DISTINCT so.skill_id) as skills_offered,
                COUNT(DISTINCT r.review_id) as total_reviews,
                COALESCE(AVG(r.rating), 0) as average_rating
            FROM users u
            LEFT JOIN skill_offerings so ON u.user_id = so.tutor_id AND so.is_active = TRUE
            LEFT JOIN reviews r ON u.user_id = r.tutor_id
            WHERE u.user_id = ?
        ");
        $tutorStatsStmt->execute([$userId]);
        $tutorStats = $tutorStatsStmt->fetch();
        
        if ($tutorStats) {
            $stats['skills_offered'] = $tutorStats['skills_offered'];
            $stats['total_reviews'] = $tutorStats['total_reviews'];
            $stats['average_rating'] = round($tutorStats['average_rating'], 1);
        }
        
        // Get enrollment stats for tutor
        $enrollmentStatsStmt = $db->prepare("
            SELECT 
                COUNT(CASE WHEN e.payment_status = 'pending' THEN 1 END) as pending_enrollments,
                COUNT(CASE WHEN e.access_granted = TRUE THEN 1 END) as active_students
            FROM enrollments e
            JOIN skill_offerings so ON e.skill_id = so.skill_id
            WHERE so.tutor_id = ?
        ");
        $enrollmentStatsStmt->execute([$userId]);
        $enrollmentStats = $enrollmentStatsStmt->fetch();
        
        if ($enrollmentStats) {
            $stats['pending_enrollments'] = $enrollmentStats['pending_enrollments'];
            $stats['active_students'] = $enrollmentStats['active_students'];
        }
        
        // Get recent reviews for tutor
        $recentReviewsStmt = $db->prepare("
            SELECT 
                r.*,
                u.first_name,
                u.last_name,
                so.skill_title
            FROM reviews r
            JOIN users u ON r.learner_id = u.user_id
            JOIN skill_offerings so ON r.skill_id = so.skill_id
            WHERE r.tutor_id = ?
            ORDER BY r.created_at DESC
            LIMIT 5
        ");
        $recentReviewsStmt->execute([$userId]);
        $recentReviews = $recentReviewsStmt->fetchAll();
    }
    
    // Get unread messages count
    $unreadStmt = $db->prepare("
        SELECT COUNT(*) as unread_count 
        FROM messages 
        WHERE receiver_id = ? AND is_read = FALSE
    ");
    $unreadStmt->execute([$userId]);
    $unreadData = $unreadStmt->fetch();
    $stats['unread_messages'] = $unreadData['unread_count'];
    
    // Get recent messages
    $messagesStmt = $db->prepare("
        SELECT 
            m.*,
            u.first_name,
            u.last_name
        FROM messages m
        JOIN users u ON m.sender_id = u.user_id
        WHERE m.receiver_id = ?
        ORDER BY m.created_at DESC
        LIMIT 5
    ");
    $messagesStmt->execute([$userId]);
    $recentMessages = $messagesStmt->fetchAll();
    
    // Get my skills if tutor
    if ($userType === 'tutor' || $userType === 'both') {
        $mySkillsStmt = $db->prepare("
            SELECT 
                so.*,
                c.category_name,
                COUNT(r.review_id) as review_count,
                COALESCE(AVG(r.rating), 0) as avg_rating
            FROM skill_offerings so
            JOIN categories c ON so.category_id = c.category_id
            LEFT JOIN reviews r ON so.skill_id = r.skill_id
            WHERE so.tutor_id = ?
            GROUP BY so.skill_id
            ORDER BY so.created_at DESC
            LIMIT 5
        ");
        $mySkillsStmt->execute([$userId]);
        $mySkills = $mySkillsStmt->fetchAll();
    }
    
} catch (PDOException $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    $stats = ['skills_offered' => 0, 'total_reviews' => 0, 'average_rating' => 0, 'unread_messages' => 0, 'pending_enrollments' => 0, 'active_students' => 0];
    $recentMessages = [];
    $mySkills = [];
    $recentReviews = [];
}

$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo SITE_NAME; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .stat-card {
            border-left: 4px solid #667eea;
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateX(5px);
        }
        .quick-action-btn {
            border-radius: 15px;
            padding: 20px;
            transition: all 0.3s;
        }
        .quick-action-btn:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
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
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">
                            <i class="fas fa-home me-1"></i>Dashboard
                        </a>
                    </li>
                    
                    <!-- Show Browse Skills for learners and both -->
                    <?php if ($userType === 'learner' || $userType === 'both'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="browse-skills.php">Browse Skills</a>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Show My Skills for tutors and both -->
                    <?php if ($userType === 'tutor' || $userType === 'both'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="my-skills.php">My Skills</a>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Messages for all users except admin -->
                    <?php if ($userType !== 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="messages.php">
                            Messages 
                            <?php if ($stats['unread_messages'] > 0): ?>
                                <span class="badge bg-danger"><?php echo $stats['unread_messages']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" 
                           data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle"></i> <?php echo escape($user['first_name']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if ($userType !== 'admin'): ?>
                            <li><a class="dropdown-item" href="profile.php">
                                <i class="fas fa-user me-2"></i>My Profile
                            </a></li>
                            <li><a class="dropdown-item" href="edit-profile.php">
                                <i class="fas fa-edit me-2"></i>Edit Profile
                            </a></li>
                            <?php endif; ?>
                            
                            <?php if ($userType === 'tutor' || $userType === 'both'): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="my-skills.php">
                                    <i class="fas fa-list me-2"></i>My Skills
                                </a></li>
                                <li><a class="dropdown-item" href="create-skill.php">
                                    <i class="fas fa-plus me-2"></i>Add New Skill
                                </a></li>
                                <li><a class="dropdown-item" href="manage-enrollments.php">
                                    <i class="fas fa-users-cog me-2"></i>Manage Enrollments
                                    <?php if ($stats['pending_enrollments'] > 0): ?>
                                        <span class="badge bg-warning text-dark"><?php echo $stats['pending_enrollments']; ?></span>
                                    <?php endif; ?>
                                </a></li>
                            <?php endif; ?>
                            
                            <?php if ($userType === 'learner' || $userType === 'both'): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="my-enrollments.php">
                                    <i class="fas fa-graduation-cap me-2"></i>My Courses
                                </a></li>
                            <?php endif; ?>
                            
                            <?php if ($userType === 'admin'): ?>
                                <li><a class="dropdown-item" href="admin-dashboard.php">
                                    <i class="fas fa-shield-alt me-2"></i>Admin Panel
                                </a></li>
                            <?php endif; ?>
                            
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container my-5">
        <!-- Welcome Section -->
        <div class="row mb-4">
            <div class="col-12">
                <?php if ($flashMessage): ?>
                    <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show" role="alert">
                        <?php echo escape($flashMessage['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <h1 class="mb-2">Welcome back, <?php echo escape($user['first_name']); ?>! 👋</h1>
                <p class="text-muted">
                    <?php if ($userType === 'admin'): ?>
                        You're registered as an <span class="badge bg-danger">Admin</span>
                    <?php elseif ($userType === 'tutor'): ?>
                        You're registered as a <span class="badge bg-primary">Tutor</span>
                    <?php elseif ($userType === 'learner'): ?>
                        You're registered as a <span class="badge bg-success">Learner</span>
                    <?php elseif ($userType === 'both'): ?>
                        You're registered as both <span class="badge bg-primary">Tutor</span> and <span class="badge bg-success">Learner</span>
                    <?php endif; ?>
                    
                    <?php if ($user['is_verified']): ?>
                        <span class="badge bg-success ms-2">
                            <i class="fas fa-check-circle"></i> Verified
                        </span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Admin Quick Access -->
        <?php if ($userType === 'admin'): ?>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Admin View:</strong> You're viewing the user dashboard. 
                    Go to <a href="admin-dashboard.php" class="alert-link">Admin Panel</a> for full admin features.
                </div>
            </div>
            <div class="col-md-4">
                <a href="admin-dashboard.php" class="btn btn-danger w-100 quick-action-btn text-start">
                    <i class="fas fa-shield-alt fa-2x mb-2"></i>
                    <h6 class="mb-0">Admin Panel</h6>
                    <small class="text-white">Manage platform</small>
                </a>
            </div>
            <div class="col-md-4">
                <a href="admin-enrollments.php" class="btn btn-outline-primary w-100 quick-action-btn text-start">
                    <i class="fas fa-eye fa-2x mb-2"></i>
                    <h6 class="mb-0">View Enrollments</h6>
                    <small class="text-muted">Monitor enrollments</small>
                </a>
            </div>
            <div class="col-md-4">
                <a href="manage-users.php" class="btn btn-outline-success w-100 quick-action-btn text-start">
                    <i class="fas fa-users-cog fa-2x mb-2"></i>
                    <h6 class="mb-0">Manage Users</h6>
                    <small class="text-muted">View all users</small>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards - Only for Tutors and Both -->
        <?php if ($userType === 'tutor' || $userType === 'both'): ?>
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card stat-card">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">Skills Offered</h6>
                        <h2 class="mb-0"><?php echo $stats['skills_offered']; ?></h2>
                        <small class="text-muted">
                            <i class="fas fa-book-open me-1"></i>Active listings
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card" style="border-left-color: #28a745;">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">Total Reviews</h6>
                        <h2 class="mb-0"><?php echo $stats['total_reviews']; ?></h2>
                        <small class="text-muted">
                            <i class="fas fa-star me-1"></i>Received
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card" style="border-left-color: #ffc107;">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">Average Rating</h6>
                        <h2 class="mb-0"><?php echo $stats['average_rating']; ?>/5</h2>
                        <small class="text-muted">
                            <?php echo generateStarRating($stats['average_rating']); ?>
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card" style="border-left-color: #dc3545;">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">Unread Messages</h6>
                        <h2 class="mb-0"><?php echo $stats['unread_messages']; ?></h2>
                        <small class="text-muted">
                            <i class="fas fa-envelope me-1"></i>New
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enrollment Stats Row -->
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card stat-card" style="border-left-color: #f59e0b;">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">Pending Enrollments</h6>
                        <h2 class="mb-0"><?php echo $stats['pending_enrollments']; ?></h2>
                        <small class="text-muted">
                            <i class="fas fa-clock me-1"></i>Waiting for approval
                        </small>
                        <?php if ($stats['pending_enrollments'] > 0): ?>
                            <div class="mt-2">
                                <a href="manage-enrollments.php?filter=pending" class="btn btn-sm btn-warning">
                                    <i class="fas fa-eye me-1"></i>Review Now
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card stat-card" style="border-left-color: #0ea5e9;">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">Active Students</h6>
                        <h2 class="mb-0"><?php echo $stats['active_students']; ?></h2>
                        <small class="text-muted">
                            <i class="fas fa-user-graduate me-1"></i>Enrolled students
                        </small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions - Only for non-admin users -->
        <?php if ($userType !== 'admin'): ?>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <h4 class="mb-3">Quick Actions</h4>
            </div>
            
            <?php if ($userType === 'learner' || $userType === 'both'): ?>
            <div class="col-md-3">
                <a href="browse-skills.php" class="btn btn-outline-primary w-100 quick-action-btn text-start">
                    <i class="fas fa-search fa-2x mb-2"></i>
                    <h6 class="mb-0">Browse Skills</h6>
                    <small class="text-muted">Find something new to learn</small>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if ($userType === 'tutor' || $userType === 'both'): ?>
            <div class="col-md-3">
                <a href="create-skill.php" class="btn btn-outline-success w-100 quick-action-btn text-start">
                    <i class="fas fa-plus-circle fa-2x mb-2"></i>
                    <h6 class="mb-0">Add New Skill</h6>
                    <small class="text-muted">Share your expertise</small>
                </a>
            </div>
            <div class="col-md-3">
                <a href="my-skills.php" class="btn btn-outline-info w-100 quick-action-btn text-start">
                    <i class="fas fa-book-open fa-2x mb-2"></i>
                    <h6 class="mb-0">Manage Skills</h6>
                    <small class="text-muted">Edit your offerings</small>
                </a>
            </div>
            <div class="col-md-3">
                <a href="manage-enrollments.php" class="btn btn-outline-warning w-100 quick-action-btn text-start position-relative">
                    <i class="fas fa-users-cog fa-2x mb-2"></i>
                    <h6 class="mb-0">Manage Enrollments</h6>
                    <small class="text-muted">Approve student requests</small>
                    <?php if ($stats['pending_enrollments'] > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark">
                            <?php echo $stats['pending_enrollments']; ?>
                        </span>
                    <?php endif; ?>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if ($userType === 'learner'): ?>
            <div class="col-md-3">
                <a href="my-enrollments.php" class="btn btn-outline-success w-100 quick-action-btn text-start">
                    <i class="fas fa-graduation-cap fa-2x mb-2"></i>
                    <h6 class="mb-0">My Courses</h6>
                    <small class="text-muted">View enrolled courses</small>
                </a>
            </div>
            <div class="col-md-3">
                <a href="messages.php" class="btn btn-outline-warning w-100 quick-action-btn text-start">
                    <i class="fas fa-envelope fa-2x mb-2"></i>
                    <h6 class="mb-0">Messages</h6>
                    <small class="text-muted">Check your inbox</small>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- My Skills Section (for tutors) -->
            <?php if (($userType === 'tutor' || $userType === 'both') && !empty($mySkills)): ?>
            <div class="col-md-<?php echo !empty($recentMessages) ? '6' : '12'; ?> mb-4">
                <div class="card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-book-open me-2"></i>My Skills</h5>
                        <a href="my-skills.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($mySkills as $skill): ?>
                            <a href="skill-details.php?id=<?php echo $skill['skill_id']; ?>" 
                               class="list-group-item list-group-item-action">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="mb-1"><?php echo escape($skill['skill_title']); ?></h6>
                                        <small class="text-muted">
                                            <span class="badge bg-light text-dark">
                                                <?php echo escape($skill['category_name']); ?>
                                            </span>
                                            <?php if ($skill['review_count'] > 0): ?>
                                                | <?php echo generateStarRating($skill['avg_rating']); ?>
                                                (<?php echo $skill['review_count']; ?>)
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-<?php echo $skill['is_active'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $skill['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Messages Section -->
            <?php if (!empty($recentMessages) && $userType !== 'admin'): ?>
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-envelope me-2"></i>Recent Messages</h5>
                        <a href="messages.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentMessages as $message): ?>
                            <a href="conversation.php?user=<?php echo $message['sender_id']; ?>" 
                               class="list-group-item list-group-item-action <?php echo !$message['is_read'] ? 'bg-light' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1">
                                            <?php echo escape($message['first_name'] . ' ' . $message['last_name']); ?>
                                            <?php if (!$message['is_read']): ?>
                                                <span class="badge bg-danger ms-2">New</span>
                                            <?php endif; ?>
                                        </h6>
                                        <p class="mb-1 text-truncate">
                                            <?php echo escape(substr($message['message_text'], 0, 60)) . '...'; ?>
                                        </p>
                                        <small class="text-muted"><?php echo timeAgo($message['created_at']); ?></small>
                                    </div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Reviews Section (for tutors) -->
            <?php if (($userType === 'tutor' || $userType === 'both') && !empty($recentReviews)): ?>
            <div class="col-md-12 mb-4">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-star me-2"></i>Recent Reviews</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($recentReviews as $review): ?>
                        <div class="border-bottom pb-3 mb-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="mb-0">
                                        <?php echo escape($review['first_name'] . ' ' . $review['last_name']); ?>
                                    </h6>
                                    <small class="text-muted">
                                        for <strong><?php echo escape($review['skill_title']); ?></strong>
                                    </small>
                                </div>
                                <div class="text-end">
                                    <?php echo generateStarRating($review['rating']); ?>
                                    <br>
                                    <small class="text-muted"><?php echo timeAgo($review['created_at']); ?></small>
                                </div>
                            </div>
                            <?php if ($review['review_text']): ?>
                            <p class="mb-0 text-muted"><?php echo escape($review['review_text']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>