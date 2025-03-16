#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc'); // Includes the RabbitMQ Library
require_once('mysqlconnect.php'); // Includes the database config
require_once('populateDB.php'); // Populates teh database with schema
// Login function
function doLogin($username, $password)
{
    global $mydb;
    // echo "debug: doLogin() function started.\n";

    // Check if the user exists
    $query = "SELECT id, first_name, last_name, password_hash FROM users WHERE username = ?";
    $stmt = $mydb->prepare($query);
    
    if (!$stmt) {
        // echo "debug: Database error - " . $mydb->error . "\n";
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    // echo "debug: Query executed. Found rows: " . $stmt->num_rows . "\n";

    if ($stmt->num_rows == 0) {
        // echo "debug: No user found.\n";
        return ["status" => "error", "message" => "Invalid username or password."];
    }

    // User exists, fetch hashed password
    $stmt->bind_result($userId, $firstName, $lastName, $hashedPassword);
    $stmt->fetch();

    echo "User found. Checking password...\n";

    if (!password_verify($password, $hashedPassword)) {
        return ["status" => "error", "message" => "Invalid username or password."];
    }

    // Generate session token
    $sessionToken = bin2hex(random_bytes(32));
    $expiresAt = date("Y-m-d H:i:s", strtotime("+1 hour"));

    // Insert session into the database
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
function doValidate($sessionToken)
{
    global $mydb;

    // Check if the session exists and is still valid
    $query = "SELECT user_id FROM sessions WHERE session_token = ? AND expires_at > NOW()";
    $stmt = $mydb->prepare($query);
    if (!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }

    $stmt->bind_param("s", $sessionToken);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows == 0) {
        return ["status" => "error", "message" => "session expired or invalid."];
    }

    return ["status" => "success", "message" => "session is valid."];
}

// Registration function
function doRegister($first, $last, $username, $email, $password)
{
    global $mydb;
    
    // Checks database for duplicate credentials
    $checkQuery = "SELECT id FROM users WHERE username = ? OR email = ?";
    $stmt = $mydb->prepare($checkQuery);
    if (!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        return ["status" => "error", "message" => "username or email already exists!"];
    }
    
    $stmt->close();

    // Insert user into the database
    $query = "INSERT INTO users (first_name, last_name, username, email, password_hash) VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $mydb->prepare($query);
    if (!$stmt) {
        return "Database error: " . $mydb->error;
    }

    $stmt->bind_param("sssss", $first, $last, $username, $email, $password);
    
    if ($stmt->execute()) {
        return "user registered successfully.";
    } else {
        return "error registering user: " . $stmt->error;
    }
}

// Logout fucntion
function doLogout($sessionToken)
{
    global $mydb;

    // Check if the session exists before attempting to delete
    $checkQuery = "SELECT id FROM sessions WHERE session_token = ?";
    $stmt = $mydb->prepare($checkQuery);
    if (!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }

    $stmt->bind_param("s", $sessionToken);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows == 0) {
        return ["status" => "error", "message" => "session does not exist or already logged out."];
    }

    // delete session
    $deleteQuery = "DELETE FROM sessions WHERE session_token = ?";
    $stmt = $mydb->prepare($deleteQuery);
    if (!$stmt) {
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }

    $stmt->bind_param("s", $sessionToken);

    if ($stmt->execute()) {
        return ["status" => "success", "message" => "session logged out successfully."];
    } else {
        return ["status" => "error", "message" => "error logging out: " . $stmt->error];
    }
}

