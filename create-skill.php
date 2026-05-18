<?php
session_start();
require_once 'config.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    setFlashMessage('warning', 'Please log in to create skills.');
    redirect('login.php');
}

// Check if user is tutor or both
if ($_SESSION['user_type'] !== 'tutor' && $_SESSION['user_type'] !== 'both') {
    setFlashMessage('error', 'Only tutors can create skill offerings.');
    redirect('dashboard.php');
}

$userId = $_SESSION['user_id'];
$errors = [];
$success = false;

// Get categories
try {
    $db = getDB();
    $categoriesStmt = $db->query("SELECT * FROM categories ORDER BY category_name");
    $categories = $categoriesStmt->fetchAll();
} catch (PDOException $e) {
    error_log("Categories Load Error: " . $e->getMessage());
    $categories = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid security token.";
    } else {
        $skillTitle = trim($_POST['skill_title'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $skillDescription = trim($_POST['skill_description'] ?? '');
        $qualifications = trim($_POST['qualifications'] ?? '');
        $availability = trim($_POST['availability'] ?? '');
        $isFree = isset($_POST['is_free']);
        $pricePerHour = $isFree ? 0 : floatval($_POST['price_per_hour'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $hasPremiumContent = isset($_POST['has_premium_content']) ? 1 : 0;
        $videoUrl = trim($_POST['video_url'] ?? '');
        
        // File upload variables
        $roadmapPdf = null;
        $materialsPdf = null;
        
        // Validation
        if (empty($skillTitle)) {
            $errors[] = "Skill title is required.";
        } elseif (strlen($skillTitle) < 5) {
            $errors[] = "Skill title must be at least 5 characters.";
        }
        
        if ($categoryId === 0) {
            $errors[] = "Please select a category.";
        }
        
        if (empty($skillDescription)) {
            $errors[] = "Skill description is required.";
        } elseif (strlen($skillDescription) < 50) {
            $errors[] = "Description must be at least 50 characters.";
        }
        
        if (empty($qualifications)) {
            $errors[] = "Qualifications are required.";
        }
        
        if (empty($availability)) {
            $errors[] = "Availability information is required.";
        }
        
        if (!$isFree && $pricePerHour <= 0) {
            $errors[] = "Please enter a valid price per hour.";
        }
        
        // Validate video URL if premium content is checked
        if ($hasPremiumContent && !empty($videoUrl)) {
            if (!filter_var($videoUrl, FILTER_VALIDATE_URL)) {
                $errors[] = "Please enter a valid video URL.";
            }
        }
        
        // Create upload directories if they don't exist
        $roadmapDir = 'uploads/roadmaps/';
        $materialsDir = 'uploads/materials/';
        
        if (!file_exists($roadmapDir)) {
            mkdir($roadmapDir, 0755, true);
        }
        if (!file_exists($materialsDir)) {
            mkdir($materialsDir, 0755, true);
        }
        
        // Handle Roadmap PDF Upload
        if (isset($_FILES['roadmap_pdf']) && $_FILES['roadmap_pdf']['error'] === UPLOAD_ERR_OK) {
            $fileTmp = $_FILES['roadmap_pdf']['tmp_name'];
            $fileName = $_FILES['roadmap_pdf']['name'];
            $fileSize = $_FILES['roadmap_pdf']['size'];
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            // Validate file
            if ($fileExt !== 'pdf') {
                $errors[] = "Roadmap must be a PDF file.";
            } elseif ($fileSize > 10 * 1024 * 1024) { // 10MB limit
                $errors[] = "Roadmap PDF must be less than 10MB.";
            } else {
                // Generate unique filename
                $newFilename = 'roadmap_' . $userId . '_' . time() . '.pdf';
                $destination = $roadmapDir . $newFilename;
                
                if (move_uploaded_file($fileTmp, $destination)) {
                    $roadmapPdf = $destination;
                } else {
                    $errors[] = "Failed to upload roadmap PDF.";
                }
            }
        }
        
        // Handle Materials PDF Upload (Premium Content)
        if ($hasPremiumContent && isset($_FILES['materials_pdf']) && $_FILES['materials_pdf']['error'] === UPLOAD_ERR_OK) {
            $fileTmp = $_FILES['materials_pdf']['tmp_name'];
            $fileName = $_FILES['materials_pdf']['name'];
            $fileSize = $_FILES['materials_pdf']['size'];
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            // Validate file
            if ($fileExt !== 'pdf') {
                $errors[] = "Materials must be a PDF file.";
            } elseif ($fileSize > 50 * 1024 * 1024) { // 50MB limit
                $errors[] = "Materials PDF must be less than 50MB.";
            } else {
                // Generate unique filename
                $newFilename = 'materials_' . $userId . '_' . time() . '.pdf';
                $destination = $materialsDir . $newFilename;
                
                if (move_uploaded_file($fileTmp, $destination)) {
                    $materialsPdf = $destination;
                } else {
                    $errors[] = "Failed to upload materials PDF.";
                }
            }
        }
        
        if (empty($errors)) {
            try {
                $insertStmt = $db->prepare("
                    INSERT INTO skill_offerings 
                    (tutor_id, category_id, skill_title, skill_description, 
                     qualifications, availability, is_free, price_per_hour, is_active, 
                     roadmap_pdf, materials_pdf, video_url, has_premium_content, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $insertStmt->execute([
                    $userId,
                    $categoryId,
                    $skillTitle,
                    $skillDescription,
                    $qualifications,
                    $availability,
                    $isFree ? 1 : 0,
                    $pricePerHour,
                    $isActive,
                    $roadmapPdf,
                    $materialsPdf,
                    $videoUrl,
                    $hasPremiumContent
                ]);
                
                $skillId = $db->lastInsertId();
                
                setFlashMessage('success', 'Skill created successfully!');
                redirect('skill-details.php?id=' . $skillId);
                
            } catch (PDOException $e) {
                error_log("Skill Creation Error: " . $e->getMessage());
                $errors[] = "Failed to create skill. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Skill - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .char-counter {
            font-size: 0.875rem;
            color: #6c757d;
        }
        .char-counter.warning {
            color: #ffc107;
        }
        .char-counter.danger {
            color: #dc3545;
        }
        
        /* Skill Active Toggle Styling - Sky Blue Theme */
        .skill-active-toggle {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border: none;
            border-left: 4px solid #0ea5e9;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border-radius: 0.5rem;
        }
        
        .skill-active-toggle .form-check-input {
            width: 3rem;
            height: 1.5rem;
            background-color: #cbd5e1;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .skill-active-toggle .form-check-input:checked {
            background-color: #0ea5e9 !important;
            box-shadow: 0 0 0 0.25rem rgba(14, 165, 233, 0.25);
        }
        
        .skill-active-toggle .form-check-input:focus {
            box-shadow: 0 0 0 0.25rem rgba(14, 165, 233, 0.25);
        }
        
        .skill-active-toggle label {
            color: #0c4a6e;
            font-size: 1.1rem;
        }
        
        /* Premium Content Section */
        .premium-section {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #0ea5e9;
            margin: 20px 0;
        }
        
        .premium-section .form-check-input {
            width: 3rem;
            height: 1.5rem;
            cursor: pointer;
        }
        
        .premium-section .form-check-input:checked {
            background-color: #0ea5e9 !important;
        }
        
        #premium-content-fields {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            padding: 20px;
            border-radius: 8px;
            margin-top: 15px;
            border: 2px dashed #f59e0b;
            display: none; /* Hidden by default */
        }
        
        /* File Upload Box Styling */
        .file-upload-box {
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            background: #f8fafc;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .file-upload-box:hover {
            border-color: #0ea5e9;
            background: #eff6ff;
        }
        
        .file-upload-box input[type="file"] {
            display: none;
        }
        
        .file-upload-label {
            cursor: pointer;
            display: block;
            margin: 0;
        }
        
        .file-name-display {
            margin-top: 10px;
            font-size: 0.9rem;
            color: #059669;
            font-weight: 500;
        }
        
        /* Header - Sky Blue */
        .card-header.bg-primary-custom {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%) !important;
            border: none;
        }
        
        /* Button - Sky Blue */
        .btn-primary-custom {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%) !important;
            border: none !important;
            color: white !important;
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom:hover {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%) !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
        }
        
        /* Free Toggle - Sky Blue */
        #is_free:checked {
            background-color: #0ea5e9 !important;
        }
        
        #is_free {
            width: 3rem;
            height: 1.5rem;
            cursor: pointer;
        }
        
        /* Better form focus states */
        .form-control:focus,
        .form-select:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 0.25rem rgba(14, 165, 233, 0.15);
        }
        
        .card {
            border: none;
            border-radius: 12px;
        }
        
        /* Debug helper - Remove after testing */
        .debug-info {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 10px;
            border-radius: 5px;
            font-size: 12px;
            z-index: 9999;
            display: none;
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
                <a class="nav-link" href="my-skills.php">My Skills</a>
                <a class="nav-link" href="manage-enrollments.php">Enrollments</a>
                <a class="nav-link" href="messages.php">Messages</a>
                <a class="nav-link" href="profile.php">Profile</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container my-5">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="card shadow">
                    <div class="card-header bg-primary-custom text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-plus-circle me-2"></i>Create New Skill Offering
                        </h4>
                    </div>
                    <div class="card-body p-4">
                        
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Please fix the following errors:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo escape($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="createSkillForm" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            
                            <!-- Skill Active Toggle -->
                            <div class="skill-active-toggle">
                                <div class="form-check form-switch d-flex align-items-center">
                                    <input class="form-check-input me-3" type="checkbox" 
                                           id="is_active" name="is_active" checked>
                                    <div>
                                        <label class="form-check-label fw-bold mb-0" for="is_active">
                                            Skill is Active
                                        </label>
                                        <p class="mb-0 text-muted small">Inactive skills won't appear in search results</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Skill Title -->
                            <div class="mb-4">
                                <label for="skill_title" class="form-label fw-bold">
                                    Skill Title *
                                </label>
                                <input type="text" class="form-control form-control-lg" 
                                       id="skill_title" name="skill_title" 
                                       value="<?php echo escape($_POST['skill_title'] ?? ''); ?>"
                                       placeholder="e.g., Advanced Python Programming"
                                       required maxlength="200">
                                <small class="text-muted">
                                    Give your skill a clear, descriptive title (min 5 characters)
                                </small>
                            </div>

                            <!-- Category -->
                            <div class="mb-4">
                                <label for="category_id" class="form-label fw-bold">
                                    Category *
                                </label>
                                <select class="form-select form-select-lg" id="category_id" 
                                        name="category_id" required>
                                    <option value="">Select a category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['category_id']; ?>"
                                                <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $category['category_id']) ? 'selected' : ''; ?>>
                                            <?php echo escape($category['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Description -->
                            <div class="mb-4">
                                <label for="skill_description" class="form-label fw-bold">
                                    Description *
                                </label>
                                <textarea class="form-control" id="skill_description" 
                                          name="skill_description" rows="6" required
                                          placeholder="Describe what you'll teach, your teaching style, and what students will learn..."><?php echo escape($_POST['skill_description'] ?? ''); ?></textarea>
                                <div class="d-flex justify-content-between mt-1">
                                    <small class="text-muted">Minimum 50 characters</small>
                                    <small class="char-counter" id="descCounter">0 characters</small>
                                </div>
                            </div>

                            <!-- Qualifications -->
                            <div class="mb-4">
                                <label for="qualifications" class="form-label fw-bold">
                                    Your Qualifications *
                                </label>
                                <textarea class="form-control" id="qualifications" 
                                          name="qualifications" rows="4" required
                                          placeholder="List your relevant education, certifications, experience, or achievements..."><?php echo escape($_POST['qualifications'] ?? ''); ?></textarea>
                                <small class="text-muted">
                                    Help learners understand why you're qualified to teach this skill
                                </small>
                            </div>

                            <hr class="my-4">

                            <!-- Course Roadmap PDF Upload (Public) -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-file-pdf me-2 text-danger"></i>Course Roadmap PDF (Optional)
                                </label>
                                <div class="file-upload-box" onclick="document.getElementById('roadmap_pdf').click();">
                                    <label for="roadmap_pdf" class="file-upload-label">
                                        <i class="fas fa-cloud-upload-alt fa-3x text-primary mb-3"></i>
                                        <p class="mb-0">Click to upload roadmap PDF</p>
                                        <small class="text-muted">Max 10MB - This will be visible to all users</small>
                                    </label>
                                    <input type="file" id="roadmap_pdf" name="roadmap_pdf" accept=".pdf" onclick="event.stopPropagation();">
                                    <div class="file-name-display" id="roadmap-filename"></div>
                                </div>
                                <small class="text-muted d-block mt-2">
                                    Upload a PDF showing your course structure/syllabus. This helps students understand what they'll learn.
                                </small>
                            </div>

                            <hr class="my-4">

                            <!-- Premium Content Section -->
                            <div class="premium-section">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" 
                                           id="has_premium_content" name="has_premium_content"
                                           <?php echo isset($_POST['has_premium_content']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold" for="has_premium_content">
                                        <i class="fas fa-star text-warning me-2"></i>This course has premium content
                                    </label>
                                </div>
                                <small class="text-muted d-block mt-2">
                                    Enable this if you have materials/videos only for enrolled students
                                </small>
                            </div>

                            <!-- Premium Content Fields -->
                            <div id="premium-content-fields">
                                <h5 class="mb-3">
                                    <i class="fas fa-lock text-warning me-2"></i>Premium Content (Only for Enrolled Students)
                                </h5>
                                
                                <!-- Course Materials PDF Upload -->
                                <div class="mb-4">
                                    <label class="form-label fw-bold">
                                        <i class="fas fa-book me-2 text-success"></i>Course Materials PDF
                                    </label>
                                    <div class="file-upload-box" onclick="document.getElementById('materials_pdf').click();">
                                        <label for="materials_pdf" class="file-upload-label">
                                            <i class="fas fa-cloud-upload-alt fa-3x text-success mb-3"></i>
                                            <p class="mb-0">Click to upload materials PDF</p>
                                            <small class="text-muted">Max 50MB - Only enrolled students can access</small>
                                        </label>
                                        <input type="file" id="materials_pdf" name="materials_pdf" accept=".pdf" onclick="event.stopPropagation();">
                                        <div class="file-name-display" id="materials-filename"></div>
                                    </div>
                                    <small class="text-muted d-block mt-2">
                                        Upload your course notes, study materials, or worksheets
                                    </small>
                                </div>

                                <!-- Video URL -->
                                <div class="mb-4">
                                    <label for="video_url" class="form-label fw-bold">
                                        <i class="fas fa-video me-2 text-danger"></i>Course Video URL
                                    </label>
                                    <input type="url" class="form-control" id="video_url" name="video_url"
                                           value="<?php echo escape($_POST['video_url'] ?? ''); ?>"
                                           placeholder="https://youtube.com/watch?v=... or https://drive.google.com/...">
                                    <small class="text-muted">
                                        YouTube, Google Drive, or other video platform URL. Only enrolled students can access.
                                    </small>
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Availability -->
                            <div class="mb-4">
                                <label for="availability" class="form-label fw-bold">
                                    Availability *
                                </label>
                                <textarea class="form-control" id="availability" 
                                          name="availability" rows="3" required
                                          placeholder="e.g., Weekdays 6-9 PM, Weekends flexible, or By appointment..."><?php echo escape($_POST['availability'] ?? ''); ?></textarea>
                                <small class="text-muted">
                                    When are you generally available to teach?
                                </small>
                            </div>

                            <!-- Pricing -->
                            <h5 class="mb-3">Pricing</h5>
                            
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" 
                                           id="is_free" name="is_free" 
                                           <?php echo isset($_POST['is_free']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_free">
                                        <strong>Offer this skill for free</strong>
                                    </label>
                                </div>
                            </div>

                            <div class="mb-4" id="pricingSection">
                                <label for="price_per_hour" class="form-label fw-bold">
                                    Price per Hour (BDT) *
                                </label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text">৳</span>
                                    <input type="number" class="form-control" 
                                           id="price_per_hour" name="price_per_hour" 
                                           value="<?php echo escape($_POST['price_per_hour'] ?? ''); ?>"
                                           min="0" step="50" placeholder="500">
                                    <span class="input-group-text">per hour</span>
                                </div>
                                <small class="text-muted">
                                    Set a fair price based on your experience and market rates
                                </small>
                            </div>

                            <hr class="my-4">

                            <div class="d-flex justify-content-between">
                                <a href="dashboard.php" class="btn btn-outline-secondary btn-lg">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-primary-custom btn-lg">
                                    <i class="fas fa-check me-2"></i>Create Skill
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card mt-4 border-info">
                    <div class="card-body">
                        <h6 class="card-title">
                            <i class="fas fa-lightbulb text-warning me-2"></i>Tips for Success
                        </h6>
                        <ul class="mb-0 small">
                            <li>Use a clear, specific title that accurately describes your skill</li>
                            <li>Write a detailed description (200+ words recommended)</li>
                            <li>Upload a roadmap PDF to show your course structure</li>
                            <li>Add premium materials/videos for enrolled students</li>
                            <li>Highlight your unique qualifications and teaching approach</li>
                            <li>Be honest about your availability</li>
                            <li>Research similar skills to price competitively</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Debug Info (Remove after testing) -->
    <div class="debug-info" id="debugInfo"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        console.log('Script loaded');
        
        // Wait for DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM Content Loaded');
            
            // Character counter for description
            const descTextarea = document.getElementById('skill_description');
            const descCounter = document.getElementById('descCounter');
            
            if (descTextarea && descCounter) {
                descTextarea.addEventListener('input', function() {
                    const count = this.value.length;
                    descCounter.textContent = count + ' characters';
                    
                    if (count < 50) {
                        descCounter.classList.add('danger');
                        descCounter.classList.remove('warning');
                    } else if (count < 100) {
                        descCounter.classList.add('warning');
                        descCounter.classList.remove('danger');
                    } else {
                        descCounter.classList.remove('warning', 'danger');
                    }
                });
                
                // Trigger on page load
                descTextarea.dispatchEvent(new Event('input'));
            }
            
            // Toggle pricing section
            const isFreeCheckbox = document.getElementById('is_free');
            const pricingSection = document.getElementById('pricingSection');
            const priceInput = document.getElementById('price_per_hour');
            
            if (isFreeCheckbox && pricingSection && priceInput) {
                isFreeCheckbox.addEventListener('change', function() {
                    console.log('Free checkbox changed:', this.checked);
                    if (this.checked) {
                        pricingSection.style.display = 'none';
                        priceInput.required = false;
                        priceInput.value = '0';
                    } else {
                        pricingSection.style.display = 'block';
                        priceInput.required = true;
                        priceInput.value = '';
                    }
                });
                
                // Trigger on page load
                if (isFreeCheckbox.checked) {
                    pricingSection.style.display = 'none';
                    priceInput.required = false;
                }
            }

            // Toggle premium content fields - ENHANCED VERSION
            const hasPremiumCheckbox = document.getElementById('has_premium_content');
            const premiumFields = document.getElementById('premium-content-fields');
            
            if (hasPremiumCheckbox && premiumFields) {
                console.log('Premium checkbox found');
                
                // Set initial state
                premiumFields.style.display = hasPremiumCheckbox.checked ? 'block' : 'none';
                console.log('Initial premium state:', hasPremiumCheckbox.checked);
                
                // Add change event listener
                hasPremiumCheckbox.addEventListener('change', function() {
                    console.log('Premium checkbox changed:', this.checked);
                    
                    if (this.checked) {
                        premiumFields.style.display = 'block';
                        console.log('Showing premium fields');
                    } else {
                        premiumFields.style.display = 'none';
                        console.log('Hiding premium fields');
                    }
                    
                    // Update debug info
                    updateDebugInfo();
                });
                
                // Trigger initial state
                if (hasPremiumCheckbox.checked) {
                    premiumFields.style.display = 'block';
                }
            } else {
                console.error('Premium elements not found!');
            }

            // File upload displays
            const roadmapInput = document.getElementById('roadmap_pdf');
            const roadmapDisplay = document.getElementById('roadmap-filename');
            
            if (roadmapInput && roadmapDisplay) {
                roadmapInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        roadmapDisplay.innerHTML = '<i class="fas fa-check-circle me-2"></i>' + this.files[0].name;
                    } else {
                        roadmapDisplay.innerHTML = '';
                    }
                });
            }

            const materialsInput = document.getElementById('materials_pdf');
            const materialsDisplay = document.getElementById('materials-filename');
            
            if (materialsInput && materialsDisplay) {
                materialsInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        materialsDisplay.innerHTML = '<i class="fas fa-check-circle me-2"></i>' + this.files[0].name;
                    } else {
                        materialsDisplay.innerHTML = '';
                    }
                });
            }
            
            // Debug helper function
            function updateDebugInfo() {
                const debugDiv = document.getElementById('debugInfo');
                const premiumChecked = hasPremiumCheckbox.checked;
                const premiumDisplay = premiumFields.style.display;
                
                debugDiv.innerHTML = `
                    Premium Checked: ${premiumChecked}<br>
                    Premium Display: ${premiumDisplay}<br>
                    Time: ${new Date().toLocaleTimeString()}
                `;
                debugDiv.style.display = 'block';
                
                // Auto-hide after 3 seconds
                setTimeout(() => {
                    debugDiv.style.display = 'none';
                }, 3000);
            }
        });
    </script>
</body>
</html>