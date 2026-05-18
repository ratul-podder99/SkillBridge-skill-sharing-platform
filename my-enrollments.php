<?php
session_start();
require_once 'config.php';

if (!isLoggedIn()) {
    setFlashMessage('warning', 'Please log in to manage enrollments.');
    redirect('login.php');
}

if ($_SESSION['user_type'] !== 'tutor' && $_SESSION['user_type'] !== 'both') {
    setFlashMessage('error', 'Only tutors can manage enrollments.');
    redirect('dashboard.php');
}

$userId = $_SESSION['user_id'];

// Handle enrollment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid security token.');
    } else {
        $action = $_POST['action'];
        $enrollmentId = (int)$_POST['enrollment_id'];
        
        try {
            $db = getDB();
            
            $verifyStmt = $db->prepare("
                SELECT e.*, so.skill_title, so.tutor_id
                FROM enrollments e
                JOIN skill_offerings so ON e.skill_id = so.skill_id
                WHERE e.enrollment_id = ? AND so.tutor_id = ?
            ");
            $verifyStmt->execute([$enrollmentId, $userId]);
            $enrollment = $verifyStmt->fetch();
            
            if (!$enrollment) {
                setFlashMessage('error', 'Invalid enrollment or access denied.');
            } else {
                if ($action === 'approve') {
                    // ONE-STEP APPROVAL
                    $updateStmt = $db->prepare("
                        UPDATE enrollments 
                        SET payment_status = 'completed',
                            access_granted = TRUE,
                            payment_date = NOW()
                        WHERE enrollment_id = ?
                    ");
                    $updateStmt->execute([$enrollmentId]);
                    setFlashMessage('success', 'Enrollment approved! Student now has access to course materials.');
                    
                } elseif ($action === 'reject') {
                    $updateStmt = $db->prepare("
                        UPDATE enrollments 
                        SET payment_status = 'failed',
                            access_granted = FALSE
                        WHERE enrollment_id = ?
                    ");
                    $updateStmt->execute([$enrollmentId]);
                    setFlashMessage('success', 'Enrollment rejected.');
                    
                } elseif ($action === 'revoke') {
                    $updateStmt = $db->prepare("
                        UPDATE enrollments 
                        SET access_granted = FALSE,
                            payment_status = 'refunded'
                        WHERE enrollment_id = ?
                    ");
                    $updateStmt->execute([$enrollmentId]);
                    setFlashMessage('success', 'Access revoked successfully.');
                }
            }
            
            redirect('manage-enrollments.php');
            
        } catch (PDOException $e) {
            error_log("Manage Enrollments Action Error: " . $e->getMessage());
            setFlashMessage('error', 'Failed to process action.');
        }
    }
}

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

try {
    $db = getDB();
    
    $whereConditions = ["so.tutor_id = ?"];
    $params = [$userId];
    
    if ($filter === 'pending') {
        $whereConditions[] = "e.payment_status = 'pending'";
    } elseif ($filter === 'approved') {
        $whereConditions[] = "e.payment_status = 'completed'";
    } elseif ($filter === 'rejected') {
        $whereConditions[] = "e.payment_status = 'failed'";
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    $enrollmentsQuery = "
        SELECT e.*,
               so.skill_title,
               so.price_per_hour,
               so.is_free,
               so.tutor_id,
               u.first_name,
               u.last_name,
               u.email,
               u.profile_photo
        FROM enrollments e
        JOIN skill_offerings so ON e.skill_id = so.skill_id
        JOIN users u ON e.student_id = u.user_id
        WHERE $whereClause
        ORDER BY e.enrolled_at DESC
    ";
    
    $enrollmentsStmt = $db->prepare($enrollmentsQuery);
    $enrollmentsStmt->execute($params);
    $enrollments = $enrollmentsStmt->fetchAll();
    
    $statsStmt = $db->prepare("
        SELECT 
            COUNT(*) as total_enrollments,
            SUM(CASE WHEN e.payment_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN e.payment_status = 'completed' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN e.payment_status = 'failed' THEN 1 ELSE 0 END) as rejected_count,
            SUM(CASE WHEN e.access_granted = TRUE THEN 1 ELSE 0 END) as active_students
        FROM enrollments e
        JOIN skill_offerings so ON e.skill_id = so.skill_id
        WHERE so.tutor_id = ?
    ");
    $statsStmt->execute([$userId]);
    $stats = $statsStmt->fetch();
    
} catch (PDOException $e) {
    error_log("Manage Enrollments Error: " . $e->getMessage());
    $enrollments = [];
    $stats = [
        'total_enrollments' => 0,
        'pending_count' => 0,
        'approved_count' => 0,
        'rejected_count' => 0,
        'active_students' => 0
    ];
}

$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Enrollments - <?php echo SITE_NAME; ?></title>
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
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-bridge me-2"></i><?php echo SITE_NAME; ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="my-skills.php">My Skills</a>
                <a class="nav-link active" href="manage-enrollments.php">Enrollments</a>
                <a class="nav-link" href="messages.php">Messages</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="page-header">
        <div class="container">
            <h1 class="mb-2"><i class="fas fa-users-cog me-3"></i>Manage Enrollments</h1>
            <p class="mb-0">Review and approve student enrollment requests</p>
        </div>
    </div>

    <div class="container my-4">
        <?php if ($flashMessage): ?>
            <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show">
                <?php echo escape($flashMessage['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

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
                <div class="card stat-card shadow-sm" style="border-left-color: #0ea5e9;">
                    <div class="card-body text-center">
                        <i class="fas fa-user-graduate fa-2x text-info mb-2"></i>
                        <h3 class="mb-0"><?php echo number_format($stats['active_students']); ?></h3>
                        <small class="text-muted">Active Students</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="btn-group" role="group">
                    <a href="manage-enrollments.php?filter=all" 
                       class="btn <?php echo $filter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        All (<?php echo $stats['total_enrollments']; ?>)
                    </a>
                    <a href="manage-enrollments.php?filter=pending" 
                       class="btn <?php echo $filter === 'pending' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                        Pending (<?php echo $stats['pending_count']; ?>)
                    </a>
                    <a href="manage-enrollments.php?filter=approved" 
                       class="btn <?php echo $filter === 'approved' ? 'btn-success' : 'btn-outline-success'; ?>">
                        Approved (<?php echo $stats['approved_count']; ?>)
                    </a>
                    <a href="manage-enrollments.php?filter=rejected" 
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
                    <p class="text-muted mb-0">No students have enrolled in your courses yet.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($enrollments as $enrollment): ?>
                <div class="enrollment-card card <?php echo $enrollment['payment_status'] === 'pending' ? 'pending' : ($enrollment['payment_status'] === 'completed' ? 'approved' : 'rejected'); ?>">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-4">
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
                                    <?php if ($enrollment['is_free']): ?>
                                        <span class="badge bg-success">FREE</span>
                                    <?php else: ?>
                                        <strong><?php echo formatPrice($enrollment['payment_amount']); ?></strong>
                                    <?php endif; ?>
                                </small>
                            </div>

                            <div class="col-md-2">
                                <?php if ($enrollment['payment_status'] === 'pending'): ?>
                                    <span class="badge badge-pending"><i class="fas fa-clock me-1"></i>Pending</span>
                                <?php elseif ($enrollment['payment_status'] === 'completed'): ?>
                                    <span class="badge badge-approved"><i class="fas fa-check-circle me-1"></i>Approved</span>
                                <?php else: ?>
                                    <span class="badge badge-rejected"><i class="fas fa-times-circle me-1"></i>Rejected</span>
                                <?php endif; ?>
                                <br>
                                <small class="text-muted"><?php echo timeAgo($enrollment['enrolled_at']); ?></small>
                            </div>

                            <div class="col-md-3 text-end">
                                <?php if ($enrollment['payment_status'] === 'pending'): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Approve this enrollment?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="enrollment_id" value="<?php echo $enrollment['enrollment_id']; ?>">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fas fa-check me-1"></i>Approve
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Reject this enrollment?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="enrollment_id" value="<?php echo $enrollment['enrollment_id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-times me-1"></i>Reject
                                        </button>
                                    </form>
                                <?php elseif ($enrollment['payment_status'] === 'completed' && $enrollment['access_granted']): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Revoke access?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="enrollment_id" value="<?php echo $enrollment['enrollment_id']; ?>">
                                        <button type="submit" class="btn btn-warning btn-sm">
                                            <i class="fas fa-ban me-1"></i>Revoke
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <a href="conversation.php?user=<?php echo $enrollment['student_id']; ?>" 
                                   class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-envelope me-1"></i>Message
                                </a>
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