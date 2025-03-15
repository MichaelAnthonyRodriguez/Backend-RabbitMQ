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
  }
  return array("returnCode" => '0', 'message'=>"Server received request and processed");
}

$server = new rabbitMQServer("testRabbitMQ.ini","testServer");

echo "testRabbitMQServer BEGIN".PHP_EOL;
$server->process_requests('requestProcessor');
?>