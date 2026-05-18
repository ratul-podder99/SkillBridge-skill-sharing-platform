<?php
session_start();
require_once 'config.php';

// Get search and filter parameters
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$categoryFilter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;

try {
    $db = getDB();
    
    // Get all categories for filter
    $categoriesStmt = $db->query("SELECT * FROM categories ORDER BY category_name");
    $categories = $categoriesStmt->fetchAll();
    
    // Build WHERE clause
    $whereConditions = ["so.is_active = TRUE"];
    $params = [];
    
    if (!empty($searchQuery)) {
        $whereConditions[] = "(so.skill_title LIKE ? OR so.skill_description LIKE ?)";
        $searchTerm = '%' . $searchQuery . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if ($categoryFilter > 0) {
        $whereConditions[] = "so.category_id = ?";
        $params[] = $categoryFilter;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Determine sort order
    switch ($sortBy) {
        case 'price_low':
            $orderBy = "so.price_per_hour ASC, so.is_free DESC";
            break;
        case 'price_high':
            $orderBy = "so.price_per_hour DESC";
            break;
        case 'rating':
            $orderBy = "avg_rating DESC, review_count DESC";
            break;
        case 'popular':
            $orderBy = "review_count DESC, avg_rating DESC";
            break;
        case 'newest':
        default:
            $orderBy = "so.created_at DESC";
            break;
    }
    
    // Get total count for pagination
    $countSql = "
        SELECT COUNT(DISTINCT so.skill_id)
        FROM skill_offerings so
        WHERE $whereClause
    ";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalSkills = $countStmt->fetchColumn();
    $totalPages = ceil($totalSkills / $perPage);
    
    // Get skills with tutor info and stats
    $skillsSql = "
        SELECT 
            so.*,
            c.category_name,
            u.first_name,
            u.last_name,
            u.profile_photo,
            u.is_verified,
            COUNT(DISTINCT r.review_id) as review_count,
            COALESCE(AVG(r.rating), 0) as avg_rating
        FROM skill_offerings so
        JOIN categories c ON so.category_id = c.category_id
        JOIN users u ON so.tutor_id = u.user_id
        LEFT JOIN reviews r ON so.skill_id = r.skill_id
        WHERE $whereClause
        GROUP BY so.skill_id
        ORDER BY $orderBy
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $perPage;
    $params[] = $offset;
    
    $skillsStmt = $db->prepare($skillsSql);
    $skillsStmt->execute($params);
    $skills = $skillsStmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Browse Skills Error: " . $e->getMessage());
    $skills = [];
    $totalSkills = 0;
    $totalPages = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Skills - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .search-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 60px 0 40px;
        }
        .skill-card {
            transition: transform 0.2s, box-shadow 0.2s;
            height: 100%;
        }
        .skill-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15) !important;
        }
        .tutor-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .filter-card {
            position: sticky;
            top: 20px;
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
                    <a class="nav-link active" href="browse-skills.php">Browse Skills</a>
                    <?php if ($_SESSION['user_type'] === 'tutor' || $_SESSION['user_type'] === 'both'): ?>
                        <a class="nav-link" href="my-skills.php">My Skills</a>
                    <?php endif; ?>
                    <a class="nav-link" href="logout.php">Logout</a>
                <?php else: ?>
                    <a class="nav-link active" href="browse-skills.php">Browse Skills</a>
                    <a class="nav-link" href="login.php">Login</a>
                    <a class="nav-link" href="register.php">Sign Up</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Search Header -->
    <div class="search-header">
        <div class="container">
            <h1 class="display-5 fw-bold mb-4">Discover Skills</h1>
            <form method="GET" action="browse-skills.php" class="row g-3">
                <div class="col-md-8">
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" class="form-control" name="search" 
                               placeholder="Search for skills, topics, or tutors..." 
                               value="<?php echo escape($searchQuery); ?>">
                        <button type="submit" class="btn btn-light">
                            <i class="fas fa-search me-2"></i>Search
                        </button>
                    </div>
                </div>
                <div class="col-md-4">
                    <select class="form-select form-select-lg" name="category" 
                            onchange="this.form.submit()">
                        <option value="0">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['category_id']; ?>"
                                    <?php echo $categoryFilter == $category['category_id'] ? 'selected' : ''; ?>>
                                <?php echo escape($category['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <div class="container my-5">
        <div class="row">
            <!-- Sidebar Filters -->
            <div class="col-lg-3 mb-4">
                <div class="card filter-card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="fas fa-filter me-2"></i>Filters
                        </h5>
                        
                        <!-- Sort By -->
                        <form method="GET" action="browse-skills.php" id="filterForm">
                            <input type="hidden" name="search" value="<?php echo escape($searchQuery); ?>">
                            <input type="hidden" name="category" value="<?php echo $categoryFilter; ?>">
                            
                            <div class="mb-4">
                                <label class="form-label fw-bold">Sort By</label>
                                <select class="form-select" name="sort" onchange="document.getElementById('filterForm').submit()">
                                    <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>
                                        Newest First
                                    </option>
                                    <option value="popular" <?php echo $sortBy === 'popular' ? 'selected' : ''; ?>>
                                        Most Popular
                                    </option>
                                    <option value="rating" <?php echo $sortBy === 'rating' ? 'selected' : ''; ?>>
                                        Highest Rated
                                    </option>
                                    <option value="price_low" <?php echo $sortBy === 'price_low' ? 'selected' : ''; ?>>
                                        Price: Low to High
                                    </option>
                                    <option value="price_high" <?php echo $sortBy === 'price_high' ? 'selected' : ''; ?>>
                                        Price: High to Low
                                    </option>
                                </select>
                            </div>
                        </form>
                        
                        <!-- Active Filters -->
                        <?php if (!empty($searchQuery) || $categoryFilter > 0): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Active Filters</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php if (!empty($searchQuery)): ?>
                                        <span class="badge bg-primary">
                                            Search: <?php echo escape($searchQuery); ?>
                                            <a href="browse-skills.php?category=<?php echo $categoryFilter; ?>&sort=<?php echo $sortBy; ?>" 
                                               class="text-white ms-1">×</a>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($categoryFilter > 0): ?>
                                        <?php
                                        $selectedCat = array_filter($categories, function($c) use ($categoryFilter) {
                                            return $c['category_id'] == $categoryFilter;
                                        });
                                        $selectedCat = reset($selectedCat);
                                        ?>
                                        <span class="badge bg-success">
                                            <?php echo escape($selectedCat['category_name']); ?>
                                            <a href="browse-skills.php?search=<?php echo urlencode($searchQuery); ?>&sort=<?php echo $sortBy; ?>" 
                                               class="text-white ms-1">×</a>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <a href="browse-skills.php" class="btn btn-sm btn-outline-secondary mt-2 w-100">
                                    Clear All Filters
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Results Count -->
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong><?php echo $totalSkills; ?></strong> skill<?php echo $totalSkills != 1 ? 's' : ''; ?> found
                        </div>
                    </div>
                </div>
            </div>

            <!-- Skills Grid -->
            <div class="col-lg-9">
                <?php if (empty($skills)): ?>
                    <div class="card shadow-sm">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-search fa-4x text-muted mb-4"></i>
                            <h4 class="text-muted mb-3">No Skills Found</h4>
                            <p class="text-muted mb-4">
                                Try adjusting your search or filters to find what you're looking for.
                            </p>
                            <a href="browse-skills.php" class="btn btn-primary">
                                <i class="fas fa-refresh me-2"></i>Clear Filters
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-4 mb-4">
                        <?php foreach ($skills as $skill): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card skill-card shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-3">
                                        <?php if ($skill['profile_photo']): ?>
                                            <img src="<?php echo escape($skill['profile_photo']); ?>" 
                                                 alt="<?php echo escape($skill['first_name']); ?>"
                                                 class="tutor-avatar me-2">
                                        <?php else: ?>
                                            <div class="tutor-avatar bg-secondary d-flex align-items-center justify-content-center me-2">
                                                <i class="fas fa-user text-white"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex-grow-1">
                                            <small class="text-muted d-block">
                                                <?php echo escape($skill['first_name'] . ' ' . $skill['last_name']); ?>
                                                <?php if ($skill['is_verified']): ?>
                                                    <i class="fas fa-check-circle text-success" title="Verified"></i>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <h5 class="card-title mb-2">
                                        <a href="skill-details.php?id=<?php echo $skill['skill_id']; ?>" 
                                           class="text-decoration-none text-dark">
                                            <?php echo escape($skill['skill_title']); ?>
                                        </a>
                                    </h5>
                                    
                                    <span class="badge bg-primary mb-3">
                                        <?php echo escape($skill['category_name']); ?>
                                    </span>
                                    
                                    <p class="card-text text-muted small">
                                        <?php echo escape(substr($skill['skill_description'], 0, 100)) . '...'; ?>
                                    </p>
                                    
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <?php if ($skill['review_count'] > 0): ?>
                                                <?php echo generateStarRating($skill['avg_rating']); ?>
                                                <small class="text-muted">(<?php echo $skill['review_count']; ?>)</small>
                                            <?php else: ?>
                                                <small class="text-muted">No reviews yet</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong class="text-primary fs-5">
                                            <?php echo $skill['is_free'] ? 'FREE' : formatPrice($skill['price_per_hour']) . '/hr'; ?>
                                        </strong>
                                        <a href="skill-details.php?id=<?php echo $skill['skill_id']; ?>" 
                                           class="btn btn-outline-primary btn-sm">
                                            View Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <nav aria-label="Skills pagination">
                        <ul class="pagination justify-content-center">
                            <!-- Previous -->
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="?search=<?php echo urlencode($searchQuery); ?>&category=<?php echo $categoryFilter; ?>&sort=<?php echo $sortBy; ?>&page=<?php echo $page - 1; ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            
                            <!-- Page Numbers -->
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" 
                                       href="?search=<?php echo urlencode($searchQuery); ?>&category=<?php echo $categoryFilter; ?>&sort=<?php echo $sortBy; ?>&page=<?php echo $i; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Next -->
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" 
                                   href="?search=<?php echo urlencode($searchQuery); ?>&category=<?php echo $categoryFilter; ?>&sort=<?php echo $sortBy; ?>&page=<?php echo $page + 1; ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>