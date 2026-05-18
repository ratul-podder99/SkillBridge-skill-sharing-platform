<?php
session_start();
require_once 'config.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    setFlashMessage('warning', 'Please log in to access admin panel.');
    redirect('login.php');
}

// Check if user is admin
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    setFlashMessage('error', 'Access denied. Admin privileges required.');
    redirect('dashboard.php');
}

// Handle verification action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid security token.');
    } else {
        $action = $_POST['action'];
        $userId = (int)$_POST['user_id'];
        
        try {
            $db = getDB();
            
            if ($action === 'verify') {
                $stmt = $db->prepare("UPDATE users SET is_verified = TRUE WHERE user_id = ?");
                $stmt->execute([$userId]);
                setFlashMessage('success', 'Tutor verified successfully!');
            } elseif ($action === 'reject') {
                // You could also deactivate or send notification
                $stmt = $db->prepare("UPDATE users SET is_verified = FALSE WHERE user_id = ?");
                $stmt->execute([$userId]);
                setFlashMessage('success', 'Tutor verification rejected!');
            }
            
            redirect('verify-tutor.php');
            
        } catch (PDOException $e) {
            error_log("Verify Tutor Error: " . $e->getMessage());
            setFlashMessage('error', 'Failed to perform action.');
        }
    }
}

// Get tutor ID if viewing specific tutor
$viewTutorId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$viewTutor = null;

