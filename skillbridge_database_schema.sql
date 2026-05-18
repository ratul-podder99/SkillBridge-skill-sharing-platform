-- SkillBridge Database Schema
-- Drop existing database if exists and create new one
DROP DATABASE IF EXISTS skillbridge;
CREATE DATABASE skillbridge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE skillbridge;

-- Users Table (Core user information)
CREATE TABLE users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    user_type ENUM('learner', 'tutor', 'both') DEFAULT 'learner',
    is_verified BOOLEAN DEFAULT FALSE,
    profile_photo VARCHAR(255) DEFAULT NULL,
    bio TEXT,
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    INDEX idx_email (email),
    INDEX idx_user_type (user_type),
    INDEX idx_is_verified (is_verified)
);

-- Social Links Table (For tutor profiles)
CREATE TABLE social_links (
    link_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    platform VARCHAR(50) NOT NULL,
    url VARCHAR(500) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
);

-- Categories Table (Skill categories)
CREATE TABLE categories (
    category_id INT PRIMARY KEY AUTO_INCREMENT,
    category_name VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    icon VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Skill Offerings Table (Tutors' skill listings)
CREATE TABLE skill_offerings (
    skill_id INT PRIMARY KEY AUTO_INCREMENT,
    tutor_id INT NOT NULL,
    category_id INT NOT NULL,
    skill_title VARCHAR(200) NOT NULL,
    skill_description TEXT NOT NULL,
    qualifications TEXT,
    price_per_hour DECIMAL(10, 2) DEFAULT 0.00,
    is_free BOOLEAN DEFAULT FALSE,
    availability_description TEXT,
    skill_level ENUM('beginner', 'intermediate', 'advanced', 'all_levels') DEFAULT 'all_levels',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tutor_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(category_id),
    INDEX idx_tutor_id (tutor_id),
    INDEX idx_category_id (category_id),
    INDEX idx_is_active (is_active),
    INDEX idx_price (price_per_hour),
    FULLTEXT INDEX idx_skill_search (skill_title, skill_description)
);

-- Messages Table (Internal messaging system)
CREATE TABLE messages (
    message_id INT PRIMARY KEY AUTO_INCREMENT,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    subject VARCHAR(200),
    message_text TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_sender_id (sender_id),
    INDEX idx_receiver_id (receiver_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at)
);

-- Reviews Table (Learner feedback for tutors)
CREATE TABLE reviews (
    review_id INT PRIMARY KEY AUTO_INCREMENT,
    tutor_id INT NOT NULL,
    learner_id INT NOT NULL,
    skill_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    review_text TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tutor_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (learner_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skill_offerings(skill_id) ON DELETE CASCADE,
    INDEX idx_tutor_id (tutor_id),
    INDEX idx_learner_id (learner_id),
    INDEX idx_skill_id (skill_id),
    INDEX idx_rating (rating),
    -- Ensure one review per learner per skill
    UNIQUE KEY unique_review (learner_id, skill_id)
);

-- Admin Actions Log (For audit trail)
CREATE TABLE admin_actions (
    action_id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id INT NOT NULL,
    action_type ENUM('verify_tutor', 'unverify_tutor', 'delete_user', 'delete_skill', 'delete_review') NOT NULL,
    target_user_id INT,
    target_skill_id INT,
    target_review_id INT,
    action_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_admin_id (admin_id),
    INDEX idx_action_type (action_type),
    INDEX idx_created_at (created_at)
);

-- Session Management Table (Optional - for better security)
CREATE TABLE user_sessions (
    session_id VARCHAR(255) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
);

-- Insert default categories
INSERT INTO categories (category_name, description, icon) VALUES
('Programming & Development', 'Web development, mobile apps, software engineering', 'fa-code'),
('Design & Creative', 'Graphic design, UI/UX, video editing, photography', 'fa-palette'),
('Business & Marketing', 'Digital marketing, business strategy, entrepreneurship', 'fa-briefcase'),
('Language Learning', 'English, Spanish, French, and other languages', 'fa-language'),
('Music & Arts', 'Musical instruments, singing, painting, drawing', 'fa-music'),
('Fitness & Wellness', 'Yoga, personal training, nutrition, meditation', 'fa-heartbeat'),
('Academic Tutoring', 'Math, science, history, test preparation', 'fa-book'),
('Cooking & Culinary', 'Cooking techniques, baking, international cuisine', 'fa-utensils'),
('Technology & IT', 'Networking, cybersecurity, cloud computing', 'fa-server'),
('Crafts & DIY', 'Woodworking, knitting, pottery, home improvement', 'fa-hammer');

-- Create a default admin user (password: admin123 - CHANGE THIS!)
-- Password hash for 'admin123' using PHP password_hash with PASSWORD_DEFAULT
INSERT INTO users (email, password_hash, first_name, last_name, user_type, is_verified, is_active) 
VALUES ('admin@skillbridge.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', 'User', 'both', TRUE, TRUE);

-- View for getting tutor statistics
CREATE VIEW tutor_stats AS
SELECT 
    u.user_id,
    u.first_name,
    u.last_name,
    u.email,
    u.is_verified,
    COUNT(DISTINCT so.skill_id) as total_skills,
    COUNT(DISTINCT r.review_id) as total_reviews,
    COALESCE(AVG(r.rating), 0) as average_rating
FROM users u
LEFT JOIN skill_offerings so ON u.user_id = so.tutor_id AND so.is_active = TRUE
LEFT JOIN reviews r ON u.user_id = r.tutor_id
WHERE u.user_type IN ('tutor', 'both')
GROUP BY u.user_id;

-- View for popular skills
CREATE VIEW popular_skills AS
SELECT 
    so.skill_id,
    so.skill_title,
    so.skill_description,
    so.price_per_hour,
    c.category_name,
    u.first_name,
    u.last_name,
    u.is_verified,
    COUNT(DISTINCT r.review_id) as review_count,
    COALESCE(AVG(r.rating), 0) as average_rating
FROM skill_offerings so
JOIN users u ON so.tutor_id = u.user_id
JOIN categories c ON so.category_id = c.category_id
LEFT JOIN reviews r ON so.skill_id = r.skill_id
WHERE so.is_active = TRUE AND u.is_active = TRUE
GROUP BY so.skill_id
ORDER BY review_count DESC, average_rating DESC;
