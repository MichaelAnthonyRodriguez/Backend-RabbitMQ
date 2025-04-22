CREATE TABLE bundles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,      -- e.g., "login"
    version INT NOT NULL,             -- e.g., 1
    status ENUM('new', 'passed', 'failed') DEFAULT 'new',
    size BIGINT,                      -- Bundle size in bytes
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
