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
    
    // Overall Statistics
    $overallStats = [];
    
    // Total counts
    $overallStats['total_users'] = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $overallStats['total_skills'] = $db->query("SELECT COUNT(*) FROM skill_offerings")->fetchColumn();
    $overallStats['total_messages'] = $db->query("SELECT COUNT(*) FROM messages")->fetchColumn();
    $overallStats['total_reviews'] = $db->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
    
    // User growth (last 30 days)
    $userGrowthStmt = $db->query("
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM users
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $userGrowth = $userGrowthStmt->fetchAll();
    
    // Skills by category
    $skillsByCategoryStmt = $db->query("
        SELECT c.category_name, COUNT(so.skill_id) as count
        FROM categories c
        LEFT JOIN skill_offerings so ON c.category_id = so.category_id
        GROUP BY c.category_id, c.category_name
        ORDER BY count DESC
    ");
    $skillsByCategory = $skillsByCategoryStmt->fetchAll();
    
    // User types distribution
    $userTypesStmt = $db->query("
        SELECT user_type, COUNT(*) as count
        FROM users
        GROUP BY user_type
    ");
    $userTypes = $userTypesStmt->fetchAll();
    
    // Top tutors by skills
    $topTutorsStmt = $db->query("
        SELECT u.first_name, u.last_name, COUNT(so.skill_id) as skills_count
        FROM users u
        JOIN skill_offerings so ON u.user_id = so.tutor_id
        WHERE so.is_active = TRUE
        GROUP BY u.user_id
        ORDER BY skills_count DESC
        LIMIT 10
    ");
    $topTutors = $topTutorsStmt->fetchAll();
    
    // Top rated skills
    $topSkillsStmt = $db->query("
        SELECT so.skill_title, u.first_name, u.last_name, 
               COUNT(r.review_id) as review_count,
               AVG(r.rating) as avg_rating
        FROM skill_offerings so
        JOIN users u ON so.tutor_id = u.user_id
        LEFT JOIN reviews r ON so.skill_id = r.skill_id
        WHERE so.is_active = TRUE
        GROUP BY so.skill_id
        HAVING review_count > 0
        ORDER BY avg_rating DESC, review_count DESC
        LIMIT 10
    ");
    $topSkills = $topSkillsStmt->fetchAll();
    
    // Recent activity (last 7 days)
    $recentActivityStmt = $db->query("
        SELECT 
            DATE(created_at) as date,
            SUM(CASE WHEN table_name = 'users' THEN 1 ELSE 0 END) as new_users,
            SUM(CASE WHEN table_name = 'skills' THEN 1 ELSE 0 END) as new_skills
        FROM (
            SELECT created_at, 'users' as table_name FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            UNION ALL
            SELECT created_at, 'skills' as table_name FROM skill_offerings WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ) as combined
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ");
    $recentActivity = $recentActivityStmt->fetchAll();
    
    // Average rating across platform
    $avgRatingStmt = $db->query("SELECT AVG(rating) as avg_rating FROM reviews");
    $overallStats['avg_rating'] = $avgRatingStmt->fetchColumn() ?: 0;
    
    // Active vs Inactive
    $overallStats['active_skills'] = $db->query("SELECT COUNT(*) FROM skill_offerings WHERE is_active = TRUE")->fetchColumn();
    $overallStats['inactive_skills'] = $db->query("SELECT COUNT(*) FROM skill_offerings WHERE is_active = FALSE")->fetchColumn();
    
    $overallStats['active_users'] = $db->query("SELECT COUNT(*) FROM users WHERE is_active = TRUE")->fetchColumn();
    $overallStats['inactive_users'] = $db->query("SELECT COUNT(*) FROM users WHERE is_active = FALSE")->fetchColumn();
    
    // Revenue statistics (if paid skills exist)
    $revenueStmt = $db->query("
        SELECT 
            COUNT(*) as paid_skills,
            AVG(price_per_hour) as avg_price,
            MIN(price_per_hour) as min_price,
            MAX(price_per_hour) as max_price
        FROM skill_offerings
        WHERE is_free = FALSE
    ");
    $revenueStats = $revenueStmt->fetch();
    
} catch (PDOException $e) {
    error_log("Admin Reports Error: " . $e->getMessage());
    $overallStats = [];
    $userGrowth = [];
    $skillsByCategory = [];
    $userTypes = [];
    $topTutors = [];
    $topSkills = [];
    $recentActivity = [];
    $revenueStats = [];
}

$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Admin - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stat-card {
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
        .chart-container {
            position: relative;
            height: 300px;
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
                <a class="nav-link active" href="admin-reports.php">
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
                <i class="fas fa-chart-line text-info me-2"></i>Reports & Analytics
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

        <!-- Overall Statistics -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card stat-card border-primary shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-users fa-3x text-primary mb-3"></i>
                        <h3 class="mb-1"><?php echo number_format($overallStats['total_users']); ?></h3>
                        <p class="text-muted mb-1">Total Users</p>
                        <small class="text-success">
                            <?php echo number_format($overallStats['active_users']); ?> active
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card border-success shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-book-open fa-3x text-success mb-3"></i>
                        <h3 class="mb-1"><?php echo number_format($overallStats['total_skills']); ?></h3>
                        <p class="text-muted mb-1">Total Skills</p>
                        <small class="text-success">
                            <?php echo number_format($overallStats['active_skills']); ?> active
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card border-info shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-star fa-3x text-warning mb-3"></i>
                        <h3 class="mb-1"><?php echo number_format($overallStats['avg_rating'], 1); ?>/5</h3>
                        <p class="text-muted mb-1">Average Rating</p>
                        <small class="text-muted">
                            <?php echo number_format($overallStats['total_reviews']); ?> reviews
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card stat-card border-warning shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-envelope fa-3x text-info mb-3"></i>
                        <h3 class="mb-1"><?php echo number_format($overallStats['total_messages']); ?></h3>
                        <p class="text-muted mb-1">Total Messages</p>
                        <small class="text-muted">Platform communication</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row g-4 mb-4">
            <!-- Skills by Category -->
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-pie me-2"></i>Skills by Category
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="categoryChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- User Types Distribution -->
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-bar me-2"></i>User Types
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="userTypesChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-calendar-week me-2"></i>Recent Activity (Last 7 Days)
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>New Users</th>
                                        <th>New Skills</th>
                                        <th>Total Activity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentActivity)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">
                                                No recent activity
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentActivity as $activity): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($activity['date'])); ?></td>
                                            <td>
                                                <span class="badge bg-primary">
                                                    <?php echo $activity['new_users']; ?> users
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-success">
                                                    <?php echo $activity['new_skills']; ?> skills
                                                </span>
                                            </td>
                                            <td>
                                                <strong><?php echo $activity['new_users'] + $activity['new_skills']; ?></strong> items
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Lists -->
        <div class="row g-4 mb-4">
            <!-- Top Tutors -->
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">
                            <i class="fas fa-trophy me-2"></i>Top Tutors by Skills
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Tutor</th>
                                        <th>Skills</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($topTutors)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center py-4 text-muted">
                                                No tutors yet
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php $rank = 1; foreach ($topTutors as $tutor): ?>
                                        <tr>
                                            <td><strong><?php echo $rank++; ?></strong></td>
                                            <td><?php echo escape($tutor['first_name'] . ' ' . $tutor['last_name']); ?></td>
                                            <td>
                                                <span class="badge bg-primary">
                                                    <?php echo $tutor['skills_count']; ?> skills
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Rated Skills -->
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header" style="background-color: #764ba2; color: white;">
                        <h5 class="mb-0">
                            <i class="fas fa-star me-2"></i>Top Rated Skills
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Skill</th>
                                        <th>Rating</th>
                                        <th>Reviews</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($topSkills)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">
                                                No rated skills yet
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php $rank = 1; foreach ($topSkills as $skill): ?>
                                        <tr>
                                            <td><strong><?php echo $rank++; ?></strong></td>
                                            <td>
                                                <?php echo escape(substr($skill['skill_title'], 0, 30)); ?>
                                                <br>
                                                <small class="text-muted">
                                                    by <?php echo escape($skill['first_name'] . ' ' . $skill['last_name']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <i class="fas fa-star text-warning"></i>
                                                <strong><?php echo number_format($skill['avg_rating'], 1); ?></strong>
                                            </td>
                                            <td><?php echo $skill['review_count']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue Statistics -->
        <?php if ($revenueStats && $revenueStats['paid_skills'] > 0): ?>
        <div class="row g-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-dollar-sign me-2"></i>Pricing Statistics
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-3">
                                <h4><?php echo $revenueStats['paid_skills']; ?></h4>
                                <p class="text-muted">Paid Skills</p>
                            </div>
                            <div class="col-md-3">
                                <h4><?php echo formatPrice($revenueStats['avg_price']); ?></h4>
                                <p class="text-muted">Average Price/hr</p>
                            </div>
                            <div class="col-md-3">
                                <h4><?php echo formatPrice($revenueStats['min_price']); ?></h4>
                                <p class="text-muted">Lowest Price/hr</p>
                            </div>
                            <div class="col-md-3">
                                <h4><?php echo formatPrice($revenueStats['max_price']); ?></h4>
                                <p class="text-muted">Highest Price/hr</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Skills by Category Chart
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: [
                    <?php foreach ($skillsByCategory as $cat): ?>
                        '<?php echo addslashes($cat['category_name']); ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    data: [
                        <?php foreach ($skillsByCategory as $cat): ?>
                            <?php echo $cat['count']; ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: [
                        '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#764ba2'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // User Types Chart
        const userTypesCtx = document.getElementById('userTypesChart').getContext('2d');
        new Chart(userTypesCtx, {
            type: 'bar',
            data: {
                labels: [
                    <?php foreach ($userTypes as $type): ?>
                        '<?php echo ucfirst($type['user_type']); ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    label: 'Number of Users',
                    data: [
                        <?php foreach ($userTypes as $type): ?>
                            <?php echo $type['count']; ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: '#28a745'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>