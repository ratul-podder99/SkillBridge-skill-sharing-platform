<?php
session_start();
require_once 'config.php';

if (!isLoggedIn()) {
    setFlashMessage('warning', 'Please log in to access admin panel.');
    redirect('login.php');
}

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    setFlashMessage('error', 'Access denied. Admin privileges required.');
    redirect('dashboard.php');
}

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

try {
    $db = getDB();
    
    $whereConditions = [];
    $params = [];
    
    if ($filter === 'pending') {
        $whereConditions[] = "e.payment_status = 'pending'";
    } elseif ($filter === 'approved') {
        $whereConditions[] = "e.payment_status = 'completed'";
    } elseif ($filter === 'rejected') {
        $whereConditions[] = "e.payment_status = 'failed'";
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $enrollmentsQuery = "
        SELECT e.*,
               so.skill_title,
               so.price_per_hour,
               so.is_free,
               u.first_name,
               u.last_name,
               u.email,
               u.profile_photo,
               tutor.first_name as tutor_first_name,
               tutor.last_name as tutor_last_name
        FROM enrollments e
        JOIN skill_offerings so ON e.skill_id = so.skill_id
        JOIN users u ON e.student_id = u.user_id
        JOIN users tutor ON so.tutor_id = tutor.user_id
        $whereClause
        ORDER BY e.enrolled_at DESC
    ";
    
    $enrollmentsStmt = $db->prepare($enrollmentsQuery);
    $enrollmentsStmt->execute($params);
    $enrollments = $enrollmentsStmt->fetchAll();
    
    $statsStmt = $db->query("
        SELECT 
            COUNT(*) as total_enrollments,
            SUM(CASE WHEN e.payment_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN e.payment_status = 'completed' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN e.payment_status = 'failed' THEN 1 ELSE 0 END) as rejected_count
        FROM enrollments e
    ");
    $stats = $statsStmt->fetch();
    
} catch (PDOException $e) {
    error_log("Admin Enrollments Error: " . $e->getMessage());
    $enrollments = [];
    $stats = [
        'total_enrollments' => 0,
        'pending_count' => 0,
        'approved_count' => 0,
        'rejected_count' => 0
    ];
}

$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Enrollments - Admin - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        .stat-card { border-left: 4px solid; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 6px 16px rgba(0,0,0,0.1); }
        .enrollment-card { border: none; border-radius: 12px; margin-bottom: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .enrollment-card.pending { border-left: 4px solid #f59e0b; }
        .enrollment-card.approved { border-left: 4px solid #10b981; }
        .enrollment-card.rejected { border-left: 4px solid #ef4444; }
        .student-avatar { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; }
        .badge-pending { background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); }
        .badge-approved { background: linear-gradient(135deg, #34d399 0%, #10b981 100%); }
        .badge-rejected { background: linear-gradient(135deg, #f87171 0%, #ef4444 100%); }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-bridge me-2"></i><?php echo SITE_NAME; ?> - Admin
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="admin-dashboard.php">Dashboard</a>
                <a class="nav-link" href="admin-users.php">Users</a>
                <a class="nav-link" href="admin-skills.php">Skills</a>
                <a class="nav-link active" href="admin-enrollments.php">View Enrollments</a>
                <a class="nav-link" href="admin-reports.php">Reports</a>
                <a class="nav-link" href="dashboard.php">User View</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="page-header">
        <div class="container">
            <h1 class="mb-2"><i class="fas fa-eye me-3"></i>View All Enrollments</h1>
            <p class="mb-0">Monitor student enrollments across the platform (View Only)</p>
        </div>
    </div>

    <div class="container my-4">
        <?php if ($flashMessage): ?>
            <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show">
                <?php echo escape($flashMessage['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Admin View:</strong> You can monitor all enrollments. Tutors handle approval/rejection.
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card stat-card shadow-sm" style="border-left-color: #667eea;">
                    <div class="card-body text-center">
                        <i class="fas fa-users fa-2x text-primary mb-2"></i>
                        <h3 class="mb-0"><?php echo number_format($stats['total_enrollments']); ?></h3>
                        <small class="text-muted">Total Enrollments</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card shadow-sm" style="border-left-color: #f59e0b;">
                    <div class="card-body text-center">
                        <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                        <h3 class="mb-0"><?php echo number_format($stats['pending_count']); ?></h3>
                        <small class="text-muted">Pending Approval</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card shadow-sm" style="border-left-color: #10b981;">
                    <div class="card-body text-center">
                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                        <h3 class="mb-0"><?php echo number_format($stats['approved_count']); ?></h3>
                        <small class="text-muted">Approved</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card shadow-sm" style="border-left-color: #ef4444;">
                    <div class="card-body text-center">
                        <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                        <h3 class="mb-0"><?php echo number_format($stats['rejected_count']); ?></h3>
                        <small class="text-muted">Rejected</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="btn-group" role="group">
                    <a href="admin-enrollments.php?filter=all" 
                       class="btn <?php echo $filter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        All (<?php echo $stats['total_enrollments']; ?>)
                    </a>
                    <a href="admin-enrollments.php?filter=pending" 
                       class="btn <?php echo $filter === 'pending' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                        Pending (<?php echo $stats['pending_count']; ?>)
                    </a>
                    <a href="admin-enrollments.php?filter=approved" 
                       class="btn <?php echo $filter === 'approved' ? 'btn-success' : 'btn-outline-success'; ?>">
                        Approved (<?php echo $stats['approved_count']; ?>)
                    </a>
                    <a href="admin-enrollments.php?filter=rejected" 
                       class="btn <?php echo $filter === 'rejected' ? 'btn-danger' : 'btn-outline-danger'; ?>">
                        Rejected (<?php echo $stats['rejected_count']; ?>)
                    </a>
                </div>
            </div>
        </div>

        <?php if (empty($enrollments)): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">No Enrollments Found</h4>
                    <p class="text-muted mb-0">No enrollments in the system yet.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($enrollments as $enrollment): ?>
                <div class="enrollment-card card <?php echo $enrollment['payment_status'] === 'pending' ? 'pending' : ($enrollment['payment_status'] === 'completed' ? 'approved' : 'rejected'); ?>">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-3">
                                <div class="d-flex align-items-center">
                                    <?php if ($enrollment['profile_photo']): ?>
                                        <img src="<?php echo escape($enrollment['profile_photo']); ?>" 
                                             alt="<?php echo escape($enrollment['first_name']); ?>"
                                             class="student-avatar me-3">
                                    <?php else: ?>
                                        <div class="student-avatar bg-secondary d-flex align-items-center justify-content-center me-3">
                                            <i class="fas fa-user text-white"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <h6 class="mb-0"><?php echo escape($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?></h6>
                                        <small class="text-muted"><?php echo escape($enrollment['email']); ?></small>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <h6 class="mb-1"><?php echo escape($enrollment['skill_title']); ?></h6>
                                <small class="text-muted">
                                    Tutor: <?php echo escape($enrollment['tutor_first_name'] . ' ' . $enrollment['tutor_last_name']); ?>
                                </small>
                                <br>
                                <small class="text-muted">
                                    <?php if ($enrollment['is_free']): ?>
                                        <span class="badge bg-success">FREE</span>
                                    <?php else: ?>
                                        <strong><?php echo formatPrice($enrollment['payment_amount']); ?></strong>
                                    <?php endif; ?>
                                </small>
                            </div>

                            <div class="col-md-3">
                                <?php if ($enrollment['payment_status'] === 'pending'): ?>
                                    <span class="badge badge-pending">
                                        <i class="fas fa-clock me-1"></i>Pending Tutor Approval
                                    </span>
                                <?php elseif ($enrollment['payment_status'] === 'completed'): ?>
                                    <span class="badge badge-approved">
                                        <i class="fas fa-check-circle me-1"></i>Approved by Tutor
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-rejected">
                                        <i class="fas fa-times-circle me-1"></i>Rejected by Tutor
                                    </span>
                                <?php endif; ?>
                                <br>
                                <small class="text-muted">Enrolled: <?php echo timeAgo($enrollment['enrolled_at']); ?></small>
                                <?php if ($enrollment['payment_date']): ?>
                                    <br><small class="text-muted">Approved: <?php echo timeAgo($enrollment['payment_date']); ?></small>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-3 text-end">
                                <span class="text-muted">
                                    <i class="fas fa-eye"></i> View Only
                                </span>
                                <br>
                                <small class="text-muted">Tutor manages this</small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>