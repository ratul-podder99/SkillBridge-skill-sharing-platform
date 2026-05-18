<?php
session_start();
require_once 'config.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    setFlashMessage('warning', 'Please log in to manage your skills.');
    redirect('login.php');
}

// Check if user is tutor or both
if ($_SESSION['user_type'] !== 'tutor' && $_SESSION['user_type'] !== 'both') {
    setFlashMessage('error', 'Only tutors can manage skill offerings.');
    redirect('dashboard.php');
}

$userId = $_SESSION['user_id'];

// Handle skill deletion
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $skillId = (int)$_GET['id'];
    try {
        $db = getDB();
        $deleteStmt = $db->prepare("DELETE FROM skill_offerings WHERE skill_id = ? AND tutor_id = ?");
        $deleteStmt->execute([$skillId, $userId]);
        setFlashMessage('success', 'Skill deleted successfully!');
        redirect('my-skills.php');
    } catch (PDOException $e) {
        error_log("Skill Delete Error: " . $e->getMessage());
        setFlashMessage('error', 'Failed to delete skill.');
    }
}

// Handle skill activation toggle
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $skillId = (int)$_GET['id'];
    try {
        $db = getDB();
        $toggleStmt = $db->prepare("
            UPDATE skill_offerings 
            SET is_active = NOT is_active 
            WHERE skill_id = ? AND tutor_id = ?
        ");
        $toggleStmt->execute([$skillId, $userId]);
        setFlashMessage('success', 'Skill status updated!');
        redirect('my-skills.php');
    } catch (PDOException $e) {
        error_log("Skill Toggle Error: " . $e->getMessage());
        setFlashMessage('error', 'Failed to update skill status.');
    }
}

try {
    $db = getDB();
    
    // Get all skills with statistics
    $skillsStmt = $db->prepare("
        SELECT 
            so.*,
            c.category_name,
            COUNT(DISTINCT r.review_id) as review_count,
            COALESCE(AVG(r.rating), 0) as avg_rating
        FROM skill_offerings so
        JOIN categories c ON so.category_id = c.category_id
        LEFT JOIN reviews r ON so.skill_id = r.skill_id
        WHERE so.tutor_id = ?
        GROUP BY so.skill_id
        ORDER BY so.created_at DESC
    ");
    $skillsStmt->execute([$userId]);
    $skills = $skillsStmt->fetchAll();
    
    // Get summary statistics
    $statsStmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT so.skill_id) as total_skills,
            SUM(CASE WHEN so.is_active = 1 THEN 1 ELSE 0 END) as active_skills,
            COUNT(DISTINCT r.review_id) as total_reviews,
            COALESCE(AVG(r.rating), 0) as overall_rating
        FROM skill_offerings so
        LEFT JOIN reviews r ON so.skill_id = r.skill_id
        WHERE so.tutor_id = ?
    ");
    $statsStmt->execute([$userId]);
    $stats = $statsStmt->fetch();
    
} catch (PDOException $e) {
    error_log("My Skills Error: " . $e->getMessage());
    $skills = [];
    $stats = [
        'total_skills' => 0,
        'active_skills' => 0,
        'total_reviews' => 0,
        'overall_rating' => 0
    ];
}

