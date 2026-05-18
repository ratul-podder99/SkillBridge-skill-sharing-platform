<?php
session_start();
require_once 'config.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    setFlashMessage('warning', 'Please log in to access course materials.');
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
               c.category_name
        FROM skill_offerings so
        JOIN users u ON so.tutor_id = u.user_id
        JOIN categories c ON so.category_id = c.category_id
        WHERE so.skill_id = ?
    ");
    $skillStmt->execute([$skillId]);
    $skill = $skillStmt->fetch();
    
    if (!$skill) {
        setFlashMessage('error', 'Skill not found.');
        redirect('browse-skills.php');
    }
    
    // Check if user has premium content
    if (!$skill['has_premium_content']) {
        setFlashMessage('info', 'This course does not have premium content.');
        redirect('skill-details.php?id=' . $skillId);
    }
    
    // Check if user has access to this course
    $hasAccess = false;
    $enrollmentStatus = null;
    $isTutor = false;

    // Check if user is the tutor
    if ($userId == $skill['tutor_id']) {
        $hasAccess = true;
        $isTutor = true;
    } else {
        // Check enrollment status
        $enrollmentStmt = $db->prepare("
            SELECT * FROM enrollments 
            WHERE skill_id = ? AND student_id = ?
        ");
        $enrollmentStmt->execute([$skillId, $userId]);
        $enrollment = $enrollmentStmt->fetch();
        
        if ($enrollment) {
            $enrollmentStatus = [
                'payment_status' => $enrollment['payment_status'],
                'access_granted' => $enrollment['access_granted']
            ];
            
            // Check: Tutor approved (payment_status = 'completed') AND access granted
            if ($enrollment['payment_status'] === 'completed' && $enrollment['access_granted']) {
                $hasAccess = true;
            }
        }
    }

    // If no access, show appropriate message and redirect
    if (!$hasAccess) {
        if (!$enrollment) {
            setFlashMessage('error', 'You are not enrolled in this course.');
            redirect('skill-details.php?id=' . $skillId);
        } elseif ($enrollmentStatus['payment_status'] === 'pending') {
            setFlashMessage('warning', 'Your enrollment is pending tutor approval. Please wait.');
            redirect('my-enrollments.php');
        } elseif ($enrollmentStatus['payment_status'] === 'failed') {
            setFlashMessage('error', 'Your enrollment was rejected by the tutor.');
            redirect('my-enrollments.php');
        } else {
            setFlashMessage('error', 'You do not have access to this course yet. Please contact the tutor.');
            redirect('skill-details.php?id=' . $skillId);
        }
    }
    
} catch (PDOException $e) {
    error_log("Course Materials Error: " . $e->getMessage());
    setFlashMessage('error', 'Failed to load course materials.');
    redirect('browse-skills.php');
}

