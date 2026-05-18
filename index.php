<?php
/**
 * Homepage / Landing Page
 * Main entry point for SkillBridge platform
 */

session_start();
require_once 'config.php';

// Get popular skills and categories
try {
    $db = getDB();
    
    // Get featured categories
    $categoryStmt = $db->prepare("
        SELECT category_id, category_name, description, icon 
        FROM categories 
        WHERE is_active = TRUE 
        ORDER BY category_name 
        LIMIT 6
    ");
    $categoryStmt->execute();
    $categories = $categoryStmt->fetchAll();
    
    // Get popular skills (skills with reviews)
    $skillStmt = $db->prepare("
        SELECT 
            so.skill_id,
            so.skill_title,
            so.skill_description,
            so.price_per_hour,
            so.is_free,
            c.category_name,
            u.first_name,
            u.last_name,
            u.is_verified,
            COUNT(r.review_id) as review_count,
            COALESCE(AVG(r.rating), 0) as avg_rating
        FROM skill_offerings so
        JOIN users u ON so.tutor_id = u.user_id
        JOIN categories c ON so.category_id = c.category_id
        LEFT JOIN reviews r ON so.skill_id = r.skill_id
        WHERE so.is_active = TRUE AND u.is_active = TRUE
        GROUP BY so.skill_id
        ORDER BY review_count DESC, avg_rating DESC
        LIMIT 6
    ");
    $skillStmt->execute();
    $popularSkills = $skillStmt->fetchAll();
    
    // Get statistics
    $statsStmt = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM users WHERE is_active = TRUE) as total_users,
            (SELECT COUNT(*) FROM users WHERE user_type IN ('tutor', 'both') AND is_verified = TRUE) as verified_tutors,
            (SELECT COUNT(*) FROM skill_offerings WHERE is_active = TRUE) as total_skills,
            (SELECT COUNT(*) FROM reviews) as total_reviews
    ");
    $stats = $statsStmt->fetch();
    
} catch (PDOException $e) {
    error_log("Homepage Error: " . $e->getMessage());
    $categories = [];
    $popularSkills = [];
    $stats = ['total_users' => 0, 'verified_tutors' => 0, 'total_skills' => 0, 'total_reviews' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Learn & Share Skills in Your Community</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
        }
        
        .hero-section {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 100px 0;
        }
        
        .search-box {
            background: white;
            border-radius: 50px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        .category-card {
            transition: transform 0.3s, box-shadow 0.3s;
            border: none;
            border-radius: 15px;
        }
        
        .category-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .skill-card {
            transition: all 0.3s;
            border-radius: 15px;
            overflow: hidden;
        }
        
        .skill-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
        }
        
        .badge-verified {
            background: #28a745;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
        }
        
        .feature-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            margin: 0 auto 15px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm sticky-top">
        <div class="container">
            <a class="navbar-brand fw-bold fs-4" href="index.php">
                <i class="fas fa-bridge text-primary me-2"></i><?php echo SITE_NAME; ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link" href="browse-skills.php">Browse Skills</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="how-it-works.php">How It Works</a>
                    </li>
                    <?php if (isLoggedIn()): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">Dashboard</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="messages.php">
                                <i class="fas fa-envelope"></i> Messages
                            </a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" 
                               data-bs-toggle="dropdown">
                                <i class="fas fa-user-circle"></i> 
                                <?php echo escape($_SESSION['first_name']); ?>
                            </a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="profile.php">My Profile</a></li>
                                <li><a class="dropdown-item" href="my-skills.php">My Skills</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="login.php">Login</a>
                        </li>
                        <li class="nav-item">
                            <a class="btn btn-primary rounded-pill px-4" href="register.php">Get Started</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8 mx-auto text-center">
                    <h1 class="display-3 fw-bold mb-4">Learn & Share Skills<br>in Your Community</h1>
                    <p class="lead mb-5">Connect with local experts, master new skills, or teach what you know best. 
                    SkillBridge makes peer-to-peer learning easy and accessible.</p>
                    
                    <!-- Search Box -->
                    <form action="browse-skills.php" method="GET" class="search-box p-2">
                        <div class="input-group input-group-lg">
                            <input type="text" class="form-control border-0" name="q" 
                                   placeholder="What do you want to learn today?" 
                                   aria-label="Search skills">
                            <button class="btn btn-primary rounded-pill px-5" type="submit">
                                <i class="fas fa-search me-2"></i>Search
                            </button>
                        </div>
                    </form>
                    
                    <div class="mt-4">
                        <small class="text-white-50">
                            Popular: <a href="browse-skills.php?cat=1" class="text-white ms-2">Web Development</a>
                            <a href="browse-skills.php?cat=2" class="text-white ms-2">Graphic Design</a>
                            <a href="browse-skills.php?cat=4" class="text-white ms-2">Language Learning</a>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-3">
                    <div class="stat-card text-center">
                        <i class="fas fa-users fa-3x mb-3"></i>
                        <h2 class="fw-bold"><?php echo number_format($stats['total_users']); ?>+</h2>
                        <p class="mb-0">Active Users</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card text-center">
                        <i class="fas fa-chalkboard-teacher fa-3x mb-3"></i>
                        <h2 class="fw-bold"><?php echo number_format($stats['verified_tutors']); ?>+</h2>
                        <p class="mb-0">Verified Tutors</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card text-center">
                        <i class="fas fa-book-open fa-3x mb-3"></i>
                        <h2 class="fw-bold"><?php echo number_format($stats['total_skills']); ?>+</h2>
                        <p class="mb-0">Skills Offered</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card text-center">
                        <i class="fas fa-star fa-3x mb-3"></i>
                        <h2 class="fw-bold"><?php echo number_format($stats['total_reviews']); ?>+</h2>
                        <p class="mb-0">Reviews</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Categories Section -->
    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold">Explore Popular Categories</h2>
                <p class="text-muted">Find the perfect skill to learn or teach</p>
            </div>
            
            <div class="row g-4">
                <?php foreach ($categories as $category): ?>
                <div class="col-md-4 col-lg-2">
                    <a href="browse-skills.php?category=<?php echo $category['category_id']; ?>" 
                       class="text-decoration-none">
                        <div class="card category-card text-center p-4">
                            <i class="fas <?php echo escape($category['icon']); ?> fa-3x text-primary mb-3"></i>
                            <h6 class="fw-bold"><?php echo escape($category['category_name']); ?></h6>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Popular Skills Section -->
    <?php if (!empty($popularSkills)): ?>
    <section class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold">Popular Skills</h2>
                <p class="text-muted">Highly rated skills from our community</p>
            </div>
            
            <div class="row g-4">
                <?php foreach ($popularSkills as $skill): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card skill-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <span class="badge bg-primary"><?php echo escape($skill['category_name']); ?></span>
                                <?php if ($skill['is_verified']): ?>
                                <span class="badge-verified">
                                    <i class="fas fa-check-circle"></i> Verified
                                </span>
                                <?php endif; ?>
                            </div>
                            
                            <h5 class="card-title fw-bold"><?php echo escape($skill['skill_title']); ?></h5>
                            <p class="text-muted small mb-3">
                                by <?php echo escape($skill['first_name'] . ' ' . $skill['last_name']); ?>
                            </p>
                            
                            <p class="card-text text-muted">
                                <?php echo escape(substr($skill['skill_description'], 0, 100)) . '...'; ?>
                            </p>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <?php if ($skill['review_count'] > 0): ?>
                                        <?php echo generateStarRating($skill['avg_rating']); ?>
                                        <small class="text-muted ms-2">
                                            (<?php echo $skill['review_count']; ?>)
                                        </small>
                                    <?php else: ?>
                                        <small class="text-muted">No reviews yet</small>
                                    <?php endif; ?>
                                </div>
                                <div class="fw-bold text-primary">
                                    <?php echo $skill['is_free'] ? 'Free' : formatPrice($skill['price_per_hour']) . '/hr'; ?>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-0">
                            <a href="skill-details.php?id=<?php echo $skill['skill_id']; ?>" 
                               class="btn btn-outline-primary w-100">View Details</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="text-center mt-5">
                <a href="browse-skills.php" class="btn btn-primary btn-lg rounded-pill px-5">
                    Browse All Skills <i class="fas fa-arrow-right ms-2"></i>
                </a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- How It Works Section -->
    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold">How It Works</h2>
                <p class="text-muted">Get started in three simple steps</p>
            </div>
            
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="text-center">
                        <div class="feature-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h4 class="fw-bold">1. Create Your Profile</h4>
                        <p class="text-muted">Sign up and set up your profile as a learner, tutor, or both. 
                        It's free and takes less than 2 minutes.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center">
                        <div class="feature-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <h4 class="fw-bold">2. Find or Share Skills</h4>
                        <p class="text-muted">Browse skills you want to learn or create your own skill offerings 
                        to share your expertise.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center">
                        <div class="feature-icon">
                            <i class="fas fa-comments"></i>
                        </div>
                        <h4 class="fw-bold">3. Connect & Learn</h4>
                        <p class="text-muted">Message tutors to arrange lessons, learn new skills, and leave 
                        reviews to help the community.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-5" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8 mx-auto text-center text-white">
                    <h2 class="fw-bold mb-4">Ready to Start Your Learning Journey?</h2>
                    <p class="lead mb-4">Join thousands of learners and tutors in our community today.</p>
                    <div class="d-flex gap-3 justify-content-center">
                        <a href="register.php" class="btn btn-light btn-lg rounded-pill px-5">
                            Get Started Free
                        </a>
                        <a href="browse-skills.php" class="btn btn-outline-light btn-lg rounded-pill px-5">
                            Browse Skills
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-dark text-white py-5">
        <div class="container">
            <div class="row">
                <div class="col-md-4 mb-4">
                    <h5 class="fw-bold mb-3">
                        <i class="fas fa-bridge me-2"></i><?php echo SITE_NAME; ?>
                    </h5>
                    <p class="text-muted">Connecting local learners and tutors for peer-to-peer skill sharing.</p>
                </div>
                <div class="col-md-4 mb-4">
                    <h6 class="fw-bold mb-3">Quick Links</h6>
                    <ul class="list-unstyled">
                        <li><a href="about.php" class="text-muted text-decoration-none">About Us</a></li>
                        <li><a href="browse-skills.php" class="text-muted text-decoration-none">Browse Skills</a></li>
                        <li><a href="how-it-works.php" class="text-muted text-decoration-none">How It Works</a></li>
                        <li><a href="contact.php" class="text-muted text-decoration-none">Contact</a></li>
                    </ul>
                </div>
                <div class="col-md-4 mb-4">
                    <h6 class="fw-bold mb-3">Connect With Us</h6>
                    <div class="d-flex gap-3">
                        <a href="#" class="text-white"><i class="fab fa-facebook fa-2x"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-twitter fa-2x"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-instagram fa-2x"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-linkedin fa-2x"></i></a>
                    </div>
                </div>
            </div>
            <hr class="bg-secondary">
            <div class="text-center text-muted">
                <p class="mb-0">&copy; 2024 <?php echo SITE_NAME; ?>. All rights reserved. | 
                <a href="privacy.php" class="text-muted">Privacy Policy</a> | 
                <a href="terms.php" class="text-muted">Terms of Service</a></p>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
