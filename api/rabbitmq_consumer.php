<?php

require_once DIR . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;

define('RABBITMQ_HOST', '100.105.162.20');
define('RABBITMQ_QUEUE', 'tmdb_data_queue');

$connection = new AMQPStreamConnection(RABBITMQ_HOST, 5672, 'webdev', 'password');
$channel = $connection->channel();

$channel->queue_declare(RABBITMQ_QUEUE, false, true, false, false);

echo " [*] Waiting for messages. To exit, press CTRL+C\n";

$callback = function ($msg) {
    echo " [x] Received ", $msg->body, "\n";
};

$channel->basic_consume(RABBITMQ_QUEUE, '', false, true, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
}

$channel->close();
$connection->close();

?>