$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Skills - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .skill-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .skill-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15) !important;
        }
        .stat-card {
            border-left: 4px solid;
        }
        .stat-card.blue {
            border-color: #667eea;
        }
        .stat-card.green {
            border-color: #28a745;
        }
        .stat-card.warning {
            border-color: #ffc107;
        }
        .stat-card.purple {
            border-color: #764ba2;
        }
        .badge-inactive {
            background-color: #6c757d;
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
                <a class="nav-link active" href="my-skills.php">My Skills</a>
                <a class="nav-link" href="browse-skills.php">Browse Skills</a>
                <a class="nav-link" href="profile.php">Profile</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container my-5">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">
                    <i class="fas fa-list-alt text-primary me-2"></i>My Skills
                </h2>
                <p class="text-muted mb-0">Manage your skill offerings</p>
            </div>
            <a href="create-skill.php" class="btn btn-success btn-lg">
                <i class="fas fa-plus-circle me-2"></i>Add New Skill
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

        <!-- Statistics Cards -->
        <div class="row g-4 mb-5">
            <div class="col-md-3">
                <div class="card stat-card blue shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-book-open fa-3x text-primary mb-3"></i>
                        <h3 class="mb-1"><?php echo $stats['total_skills']; ?></h3>
                        <p class="text-muted mb-0">Total Skills</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card green shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                        <h3 class="mb-1"><?php echo $stats['active_skills']; ?></h3>
                        <p class="text-muted mb-0">Active Skills</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card warning shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-star fa-3x text-warning mb-3"></i>
                        <h3 class="mb-1"><?php echo number_format($stats['overall_rating'], 1); ?></h3>
                        <p class="text-muted mb-0">Average Rating</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card purple shadow-sm">
                    <div class="card-body text-center">
                        <i class="fas fa-comments fa-3x text-info mb-3"></i>
                        <h3 class="mb-1"><?php echo $stats['total_reviews']; ?></h3>
                        <p class="text-muted mb-0">Total Reviews</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Skills List -->
        <?php if (empty($skills)): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="fas fa-inbox fa-4x text-muted mb-4"></i>
                    <h4 class="text-muted mb-3">No Skills Yet</h4>
                    <p class="text-muted mb-4">Start sharing your expertise by creating your first skill offering!</p>
                    <a href="create-skill.php" class="btn btn-success btn-lg">
                        <i class="fas fa-plus-circle me-2"></i>Create Your First Skill
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($skills as $skill): ?>
                <div class="col-lg-6">
                    <div class="card skill-card shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-2">
                                        <a href="skill-details.php?id=<?php echo $skill['skill_id']; ?>" 
                                           class="text-decoration-none text-dark">
                                            <?php echo escape($skill['skill_title']); ?>
                                        </a>
                                    </h5>
                                    <span class="badge bg-primary me-2">
                                        <?php echo escape($skill['category_name']); ?>
                                    </span>
                                    <?php if ($skill['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <p class="card-text text-muted small">
                                <?php echo escape(substr($skill['skill_description'], 0, 120)) . '...'; ?>
                            </p>

                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <?php if ($skill['review_count'] > 0): ?>
                                        <?php echo generateStarRating($skill['avg_rating']); ?>
                                        <small class="text-muted ms-1">(<?php echo $skill['review_count']; ?>)</small>
                                    <?php else: ?>
                                        <small class="text-muted">No reviews yet</small>
                                    <?php endif; ?>
                                </div>
                                <strong class="text-primary">
                                    <?php echo $skill['is_free'] ? 'Free' : formatPrice($skill['price_per_hour']) . '/hr'; ?>
                                </strong>
                            </div>

                            <small class="text-muted d-block mb-3">
                                <i class="fas fa-clock me-1"></i>Created <?php echo timeAgo($skill['created_at']); ?>
                            </small>

                            <div class="d-flex gap-2">
                                <a href="skill-details.php?id=<?php echo $skill['skill_id']; ?>" 
                                   class="btn btn-sm btn-outline-primary flex-fill">
                                    <i class="fas fa-eye me-1"></i>View
                                </a>
                                <a href="edit-skill.php?id=<?php echo $skill['skill_id']; ?>" 
                                   class="btn btn-sm btn-outline-secondary flex-fill">
                                    <i class="fas fa-edit me-1"></i>Edit
                                </a>
                                <a href="my-skills.php?toggle=1&id=<?php echo $skill['skill_id']; ?>" 
                                   class="btn btn-sm btn-outline-<?php echo $skill['is_active'] ? 'warning' : 'success'; ?> flex-fill"
                                   onclick="return confirm('Are you sure you want to <?php echo $skill['is_active'] ? 'deactivate' : 'activate'; ?> this skill?')">
                                    <i class="fas fa-<?php echo $skill['is_active'] ? 'pause' : 'play'; ?> me-1"></i>
                                    <?php echo $skill['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                </a>
                                <a href="my-skills.php?delete=1&id=<?php echo $skill['skill_id']; ?>" 
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Are you sure you want to delete this skill? This action cannot be undone!')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>