try {
    $db = getDB();
    
    // Get unverified tutors
    $unverifiedStmt = $db->query("
        SELECT u.*,
               COUNT(DISTINCT so.skill_id) as skills_count,
               COUNT(DISTINCT r.review_id) as reviews_count,
               COALESCE(AVG(r.rating), 0) as avg_rating
        FROM users u
        LEFT JOIN skill_offerings so ON u.user_id = so.tutor_id
        LEFT JOIN reviews r ON u.user_id = r.user_id
        WHERE (u.user_type = 'tutor' OR u.user_type = 'both')
        AND u.is_verified = FALSE
        GROUP BY u.user_id
        ORDER BY u.created_at DESC
    ");
    $unverifiedTutors = $unverifiedStmt->fetchAll();
    
    // Get verified tutors
    $verifiedStmt = $db->query("
        SELECT u.*,
               COUNT(DISTINCT so.skill_id) as skills_count,
               COUNT(DISTINCT r.review_id) as reviews_count,
               COALESCE(AVG(r.rating), 0) as avg_rating
        FROM users u
        LEFT JOIN skill_offerings so ON u.user_id = so.tutor_id
        LEFT JOIN reviews r ON u.user_id = r.user_id
        WHERE (u.user_type = 'tutor' OR u.user_type = 'both')
        AND u.is_verified = TRUE
        GROUP BY u.user_id
        ORDER BY u.created_at DESC
        LIMIT 10
    ");
    $verifiedTutors = $verifiedStmt->fetchAll();
    
    // If viewing specific tutor
    if ($viewTutorId > 0) {
        $tutorStmt = $db->prepare("
            SELECT u.*,
                   COUNT(DISTINCT so.skill_id) as skills_count,
                   COUNT(DISTINCT r.review_id) as reviews_count,
                   COALESCE(AVG(r.rating), 0) as avg_rating
            FROM users u
            LEFT JOIN skill_offerings so ON u.user_id = so.tutor_id
            LEFT JOIN reviews r ON u.user_id = r.user_id
            WHERE u.user_id = ?
            GROUP BY u.user_id
        ");
        $tutorStmt->execute([$viewTutorId]);
        $viewTutor = $tutorStmt->fetch();
        
        // Get tutor's skills
        if ($viewTutor) {
            $skillsStmt = $db->prepare("
                SELECT so.*, c.category_name
                FROM skill_offerings so
                JOIN categories c ON so.category_id = c.category_id
                WHERE so.tutor_id = ?
                ORDER BY so.created_at DESC
            ");
            $skillsStmt->execute([$viewTutorId]);
            $tutorSkills = $skillsStmt->fetchAll();
        }
    }
    
    // Statistics
    $statsStmt = $db->query("
        SELECT 
            COUNT(*) as total_tutors,
            SUM(CASE WHEN is_verified = TRUE THEN 1 ELSE 0 END) as verified_count,
            SUM(CASE WHEN is_verified = FALSE THEN 1 ELSE 0 END) as unverified_count
        FROM users
        WHERE user_type = 'tutor' OR user_type = 'both'
    ");
    $stats = $statsStmt->fetch();
    
} catch (PDOException $e) {
    error_log("Verify Tutor Page Error: " . $e->getMessage());
    $unverifiedTutors = [];
    $verifiedTutors = [];
    $stats = ['total_tutors' => 0, 'verified_count' => 0, 'unverified_count' => 0];
}

$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Tutors - Admin - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .tutor-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .tutor-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
        .user-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-bridge me-2"></i><?php echo SITE_NAME; ?> - Admin
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="admin-dashboard.php">
                    <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                </a>
                <a class="nav-link" href="admin-users.php">
                    <i class="fas fa-users me-1"></i>Users
                </a>
                <a class="nav-link" href="admin-skills.php">
                    <i class="fas fa-book me-1"></i>Skills
                </a>
                <a class="nav-link" href="admin-reports.php">
                    <i class="fas fa-chart-bar me-1"></i>Reports
                </a>
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-home me-1"></i>User View
                </a>
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid my-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">
                <i class="fas fa-user-check text-warning me-2"></i>Tutor Verification
            </h2>
            <a href="admin-dashboard.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>

        <!-- Flash Message -->
        <?php if ($flashMessage): ?>
            <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show">
                <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                <?php echo escape($flashMessage['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card text-center shadow-sm">
                    <div class="card-body">
                        <i class="fas fa-users fa-2x text-primary mb-2"></i>
                        <h3 class="mb-0"><?php echo number_format($stats['total_tutors']); ?></h3>
                        <p class="text-muted mb-0">Total Tutors</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center shadow-sm">
                    <div class="card-body">
                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                        <h3 class="mb-0 text-success"><?php echo number_format($stats['verified_count']); ?></h3>
                        <p class="text-muted mb-0">Verified</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center shadow-sm">
                    <div class="card-body">
                        <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                        <h3 class="mb-0 text-warning"><?php echo number_format($stats['unverified_count']); ?></h3>
                        <p class="text-muted mb-0">Pending Verification</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($viewTutor): ?>
            <!-- Detailed Tutor View -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-user-circle me-2"></i>Tutor Details
                        </h5>
                        <a href="verify-tutor.php" class="btn btn-sm btn-light">
                            <i class="fas fa-arrow-left me-1"></i>Back to List
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 text-center">
                            <?php if ($viewTutor['profile_photo']): ?>
                                <img src="<?php echo escape($viewTutor['profile_photo']); ?>" 
                                     alt="<?php echo escape($viewTutor['first_name']); ?>"
                                     class="user-avatar-large mb-3">
                            <?php else: ?>
                                <div class="user-avatar-large bg-secondary d-flex align-items-center justify-content-center mx-auto mb-3">
                                    <i class="fas fa-user fa-3x text-white"></i>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($viewTutor['is_verified']): ?>
                                <span class="badge bg-success d-block mb-2">
                                    <i class="fas fa-check-circle me-1"></i>Verified
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning d-block mb-2">
                                    <i class="fas fa-clock me-1"></i>Pending
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-9">
                            <h3><?php echo escape($viewTutor['first_name'] . ' ' . $viewTutor['last_name']); ?></h3>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <p class="mb-1">
                                        <strong>Email:</strong> 
                                        <?php echo escape($viewTutor['email']); ?>
                                    </p>
                                    <p class="mb-1">
                                        <strong>User Type:</strong> 
                                        <span class="badge bg-secondary">
                                            <?php echo ucfirst($viewTutor['user_type']); ?>
                                        </span>
                                    </p>
                                    <p class="mb-1">
                                        <strong>Status:</strong> 
                                        <?php if ($viewTutor['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-1">
                                        <strong>Skills Offered:</strong> 
                                        <span class="badge bg-primary"><?php echo $viewTutor['skills_count']; ?></span>
                                    </p>
                                    <p class="mb-1">
                                        <strong>Reviews:</strong> 
                                        <span class="badge bg-info"><?php echo $viewTutor['reviews_count']; ?></span>
                                    </p>
                                    <p class="mb-1">
                                        <strong>Average Rating:</strong> 
                                        <i class="fas fa-star text-warning"></i>
                                        <?php echo number_format($viewTutor['avg_rating'], 1); ?>/5
                                    </p>
                                </div>
                            </div>
                            
                            <?php if ($viewTutor['bio']): ?>
                                <div class="mb-3">
                                    <strong>Bio:</strong>
                                    <p class="mb-0"><?php echo nl2br(escape($viewTutor['bio'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <p class="text-muted mb-3">
                                <small>
                                    <i class="fas fa-calendar me-1"></i>
                                    Joined <?php echo timeAgo($viewTutor['created_at']); ?>
                                </small>
                            </p>
                            
                            <!-- Actions -->
                            <div class="btn-group">
                                <a href="profile.php?id=<?php echo $viewTutor['user_id']; ?>" 
                                   class="btn btn-primary"
                                   target="_blank">
                                    <i class="fas fa-eye me-1"></i>View Profile
                                </a>
                                
                                <?php if (!$viewTutor['is_verified']): ?>
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Verify this tutor?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="verify">
                                        <input type="hidden" name="user_id" value="<?php echo $viewTutor['user_id']; ?>">
                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-check me-1"></i>Verify Tutor
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Reject this tutor verification?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="user_id" value="<?php echo $viewTutor['user_id']; ?>">
                                        <button type="submit" class="btn btn-danger">
                                            <i class="fas fa-times me-1"></i>Reject
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Remove verification from this tutor?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="user_id" value="<?php echo $viewTutor['user_id']; ?>">
                                        <button type="submit" class="btn btn-warning">
                                            <i class="fas fa-times me-1"></i>Remove Verification
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tutor's Skills -->
                    <?php if (isset($tutorSkills) && !empty($tutorSkills)): ?>
                        <hr class="my-4">
                        <h5 class="mb-3">
                            <i class="fas fa-book me-2"></i>Skills Offered
                        </h5>
                        <div class="row g-3">
                            <?php foreach ($tutorSkills as $skill): ?>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title"><?php echo escape($skill['skill_title']); ?></h6>
                                            <span class="badge bg-primary mb-2">
                                                <?php echo escape($skill['category_name']); ?>
                                            </span>
                                            <span class="badge <?php echo $skill['is_active'] ? 'bg-success' : 'bg-secondary'; ?> mb-2">
                                                <?php echo $skill['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                            <p class="card-text small text-muted mb-2">
                                                <?php echo escape(substr($skill['skill_description'], 0, 100)) . '...'; ?>
                                            </p>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <strong class="text-primary">
                                                    <?php echo $skill['is_free'] ? 'FREE' : formatPrice($skill['price_per_hour']) . '/hr'; ?>
                                                </strong>
                                                <a href="skill-details.php?id=<?php echo $skill['skill_id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary"
                                                   target="_blank">
                                                    View
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Unverified Tutors -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-warning">
                    <h5 class="mb-0">
                        <i class="fas fa-clock me-2"></i>Pending Verification (<?php echo count($unverifiedTutors); ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($unverifiedTutors)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h4 class="text-muted">All Caught Up!</h4>
                            <p class="text-muted">No tutors pending verification.</p>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($unverifiedTutors as $tutor): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="card tutor-card h-100 shadow-sm">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center mb-3">
                                                <?php if ($tutor['profile_photo']): ?>
                                                    <img src="<?php echo escape($tutor['profile_photo']); ?>" 
                                                         alt="<?php echo escape($tutor['first_name']); ?>"
                                                         style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover;"
                                                         class="me-2">
                                                <?php else: ?>
                                                    <div style="width: 50px; height: 50px; border-radius: 50%;" 
                                                         class="bg-secondary d-flex align-items-center justify-content-center me-2">
                                                        <i class="fas fa-user text-white"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-0">
                                                        <?php echo escape($tutor['first_name'] . ' ' . $tutor['last_name']); ?>
                                                    </h6>
                                                    <small class="text-muted"><?php echo escape($tutor['email']); ?></small>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <span class="badge bg-secondary me-1">
                                                    <?php echo ucfirst($tutor['user_type']); ?>
                                                </span>
                                                <span class="badge bg-primary me-1">
                                                    <?php echo $tutor['skills_count']; ?> skills
                                                </span>
                                                <span class="badge bg-info">
                                                    <?php echo $tutor['reviews_count']; ?> reviews
                                                </span>
                                            </div>
                                            
                                            <p class="text-muted small mb-3">
                                                <i class="fas fa-calendar me-1"></i>
                                                Joined <?php echo timeAgo($tutor['created_at']); ?>
                                            </p>
                                            
                                            <div class="d-grid gap-2">
                                                <a href="verify-tutor.php?id=<?php echo $tutor['user_id']; ?>" 
                                                   class="btn btn-outline-primary btn-sm">
                                                    <i class="fas fa-eye me-1"></i>View Details
                                                </a>
                                                <form method="POST" onsubmit="return confirm('Verify this tutor?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="verify">
                                                    <input type="hidden" name="user_id" value="<?php echo $tutor['user_id']; ?>">
                                                    <button type="submit" class="btn btn-success btn-sm w-100">
                                                        <i class="fas fa-check me-1"></i>Verify Now
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recently Verified -->
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-check-circle me-2"></i>Recently Verified
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tutor</th>
                                    <th>Email</th>
                                    <th>Skills</th>
                                    <th>Rating</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($verifiedTutors)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            No verified tutors yet
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($verifiedTutors as $tutor): ?>
                                    <tr>
                                        <td>
                                            <?php echo escape($tutor['first_name'] . ' ' . $tutor['last_name']); ?>
                                            <i class="fas fa-check-circle text-success ms-1"></i>
                                        </td>
                                        <td><small><?php echo escape($tutor['email']); ?></small></td>
                                        <td><?php echo $tutor['skills_count']; ?></td>
                                        <td>
                                            <i class="fas fa-star text-warning"></i>
                                            <?php echo number_format($tutor['avg_rating'], 1); ?>
                                        </td>
                                        <td><small><?php echo timeAgo($tutor['created_at']); ?></small></td>
                                        <td>
                                            <a href="verify-tutor.php?id=<?php echo $tutor['user_id']; ?>" 
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>