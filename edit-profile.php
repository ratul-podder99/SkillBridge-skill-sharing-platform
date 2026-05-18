<?php
session_start();
require_once 'config.php';

// Redirect if not logged in
if (!isLoggedIn()) {
    setFlashMessage('warning', 'Please log in to edit your profile.');
    redirect('login.php');
}

$userId = $_SESSION['user_id'];
$errors = [];
$success = false;

// Get current user data
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        setFlashMessage('error', 'User not found.');
        redirect('dashboard.php');
    }
    
} catch (PDOException $e) {
    error_log("Profile Load Error: " . $e->getMessage());
    setFlashMessage('error', 'Error loading profile.');
    redirect('dashboard.php');
}

// Handle photo deletion
if (isset($_GET['delete_photo']) && $_GET['delete_photo'] === '1') {
    if ($user['profile_photo']) {
        $photoPath = __DIR__ . '/' . $user['profile_photo'];
        if (file_exists($photoPath)) {
            unlink($photoPath);
        }
        try {
            $db->prepare("UPDATE users SET profile_photo = NULL WHERE user_id = ?")->execute([$userId]);
            setFlashMessage('success', 'Profile photo removed successfully!');
            redirect('edit-profile.php');
        } catch (PDOException $e) {
            error_log("Photo Delete Error: " . $e->getMessage());
            setFlashMessage('error', 'Failed to remove photo.');
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid security token.";
    } else {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        // Validation
        if (empty($firstName)) $errors[] = "First name is required.";
        if (empty($lastName)) $errors[] = "Last name is required.";
        
        // Handle photo upload
        $photoPath = $user['profile_photo'];
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['profile_photo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (!in_array($ext, $allowed)) {
                $errors[] = "Invalid file type. Use JPG, PNG, or GIF.";
            } elseif ($_FILES['profile_photo']['size'] > 5242880) {
                $errors[] = "File too large. Max 5MB.";
            } else {
                $uploadDir = __DIR__ . '/uploads/profiles/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $newFilename = 'user_' . $userId . '_' . time() . '.' . $ext;
                $uploadPath = $uploadDir . $newFilename;
                
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadPath)) {
                    $photoPath = 'uploads/profiles/' . $newFilename;
                    
                    if ($user['profile_photo'] && file_exists(__DIR__ . '/' . $user['profile_photo'])) {
                        unlink(__DIR__ . '/' . $user['profile_photo']);
                    }
                } else {
                    $errors[] = "Failed to upload photo.";
                }
            }
        }
        
        if (empty($errors)) {
            try {
                $updateStmt = $db->prepare("
                    UPDATE users 
                    SET first_name = ?, last_name = ?, bio = ?, phone = ?, 
                        profile_photo = ?, updated_at = NOW()
                    WHERE user_id = ?
                ");
                $updateStmt->execute([$firstName, $lastName, $bio, $phone, $photoPath, $userId]);
                
                $_SESSION['first_name'] = $firstName;
                $_SESSION['last_name'] = $lastName;
                
                // Handle social links
                $db->prepare("DELETE FROM social_links WHERE user_id = ?")->execute([$userId]);
                
                if (!empty($_POST['social_platform']) && !empty($_POST['social_url'])) {
                    $platforms = $_POST['social_platform'];
                    $urls = $_POST['social_url'];
                    
                    $socialInsert = $db->prepare("INSERT INTO social_links (user_id, platform, url) VALUES (?, ?, ?)");
                    
                    foreach ($platforms as $index => $platform) {
                        if (!empty($platform) && !empty($urls[$index])) {
                            $socialInsert->execute([$userId, $platform, $urls[$index]]);
                        }
                    }
                }
                
                $success = true;
                setFlashMessage('success', 'Profile updated successfully!');
                
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                
            } catch (PDOException $e) {
                error_log("Profile Update Error: " . $e->getMessage());
                $errors[] = "Failed to update profile.";
            }
        }
    }
}

