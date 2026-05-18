<?php
/**
 * User Registration Page
 * Handles new user account creation with validation and security
 */

session_start();
require_once 'config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Invalid security token. Please try again.";
    } else {
        // Sanitize and validate input
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $user_type = $_POST['user_type'] ?? 'learner';
        
        // Validation
        if (empty($first_name)) {
            $errors[] = "First name is required.";
        } elseif (strlen($first_name) > 100) {
            $errors[] = "First name is too long.";
        }
        
        if (empty($last_name)) {
            $errors[] = "Last name is required.";
        } elseif (strlen($last_name) > 100) {
            $errors[] = "Last name is too long.";
        }
        
        if (empty($email)) {
            $errors[] = "Email is required.";
        } elseif (!isValidEmail($email)) {
            $errors[] = "Invalid email format.";
        }
        
        if (empty($password)) {
            $errors[] = "Password is required.";
        } elseif (!isValidPassword($password)) {
            $errors[] = "Password must be at least 8 characters and contain uppercase, lowercase, and numbers.";
        }
        
        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match.";
        }
        
        if (!in_array($user_type, ['learner', 'tutor', 'both'])) {
            $errors[] = "Invalid user type selected.";
        }
        
        // Check if email already exists
        if (empty($errors)) {
            try {
                $db = getDB();
                $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                
                if ($stmt->fetch()) {
                    $errors[] = "An account with this email already exists.";
                }
            } catch (PDOException $e) {
                error_log("Registration Check Error: " . $e->getMessage());
                $errors[] = "A system error occurred. Please try again.";
            }
        }
        
        // Register user if no errors
        if (empty($errors)) {
            try {
                $db = getDB();
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $db->prepare("
                    INSERT INTO users (email, password_hash, first_name, last_name, user_type) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([$email, $password_hash, $first_name, $last_name, $user_type]);
                
                $success = true;
                setFlashMessage('success', 'Registration successful! Please log in.');
                
                // Redirect to login after 2 seconds
                header("refresh:2;url=" . SITE_URL . "/login.php");
                
            } catch (PDOException $e) {
                error_log("Registration Error: " . $e->getMessage());
                $errors[] = "Registration failed. Please try again.";
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
    <title>Register - <?php echo SITE_NAME; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .register-card {
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .password-strength {
            height: 5px;
            border-radius: 3px;
            transition: all 0.3s;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card register-card">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <h2 class="fw-bold text-primary">
                                <i class="fas fa-bridge me-2"></i><?php echo SITE_NAME; ?>
                            </h2>
                            <p class="text-muted">Create your account</p>
                        </div>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>Success!</strong> Your account has been created. Redirecting to login...
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo escape($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$success): ?>
                        <form method="POST" action="" id="registerForm">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="first_name" class="form-label">First Name</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" 
                                           value="<?php echo escape($_POST['first_name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="last_name" class="form-label">Last Name</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" 
                                           value="<?php echo escape($_POST['last_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo escape($_POST['email'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                                <div class="password-strength mt-2" id="passwordStrength"></div>
                                <small class="text-muted">
                                    Min 8 characters, including uppercase, lowercase, and numbers
                                </small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password</label>
                                <input type="password" class="form-control" id="confirm_password" 
                                       name="confirm_password" required>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label">I want to:</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="user_type" 
                                           id="learner" value="learner" 
                                           <?php echo (!isset($_POST['user_type']) || $_POST['user_type'] === 'learner') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="learner">
                                        <i class="fas fa-graduation-cap me-1"></i> Learn new skills
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="user_type" 
                                           id="tutor" value="tutor"
                                           <?php echo (isset($_POST['user_type']) && $_POST['user_type'] === 'tutor') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="tutor">
                                        <i class="fas fa-chalkboard-teacher me-1"></i> Teach others
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="user_type" 
                                           id="both" value="both"
                                           <?php echo (isset($_POST['user_type']) && $_POST['user_type'] === 'both') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="both">
                                        <i class="fas fa-users me-1"></i> Both learn and teach
                                    </label>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-user-plus me-2"></i>Create Account
                                </button>
                            </div>
                        </form>
                        <?php endif; ?>
                        
                        <div class="text-center mt-4">
                            <p class="text-muted">
                                Already have an account? 
                                <a href="login.php" class="text-decoration-none">Log in here</a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Password strength indicator
        const password = document.getElementById('password');
        const strengthBar = document.getElementById('passwordStrength');
        
        password.addEventListener('input', function() {
            const val = this.value;
            let strength = 0;
            
            if (val.length >= 8) strength++;
            if (val.match(/[a-z]/)) strength++;
            if (val.match(/[A-Z]/)) strength++;
            if (val.match(/[0-9]/)) strength++;
            if (val.match(/[^a-zA-Z0-9]/)) strength++;
            
            strengthBar.style.width = (strength * 20) + '%';
            
            if (strength <= 2) {
                strengthBar.style.backgroundColor = '#dc3545';
            } else if (strength <= 3) {
                strengthBar.style.backgroundColor = '#ffc107';
            } else {
                strengthBar.style.backgroundColor = '#28a745';
            }
        });
        
        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
            }
        });
    </script>
</body>
</html>
