#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');  // RabbitMQ Library
require_once('mysqlconnect.php');  // Sets up $mydb connection
require_once('populateDB.php');   // Populates the database schema

// ---------------------------
// USER FUNCTIONS
// ---------------------------

// Login function
function doLogin($username, $password) {
    global $mydb;
    $query = "SELECT id, first_name, last_name, password_hash FROM users WHERE username = ?";
    $stmt = $mydb->prepare($query);
    if (!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows == 0) {
        return ["status" => "error", "message" => "Invalid username or password."];
    }
    $stmt->bind_result($userId, $firstName, $lastName, $hashedPassword);
    $stmt->fetch();
    echo "User found. Checking password...\n";
    if (!password_verify($password, $hashedPassword)) {
        return ["status" => "error", "message" => "Invalid username or password."];
    }
    $sessionToken = bin2hex(random_bytes(32));
    $expiresAt = date("Y-m-d H:i:s", strtotime("+1 hour"));
    $insertSessionQuery = "INSERT INTO sessions (user_id, session_token, expires_at) VALUES (?, ?, ?)";
    $stmt = $mydb->prepare($insertSessionQuery);
    if (!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("iss", $userId, $sessionToken, $expiresAt);
    if (!$stmt->execute()) {
        return ["status" => "error", "message" => "Failed to create session."];
    }
    return [
        "status" => "success",
        "message" => "Login successful.",
        "session_token" => $sessionToken,
        "user_id" => $userId,
        "first_name" => $firstName,
        "last_name" => $lastName
    ];
}

// Session validation function
function doValidate($sessionToken) {
    global $mydb;
    $query = "SELECT user_id FROM sessions WHERE session_token = ? AND expires_at > NOW()";
    $stmt = $mydb->prepare($query);
    if (!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("s", $sessionToken);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows == 0) {
        return ["status" => "error", "message" => "Session expired or invalid."];
    }
    return ["status" => "success", "message" => "Session is valid."];
}

// Registration function
function doRegister($first, $last, $username, $email, $password) {
    global $mydb;
    $checkQuery = "SELECT id FROM users WHERE username = ? OR email = ?";
    $stmt = $mydb->prepare($checkQuery);
    if (!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        return ["status" => "error", "message" => "Username or email already exists!"];
    }
    $stmt->close();
    $query = "INSERT INTO users (first_name, last_name, username, email, password_hash) VALUES (?, ?, ?, ?, ?)";
    $stmt = $mydb->prepare($query);
    if (!$stmt) {
        return "Database error: " . $mydb->error;
    }
    $stmt->bind_param("sssss", $first, $last, $username, $email, $password);
    if ($stmt->execute()) {
        return "User registered successfully.";
    } else {
        return "Error registering user: " . $stmt->error;
    }
}

// Logout function
function doLogout($sessionToken) {
    global $mydb;
    $checkQuery = "SELECT id FROM sessions WHERE session_token = ?";
    $stmt = $mydb->prepare($checkQuery);
    if (!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("s", $sessionToken);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows == 0) {
        return ["status" => "error", "message" => "Session does not exist or already logged out."];
    }
    $deleteQuery = "DELETE FROM sessions WHERE session_token = ?";
    $stmt = $mydb->prepare($deleteQuery);
    if (!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("s", $sessionToken);
    if ($stmt->execute()) {
        return ["status" => "success", "message" => "Session logged out successfully."];
    } else {
        return ["status" => "error", "message" => "Error logging out: " . $stmt->error];
    }
}

// ---------------------------
// MOVIE FUNCTIONS
// ---------------------------

// Movie search function
function doSearch($movie_title) {
    global $mydb;
    $query = "SELECT * FROM movies WHERE title LIKE CONCAT('%', ?, '%')";
    $stmt = $mydb->prepare($query);
    if(!$stmt){
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("s", $movie_title);
    $stmt->execute();
    $result = $stmt->get_result();
    $movies = [];
    while($row = $result->fetch_assoc()){
        $movies[] = $row;
    }
    if(count($movies) == 0){
        return ["status" => "error", "message" => "No movies found."];
    }
    return ["status" => "success", "movies" => $movies];
}

// Top movies function
function doTopMovies($year = null) {
    global $mydb;
    if ($year === null) {
        $year = date("Y");
    }
    $query = "SELECT tmdb_id, poster_path, title, overview, release_date, popularity 
              FROM movies 
              WHERE LEFT(release_date, 4) = ? 
                AND release_date <= CURDATE()
              ORDER BY popularity DESC 
              LIMIT 10";
    $stmt = $mydb->prepare($query);
    if (!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("s", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $movies = [];
    while ($row = $result->fetch_assoc()) {
        $movies[] = $row;
    }
    $stmt->close();
    if (count($movies) === 0) {
        return ["status" => "error", "message" => "No movies found for year $year."];
    }
    return ["status" => "success", "movies" => $movies];
}

// User watchlist function
function doWatchlist($user_id) {
    global $mydb;
    $query = "SELECT m.tmdb_id, m.poster_path, m.title, m.release_date, m.overview, m.vote_average 
              FROM movies m 
              JOIN user_movies um ON m.id = um.movie_id 
              WHERE um.user_id = ? AND um.watchlist = 1";
    $stmt = $mydb->prepare($query);
    if (!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $movies = [];
    while ($row = $result->fetch_assoc()){
        $movies[] = $row;
    }
    $stmt->close();
    if (count($movies) == 0) {
        return ["status" => "error", "message" => "No movies found in watchlist."];
    }
    return ["status" => "success", "movies" => $movies];
}

// ---------------------------
// REVIEW UPDATE FUNCTIONS
// These functions update one field at a time.
// ---------------------------

// Update Watchlist only
function doUpdateWatchlist($user_id, $tmdb_id, $watchlist) {
    global $mydb;
    $query = "SELECT id FROM user_movies WHERE user_id = ? AND movie_id = (SELECT id FROM movies WHERE tmdb_id = ?)";
    $stmt = $mydb->prepare($query);
    if(!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("ii", $user_id, $tmdb_id);
    $stmt->execute();
    $stmt->store_result();
    if($stmt->num_rows > 0) {
        $stmt->bind_result($id);
        $stmt->fetch();
        $stmt->close();
        $updateQuery = "UPDATE user_movies SET watchlist = ? WHERE id = ?";
        $updateStmt = $mydb->prepare($updateQuery);
        if(!$updateStmt) {
            return ["status" => "error", "message" => "Database error: " . $mydb->error];
        }
        $updateStmt->bind_param("ii", $watchlist, $id);
        if($updateStmt->execute()){
            $updateStmt->close();
            return ["status" => "success", "message" => "Watchlist updated."];
        } else {
            $updateStmt->close();
            return ["status" => "error", "message" => "Failed to update watchlist: " . $mydb->error];
        }
    } else {
        $stmt->close();
        $selectQuery = "SELECT id FROM movies WHERE tmdb_id = ?";
        $selectStmt = $mydb->prepare($selectQuery);
        if(!$selectStmt) {
            return ["status" => "error", "message" => "Database error: " . $mydb->error];
        }
        $selectStmt->bind_param("i", $tmdb_id);
        $selectStmt->execute();
        $selectStmt->bind_result($movie_id);
        if(!$selectStmt->fetch()){
            $selectStmt->close();
            return ["status" => "error", "message" => "Movie not found in local database."];
        }
        $selectStmt->close();
        $insertQuery = "INSERT INTO user_movies (user_id, movie_id, watchlist, rating, review, created_at)
                        VALUES (?, ?, ?, 0, '', UNIX_TIMESTAMP())";
        $insertStmt = $mydb->prepare($insertQuery);
        if(!$insertStmt) {
            return ["status" => "error", "message" => "Database error: " . $mydb->error];
        }
        $insertStmt->bind_param("iii", $user_id, $movie_id, $watchlist);
        if($insertStmt->execute()){
            $insertStmt->close();
            return ["status" => "success", "message" => "Watchlist added."];
        } else {
            $insertStmt->close();
            return ["status" => "error", "message" => "Failed to add watchlist: " . $mydb->error];
        }
    }
}

// Update Rating only
function doUpdateRating($user_id, $tmdb_id, $rating) {
    global $mydb;
    $query = "SELECT id FROM user_movies WHERE user_id = ? AND movie_id = (SELECT id FROM movies WHERE tmdb_id = ?)";
    $stmt = $mydb->prepare($query);
    if(!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("ii", $user_id, $tmdb_id);
    $stmt->execute();
    $stmt->store_result();
    if($stmt->num_rows > 0) {
        $stmt->bind_result($id);
        $stmt->fetch();
        $stmt->close();
        $updateQuery = "UPDATE user_movies SET rating = ? WHERE id = ?";
        $updateStmt = $mydb->prepare($updateQuery);
        if(!$updateStmt) {
            return ["status" => "error", "message" => "Database error: " . $mydb->error];
        }
        $updateStmt->bind_param("ii", $rating, $id);
        if($updateStmt->execute()){
            $updateStmt->close();
            return ["status" => "success", "message" => "Rating updated."];
        } else {
            $updateStmt->close();
            return ["status" => "error", "message" => "Failed to update rating: " . $mydb->error];
        }
    } else {
        $stmt->close();
        $selectQuery = "SELECT id FROM movies WHERE tmdb_id = ?";
        $selectStmt = $mydb->prepare($selectQuery);
        if(!$selectStmt) {
            return ["status" => "error", "message" => "Database error: " . $mydb->error];
        }
        $selectStmt->bind_param("i", $tmdb_id);
        $selectStmt->execute();
        $selectStmt->bind_result($movie_id);
        if(!$selectStmt->fetch()){
            $selectStmt->close();
            return ["status" => "error", "message" => "Movie not found in local database."];
        }
        $selectStmt->close();
        $insertQuery = "INSERT INTO user_movies (user_id, movie_id, watchlist, rating, review, created_at)
                        VALUES (?, ?, 0, ?, '', UNIX_TIMESTAMP())";
        $insertStmt = $mydb->prepare($insertQuery);
        if(!$insertStmt) {
            return ["status" => "error", "message" => "Database error: " . $mydb->error];
        }
        $insertStmt->bind_param("iii", $user_id, $movie_id, $rating);
        if($insertStmt->execute()){
            $insertStmt->close();
            return ["status" => "success", "message" => "Rating added."];
        } else {
            $insertStmt->close();
            return ["status" => "error", "message" => "Failed to add rating: " . $mydb->error];
        }
    }
}

// Update Review only
function doUpdateReview($user_id, $tmdb_id, $review) {
    global $mydb;
    $query = "SELECT id FROM user_movies WHERE user_id = ? AND movie_id = (SELECT id FROM movies WHERE tmdb_id = ?)";
    $stmt = $mydb->prepare($query);
    if(!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("ii", $user_id, $tmdb_id);
    $stmt->execute();
    $stmt->store_result();
    if($stmt->num_rows > 0) {
        $stmt->bind_result($id);
        $stmt->fetch();
        $stmt->close();
        $updateQuery = "UPDATE user_movies SET review = ? WHERE id = ?";
        $updateStmt = $mydb->prepare($updateQuery);
        if(!$updateStmt) {
            return ["status" => "error", "message" => "Database error: " . $mydb->error];
        }
        $updateStmt->bind_param("si", $review, $id);
        if($updateStmt->execute()){
            $updateStmt->close();
            return ["status" => "success", "message" => "Review updated."];
        } else {
            $updateStmt->close();
            return ["status" => "error", "message" => "Failed to update review: " . $mydb->error];
        }
    } else {
        $stmt->close();
        $selectQuery = "SELECT id FROM movies WHERE tmdb_id = ?";
        $selectStmt = $mydb->prepare($selectQuery);
        if(!$selectStmt) {
            return ["status" => "error", "message" => "Database error: " . $mydb->error];
        }
        $selectStmt->bind_param("i", $tmdb_id);
        $selectStmt->execute();
        $selectStmt->bind_result($movie_id);
        if(!$selectStmt->fetch()){
            $selectStmt->close();
            return ["status" => "error", "message" => "Movie not found in local database."];
        }
        $selectStmt->close();
        $insertQuery = "INSERT INTO user_movies (user_id, movie_id, watchlist, rating, review, created_at)
                        VALUES (?, ?, 0, 0, ?, UNIX_TIMESTAMP())";
        $insertStmt = $mydb->prepare($insertQuery);
        if(!$insertStmt) {
            return ["status" => "error", "message" => "Database error: " . $mydb->error];
        }
        $insertStmt->bind_param("iis", $user_id, $movie_id, $review);
        if($insertStmt->execute()){
            $insertStmt->close();
            return ["status" => "success", "message" => "Review added."];
        } else {
            $insertStmt->close();
            return ["status" => "error", "message" => "Failed to add review: " . $mydb->error];
        }
    }
}

// ---------------------------
// COMBINED MOVIE DETAILS & REVIEWS FUNCTION
// ---------------------------
function doMovieFullDetails($tmdb_id) {
    global $mydb;
    // Get movie details.
    $query = "SELECT tmdb_id, poster_path, title, overview, release_date, vote_average FROM movies WHERE tmdb_id = ?";
    $stmt = $mydb->prepare($query);
    if(!$stmt){
       return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("i", $tmdb_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows == 0){
         return ["status" => "error", "message" => "Movie not found."];
    }
    $movie = $result->fetch_assoc();
    $stmt->close();
    
    // Get reviews for this movie.
    $query = "SELECT u.username, um.rating, um.review, FROM_UNIXTIME(um.created_at) as review_date
              FROM user_movies um
              JOIN users u ON um.user_id = u.id
              JOIN movies m ON m.id = um.movie_id
              WHERE m.tmdb_id = ?
              ORDER BY um.created_at DESC";
    $stmt = $mydb->prepare($query);
    if(!$stmt){
         return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("i", $tmdb_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reviews = [];
    while($row = $result->fetch_assoc()){
         $reviews[] = $row;
    }
    $stmt->close();
    
    $movie["reviews"] = $reviews;
    if(empty($reviews)){
         $movie["reviews_message"] = "No reviews found for this movie.";
    }
    return ["status" => "success", "movie" => $movie];
}

// ---------------------------
// REQUEST PROCESSOR FUNCTION
// ---------------------------
function requestProcessor($request) {
    echo "Processing request...\n";
    var_dump($request);
    if(!isset($request['type'])) {
        return "ERROR: unsupported message type";
    }
    switch ($request['type']) {
        case "login":
            return doLogin($request['user'], $request['password']);
        case "validate_session":
            return doValidate($request['sessionId']);
        case "register":
            return doRegister($request['first'], $request['last'], $request['user'], $request['email'], $request['password']);
        case "logout":
            return doLogout($request['session_token']);
        case "search":
            return doSearch($request['movie_title']);
        case "full_movie_details":
            return doMovieFullDetails($request['tmdb_id']);
        case "top_movies":
            $year = isset($request['year']) ? $request['year'] : date("Y");
            return doTopMovies($year);
        case "watchlist":
            return doWatchlist($request['user_id']);
        case "update_watchlist":
            return doUpdateWatchlist($request['user_id'], $request['tmdb_id'], $request['watchlist']);
        case "update_rating":
            return doUpdateRating($request['user_id'], $request['tmdb_id'], $request['rating']);
        case "update_review":
            return doUpdateReview($request['user_id'], $request['tmdb_id'], $request['review']);
    }
    return ["returnCode" => '0', "message" => "Server received request and processed"];
}

$server = new rabbitMQServer("testRabbitMQ.ini", "testServer");
echo "testRabbitMQServer BEGIN" . PHP_EOL;
$server->process_requests("requestProcessor");
?>