// Get flash message
$flashMessage = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .profile-photo-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #667eea;
        }
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
                <a class="nav-link" href="profile.php">My Profile</a>
                <a class="nav-link" href="logout.php">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container my-5">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-user-edit me-2"></i>Edit Profile</h4>
                    </div>
                    <div class="card-body p-4">
                        
                        <?php if ($flashMessage): ?>
                            <div class="alert alert-<?php echo $flashMessage['type']; ?> alert-dismissible fade show">
                                <i class="fas fa-<?php echo $flashMessage['type'] === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                                <?php echo escape($flashMessage['message']); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="fas fa-check-circle me-2"></i>Profile updated successfully!
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo escape($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            
                            <div class="text-center mb-4">
                                <h5 class="mb-3">Profile Photo</h5>
                                <?php if ($user['profile_photo']): ?>
                                    <img src="<?php echo escape($user['profile_photo']); ?>" 
                                         class="profile-photo-preview mb-3" id="photoPreview" alt="Profile">
                                    <div class="mt-2 mb-3">
                                        <a href="edit-profile.php?delete_photo=1" 
                                           class="btn btn-danger btn-sm"
                                           onclick="return confirm('Are you sure you want to remove your profile photo?')">
                                            <i class="fas fa-trash me-1"></i>Remove Photo
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="profile-photo-preview bg-secondary d-inline-flex align-items-center justify-content-center mb-3" id="photoPreview">
                                        <i class="fas fa-user fa-4x text-white"></i>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <input type="file" class="form-control" id="profile_photo" 
                                           name="profile_photo" accept="image/*">
                                    <small class="text-muted">JPG, PNG or GIF. Max 5MB.</small>
                                </div>
                            </div>

                            <hr class="my-4">

                            <h5 class="mb-3">Basic Information</h5>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="first_name" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="first_name" 
                                           name="first_name" value="<?php echo escape($user['first_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="last_name" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="last_name" 
                                           name="last_name" value="<?php echo escape($user['last_name']); ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" value="<?php echo escape($user['email']); ?>" disabled>
                                <small class="text-muted">Email cannot be changed</small>
                            </div>

                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" 
                                       name="phone" value="<?php echo escape($user['phone'] ?? ''); ?>" 
                                       placeholder="+1234567890">
                            </div>

                            <div class="mb-3">
                                <label for="bio" class="form-label">Bio</label>
                                <textarea class="form-control" id="bio" name="bio" rows="4" 
                                          placeholder="Tell others about yourself..."><?php echo escape($user['bio'] ?? ''); ?></textarea>
                                <small class="text-muted">Introduce yourself to the community</small>
                            </div>

                            <hr class="my-4">

                            <h5 class="mb-3">Social Links</h5>
                            <div id="socialLinks">
                                <div class="row mb-2">
                                    <div class="col-md-4">
                                        <select class="form-select" name="social_platform[]">
                                            <option value="">Select Platform</option>
                                            <option value="linkedin">LinkedIn</option>
                                            <option value="github">GitHub</option>
                                            <option value="twitter">Twitter</option>
                                            <option value="facebook">Facebook</option>
                                            <option value="instagram">Instagram</option>
                                            <option value="youtube">YouTube</option>
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <input type="url" class="form-control" name="social_url[]" 
                                               placeholder="https://...">
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mb-3" onclick="addSocialLink()">
                                <i class="fas fa-plus me-1"></i>Add Another Link
                            </button>

                            <hr class="my-4">

                            <div class="d-flex justify-content-between">
                                <a href="profile.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('profile_photo').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('photoPreview');
                    preview.src = e.target.result;
                    preview.classList.remove('d-inline-flex', 'bg-secondary');
                    preview.innerHTML = '';
                }
                reader.readAsDataURL(file);
            }
        });

        function addSocialLink() {
            const container = document.getElementById('socialLinks');
            const newRow = document.createElement('div');
            newRow.className = 'row mb-2';
            newRow.innerHTML = `
                <div class="col-md-4">
                    <select class="form-select" name="social_platform[]">
                        <option value="">Select Platform</option>
                        <option value="linkedin">LinkedIn</option>
                        <option value="github">GitHub</option>
                        <option value="twitter">Twitter</option>
                        <option value="facebook">Facebook</option>
                        <option value="instagram">Instagram</option>
                        <option value="youtube">YouTube</option>
                    </select>
                </div>
                <div class="col-md-7">
                    <input type="url" class="form-control" name="social_url[]" placeholder="https://...">
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.parentElement.remove()">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            container.appendChild(newRow);
        }
    </script>
</body>
</html>