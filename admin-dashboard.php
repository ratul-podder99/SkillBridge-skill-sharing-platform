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

try {
    $db = getDB();
    
    // Get overall statistics
    $stats = [];
    
    // Total users
    $userCountStmt = $db->query("SELECT COUNT(*) as total FROM users");
    $stats['total_users'] = $userCountStmt->fetchColumn();
    
    // Users by type
    $userTypesStmt = $db->query("
        SELECT user_type, COUNT(*) as count 
        FROM users 
        GROUP BY user_type
    ");
    $stats['user_types'] = $userTypesStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Total skills
    $skillCountStmt = $db->query("SELECT COUNT(*) as total FROM skill_offerings");
    $stats['total_skills'] = $skillCountStmt->fetchColumn();
    
    // Active skills
    $activeSkillsStmt = $db->query("SELECT COUNT(*) as total FROM skill_offerings WHERE is_active = TRUE");
    $stats['active_skills'] = $activeSkillsStmt->fetchColumn();
    
    // Total messages
    $messageCountStmt = $db->query("SELECT COUNT(*) as total FROM messages");
    $stats['total_messages'] = $messageCountStmt->fetchColumn();
    
    // Total reviews
    $reviewCountStmt = $db->query("SELECT COUNT(*) as total FROM reviews");
    $stats['total_reviews'] = $reviewCountStmt->fetchColumn();
    
    // Unverified tutors
    $unverifiedStmt = $db->query("
        SELECT COUNT(*) as total 
        FROM users 
        WHERE (user_type = 'tutor' OR user_type = 'both') 
        AND is_verified = FALSE
    ");
    $stats['unverified_tutors'] = $unverifiedStmt->fetchColumn();
    
    // Enrollment statistics
    $enrollmentStatsStmt = $db->query("
        SELECT 
            COUNT(*) as total_enrollments,
            SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending_enrollments,
            SUM(CASE WHEN payment_status = 'completed' THEN 1 ELSE 0 END) as approved_enrollments
        FROM enrollments
    ");
    $enrollmentStats = $enrollmentStatsStmt->fetch();
    $stats['total_enrollments'] = $enrollmentStats['total_enrollments'];
    $stats['pending_enrollments'] = $enrollmentStats['pending_enrollments'];
    $stats['approved_enrollments'] = $enrollmentStats['approved_enrollments'];
    
    // Recent users (last 7 days)
    $recentUsersStmt = $db->query("
        SELECT COUNT(*) as total 
        FROM users 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stats['recent_users'] = $recentUsersStmt->fetchColumn();
    
    // Recent skills (last 7 days)
    $recentSkillsStmt = $db->query("
        SELECT COUNT(*) as total 
        FROM skill_offerings 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stats['recent_skills'] = $recentSkillsStmt->fetchColumn();
    
    // Get recent users
    $recentUsersListStmt = $db->query("
        SELECT user_id, first_name, last_name, email, user_type, created_at, is_verified
        FROM users 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recentUsersList = $recentUsersListStmt->fetchAll();
    
    // Get recent skills
    $recentSkillsListStmt = $db->query("
        SELECT so.skill_id, so.skill_title, so.created_at, so.is_active,
               u.first_name, u.last_name,
               c.category_name
        FROM skill_offerings so
        JOIN users u ON so.tutor_id = u.user_id
        JOIN categories c ON so.category_id = c.category_id
        ORDER BY so.created_at DESC 
        LIMIT 5
    ");
    $recentSkillsList = $recentSkillsListStmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Admin Dashboard Error: " . $e->getMessage());
    $stats = [];
    $recentUsersList = [];
    $recentSkillsList = [];
}

$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .stat-card {
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
        .stat-card.blue { border-color: #667eea; }
        .stat-card.green { border-color: #28a745; }
        .stat-card.warning { border-color: #ffc107; }
        .stat-card.purple { border-color: #8b5cf6; }
        .stat-card.red { border-color: #dc3545; }
        .stat-card.cyan { border-color: #17a2b8; }
        
        .admin-card {
            border: none;
            border-radius: 12px;
            transition: all 0.3s;
        }
        
        .admin-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .icon-wrapper {
            width: 70px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .text-purple {
            color: #8b5cf6 !important;
        }
        .bg-purple {
            background-color: #8b5cf6 !important;
        }
        .btn-purple {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
            border: none;
        }
        .btn-purple:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
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
                <a class="nav-link active" href="admin-dashboard.php">
                    <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                </a>
                <a class="nav-link" href="admin-users.php">
                    <i class="fas fa-users me-1"></i>Users
                </a>
                <a class="nav-link" href="admin-skills.php">
                    <i class="fas fa-book me-1"></i>Skills
                </a>
                <a class="nav-link" href="admin-enrollments.php">
                    <i class="fas fa-clipboard-check me-1"></i>Enrollments
                    <?php if ($stats['pending_enrollments'] > 0): ?>
                        <span class="badge bg-warning text-dark"><?php echo $stats['pending_enrollments']; ?></span>
                    <?php endif; ?>
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
            <div>
                <h2 class="mb-1">
                    <i class="fas fa-shield-alt text-danger me-2"></i>Admin Dashboard
                </h2>
                <p class="text-muted mb-0">Welcome back, <?php echo escape($_SESSION['first_name']); ?>!</p>
            </div>
            <div>
                <span class="badge bg-success fs-6">
                    <i class="fas fa-clock me-1"></i><?php echo date('F j, Y - g:i A'); ?>
                </span>
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

        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card stat-card blue shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-users fa-3x text-primary mb-3"></i>
                        <h3 class="mb-1"><?php echo number_format($stats['total_users']); ?></h3>
                        <p class="text-muted mb-0">Total Users</p>
                        <small class="text-success">
                            <i class="fas fa-arrow-up me-1"></i>
                            <?php echo $stats['recent_users']; ?> this week
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card green shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-book-open fa-3x text-success mb-3"></i>
                        <h3 class="mb-1"><?php echo number_format($stats['total_skills']); ?></h3>
                        <p class="text-muted mb-0">Total Skills</p>
                        <small class="text-success">
                            <i class="fas fa-arrow-up me-1"></i>
                            <?php echo $stats['recent_skills']; ?> this week
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card warning shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-check-circle fa-3x text-warning mb-3"></i>
                        <h3 class="mb-1"><?php echo number_format($stats['active_skills']); ?></h3>
                        <p class="text-muted mb-0">Active Skills</p>
                        <small class="text-muted">
                            <?php echo number_format($stats['total_skills'] - $stats['active_skills']); ?> inactive
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card red shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-user-clock fa-3x text-danger mb-3"></i>
                        <h3 class="mb-1"><?php echo number_format($stats['unverified_tutors']); ?></h3>
                        <p class="text-muted mb-0">Unverified Tutors</p>
                        <?php if ($stats['unverified_tutors'] > 0): ?>
                            <a href="admin-users.php?filter=unverified" class="btn btn-sm btn-danger mt-2">
                                Review Now
                            </a>
                        <?php else: ?>
                            <small class="text-success">All verified!</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Stats Row -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card stat-card purple shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-clipboard-check fa-3x text-purple mb-3"></i>
                        <h3 class="mb-1"><?php echo number_format($stats['total_enrollments']); ?></h3>
                        <p class="text-muted mb-0">Total Enrollments</p>
                        <?php if ($stats['pending_enrollments'] > 0): ?>
                            <small class="text-warning">
                                <i class="fas fa-clock me-1"></i>
                                <?php echo $stats['pending_enrollments']; ?> pending
                            </small>
                        <?php else: ?>
                            <small class="text-muted">All processed</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card purple shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-envelope fa-3x text-info mb-3"></i>
                        <h3 class="mb-1"><?php echo number_format($stats['total_messages']); ?></h3>
                        <p class="text-muted mb-0">Total Messages</p>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card cyan shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-star fa-3x text-warning mb-3"></i>
                        <h3 class="mb-1"><?php echo number_format($stats['total_reviews']); ?></h3>
                        <p class="text-muted mb-0">Total Reviews</p>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card blue shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-chart-line fa-3x text-primary mb-3"></i>
                        <h3 class="mb-1">
                            <?php 
                            $tutorCount = $stats['user_types']['tutor'] ?? 0;
                            $bothCount = $stats['user_types']['both'] ?? 0;
                            echo number_format($tutorCount + $bothCount); 
                            ?>
                        </h3>
                        <p class="text-muted mb-0">Total Tutors</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <h4 class="mb-3"><i class="fas fa-bolt me-2"></i>Quick Actions</h4>
        <div class="row g-4 mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="card admin-card h-100 shadow-sm border-0">
                    <div class="card-body text-center">
                        <div class="icon-wrapper bg-primary bg-opacity-10 rounded-circle p-3 d-inline-block mb-3">
                            <i class="fas fa-users fa-2x text-primary"></i>
                        </div>
                        <h5 class="card-title">Manage Users</h5>
                        <p class="card-text text-muted">View and manage all registered users</p>
                        <a href="admin-users.php" class="btn btn-primary">Go to Users</a>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card admin-card h-100 shadow-sm border-0">
                    <div class="card-body text-center">
                        <div class="icon-wrapper bg-success bg-opacity-10 rounded-circle p-3 d-inline-block mb-3">
                            <i class="fas fa-book-open fa-2x text-success"></i>
                        </div>
                        <h5 class="card-title">Manage Skills</h5>
                        <p class="card-text text-muted">Review and manage all skill offerings</p>
                        <a href="admin-skills.php" class="btn btn-success">Go to Skills</a>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card admin-card h-100 shadow-sm border-0">
                    <div class="card-body text-center">
                        <div class="icon-wrapper bg-purple bg-opacity-10 rounded-circle p-3 d-inline-block mb-3">
                            <i class="fas fa-clipboard-check fa-2x text-purple"></i>
                        </div>
                        <h5 class="card-title">Manage Enrollments</h5>
                        <p class="card-text text-muted">Approve student enrollment requests</p>
                        <a href="admin-enrollments.php" class="btn btn-purple">
                            Go to Enrollments
                            <?php if ($stats['pending_enrollments'] > 0): ?>
                                <span class="badge bg-warning text-dark ms-1"><?php echo $stats['pending_enrollments']; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="card admin-card h-100 shadow-sm border-0">
                    <div class="card-body text-center">
                        <div class="icon-wrapper bg-danger bg-opacity-10 rounded-circle p-3 d-inline-block mb-3">
                            <i class="fas fa-user-check fa-2x text-danger"></i>
                        </div>
                        <h5 class="card-title">Verify Tutors</h5>
                        <p class="card-text text-muted">Review and verify tutor accounts</p>
                        <a href="verify-tutor.php" class="btn btn-danger">
                            Verify Now
                            <?php if ($stats['unverified_tutors'] > 0): ?>
                                <span class="badge bg-light text-dark ms-1"><?php echo $stats['unverified_tutors']; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="row g-4">
            <!-- Recent Users -->
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-user-plus me-2"></i>Recent Users
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Joined</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentUsersList as $user): ?>
                                    <tr>
                                        <td>
                                            <a href="admin-users.php?id=<?php echo $user['user_id']; ?>" 
                                               class="text-decoration-none">
                                                <?php echo escape($user['first_name'] . ' ' . $user['last_name']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <?php echo escape(ucfirst($user['user_type'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($user['is_verified']): ?>
                                                <span class="badge bg-success">Verified</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small><?php echo timeAgo($user['created_at']); ?></small></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer text-center">
                            <a href="admin-users.php" class="btn btn-sm btn-outline-primary">
                                View All Users <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Skills -->
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-book-medical me-2"></i>Recent Skills
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Skill</th>
                                        <th>Tutor</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentSkillsList as $skill): ?>
                                    <tr>
                                        <td>
                                            <a href="skill-details.php?id=<?php echo $skill['skill_id']; ?>" 
                                               class="text-decoration-none"
                                               target="_blank">
                                                <?php echo escape(substr($skill['skill_title'], 0, 30)); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <small>
                                                <?php echo escape($skill['first_name'] . ' ' . $skill['last_name']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($skill['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><small><?php echo timeAgo($skill['created_at']); ?></small></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer text-center">
                            <a href="admin-skills.php" class="btn btn-sm btn-outline-success">
                                View All Skills <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>