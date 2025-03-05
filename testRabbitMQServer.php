#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc'); // Includes the RabbitMQ Library
require_once('mysqlconnect.php'); // Includes the database config
require_once('populateDB.php'); // Populates teh database with schema

function doLogin($username, $password)
{
    global $mydb;
    echo "🔍 Debug: doLogin() function started.\n";

    // Check if the user exists
    $query = "SELECT id, password_hash FROM users WHERE username = ?";
    $stmt = $mydb->prepare($query);
    
    if (!$stmt) {
        echo "debug: Database error - " . $mydb->error . "\n";
        return ["status" => "error", "message" => "Database error: " . $mydb->error];
    }

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    echo "🔍 Debug: Query executed. Found rows: " . $stmt->num_rows . "\n";

    if ($stmt->num_rows == 0) {
        echo "debug: No user found.\n";
        return ["status" => "error", "message" => "Invalid username or password."];
    }

    // User exists, fetch hashed password
    $stmt->bind_result($userId, $hashedPassword);
    $stmt->fetch();

    echo "🔍 Debug: User found. Checking password...\n";

    // Verify password
    if (password_verify($password, $hashedPassword)) {
        echo "debug: Password verified!\n";
        return ["status" => "success", "message" => "login successful.", "user_id" => $userId];
    } else {
        echo "debug: Password incorrect.\n";
        return ["status" => "error", "message" => "Invalid username or password."];
    }
}


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


function doRegister($first, $last, $username, $email, $password)
{
    global $mydb; // Ensure database connection is available
    
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

function doLogout($sessionToken)
{
    global $mydb;

    // Remove session from the database
    $query = "DELETE FROM sessions WHERE session_token = ?";
    $stmt = $mydb->prepare($query);
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
  }
  return array("returnCode" => '0', 'message'=>"Server received request and processed");
}

$server = new rabbitMQServer("testRabbitMQ.ini","testServer");

echo "testRabbitMQServer BEGIN".PHP_EOL;
$server->process_requests('requestProcessor');
?>