//Movie seach function
function doSearch($movie_title)
{
    global $mydb;
    // Use a LIKE query to search for movies whose title contains the search string.
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

//Movie details function
function doMovieDetails($tmdb_id)
{
    global $mydb;
    // Query the local movies table for the movie with the given tmdb_id
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
    return ["status" => "success", "movie" => $movie];
}

//Popular movies function
function doTopMovies($year = null)
{
    global $mydb;
    // If no year is provided, use the current year.
    if ($year === null) {
        $year = date("Y");
    }
    // Query for movies whose release_date (stored as string "YYYY-MM-DD")
    // starts with the provided year and that have been released (<= today's date).
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

//User watchlist function
function doWatchlist($user_id)
{
    global $mydb;
    // Query the database: join movies and user_movies to get movie details for the user's watchlist.
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

//Review adder function
function doAddReview($user_id, $tmdb_id, $watchlist, $rating, $review) {
    global $mydb;
    
    // First, check if there is already an entry for this user and movie.
    $query = "SELECT id FROM user_movies WHERE user_id = ? AND movie_id = (
                SELECT id FROM movies WHERE tmdb_id = ?
              )";
    $stmt = $mydb->prepare($query);
    if(!$stmt){
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }
    $stmt->bind_param("ii", $user_id, $tmdb_id);
    $stmt->execute();
    $stmt->store_result();
    if($stmt->num_rows > 0){
        // Update existing entry.
        $stmt->bind_result($id);
        $stmt->fetch();
        $stmt->close();
        
        $updateQuery = "UPDATE user_movies 
                        SET watchlist = ?, rating = ?, review = ?, created_at = UNIX_TIMESTAMP()
                        WHERE id = ?";
        $updateStmt = $mydb->prepare($updateQuery);
        if(!$updateStmt){
            return ["status" => "error", "message" => "Database error: " . $mydb->error];
        }
        $updateStmt->bind_param("isii", $watchlist, $rating, $review, $id);
        if($updateStmt->execute()){
            $updateStmt->close();
            return ["status" => "success", "message" => "Review updated."];
        } else {
            $updateStmt->close();
            return ["status" => "error", "message" => "Failed to update review: " . $mydb->error];
        }
    } else {
        $stmt->close();
        // Insert new entry. We need to convert the tmdb_id into the internal movie_id.
        // We assume movies table is already populated.
        $selectQuery = "SELECT id FROM movies WHERE tmdb_id = ?";
        $selectStmt = $mydb->prepare($selectQuery);
        if(!$selectStmt){
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
                        VALUES (?, ?, ?, ?, ?, UNIX_TIMESTAMP())";
        $insertStmt = $mydb->prepare($insertQuery);
        if(!$insertStmt){
            return ["status" => "error", "message" => "Database error: " . $mydb->error];
        }
        $insertStmt->bind_param("iiiss", $user_id, $movie_id, $watchlist, $rating, $review);
        if($insertStmt->execute()){
            $insertStmt->close();
            return ["status" => "success", "message" => "Review added."];
        } else {
            $insertStmt->close();
            return ["status" => "error", "message" => "Failed to add review: " . $mydb->error];
        }
    }
}

//Review getter function
function doGetReviews($tmdb_id) {
    global $mydb;
    // Join user_movies with users so you can show the reviewer's name.
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
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    $stmt->close();
    if(count($reviews) == 0){
        return ["status" => "error", "message" => "No reviews found for this movie."];
    }
    return ["status" => "success", "reviews" => $reviews];
}

// Processes rabbitmq requests
function requestProcessor($request)
{
  echo "processing requests rn".PHP_EOL;
  echo "received request".PHP_EOL;
  var_dump($request);
  if(!isset($request['type']))
  {
    return "ERROR: unsupported message type";
  }
  switch ($request['type'])
  {
    case "login":
        return doLogin($request['user'],$request['password']);
    case "validate_session":
        return doValidate($request['sessionId']);
    case "register":
        return doRegister($request['first'],$request['last'],$request['user'],$request['email'],$request['password']);
    case "logout":
        return doLogout($request['session_token']);
    case "search":
        return doSearch($request['movie_title']);
    case "movie_details":
        return doMovieDetails($request['tmdb_id']);
    case "top_movies":
        $year = isset($request['year']) ? $request['year'] : date("Y");
        return doTopMovies($year);
    case "watchlist":
        return doWatchlist($request['user_id']);
    case "add_review":
        return doAddReview($request['user_id'], $request['tmdb_id'], $request['watchlist'], $request['rating'], $request['review']);
    case "get_reviews":
        return doGetReviews($request['tmdb_id']);
  }
  return array("returnCode" => '0', 'message'=>"Server received request and processed");
}

$server = new rabbitMQServer("testRabbitMQ.ini","testServer");

echo "testRabbitMQServer BEGIN".PHP_EOL;
$server->process_requests('requestProcessor');
?>