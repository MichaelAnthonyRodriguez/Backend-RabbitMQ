#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc'); // Includes the RabbitMQ Library
// require_once('mysqlconnect.php'); // Includes the database config
// require_once('populateDB.php');

define('DB_HOST', '127.0.0.1');
define('DB_USER', 'testUser');
define('DB_PASS', '12345');
define('DB_NAME', 'testdb');

// Create a MySQLi connection
$mydb = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check for connection errors
if ($mydb->errno) {
    die("failed to connect to database: " . $mydb->error . PHP_EOL);
}

echo "successfully connected to database".PHP_EOL;

// echo "running populateDB.php to initialize the database...\n";
// $populateDBOutput = shell_exec("php " . __DIR__ . "/populateDB.php 2>&1");

// echo "populateDB Output:\n" . $populateDBOutput . "\n";


function doLogin($username,$password)
{
    // lookup username in database
    $query = "select * from students;";

    $response = $mydb->query($query);
    if ($mydb->errno != 0)
    {
      echo "failed to execute query:".PHP_EOL;
      echo __FILE__.':'.__LINE__.":error: ".$mydb->error.PHP_EOL;
      exit(0);
    }
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
    // lookup username in databas
    // check password
    return true;
    //return false if not valid
}

function requestProcessor($request)
{
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
      return doRegister($request['first'],$request['last'],$request['user'],$request['email'],$request['password'],);
  }
  return array("returnCode" => '0', 'message'=>"Server received request and processed");
}

$server = new rabbitMQServer("testRabbitMQ.ini","testServer");

$server->process_requests('requestProcessor');
exit();
?>

