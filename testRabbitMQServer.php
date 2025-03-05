#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc'); // Includes the RabbitMQ Library
require_once('mysqlconnect.php'); // Includes the database config
require_once('populateDB.php'); // Populates teh database with schema

function doLogin($username,$password)
{
    // lookup username in database
    // check password
    return true;
    //return false if not valid
}

function doValidate($sessionId)
{
    // lookup username in databas
    // check password
    return true;
    //return false if not valid
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
      return doLogin($request['username'],$request['password']);
    case "validate_session":
      return doValidate($request['sessionId']);
    case "register":
      return doRegister($request['first'],$request['last'],$request['user'],$request['email'],$request['password']);
  }
  return array("returnCode" => '0', 'message'=>"Server received request and processed");
}

$server = new rabbitMQServer("testRabbitMQ.ini","testServer");

echo "testRabbitMQServer BEGIN".PHP_EOL;
$server->process_requests('requestProcessor');
?>