$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Materials - <?php echo escape($skill['skill_title']); ?> - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .premium-header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
        }
        
        .material-card {
            border: none;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .material-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        }
        
        .material-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .premium-badge {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            display: inline-block;
            font-weight: bold;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }
        
        .video-container {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            overflow: hidden;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        }
        
        .video-container iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: none;
        }
        
        .tutor-info {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            padding: 1.5rem;
            border-radius: 12px;
            border-left: 4px solid #0ea5e9;
        }
        
        .download-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .download-btn:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
            color: white;
        }
        
        .no-content-message {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px dashed #f59e0b;
            padding: 3rem;
            border-radius: 12px;
            text-align: center;
        }
        
        .back-link {
            color: white;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .back-link:hover {
            color: #fbbf24;
            transform: translateX(-5px);
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
                <a class="nav-link" href="profile.php">Profile</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Premium Header -->
    <div class="premium-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <a href="skill-details.php?id=<?php echo $skillId; ?>" class="back-link d-inline-flex align-items-center mb-3">
                        <i class="fas fa-arrow-left me-2"></i>Back to Course Details
                    </a>
                    <h1 class="mb-2">
                        <i class="fas fa-crown me-3"></i><?php echo escape($skill['skill_title']); ?>
                    </h1>
                    <p class="mb-0 fs-5">Premium Course Materials</p>
                </div>
                <div class="col-md-4 text-end">
                    <span class="premium-badge">
                        <i class="fas fa-star me-2"></i>Premium Access
                    </span>
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

        <div class="row g-4">
            <!-- Tutor Info Sidebar -->
            <div class="col-lg-4">
                <div class="tutor-info mb-4">
                    <h5 class="mb-3">
                        <i class="fas fa-chalkboard-teacher me-2"></i>Your Instructor
                    </h5>
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

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h6 class="card-title">
                            <i class="fas fa-info-circle me-2 text-info"></i>Course Info
                        </h6>
                        <hr>
                        <p class="mb-2">
                            <strong>Category:</strong><br>
                            <span class="badge bg-primary"><?php echo escape($skill['category_name']); ?></span>
                        </p>
                        <p class="mb-2">
                            <strong>Price:</strong><br>
                            <?php if ($skill['is_free']): ?>
                                <span class="badge bg-success">FREE</span>
                            <?php else: ?>
                                <span class="fs-5 fw-bold text-primary">
                                    <?php echo formatPrice($skill['price_per_hour']); ?>/hr
                                </span>
                            <?php endif; ?>
                        </p>
                        <p class="mb-0">
                            <strong>Availability:</strong><br>
                            <small class="text-muted"><?php echo nl2br(escape($skill['availability'])); ?></small>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="col-lg-8">
                
                <?php if ($isTutor): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Instructor View:</strong> You're viewing this as the course instructor. 
                        Students enrolled in your course will see the same materials.
                    </div>
                <?php endif; ?>

                <!-- Course Materials PDF -->
                <?php if (!empty($skill['materials_pdf'])): ?>
                    <div class="material-card card mb-4">
                        <div class="card-body text-center p-5">
                            <div class="material-icon text-success">
                                <i class="fas fa-file-pdf"></i>
                            </div>
                            <h4 class="mb-3">Course Materials PDF</h4>
                            <p class="text-muted mb-4">
                                Complete course notes, study materials, and worksheets
                            </p>
                            <a href="<?php echo escape($skill['materials_pdf']); ?>" 
                               class="download-btn" 
                               download
                               target="_blank">
                                <i class="fas fa-download me-2"></i>Download Materials
                            </a>
                            <div class="mt-3">
                                <a href="<?php echo escape($skill['materials_pdf']); ?>" 
                                   target="_blank"
                                   class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-eye me-2"></i>View in Browser
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Course Video -->
                <?php if (!empty($skill['video_url'])): ?>
                    <div class="material-card card mb-4">
                        <div class="card-body p-4">
                            <h4 class="mb-3">
                                <i class="fas fa-video text-danger me-2"></i>Course Video
                            </h4>
                            <div class="video-container mb-3">
                                <?php
                                $videoUrl = $skill['video_url'];
                                
                                // Convert YouTube watch URL to embed URL
                                if (strpos($videoUrl, 'youtube.com/watch') !== false) {
                                    parse_str(parse_url($videoUrl, PHP_URL_QUERY), $params);
                                    if (isset($params['v'])) {
                                        $videoUrl = 'https://www.youtube.com/embed/' . $params['v'];
                                    }
                                } elseif (strpos($videoUrl, 'youtu.be/') !== false) {
                                    $videoId = substr(parse_url($videoUrl, PHP_URL_PATH), 1);
                                    $videoUrl = 'https://www.youtube.com/embed/' . $videoId;
                                }
                                
                                // Check if it's embeddable
                                if (strpos($videoUrl, 'youtube.com/embed') !== false || 
                                    strpos($videoUrl, 'drive.google.com') !== false ||
                                    strpos($videoUrl, 'vimeo.com') !== false): ?>
                                    <iframe src="<?php echo escape($videoUrl); ?>" 
                                            allowfullscreen>
                                    </iframe>
                                <?php else: ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        This video cannot be embedded. 
                                        <a href="<?php echo escape($skill['video_url']); ?>" 
                                           target="_blank" 
                                           class="alert-link">
                                            Click here to watch
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-center">
                                <a href="<?php echo escape($skill['video_url']); ?>" 
                                   target="_blank"
                                   class="btn btn-outline-danger">
                                    <i class="fas fa-external-link-alt me-2"></i>Open Video in New Tab
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Roadmap PDF (if exists) -->
                <?php if (!empty($skill['roadmap_pdf'])): ?>
                    <div class="material-card card mb-4">
                        <div class="card-body text-center p-4">
                            <div class="material-icon text-primary">
                                <i class="fas fa-map"></i>
                            </div>
                            <h5 class="mb-3">Course Roadmap</h5>
                            <p class="text-muted mb-4">
                                View the complete course structure and syllabus
                            </p>
                            <a href="<?php echo escape($skill['roadmap_pdf']); ?>" 
                               class="btn btn-primary" 
                               target="_blank">
                                <i class="fas fa-eye me-2"></i>View Roadmap
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- No Content Message -->
                <?php if (empty($skill['materials_pdf']) && empty($skill['video_url']) && empty($skill['roadmap_pdf'])): ?>
                    <div class="no-content-message">
                        <i class="fas fa-inbox fa-4x text-warning mb-3"></i>
                        <h4>No Materials Available Yet</h4>
                        <p class="text-muted mb-0">
                            The instructor hasn't uploaded any premium materials yet. 
                            Check back soon or contact the instructor for more information.
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Additional Resources -->
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-question-circle me-2 text-info"></i>Need Help?
                        </h5>
                        <p class="card-text">
                            If you have questions about the course materials or need clarification, 
                            don't hesitate to reach out to your instructor.
                        </p>
                        <a href="conversation.php?user=<?php echo $skill['tutor_id']; ?>" 
                           class="btn btn-success">
                            <i class="fas fa-comment me-2"></i>Ask a Question
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>