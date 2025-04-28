<?php

require_once DIR . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

define('API_URL', 'https://api.themoviedb.org/3/account/21871794');
define('API_KEY', 'e7821e4c4141a5fbcaac0777aabb8760'); // Replace with your actual API key
define('testHost', '100.105.162.20'); // Change if using a different server'
define('testQueue', 'testQueue');

// Function to fetch data from the API
function fetchApiData() {
    $url = API_URL . '?api_key=' . API_KEY;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpCode === 200) {
        return $response;
    } else {
        echo "Error fetching API data: HTTP Code $httpCode\n";
        return null;
    }
}

// Function to send data to RabbitMQ
function sendToRabbitMQ($data) {
    if (!$data) {
        echo "No data to send.\n";
        return;
    }

    try {
    // Build request
    $request = [
        "type"    => "fetch_data",
        "api_url" => "https://api.themoviedb.org/3/account/21871794?api_key=e7821e4c4141a5fbcaac0777aabb8760"
    ];

    // Send request to RabbitMQ
    $client = new rabbitMQClient("testRabbitMQ.ini", "testServer");
    $response = $client->send_request($request);

    if ($response["status"] === "success") {
        $_SESSION['fetched_data'] = $response['data'];
        echo "Data fetched successfully!";
    } else {
        echo "Error: " . htmlspecialchars($response["message"]) . "</p>";
    }
} catch (Exception $e) {
    echo "Error sending request: " . $e->getMessage();
}

// Execute script from CLI
if (php_sapi_name() == "cli") {
    $data = fetchApiData();
    sendToRabbitMQ($data);
} else {
    echo "This script must be run from the command line.\n";
}
}
?>
