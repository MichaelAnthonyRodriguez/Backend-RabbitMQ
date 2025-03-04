<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

// RabbitMQ connection parameters
$host     = '100.105.162.20'; // Replace with your RabbitMQ host if different
$port     = 5672;
$user     = 'webdev';
$password = 'password';
$vhost    = '/'; // Adjust if you're using a custom vhost

// Establish connection and open a channel
$connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost);
$channel = $connection->channel();

// Declare the queue (ensure it matches the publisher's queue name)
$queue = 'hello';
$channel->queue_declare($queue, false, true, false, false);

echo " [*] Waiting for messages. To exit press CTRL+C\n";

// Callback function to process incoming messages
$callback = function($msg) {
    echo " [x] Received: ", $msg->body, "\n";
    
    // Here you can add your logic to process the message
    // For example, decode JSON, perform database operations, etc.
    
    // Acknowledge that the message has been processed
    $msg->ack();
};

// Start consuming messages from the queue
// false for manual acknowledgement
$channel->basic_consume($queue, '', false, false, false, false, $callback);

// Keep the script running and listening for messages
while ($channel->is_consuming()) {
    $channel->wait();
}

// Cleanup (these lines may not be reached unless the loop exits)
$channel->close();
$connection->close();
?>
