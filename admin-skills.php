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
        $skillId = (int)$_POST['skill_id'];
        
        try {
            $db = getDB();
            
            switch ($action) {
                case 'activate':
                    $stmt = $db->prepare("UPDATE skill_offerings SET is_active = TRUE WHERE skill_id = ?");
                    $stmt->execute([$skillId]);
                    setFlashMessage('success', 'Skill activated successfully!');
                    break;
                    
                case 'deactivate':
                    $stmt = $db->prepare("UPDATE skill_offerings SET is_active = FALSE WHERE skill_id = ?");
                    $stmt->execute([$skillId]);
                    setFlashMessage('success', 'Skill deactivated successfully!');
                    break;
                    
                case 'delete':
                    // Delete skill
                    $stmt = $db->prepare("DELETE FROM skill_offerings WHERE skill_id = ?");
                    $stmt->execute([$skillId]);
                    setFlashMessage('success', 'Skill deleted successfully!');
                    break;
            }
            
            redirect('admin-skills.php');
            
        } catch (PDOException $e) {
            error_log("Admin Skills Action Error: " . $e->getMessage());
            setFlashMessage('error', 'Failed to perform action.');
        }
    }
}

// Get filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

try {
    $db = getDB();
    
    // Get categories for filter
    $categoriesStmt = $db->query("SELECT * FROM categories ORDER BY category_name");
    $categories = $categoriesStmt->fetchAll();
    
    // Build WHERE clause
    $whereConditions = ["1=1"];
    $params = [];
    
    if ($filter === 'active') {
        $whereConditions[] = "so.is_active = TRUE";
    } elseif ($filter === 'inactive') {
        $whereConditions[] = "so.is_active = FALSE";
    } elseif ($filter === 'free') {
        $whereConditions[] = "so.is_free = TRUE";
    } elseif ($filter === 'paid') {
        $whereConditions[] = "so.is_free = FALSE";
    }
    
    if ($category > 0) {
        $whereConditions[] = "so.category_id = ?";
        $params[] = $category;
    }
    
    if (!empty($search)) {
        $whereConditions[] = "(so.skill_title LIKE ? OR so.skill_description LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get total count
    $countSql = "
        SELECT COUNT(*) 
        FROM skill_offerings so
        WHERE $whereClause
    ";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalSkills = $countStmt->fetchColumn();
    $totalPages = ceil($totalSkills / $perPage);
    
    // Get skills
    $skillsSql = "
        SELECT so.*,
               c.category_name,
               u.first_name,
               u.last_name,
               u.email,
               COUNT(DISTINCT r.review_id) as review_count,
               COALESCE(AVG(r.rating), 0) as avg_rating
        FROM skill_offerings so
        JOIN categories c ON so.category_id = c.category_id
        JOIN users u ON so.tutor_id = u.user_id
        LEFT JOIN reviews r ON so.skill_id = r.skill_id
        WHERE $whereClause
        GROUP BY so.skill_id
        ORDER BY so.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $perPage;
    $params[] = $offset;
    
    $skillsStmt = $db->prepare($skillsSql);
    $skillsStmt->execute($params);
    $skills = $skillsStmt->fetchAll();
    
    // Get statistics
    $statsStmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_active = TRUE THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN is_active = FALSE THEN 1 ELSE 0 END) as inactive,
            SUM(CASE WHEN is_free = TRUE THEN 1 ELSE 0 END) as free,
            SUM(CASE WHEN is_free = FALSE THEN 1 ELSE 0 END) as paid
        FROM skill_offerings
    ");
    $stats = $statsStmt->fetch();
    
} catch (PDOException $e) {
    error_log("Admin Skills Error: " . $e->getMessage());
    $skills = [];
    $categories = [];
    $totalSkills = 0;
    $totalPages = 0;
    $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'free' => 0, 'paid' => 0];
}

