<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

// Connection settings
$host     = '100.105.162.20';
$port     = 5672;
$user     = 'webdev';
$password = 'password';
$vhost    = '/';

// Establish connection and channel
$connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost);
$channel = $connection->channel();

// Declare the queue (if it doesn’t already exist)
$queue = 'hello';
$channel->queue_declare($queue, false, true, false, false);

echo "Fetching messages from queue '$queue':\n";

// Loop to get all messages from the queue
while (true) {
    // basic_get returns a message or null if the queue is empty
    $msg = $channel->basic_get($queue);
    if ($msg) {
        echo " [x] Received: " . $msg->body . "\n";
        // Acknowledge the message so it is removed from the queue
        $channel->basic_ack($msg->delivery_info['delivery_tag']);
    } else {
        echo "No more messages in the queue.\n";
        break;
    }
}

// Clean up: close channel and connection
$channel->close();
$connection->close();
?>
