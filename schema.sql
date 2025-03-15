-- Create database
CREATE DATABASE IF NOT EXISTS testdb;
USE testdb;

-- Create `users` table to store user registration details
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    trivia_highscore INT NOT NULL DEFAULT 0, -- added highscores
    created_at BIGINT UNSIGNED NOT NULL DEFAULT (UNIX_TIMESTAMP())
);

-- Create `sessions` table to track user sessions
CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(255) NOT NULL UNIQUE,
    created_at BIGINT UNSIGNED NOT NULL DEFAULT (UNIX_TIMESTAMP()),
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create `user movies` table to track user movie data
CREATE TABLE IF NOT EXISTS user_movies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    movie_id INT NOT NULL,
    watchlist BOOLEAN NOT NULL DEFAULT FALSE,
    rating TINYINT,
    review TEXT,
    created_at BIGINT UNSIGNED NOT NULL DEFAULT (UNIX_TIMESTAMP()),

    -- Ensure a user can't have duplicate entries for the same movie
    UNIQUE KEY (user_id, movie_id),

    -- Foreign keys to maintain referential integrity
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS movies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tmdb_id INT NOT NULL UNIQUE,  -- The movie ID from TMDb
    adult BOOLEAN,
    backdrop_path VARCHAR(255),
    original_language VARCHAR(10),
    original_title VARCHAR(255),
    overview TEXT,
    popularity DECIMAL(7,2),      -- Adjust precision as needed
    poster_path VARCHAR(255),
    release_date DATE,
    title VARCHAR(255),
    video BOOLEAN,
    vote_average DECIMAL(3,1),
    vote_count INT,
    
    created_at BIGINT UNSIGNED NOT NULL DEFAULT (UNIX_TIMESTAMP())
);