$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Skills - Admin - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                <a class="nav-link active" href="admin-skills.php">
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
                <i class="fas fa-book text-success me-2"></i>Manage Skills
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
                        <small class="text-muted">Total Skills</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h4 class="mb-0 text-success"><?php echo number_format($stats['active']); ?></h4>
                        <small class="text-muted">Active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h4 class="mb-0 text-warning"><?php echo number_format($stats['inactive']); ?></h4>
                        <small class="text-muted">Inactive</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h4 class="mb-0 text-primary"><?php echo number_format($stats['free']); ?></h4>
                        <small class="text-muted">Free</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h4 class="mb-0 text-info"><?php echo number_format($stats['paid']); ?></h4>
                        <small class="text-muted">Paid</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters and Search -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <div class="btn-group flex-wrap" role="group">
                            <a href="admin-skills.php?filter=all" 
                               class="btn btn-sm <?php echo $filter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                All Skills
                            </a>
                            <a href="admin-skills.php?filter=active" 
                               class="btn btn-sm <?php echo $filter === 'active' ? 'btn-success' : 'btn-outline-success'; ?>">
                                Active
                            </a>
                            <a href="admin-skills.php?filter=inactive" 
                               class="btn btn-sm <?php echo $filter === 'inactive' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                                Inactive
                            </a>
                            <a href="admin-skills.php?filter=free" 
                               class="btn btn-sm <?php echo $filter === 'free' ? 'btn-info' : 'btn-outline-info'; ?>">
                                Free
                            </a>
                            <a href="admin-skills.php?filter=paid" 
                               class="btn btn-sm <?php echo $filter === 'paid' ? 'btn-secondary' : 'btn-outline-secondary'; ?>">
                                Paid
                            </a>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <form method="GET" action="admin-skills.php">
                            <input type="hidden" name="filter" value="<?php echo escape($filter); ?>">
                            <select name="category" class="form-select form-select-sm" 
                                    onchange="this.form.submit()">
                                <option value="0">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['category_id']; ?>"
                                            <?php echo $category == $cat['category_id'] ? 'selected' : ''; ?>>
                                        <?php echo escape($cat['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <div class="col-md-3">
                        <form method="GET" action="admin-skills.php" class="d-flex">
                            <input type="hidden" name="filter" value="<?php echo escape($filter); ?>">
                            <input type="hidden" name="category" value="<?php echo $category; ?>">
                            <input type="text" name="search" class="form-control form-control-sm" 
                                   placeholder="Search skills..." 
                                   value="<?php echo escape($search); ?>">
                            <button type="submit" class="btn btn-sm btn-primary ms-2">
                                <i class="fas fa-search"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Skills Table -->
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Skill Title</th>
                                <th>Category</th>
                                <th>Tutor</th>
                                <th>Price</th>
                                <th>Rating</th>
                                <th>Reviews</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($skills)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                                        <p class="text-muted">No skills found</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($skills as $skill): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?php echo escape(substr($skill['skill_title'], 0, 40)); ?>
                                            <?php if (strlen($skill['skill_title']) > 40) echo '...'; ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?php echo escape($skill['category_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small>
                                            <?php echo escape($skill['first_name'] . ' ' . $skill['last_name']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($skill['is_free']): ?>
                                            <span class="badge bg-success">FREE</span>
                                        <?php else: ?>
                                            <strong><?php echo formatPrice($skill['price_per_hour']); ?>/hr</strong>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($skill['review_count'] > 0): ?>
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-star text-warning me-1"></i>
                                                <span><?php echo number_format($skill['avg_rating'], 1); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $skill['review_count']; ?></td>
                                    <td>
                                        <?php if ($skill['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?php echo timeAgo($skill['created_at']); ?></small></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="skill-details.php?id=<?php echo $skill['skill_id']; ?>" 
                                               class="btn btn-outline-primary"
                                               target="_blank"
                                               title="View Skill">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <?php if ($skill['is_active']): ?>
                                                <form method="POST" style="display: inline;" 
                                                      onsubmit="return confirm('Deactivate this skill?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <input type="hidden" name="skill_id" value="<?php echo $skill['skill_id']; ?>">
                                                    <button type="submit" class="btn btn-outline-warning" title="Deactivate">
                                                        <i class="fas fa-pause"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="action" value="activate">
                                                    <input type="hidden" name="skill_id" value="<?php echo $skill['skill_id']; ?>">
                                                    <button type="submit" class="btn btn-outline-success" title="Activate">
                                                        <i class="fas fa-play"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="POST" style="display: inline;" 
                                                  onsubmit="return confirm('Delete this skill permanently? This cannot be undone!');">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="skill_id" value="<?php echo $skill['skill_id']; ?>">
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
                           href="?filter=<?php echo $filter; ?>&category=<?php echo $category; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">
                            Previous
                        </a>
                    </li>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" 
                               href="?filter=<?php echo $filter; ?>&category=<?php echo $category; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" 
                           href="?filter=<?php echo $filter; ?>&category=<?php echo $category; ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">
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