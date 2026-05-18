<?php
session_start();
require_once 'config.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    setFlashMessage('warning', 'Please log in to enroll in courses.');
    redirect('login.php');
}

$userId = $_SESSION['user_id'];
$skillId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($skillId === 0) {
    setFlashMessage('error', 'Invalid skill ID.');
    redirect('browse-skills.php');
}

try {
    $db = getDB();
    
    // Get skill details with tutor info
    $skillStmt = $db->prepare("
        SELECT so.*, 
               u.first_name, 
               u.last_name, 
               u.profile_photo,
               u.email as tutor_email,
               c.category_name
        FROM skill_offerings so
        JOIN users u ON so.tutor_id = u.user_id
        JOIN categories c ON so.category_id = c.category_id
        WHERE so.skill_id = ? AND so.is_active = TRUE
    ");
    $skillStmt->execute([$skillId]);
    $skill = $skillStmt->fetch();
    
    if (!$skill) {
        setFlashMessage('error', 'Skill not found or is not active.');
        redirect('browse-skills.php');
    }
    
    // Check if user is trying to enroll in their own course
    if ($skill['tutor_id'] == $userId) {
        setFlashMessage('error', 'You cannot enroll in your own course.');
        redirect('skill-details.php?id=' . $skillId);
    }
    
    // Check if already enrolled
    $enrollmentCheckStmt = $db->prepare("
        SELECT * FROM enrollments 
        WHERE skill_id = ? AND student_id = ?
    ");
    $enrollmentCheckStmt->execute([$skillId, $userId]);
    $existingEnrollment = $enrollmentCheckStmt->fetch();
    
    if ($existingEnrollment) {
        if ($existingEnrollment['access_granted']) {
            setFlashMessage('info', 'You are already enrolled in this course!');
            redirect('course-materials.php?id=' . $skillId);
        } else {
            setFlashMessage('info', 'Your enrollment request is pending tutor approval.');
            redirect('skill-details.php?id=' . $skillId);
        }
    }
    
} catch (PDOException $e) {
    error_log("Enrollment Page Error: " . $e->getMessage());
    setFlashMessage('error', 'Failed to load course details.');
    redirect('browse-skills.php');
}

// Handle enrollment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid security token.');
    } else {
        try {
            // Determine enrollment type
            if ($skill['is_free']) {
                // FREE COURSE - Instant access
                $insertStmt = $db->prepare("
                    INSERT INTO enrollments 
                    (skill_id, student_id, payment_status, payment_amount, access_granted, payment_date, enrolled_at)
                    VALUES (?, ?, 'completed', 0.00, TRUE, NOW(), NOW())
                ");
                $insertStmt->execute([$skillId, $userId]);
                
                setFlashMessage('success', 'Enrollment successful! You now have access to course materials.');
                redirect('course-materials.php?id=' . $skillId);
                
            } else {
                // PAID COURSE - Pending approval
                $insertStmt = $db->prepare("
                    INSERT INTO enrollments 
                    (skill_id, student_id, payment_status, payment_amount, access_granted, enrolled_at)
                    VALUES (?, ?, 'pending', ?, FALSE, NOW())
                ");
                $insertStmt->execute([$skillId, $userId, $skill['price_per_hour']]);
                
                setFlashMessage('success', 'Enrollment request submitted! The tutor will review and approve your request.');
                redirect('my-enrollments.php');
            }
            
        } catch (PDOException $e) {
            error_log("Enrollment Error: " . $e->getMessage());
            setFlashMessage('error', 'Failed to process enrollment. Please try again.');
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
    <title>Enroll in <?php echo escape($skill['skill_title']); ?> - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .enrollment-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
        }
        
        .course-card {
            border: none;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }
        
        .price-tag {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .price-tag.paid {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        
        .enroll-btn {
            padding: 1rem 2rem;
            font-size: 1.1rem;
            font-weight: bold;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .enroll-btn-free {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            color: white;
        }
        
        .enroll-btn-free:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
            color: white;
        }
        
        .enroll-btn-paid {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border: none;
            color: white;
        }
        
        .enroll-btn-paid:hover {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.3);
            color: white;
        }
        
        .tutor-card {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            padding: 1.5rem;
            border-radius: 12px;
            border-left: 4px solid #0ea5e9;
        }
        
        .feature-item {
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .step-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            border-left: 4px solid #8b5cf6;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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
                <a class="nav-link" href="messages.php">Messages</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Enrollment Header -->
    <div class="enrollment-header">
        <div class="container">
            <a href="skill-details.php?id=<?php echo $skillId; ?>" class="text-white text-decoration-none d-inline-flex align-items-center mb-3">
                <i class="fas fa-arrow-left me-2"></i>Back to Course Details
            </a>
            <h1 class="mb-2">
                <i class="fas fa-graduation-cap me-3"></i>Enroll in Course
            </h1>
            <p class="mb-0 fs-5">Start your learning journey today!</p>
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

        <div class="row g-4">
            <!-- Main Enrollment Area -->
            <div class="col-lg-8">
                <!-- Course Info -->
                <div class="course-card card mb-4">
                    <div class="card-body p-4">
                        <h3 class="mb-3"><?php echo escape($skill['skill_title']); ?></h3>
                        <p class="text-muted mb-3">
                            <span class="badge bg-primary"><?php echo escape($skill['category_name']); ?></span>
                        </p>
                        <p class="mb-4"><?php echo nl2br(escape($skill['skill_description'])); ?></p>
                        
                        <?php if ($skill['has_premium_content']): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-star me-2"></i>
                                <strong>Premium Content Included:</strong> This course includes exclusive materials and videos for enrolled students.
                            </div>
                        <?php endif; ?>
                        
                        <h5 class="mb-3">What's Included:</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="feature-item">
                                    <i class="fas fa-chalkboard-teacher text-primary me-2"></i>
                                    <strong>Expert Instruction</strong>
                                </div>
                            </div>
                            <?php if (!empty($skill['roadmap_pdf'])): ?>
                            <div class="col-md-6">
                                <div class="feature-item">
                                    <i class="fas fa-map text-success me-2"></i>
                                    <strong>Course Roadmap</strong>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if ($skill['has_premium_content'] && !empty($skill['materials_pdf'])): ?>
                            <div class="col-md-6">
                                <div class="feature-item">
                                    <i class="fas fa-file-pdf text-danger me-2"></i>
                                    <strong>Study Materials</strong>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if ($skill['has_premium_content'] && !empty($skill['video_url'])): ?>
                            <div class="col-md-6">
                                <div class="feature-item">
                                    <i class="fas fa-video text-warning me-2"></i>
                                    <strong>Video Lessons</strong>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-6">
                                <div class="feature-item">
                                    <i class="fas fa-comments text-info me-2"></i>
                                    <strong>Direct Messaging</strong>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-item">
                                    <i class="fas fa-clock text-secondary me-2"></i>
                                    <strong>Flexible Schedule</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Enrollment Process -->
                <?php if (!$skill['is_free']): ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="mb-3">
                            <i class="fas fa-info-circle text-info me-2"></i>Enrollment Process
                        </h5>
                        <div class="step-card">
                            <div class="d-flex align-items-start">
                                <div class="badge bg-primary rounded-circle me-3" style="width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">1</div>
                                <div>
                                    <strong>Submit Enrollment Request</strong>
                                    <p class="mb-0 text-muted">Click the "Request Enrollment" button below</p>
                                </div>
                            </div>
                        </div>
                        <div class="step-card">
                            <div class="d-flex align-items-start">
                                <div class="badge bg-warning rounded-circle me-3" style="width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">2</div>
                                <div>
                                    <strong>Contact Tutor for Payment</strong>
                                    <p class="mb-0 text-muted">Message the tutor to arrange payment (<?php echo formatPrice($skill['price_per_hour']); ?>/hour)</p>
                                </div>
                            </div>
                        </div>
                        <div class="step-card">
                            <div class="d-flex align-items-start">
                                <div class="badge bg-success rounded-circle me-3" style="width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">3</div>
                                <div>
                                    <strong>Get Approved</strong>
                                    <p class="mb-0 text-muted">Once payment is confirmed, the tutor will approve your enrollment</p>
                                </div>
                            </div>
                        </div>
                        <div class="step-card">
                            <div class="d-flex align-items-start">
                                <div class="badge bg-info rounded-circle me-3" style="width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">4</div>
                                <div>
                                    <strong>Start Learning!</strong>
                                    <p class="mb-0 text-muted">Access all premium materials and begin your course</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Price & Enroll -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <div class="<?php echo $skill['is_free'] ? 'price-tag' : 'price-tag paid'; ?> mb-4">
                            <?php if ($skill['is_free']): ?>
                                <i class="fas fa-gift fa-3x mb-3"></i>
                                <h2 class="mb-0">FREE</h2>
                                <p class="mb-0">No cost to enroll!</p>
                            <?php else: ?>
                                <i class="fas fa-tag fa-3x mb-3"></i>
                                <h2 class="mb-0"><?php echo formatPrice($skill['price_per_hour']); ?></h2>
                                <p class="mb-0">per hour</p>
                            <?php endif; ?>
                        </div>
                        
                        <form method="POST" id="enrollForm">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            
                            <?php if ($skill['is_free']): ?>
                                <button type="submit" class="btn enroll-btn-free w-100 enroll-btn">
                                    <i class="fas fa-check-circle me-2"></i>Enroll for Free
                                </button>
                                <p class="text-center text-muted mt-3 mb-0 small">
                                    <i class="fas fa-bolt me-1"></i>Instant access after enrollment
                                </p>
                            <?php else: ?>
                                <button type="submit" class="btn enroll-btn-paid w-100 enroll-btn">
                                    <i class="fas fa-paper-plane me-2"></i>Request Enrollment
                                </button>
                                <p class="text-center text-muted mt-3 mb-0 small">
                                    <i class="fas fa-clock me-1"></i>Pending tutor approval
                                </p>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Tutor Info -->
                <div class="tutor-card mb-4">
                    <h6 class="mb-3">
                        <i class="fas fa-user-tie me-2"></i>Your Instructor
                    </h6>
                    <div class="d-flex align-items-center mb-3">
                        <?php if ($skill['profile_photo']): ?>
                            <img src="<?php echo escape($skill['profile_photo']); ?>" 
                                 alt="<?php echo escape($skill['first_name']); ?>"
                                 style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover;"
                                 class="me-3">
                        <?php else: ?>
                            <div style="width: 60px; height: 60px; border-radius: 50%;" 
                                 class="bg-primary d-flex align-items-center justify-content-center me-3">
                                <i class="fas fa-user fa-2x text-white"></i>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h6 class="mb-0"><?php echo escape($skill['first_name'] . ' ' . $skill['last_name']); ?></h6>
                            <small class="text-muted">Course Instructor</small>
                        </div>
                    </div>
                    <a href="profile.php?id=<?php echo $skill['tutor_id']; ?>" 
                       class="btn btn-outline-primary btn-sm w-100 mb-2">
                        <i class="fas fa-user me-2"></i>View Profile
                    </a>
                    <a href="conversation.php?user=<?php echo $skill['tutor_id']; ?>" 
                       class="btn btn-outline-success btn-sm w-100">
                        <i class="fas fa-envelope me-2"></i>Message Instructor
                    </a>
                </div>

                <!-- Course Details -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h6 class="mb-3">
                            <i class="fas fa-info-circle me-2 text-info"></i>Course Details
                        </h6>
                        <p class="mb-2">
                            <strong>Availability:</strong><br>
                            <small class="text-muted"><?php echo nl2br(escape($skill['availability'])); ?></small>
                        </p>
                        <hr>
                        <p class="mb-2">
                            <strong>Qualifications:</strong><br>
                            <small class="text-muted"><?php echo nl2br(escape(substr($skill['qualifications'], 0, 150))); ?>...</small>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Confirm enrollment
        document.getElementById('enrollForm').addEventListener('submit', function(e) {
            <?php if ($skill['is_free']): ?>
                if (!confirm('Enroll in this free course?')) {
                    e.preventDefault();
                }
            <?php else: ?>
                if (!confirm('Submit enrollment request? You will need to arrange payment with the tutor.')) {
                    e.preventDefault();
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>