-- DMOZ Directory Database Schema
CREATE DATABASE dmoz_directory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dmoz_directory;

-- Categories table
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    parent_id INT NULL,
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parent (parent_id),
    INDEX idx_slug (slug),
    INDEX idx_active (is_active),
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- Websites table
CREATE TABLE websites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    url VARCHAR(500) NOT NULL,
    description TEXT NOT NULL,
    category_id INT NOT NULL,
    submitter_name VARCHAR(255),
    submitter_email VARCHAR(255),
    status ENUM('pending', 'approved', 'rejected', 'needs_review') DEFAULT 'pending',
    http_status INT NULL,
    last_checked TIMESTAMP NULL,
    moderator_notes TEXT,
    ai_scan_result JSON NULL,
    ai_scan_date TIMESTAMP NULL,
    rejection_reason TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category_id),
    INDEX idx_status (status),
    INDEX idx_url (url(191)),
    INDEX idx_submitted (submitted_at),
    FULLTEXT KEY ft_search (title, description),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

-- Admin users table
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username)
);

-- Search logs for analytics
CREATE TABLE search_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    query VARCHAR(500) NOT NULL,
    results_count INT DEFAULT 0,
    ip_address VARCHAR(45),
    user_agent TEXT,
    searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_query (query(191)),
    INDEX idx_date (searched_at)
);

-- Insert some default categories
INSERT INTO categories (name, slug, description, parent_id) VALUES
('Arts', 'arts', 'Creative arts, entertainment, and cultural resources', NULL),
('Business', 'business', 'Commerce, finance, and professional services', NULL),
('Computers', 'computers', 'Technology, software, and internet resources', NULL),
('Health', 'health', 'Medical, wellness, and healthcare information', NULL),
('Recreation', 'recreation', 'Sports, hobbies, and leisure activities', NULL),
('Science', 'science', 'Scientific research, education, and resources', NULL),
('Society', 'society', 'Social issues, culture, and community resources', NULL);

-- Insert subcategories
INSERT INTO categories (name, slug, description, parent_id) VALUES
('Web Design', 'web-design', 'Web design and development resources', 3),
('Programming', 'programming', 'Programming languages and development tools', 3),
('Software', 'software', 'Applications and software tools', 3),
('Photography', 'photography', 'Photography resources and galleries', 1),
('Music', 'music', 'Music resources, artists, and streaming', 1),
('Fitness', 'fitness', 'Exercise, nutrition, and fitness resources', 4);

-- Create default admin user (password: admin123 - change this!)
INSERT INTO admin_users (username, password_hash, email) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com');