#!/usr/bin/php
<?php
// Include required files for path, host info, and the RabbitMQ library.
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

/**
 * Callback function to process incoming messages.
 *
 * @param array $request The message received from the queue.
 * @return array Response data to be sent back (if needed).
 */
function requestProcessor($request) {
    echo "Received request:\n";
    print_r($request);
    
    // Process the message based on its type or content.
    // For example, if it's a Login request, check credentials, etc.
    // Here we simply acknowledge receipt and return a sample response.
    $response = array(
        "status" => "success",
        "message" => "Request processed.",
        "details" => $request
    );
    
    return $response;
}

// Create a new RabbitMQ server instance using your configuration.
$server = new rabbitMQServer("testRabbitMQ.ini", "testServer");

echo " [*] Waiting for messages. To exit press CTRL+C\n";

// Start processing requests. The function 'process_requests' will wait 
// for messages on the queue and pass each message to 'requestProcessor' callback.
$server->process_requests("requestProcessor");

?>
