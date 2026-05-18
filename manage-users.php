<?php
/**
 * Manage Users - Admin Only
 * View and manage all registered users
 */

session_start();
require_once 'config.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || $_SESSION['user_type'] !== 'admin') {
    setFlashMessage('error', 'Access denied. Admin only.');
    redirect('dashboard.php');
}

$db = getDB();

// Handle user actions (delete, verify, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid security token.');
    } else {
        $action = $_POST['action'];
        $user_id = (int)$_POST['user_id'];
        
        try {
            if ($action === 'verify') {
                $stmt = $db->prepare("UPDATE users SET is_verified = 1 WHERE user_id = ?");
                $stmt->execute([$user_id]);
                setFlashMessage('success', 'User verified successfully.');
            } elseif ($action === 'unverify') {
                $stmt = $db->prepare("UPDATE users SET is_verified = 0 WHERE user_id = ?");
                $stmt->execute([$user_id]);
                setFlashMessage('success', 'User verification removed.');
            } elseif ($action === 'delete') {
                // Don't allow admin to delete themselves
                if ($user_id == $_SESSION['user_id']) {
                    setFlashMessage('error', 'You cannot delete your own account!');
                } else {
                    $stmt = $db->prepare("DELETE FROM users WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    setFlashMessage('success', 'User deleted successfully.');
                }
            }
            redirect('manage-users.php');
        } catch (PDOException $e) {
            error_log("Manage Users Error: " . $e->getMessage());
            setFlashMessage('error', 'Failed to perform action.');
        }
    }
}

// Get filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Build query based on filter
$whereClause = "1=1";
if ($filter === 'tutor') {
    $whereClause = "user_type = 'tutor'";
} elseif ($filter === 'learner') {
    $whereClause = "user_type = 'learner'";
} elseif ($filter === 'both') {
    $whereClause = "user_type = 'both'";
} elseif ($filter === 'admin') {
    $whereClause = "user_type = 'admin'";
} elseif ($filter === 'verified') {
    $whereClause = "is_verified = 1";
} elseif ($filter === 'unverified') {
    $whereClause = "is_verified = 0";
}

