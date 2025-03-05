#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc'); // Includes the RabbitMQ Library
require_once('mysqlconnect.php'); // Includes the database config

echo "🔄 Initializing database using schema.sql...\n";

// Path to SQL schema file
$schemaFile = __DIR__ . "/schema.sql";

// Check if file exists
if (!file_exists($schemaFile)) {
    die("❌ Error: Schema file not found.\n");
}

// Read schema file
$schemaSQL = file_get_contents($schemaFile);

// Execute schema queries
if ($mydb->multi_query($schemaSQL)) {
    do {
        if ($result = $mydb->store_result()) {
            $result->free();
        }
    } while ($mydb->more_results() && $mydb->next_result());
    
    echo "✅ Database initialized successfully from schema.sql.\n";
} else {
    die("❌ Error initializing database: " . $mydb->error . "\n");
}
// Close connection
$mydb->close();


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

