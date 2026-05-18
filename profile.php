<?php
session_start();
require_once 'config.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    setFlashMessage('warning', 'Please log in to view profiles.');
    redirect('login.php');
}

// Get profile user ID (from URL or current user)
$profileUserId = isset($_GET['id']) ? (int)$_GET['id'] : $_SESSION['user_id'];
$isOwnProfile = ($profileUserId === $_SESSION['user_id']);

try {
    $db = getDB();
    
    // Get user details
    $userStmt = $db->prepare("
        SELECT u.*, 
               (SELECT COUNT(*) FROM skill_offerings WHERE tutor_id = u.user_id AND is_active = TRUE) as skill_count,
               (SELECT COUNT(*) FROM reviews WHERE tutor_id = u.user_id) as review_count,
               (SELECT COALESCE(AVG(rating), 0) FROM reviews WHERE tutor_id = u.user_id) as avg_rating
        FROM users u
        WHERE u.user_id = ? AND u.is_active = TRUE
    ");
    $userStmt->execute([$profileUserId]);
    $user = $userStmt->fetch();
    
    if (!$user) {
        setFlashMessage('error', 'User not found.');
        redirect('index.php');
    }
    
    // Get social links
    $socialStmt = $db->prepare("SELECT * FROM social_links WHERE user_id = ?");
    $socialStmt->execute([$profileUserId]);
    $socialLinks = $socialStmt->fetchAll();
    
    // Get skills if user is tutor
    $skills = [];
    if ($user['user_type'] === 'tutor' || $user['user_type'] === 'both') {
        $skillsStmt = $db->prepare("
            SELECT so.*, c.category_name,
                   COUNT(DISTINCT r.review_id) as review_count,
                   COALESCE(AVG(r.rating), 0) as avg_rating
            FROM skill_offerings so
            JOIN categories c ON so.category_id = c.category_id
            LEFT JOIN reviews r ON so.skill_id = r.skill_id
            WHERE so.tutor_id = ? AND so.is_active = TRUE
            GROUP BY so.skill_id
            ORDER BY so.created_at DESC
        ");
        $skillsStmt->execute([$profileUserId]);
        $skills = $skillsStmt->fetchAll();
        
        // Get recent reviews
        $reviewsStmt = $db->prepare("
            SELECT r.*, u.first_name, u.last_name, so.skill_title
            FROM reviews r
            JOIN users u ON r.learner_id = u.user_id
            JOIN skill_offerings so ON r.skill_id = so.skill_id
            WHERE r.tutor_id = ?
            ORDER BY r.created_at DESC
            LIMIT 5
        ");
        $reviewsStmt->execute([$profileUserId]);
        $reviews = $reviewsStmt->fetchAll();
    }
    
} catch (PDOException $e) {
    error_log("Profile Error: " . $e->getMessage());
    setFlashMessage('error', 'Error loading profile.');
    redirect('index.php');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($user['first_name'] . ' ' . $user['last_name']); ?> - Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 60px 0 40px;
        }
        .profile-photo {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid white;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
        }
        .stat-card {
            text-align: center;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .skill-card {
            transition: transform 0.3s;
        }
        .skill-card:hover {
            transform: translateY(-5px);
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
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Profile Header -->
    <div class="profile-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-3 text-center">
                    <?php if ($user['profile_photo']): ?>
                        <img src="<?php echo escape($user['profile_photo']); ?>" 
                             alt="Profile Photo" class="profile-photo">
                    <?php else: ?>
                        <div class="profile-photo bg-secondary d-flex align-items-center justify-content-center">
                            <i class="fas fa-user fa-4x text-white"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-9">
                    <h1 class="mb-2">
                        <?php echo escape($user['first_name'] . ' ' . $user['last_name']); ?>
                        <?php if ($user['is_verified']): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-check-circle"></i> Verified
                            </span>
                        <?php endif; ?>
                    </h1>
                    <p class="lead mb-3">
                        <?php if ($user['user_type'] === 'tutor'): ?>
                            <i class="fas fa-chalkboard-teacher me-2"></i>Tutor
                        <?php elseif ($user['user_type'] === 'learner'): ?>
                            <i class="fas fa-graduation-cap me-2"></i>Learner
                        <?php else: ?>
                            <i class="fas fa-users me-2"></i>Tutor & Learner
                        <?php endif; ?>
                    </p>
                    
                    <?php if ($user['bio']): ?>
                        <p class="mb-3"><?php echo nl2br(escape($user['bio'])); ?></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($socialLinks)): ?>
                        <div class="mb-3">
                            <?php foreach ($socialLinks as $link): ?>
                                <a href="<?php echo escape($link['url']); ?>" 
                                   target="_blank" class="btn btn-outline-light btn-sm me-2">
                                    <i class="fab fa-<?php echo strtolower($link['platform']); ?>"></i>
                                    <?php echo escape($link['platform']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($isOwnProfile): ?>
                        <a href="edit-profile.php" class="btn btn-light">
                            <i class="fas fa-edit me-2"></i>Edit Profile
                        </a>
                    <?php else: ?>
                        <a href="send-message.php?to=<?php echo $user['user_id']; ?>" class="btn btn-light">
                            <i class="fas fa-envelope me-2"></i>Send Message
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="container my-5">
        <!-- Stats (for tutors) -->
        <?php if ($user['user_type'] === 'tutor' || $user['user_type'] === 'both'): ?>
        <div class="row g-4 mb-5">
            <div class="col-md-4">
                <div class="stat-card">
                    <i class="fas fa-book-open fa-3x text-primary mb-3"></i>
                    <h3 class="mb-0"><?php echo $user['skill_count']; ?></h3>
                    <p class="text-muted mb-0">Skills Offered</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <i class="fas fa-star fa-3x text-warning mb-3"></i>
                    <h3 class="mb-0"><?php echo number_format($user['avg_rating'], 1); ?>/5</h3>
                    <p class="text-muted mb-0">Average Rating</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <i class="fas fa-comments fa-3x text-success mb-3"></i>
                    <h3 class="mb-0"><?php echo $user['review_count']; ?></h3>
                    <p class="text-muted mb-0">Reviews</p>
                </div>
            </div>
        </div>

        <!-- Skills Section -->
        <?php if (!empty($skills)): ?>
        <div class="mb-5">
            <h3 class="mb-4">Skills Offered</h3>
            <div class="row g-4">
                <?php foreach ($skills as $skill): ?>
                <div class="col-md-6">
                    <div class="card skill-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0"><?php echo escape($skill['skill_title']); ?></h5>
                                <span class="badge bg-primary"><?php echo escape($skill['category_name']); ?></span>
                            </div>
                            <p class="card-text text-muted">
                                <?php echo escape(substr($skill['skill_description'], 0, 100)) . '...'; ?>
                            </p>
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <?php if ($skill['review_count'] > 0): ?>
                                        <?php echo generateStarRating($skill['avg_rating']); ?>
                                        <small class="text-muted">(<?php echo $skill['review_count']; ?>)</small>
                                    <?php else: ?>
                                        <small class="text-muted">No reviews yet</small>
                                    <?php endif; ?>
                                </div>
                                <strong class="text-primary">
                                    <?php echo $skill['is_free'] ? 'Free' : formatPrice($skill['price_per_hour']) . '/hr'; ?>
                                </strong>
                            </div>
                            <a href="skill-details.php?id=<?php echo $skill['skill_id']; ?>" 
                               class="btn btn-outline-primary btn-sm mt-3 w-100">View Details</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Reviews Section -->
        <?php if (!empty($reviews)): ?>
        <div class="mb-5">
            <h3 class="mb-4">Recent Reviews</h3>
            <?php foreach ($reviews as $review): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-1"><?php echo escape($review['first_name'] . ' ' . $review['last_name']); ?></h6>
                            <small class="text-muted">for <?php echo escape($review['skill_title']); ?></small>
                        </div>
                        <div class="text-end">
                            <?php echo generateStarRating($review['rating']); ?>
                            <br>
                            <small class="text-muted"><?php echo timeAgo($review['created_at']); ?></small>
                        </div>
                    </div>
                    <?php if ($review['review_text']): ?>
                        <p class="mb-0 mt-2"><?php echo escape($review['review_text']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>