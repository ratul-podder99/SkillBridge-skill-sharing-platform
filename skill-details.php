<?php
session_start();
require_once 'config.php';

$skillId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($skillId === 0) {
    setFlashMessage('error', 'Invalid skill.');
    redirect('browse-skills.php');
}

try {
    $db = getDB();
    
    // Get skill details with tutor info
    $skillStmt = $db->prepare("
        SELECT 
            so.*,
            c.category_name,
            u.user_id as tutor_user_id,
            u.first_name,
            u.last_name,
            u.profile_photo,
            u.bio,
            u.is_verified,
            COUNT(DISTINCT r.review_id) as review_count,
            COALESCE(AVG(r.rating), 0) as avg_rating
        FROM skill_offerings so
        JOIN categories c ON so.category_id = c.category_id
        JOIN users u ON so.tutor_id = u.user_id
        LEFT JOIN reviews r ON so.skill_id = r.skill_id
        WHERE so.skill_id = ? AND so.is_active = TRUE
        GROUP BY so.skill_id
    ");
    $skillStmt->execute([$skillId]);
    $skill = $skillStmt->fetch();
    
    if (!$skill) {
        setFlashMessage('error', 'Skill not found or has been removed.');
        redirect('browse-skills.php');
    }
    
    // Check if user is enrolled (if logged in)
    $enrollment = null;
    $isEnrolled = false;
    $enrollmentStatus = null;
    
    if (isLoggedIn()) {
        try {
            $enrollmentCheckStmt = $db->prepare("
                SELECT * FROM enrollments 
                WHERE skill_id = ? AND student_id = ?
            ");
            $enrollmentCheckStmt->execute([$skillId, $_SESSION['user_id']]);
            $enrollment = $enrollmentCheckStmt->fetch();
            
            if ($enrollment) {
                $isEnrolled = true;
                $enrollmentStatus = $enrollment['payment_status'];
            }
        } catch (PDOException $e) {
            error_log("Enrollment Check Error: " . $e->getMessage());
        }
    }
    
    // Get reviews for this skill
    $reviewsStmt = $db->prepare("
        SELECT 
            r.*,
            u.first_name,
            u.last_name,
            u.profile_photo
        FROM reviews r
        JOIN users u ON r.learner_id = u.user_id
        WHERE r.skill_id = ?
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $reviewsStmt->execute([$skillId]);
    $reviews = $reviewsStmt->fetchAll();
    
    // Get other skills by same tutor
    $otherSkillsStmt = $db->prepare("
        SELECT so.*, c.category_name,
               COUNT(DISTINCT r.review_id) as review_count,
               COALESCE(AVG(r.rating), 0) as avg_rating
        FROM skill_offerings so
        JOIN categories c ON so.category_id = c.category_id
        LEFT JOIN reviews r ON so.skill_id = r.skill_id
        WHERE so.tutor_id = ? AND so.skill_id != ? AND so.is_active = TRUE
        GROUP BY so.skill_id
        ORDER BY so.created_at DESC
        LIMIT 3
    ");
    $otherSkillsStmt->execute([$skill['tutor_id'], $skillId]);
    $otherSkills = $otherSkillsStmt->fetchAll();
    
    // Check if current user is the owner
    $isOwner = isLoggedIn() && $_SESSION['user_id'] === $skill['tutor_id'];
    
} catch (PDOException $e) {
    error_log("Skill Details Error: " . $e->getMessage());
    setFlashMessage('error', 'Error loading skill details.');
    redirect('browse-skills.php');
}

$flashMessage = getFlashMessage();
$pageTitle = escape($skill['skill_title']) . ' - ' . SITE_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .skill-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 0;
        }
        .tutor-card {
            position: sticky;
            top: 20px;
        }
        .tutor-photo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
        }
        .review-card {
            border-left: 4px solid #667eea;
        }
        .enroll-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            color: white;
            padding: 0.75rem 1.5rem;
            font-size: 1.1rem;
            font-weight: bold;
            transition: all 0.3s;
        }
        .enroll-btn:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
            color: white;
        }
        .enroll-btn-paid {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        .enroll-btn-paid:hover {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.3);
        }
        .premium-badge {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            display: inline-block;
            margin-bottom: 1rem;
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
                <?php if (isLoggedIn()): ?>
                    <a class="nav-link" href="dashboard.php">Dashboard</a>
                    <a class="nav-link" href="browse-skills.php">Browse Skills</a>
                    <a class="nav-link" href="my-enrollments.php">My Courses</a>
                    <a class="nav-link" href="messages.php">Messages</a>
                    <a class="nav-link" href="logout.php">Logout</a>
                <?php else: ?>
                    <a class="nav-link" href="browse-skills.php">Browse Skills</a>
                    <a class="nav-link" href="login.php">Login</a>
                    <a class="nav-link" href="register.php">Sign Up</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Skill Header -->
    <div class="skill-header">
        <div class="container">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb text-white-50">
                    <li class="breadcrumb-item"><a href="index.php" class="text-white">Home</a></li>
                    <li class="breadcrumb-item"><a href="browse-skills.php" class="text-white">Skills</a></li>
                    <li class="breadcrumb-item active text-white"><?php echo escape($skill['skill_title']); ?></li>
                </ol>
            </nav>
            
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h1 class="display-5 fw-bold mb-3"><?php echo escape($skill['skill_title']); ?></h1>
                    <p class="lead mb-3">
                        <span class="badge bg-light text-dark me-2">
                            <i class="fas fa-tag me-1"></i><?php echo escape($skill['category_name']); ?>
                        </span>
                        <?php if ($skill['review_count'] > 0): ?>
                            <?php echo generateStarRating($skill['avg_rating']); ?>
                            <span class="ms-2"><?php echo number_format($skill['avg_rating'], 1); ?> (<?php echo $skill['review_count']; ?> reviews)</span>
                        <?php else: ?>
                            <span class="text-white-50">No reviews yet</span>
                        <?php endif; ?>
                    </p>
                    <p class="mb-0">
                        <i class="fas fa-clock me-2"></i>Posted <?php echo timeAgo($skill['created_at']); ?>
                        <?php if ($skill['has_premium_content']): ?>
                            <span class="ms-3">
                                <i class="fas fa-crown text-warning me-1"></i>
                                <span class="text-warning">Premium Content</span>
                            </span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <div class="display-4 fw-bold">
                        <?php if ($skill['is_free']): ?>
                            <span class="badge bg-success">FREE</span>
                        <?php else: ?>
                            <?php echo formatPrice($skill['price_per_hour']); ?><small class="fs-6">/hr</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container my-5">
        <!-- Flash Message -->
        <?php if ($flashMessage): ?>
            <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show">
                <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'info-circle'; ?> me-2"></i>
                <?php echo escape($flashMessage['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8 mb-4">
                <!-- Premium Content Badge -->
                <?php if ($skill['has_premium_content']): ?>
                    <div class="alert alert-warning shadow-sm">
                        <h5 class="alert-heading">
                            <i class="fas fa-crown me-2"></i>Premium Content Included
                        </h5>
                        <p class="mb-2">This course includes exclusive materials for enrolled students:</p>
                        <ul class="mb-0">
                            <?php if (!empty($skill['materials_pdf'])): ?>
                                <li><i class="fas fa-file-pdf text-danger me-2"></i>Downloadable Study Materials (PDF)</li>
                            <?php endif; ?>
                            <?php if (!empty($skill['video_url'])): ?>
                                <li><i class="fas fa-video text-primary me-2"></i>Video Lessons</li>
                            <?php endif; ?>
                            <?php if (!empty($skill['roadmap_pdf'])): ?>
                                <li><i class="fas fa-map text-success me-2"></i>Course Roadmap</li>
                            <?php endif; ?>
                        </ul>
                        <hr>
                        <p class="mb-0 small">
                            <i class="fas fa-info-circle me-2"></i>
                            Enroll to unlock all premium content
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Description -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h4 class="card-title mb-3">
                            <i class="fas fa-info-circle text-primary me-2"></i>About This Skill
                        </h4>
                        <p class="card-text" style="white-space: pre-line; line-height: 1.8;">
                            <?php echo nl2br(escape($skill['skill_description'])); ?>
                        </p>
                    </div>
                </div>

                <!-- Qualifications -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h4 class="card-title mb-3">
                            <i class="fas fa-certificate text-success me-2"></i>Tutor Qualifications
                        </h4>
                        <p class="card-text" style="white-space: pre-line; line-height: 1.8;">
                            <?php echo nl2br(escape($skill['qualifications'])); ?>
                        </p>
                    </div>
                </div>

                <!-- Availability -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h4 class="card-title mb-3">
                            <i class="fas fa-calendar-alt text-info me-2"></i>Availability
                        </h4>
                        <p class="card-text" style="white-space: pre-line; line-height: 1.8;">
                            <?php echo nl2br(escape($skill['availability'])); ?>
                        </p>
                    </div>
                </div>

                <!-- Reviews -->
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h4 class="card-title mb-4">
                            <i class="fas fa-star text-warning me-2"></i>Student Reviews 
                            <?php if ($skill['review_count'] > 0): ?>
                                (<?php echo $skill['review_count']; ?>)
                            <?php endif; ?>
                        </h4>
                        
                        <?php if (empty($reviews)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-comments fa-3x text-muted mb-3"></i>
                                <p class="text-muted mb-0">No reviews yet. Be the first to review!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($reviews as $review): ?>
                            <div class="review-card card mb-3">
                                <div class="card-body">
                                    <div class="d-flex align-items-start">
                                        <div class="me-3">
                                            <?php if ($review['profile_photo']): ?>
                                                <img src="<?php echo escape($review['profile_photo']); ?>" 
                                                     alt="<?php echo escape($review['first_name']); ?>"
                                                     class="rounded-circle" width="50" height="50"
                                                     style="object-fit: cover;">
                                            <?php else: ?>
                                                <div class="bg-secondary rounded-circle d-flex align-items-center justify-content-center"
                                                     style="width: 50px; height: 50px;">
                                                    <i class="fas fa-user text-white"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1">
                                                        <?php echo escape($review['first_name'] . ' ' . $review['last_name']); ?>
                                                    </h6>
                                                    <?php echo generateStarRating($review['rating']); ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo timeAgo($review['created_at']); ?>
                                                </small>
                                            </div>
                                            <?php if ($review['review_text']): ?>
                                                <p class="mb-0 mt-2"><?php echo nl2br(escape($review['review_text'])); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Enrollment Card -->
                <div class="card shadow tutor-card mb-4">
                    <div class="card-body p-4">
                        <!-- Price Display -->
                        <div class="text-center mb-3">
                            <?php if ($skill['is_free']): ?>
                                <div class="premium-badge">
                                    <i class="fas fa-gift fa-2x mb-2 d-block"></i>
                                    <h3 class="mb-0">FREE</h3>
                                </div>
                            <?php else: ?>
                                <h2 class="text-primary mb-0">
                                    <?php echo formatPrice($skill['price_per_hour']); ?>
                                </h2>
                                <p class="text-muted">per hour</p>
                            <?php endif; ?>
                        </div>

                        <?php if (!isLoggedIn()): ?>
                            <!-- Not Logged In -->
                            <a href="login.php" class="btn btn-primary btn-lg w-100 mb-2">
                                <i class="fas fa-sign-in-alt me-2"></i>Login to Enroll
                            </a>
                            <p class="text-center text-muted small mb-0">
                                Create an account to start learning
                            </p>
                            
                        <?php elseif ($isOwner): ?>
                            <!-- Own Course -->
                            <div class="alert alert-info mb-3">
                                <i class="fas fa-info-circle me-2"></i>
                                This is your course
                            </div>
                            <a href="edit-skill.php?id=<?php echo $skillId; ?>" 
                               class="btn btn-primary w-100 mb-2">
                                <i class="fas fa-edit me-2"></i>Edit This Skill
                            </a>
                            <?php if ($skill['has_premium_content']): ?>
                                <a href="course-materials.php?id=<?php echo $skillId; ?>" 
                                   class="btn btn-outline-success w-100 mb-2">
                                    <i class="fas fa-crown me-2"></i>View Materials
                                </a>
                            <?php endif; ?>
                            <a href="manage-enrollments.php" class="btn btn-outline-secondary w-100 mb-2">
                                <i class="fas fa-users me-2"></i>Manage Enrollments
                            </a>
                            <a href="my-skills.php" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-list me-2"></i>My Skills
                            </a>
                            
                        <?php elseif ($isEnrolled): ?>
                            <!-- Already Enrolled -->
                            <?php if ($enrollment['access_granted']): ?>
                                <!-- Access Granted -->
                                <div class="alert alert-success mb-3">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <strong>You're Enrolled!</strong>
                                </div>
                                <?php if ($skill['has_premium_content']): ?>
                                    <a href="course-materials.php?id=<?php echo $skillId; ?>" 
                                       class="btn btn-success btn-lg w-100 mb-2 enroll-btn">
                                        <i class="fas fa-crown me-2"></i>Access Course Materials
                                    </a>
                                <?php endif; ?>
                                <a href="my-enrollments.php" class="btn btn-outline-primary w-100 mb-2">
                                    <i class="fas fa-book me-2"></i>My Courses
                                </a>
                                
                            <?php elseif ($enrollmentStatus === 'pending'): ?>
                                <!-- Pending Approval -->
                                <div class="alert alert-warning mb-3">
                                    <i class="fas fa-clock me-2"></i>
                                    <strong>Enrollment Pending</strong>
                                    <p class="mb-0 small">Waiting for tutor approval</p>
                                </div>
                                <a href="my-enrollments.php" class="btn btn-outline-warning w-100 mb-2">
                                    <i class="fas fa-list me-2"></i>View Status
                                </a>
                                
                            <?php else: ?>
                                <!-- Rejected -->
                                <div class="alert alert-danger mb-3">
                                    <i class="fas fa-times-circle me-2"></i>
                                    <strong>Enrollment Rejected</strong>
                                </div>
                                <a href="enroll.php?id=<?php echo $skillId; ?>" class="btn btn-outline-danger w-100 mb-2">
                                    <i class="fas fa-redo me-2"></i>Try Again
                                </a>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <!-- Not Enrolled - Show Enroll Button -->
                            <a href="enroll.php?id=<?php echo $skillId; ?>" 
                               class="btn btn-lg w-100 mb-3 enroll-btn <?php echo $skill['is_free'] ? '' : 'enroll-btn-paid'; ?>">
                                <?php if ($skill['is_free']): ?>
                                    <i class="fas fa-check-circle me-2"></i>Enroll for Free
                                <?php else: ?>
                                    <i class="fas fa-graduation-cap me-2"></i>Enroll Now
                                <?php endif; ?>
                            </a>
                            
                            <?php if ($skill['has_premium_content']): ?>
                                <div class="alert alert-warning small mb-3">
                                    <i class="fas fa-star me-2"></i>
                                    Includes premium materials & videos
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!empty($skill['roadmap_pdf'])): ?>
                            <hr>
                            <a href="<?php echo escape($skill['roadmap_pdf']); ?>" 
                               target="_blank"
                               class="btn btn-outline-info w-100 mb-2">
                                <i class="fas fa-map me-2"></i>View Course Roadmap
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tutor Card -->
                <div class="card shadow tutor-card mb-4">
                    <div class="card-body p-4 text-center">
                        <h5 class="card-title mb-3">Your Tutor</h5>
                        
                        <?php if ($skill['profile_photo']): ?>
                            <img src="<?php echo escape($skill['profile_photo']); ?>" 
                                 alt="<?php echo escape($skill['first_name']); ?>"
                                 class="tutor-photo mb-3">
                        <?php else: ?>
                            <div class="tutor-photo bg-secondary d-inline-flex align-items-center justify-content-center mb-3">
                                <i class="fas fa-user fa-3x text-white"></i>
                            </div>
                        <?php endif; ?>
                        
                        <h4 class="mb-1">
                            <?php echo escape($skill['first_name'] . ' ' . $skill['last_name']); ?>
                            <?php if ($skill['is_verified']): ?>
                                <i class="fas fa-check-circle text-success" title="Verified Tutor"></i>
                            <?php endif; ?>
                        </h4>
                        
                        <?php if ($skill['bio']): ?>
                            <p class="text-muted small mb-3">
                                <?php echo escape(substr($skill['bio'], 0, 100)) . (strlen($skill['bio']) > 100 ? '...' : ''); ?>
                            </p>
                        <?php endif; ?>
                        
                        <a href="profile.php?id=<?php echo $skill['tutor_user_id']; ?>" 
                           class="btn btn-outline-primary w-100 mb-2">
                            <i class="fas fa-user me-2"></i>View Profile
                        </a>

                        <?php if (isLoggedIn() && !$isOwner): ?>
                            <a href="conversation.php?user=<?php echo $skill['tutor_user_id']; ?>" 
                               class="btn btn-outline-success w-100">
                                <i class="fas fa-envelope me-2"></i>Message Tutor
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Other Skills by This Tutor -->
                <?php if (!empty($otherSkills)): ?>
                <div class="card shadow">
                    <div class="card-body p-4">
                        <h5 class="card-title mb-3">More from This Tutor</h5>
                        
                        <?php foreach ($otherSkills as $otherSkill): ?>
                        <div class="card mb-3">
                            <div class="card-body p-3">
                                <h6 class="card-title mb-2">
                                    <a href="skill-details.php?id=<?php echo $otherSkill['skill_id']; ?>"
                                       class="text-decoration-none text-dark">
                                        <?php echo escape($otherSkill['skill_title']); ?>
                                    </a>
                                </h6>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <?php if ($otherSkill['review_count'] > 0): ?>
                                            <?php echo generateStarRating($otherSkill['avg_rating']); ?>
                                        <?php else: ?>
                                            No reviews
                                        <?php endif; ?>
                                    </small>
                                    <strong class="text-primary">
                                        <?php echo $otherSkill['is_free'] ? 'Free' : formatPrice($otherSkill['price_per_hour']); ?>
                                    </strong>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>