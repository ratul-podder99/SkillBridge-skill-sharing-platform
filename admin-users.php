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

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid security token.');
    } else {
        $action = $_POST['action'];
        $userId = (int)$_POST['user_id'];
        
        try {
            $db = getDB();
            
            switch ($action) {
                case 'verify':
                    $stmt = $db->prepare("UPDATE users SET is_verified = TRUE WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    setFlashMessage('success', 'User verified successfully!');
                    break;
                    
                case 'unverify':
                    $stmt = $db->prepare("UPDATE users SET is_verified = FALSE WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    setFlashMessage('success', 'User verification removed!');
                    break;
                    
                case 'activate':
                    $stmt = $db->prepare("UPDATE users SET is_active = TRUE WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    setFlashMessage('success', 'User activated successfully!');
                    break;
                    
                case 'deactivate':
                    $stmt = $db->prepare("UPDATE users SET is_active = FALSE WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    setFlashMessage('success', 'User deactivated successfully!');
                    break;
                    
                case 'delete':
                    // Delete user (cascade will handle related records if foreign keys are set)
                    $stmt = $db->prepare("DELETE FROM users WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    setFlashMessage('success', 'User deleted successfully!');
                    break;
            }
            
            redirect('admin-users.php');
            
        } catch (PDOException $e) {
            error_log("Admin Users Action Error: " . $e->getMessage());
            setFlashMessage('error', 'Failed to perform action.');
        }
    }
}

// Get filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

try {
    $db = getDB();
    
    // Build WHERE clause
    $whereConditions = ["1=1"];
    $params = [];
    
    if ($filter === 'tutors') {
        $whereConditions[] = "(user_type = 'tutor' OR user_type = 'both')";
    } elseif ($filter === 'learners') {
        $whereConditions[] = "(user_type = 'learner' OR user_type = 'both')";
    } elseif ($filter === 'unverified') {
        $whereConditions[] = "(user_type = 'tutor' OR user_type = 'both') AND is_verified = FALSE";
    } elseif ($filter === 'inactive') {
        $whereConditions[] = "is_active = FALSE";
    }
    
    if (!empty($search)) {
        $whereConditions[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get total count
    $countSql = "SELECT COUNT(*) FROM users WHERE $whereClause";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalUsers = $countStmt->fetchColumn();
    $totalPages = ceil($totalUsers / $perPage);
    
    // Get users
    $usersSql = "
        SELECT u.*,
               (SELECT COUNT(*) FROM skill_offerings WHERE tutor_id = u.user_id) as skills_count,
               (SELECT COUNT(*) FROM reviews WHERE user_id = u.user_id) as reviews_count
        FROM users u
        WHERE $whereClause
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $perPage;
    $params[] = $offset;
    
    $usersStmt = $db->prepare($usersSql);
    $usersStmt->execute($params);
    $users = $usersStmt->fetchAll();
    
    // Get statistics
    $statsStmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN user_type = 'tutor' OR user_type = 'both' THEN 1 ELSE 0 END) as tutors,
            SUM(CASE WHEN user_type = 'learner' OR user_type = 'both' THEN 1 ELSE 0 END) as learners,
            SUM(CASE WHEN is_verified = FALSE AND (user_type = 'tutor' OR user_type = 'both') THEN 1 ELSE 0 END) as unverified,
            SUM(CASE WHEN is_active = FALSE THEN 1 ELSE 0 END) as inactive
        FROM users
    ");
    $stats = $statsStmt->fetch();
    
} catch (PDOException $e) {
    error_log("Admin Users Error: " . $e->getMessage());
    $users = [];
    $totalUsers = 0;
    $totalPages = 0;
    $stats = ['total' => 0, 'tutors' => 0, 'learners' => 0, 'unverified' => 0, 'inactive' => 0];
}

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
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .filter-btn {
            margin: 5px;
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
                <a class="nav-link active" href="admin-users.php">
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
                <i class="fas fa-users text-primary me-2"></i>Manage Users
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
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h4 class="mb-0"><?php echo number_format($stats['total']); ?></h4>
                        <small class="text-muted">Total Users</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h4 class="mb-0 text-primary"><?php echo number_format($stats['tutors']); ?></h4>
                        <small class="text-muted">Tutors</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h4 class="mb-0 text-success"><?php echo number_format($stats['learners']); ?></h4>
                        <small class="text-muted">Learners</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h4 class="mb-0 text-warning"><?php echo number_format($stats['unverified']); ?></h4>
                        <small class="text-muted">Unverified</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h4 class="mb-0 text-danger"><?php echo number_format($stats['inactive']); ?></h4>
                        <small class="text-muted">Inactive</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters and Search -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="btn-group flex-wrap" role="group">
                            <a href="admin-users.php?filter=all" 
                               class="btn btn-sm <?php echo $filter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                All Users
                            </a>
                            <a href="admin-users.php?filter=tutors" 
                               class="btn btn-sm <?php echo $filter === 'tutors' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                Tutors
                            </a>
                            <a href="admin-users.php?filter=learners" 
                               class="btn btn-sm <?php echo $filter === 'learners' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                Learners
                            </a>
                            <a href="admin-users.php?filter=unverified" 
                               class="btn btn-sm <?php echo $filter === 'unverified' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                                Unverified (<?php echo $stats['unverified']; ?>)
                            </a>
                            <a href="admin-users.php?filter=inactive" 
                               class="btn btn-sm <?php echo $filter === 'inactive' ? 'btn-danger' : 'btn-outline-danger'; ?>">
                                Inactive
                            </a>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <form method="GET" action="admin-users.php" class="d-flex">
                            <input type="hidden" name="filter" value="<?php echo escape($filter); ?>">
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search users..." 
                                   value="<?php echo escape($search); ?>">
                            <button type="submit" class="btn btn-primary ms-2">
                                <i class="fas fa-search"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Users Table -->
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Type</th>
                                <th>Skills</th>
                                <th>Reviews</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No users found</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if ($user['profile_photo']): ?>
                                                <img src="<?php echo escape($user['profile_photo']); ?>" 
                                                     alt="<?php echo escape($user['first_name']); ?>"
                                                     class="user-avatar me-2">
                                            <?php else: ?>
                                                <div class="user-avatar bg-secondary d-flex align-items-center justify-content-center me-2">
                                                    <i class="fas fa-user text-white"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <strong>
                                                    <?php echo escape($user['first_name'] . ' ' . $user['last_name']); ?>
                                                </strong>
                                                <?php if ($user['is_verified']): ?>
                                                    <i class="fas fa-check-circle text-success ms-1" title="Verified"></i>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><small><?php echo escape($user['email']); ?></small></td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo escape(ucfirst($user['user_type'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $user['skills_count']; ?></td>
                                    <td><?php echo $user['reviews_count']; ?></td>
                                    <td>
                                        <?php if ($user['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?php echo timeAgo($user['created_at']); ?></small></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="profile.php?id=<?php echo $user['user_id']; ?>" 
                                               class="btn btn-outline-primary"
                                               target="_blank"
                                               title="View Profile">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <?php if (!$user['is_verified'] && ($user['user_type'] === 'tutor' || $user['user_type'] === 'both')): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="verify">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                    <button type="submit" class="btn btn-outline-success" title="Verify User">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                            <?php elseif ($user['is_verified']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="unverify">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                    <button type="submit" class="btn btn-outline-warning" title="Remove Verification">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <?php if ($user['is_active']): ?>
                                                <form method="POST" style="display: inline;" 
                                                      onsubmit="return confirm('Deactivate this user?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                    <button type="submit" class="btn btn-outline-warning" title="Deactivate">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="activate">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                    <button type="submit" class="btn btn-outline-success" title="Activate">
                                                        <i class="fas fa-check-circle"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="POST" style="display: inline;" 
                                                  onsubmit="return confirm('Delete this user permanently? This cannot be undone!');">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" 
                           href="?filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">
                            Previous
                        </a>
                    </li>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" 
                               href="?filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" 
                           href="?filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">
                            Next
                        </a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>