// Get all users
$stmt = $db->prepare("
    SELECT 
        user_id,
        email,
        first_name,
        last_name,
        user_type,
        is_verified,
        created_at,
        profile_photo
    FROM users
    WHERE $whereClause
    ORDER BY created_at DESC
");
$stmt->execute();
$users = $stmt->fetchAll();

// Get statistics
$statsStmt = $db->query("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN user_type = 'tutor' THEN 1 ELSE 0 END) as total_tutors,
        SUM(CASE WHEN user_type = 'learner' THEN 1 ELSE 0 END) as total_learners,
        SUM(CASE WHEN user_type = 'both' THEN 1 ELSE 0 END) as total_both,
        SUM(CASE WHEN user_type = 'admin' THEN 1 ELSE 0 END) as total_admins,
        SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified_users
    FROM users
");
$stats = $statsStmt->fetch();

$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Admin - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .stat-card {
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
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
                <a class="nav-link" href="admin-dashboard.php">Admin Panel</a>
                <a class="nav-link active" href="manage-users.php">Users</a>
                <a class="nav-link" href="admin-enrollments.php">Enrollments</a>
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Page Header -->
    <div class="page-header">
        <div class="container">
            <h1 class="mb-2"><i class="fas fa-users-cog me-3"></i>Manage Users</h1>
            <p class="mb-0">View and manage all registered users</p>
        </div>
    </div>

    <div class="container my-4">
        <!-- Flash Messages -->
        <?php if ($flashMessage): ?>
            <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show">
                <?php echo escape($flashMessage['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-2">
                <div class="card stat-card shadow-sm" style="border-left-color: #667eea;">
                    <div class="card-body text-center">
                        <h3 class="mb-0"><?php echo $stats['total_users']; ?></h3>
                        <small class="text-muted">Total Users</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card shadow-sm" style="border-left-color: #10b981;">
                    <div class="card-body text-center">
                        <h3 class="mb-0"><?php echo $stats['total_tutors']; ?></h3>
                        <small class="text-muted">Tutors</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card shadow-sm" style="border-left-color: #f59e0b;">
                    <div class="card-body text-center">
                        <h3 class="mb-0"><?php echo $stats['total_learners']; ?></h3>
                        <small class="text-muted">Learners</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card shadow-sm" style="border-left-color: #8b5cf6;">
                    <div class="card-body text-center">
                        <h3 class="mb-0"><?php echo $stats['total_both']; ?></h3>
                        <small class="text-muted">Both</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card shadow-sm" style="border-left-color: #ef4444;">
                    <div class="card-body text-center">
                        <h3 class="mb-0"><?php echo $stats['total_admins']; ?></h3>
                        <small class="text-muted">Admins</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card shadow-sm" style="border-left-color: #0ea5e9;">
                    <div class="card-body text-center">
                        <h3 class="mb-0"><?php echo $stats['verified_users']; ?></h3>
                        <small class="text-muted">Verified</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Buttons -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="btn-group flex-wrap" role="group">
                    <a href="manage-users.php?filter=all" 
                       class="btn <?php echo $filter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        All (<?php echo $stats['total_users']; ?>)
                    </a>
                    <a href="manage-users.php?filter=tutor" 
                       class="btn <?php echo $filter === 'tutor' ? 'btn-success' : 'btn-outline-success'; ?>">
                        Tutors (<?php echo $stats['total_tutors']; ?>)
                    </a>
                    <a href="manage-users.php?filter=learner" 
                       class="btn <?php echo $filter === 'learner' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                        Learners (<?php echo $stats['total_learners']; ?>)
                    </a>
                    <a href="manage-users.php?filter=both" 
                       class="btn <?php echo $filter === 'both' ? 'btn-info' : 'btn-outline-info'; ?>">
                        Both (<?php echo $stats['total_both']; ?>)
                    </a>
                    <a href="manage-users.php?filter=admin" 
                       class="btn <?php echo $filter === 'admin' ? 'btn-danger' : 'btn-outline-danger'; ?>">
                        Admins (<?php echo $stats['total_admins']; ?>)
                    </a>
                    <a href="manage-users.php?filter=verified" 
                       class="btn <?php echo $filter === 'verified' ? 'btn-success' : 'btn-outline-success'; ?>">
                        Verified (<?php echo $stats['verified_users']; ?>)
                    </a>
                    <a href="manage-users.php?filter=unverified" 
                       class="btn <?php echo $filter === 'unverified' ? 'btn-secondary' : 'btn-outline-secondary'; ?>">
                        Unverified
                    </a>
                </div>
            </div>
        </div>

        <!-- Users Table -->
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-users me-2"></i>All Users (<?php echo count($users); ?>)</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($users)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-4x text-muted mb-3"></i>
                        <p class="text-muted">No users found.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo $user['user_id']; ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <?php if ($user['profile_photo']): ?>
                                                    <img src="<?php echo escape($user['profile_photo']); ?>" 
                                                         alt="Avatar" class="user-avatar me-2">
                                                <?php else: ?>
                                                    <div class="user-avatar bg-secondary d-flex align-items-center justify-content-center me-2">
                                                        <i class="fas fa-user text-white"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <strong><?php echo escape($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                                            </div>
                                        </td>
                                        <td><?php echo escape($user['email']); ?></td>
                                        <td>
                                            <?php if ($user['user_type'] === 'admin'): ?>
                                                <span class="badge bg-danger">Admin</span>
                                            <?php elseif ($user['user_type'] === 'tutor'): ?>
                                                <span class="badge bg-primary">Tutor</span>
                                            <?php elseif ($user['user_type'] === 'learner'): ?>
                                                <span class="badge bg-success">Learner</span>
                                            <?php elseif ($user['user_type'] === 'both'): ?>
                                                <span class="badge bg-info">Both</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($user['is_verified']): ?>
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check-circle"></i> Verified
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-clock"></i> Unverified
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                                <!-- Verify/Unverify -->
                                                <?php if (!$user['is_verified']): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                        <input type="hidden" name="action" value="verify">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-success" title="Verify User">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                        <input type="hidden" name="action" value="unverify">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-warning" title="Remove Verification">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <!-- Delete -->
                                                <form method="POST" style="display: inline;" 
                                                      onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone!');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete User">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge bg-info">You</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>