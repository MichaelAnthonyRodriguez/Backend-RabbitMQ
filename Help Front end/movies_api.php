<?php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Validate session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_key'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: No active session']);
    exit();
}

function sendMovieRPC($queueName, $data) {
    try {
        $connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
        $channel = $connection->channel();
        
        list($callbackQueue) = $channel->queue_declare("", false, false, true, false);
        $corrId = uniqid();

        $msg = new AMQPMessage(
            json_encode(array_merge($data, ['user_id' => $_SESSION['user_id']])),
            ['correlation_id' => $corrId, 'reply_to' => $callbackQueue]
        );
        
        $channel->basic_publish($msg, '', $queueName);
        
        $response = null;
        $callback = function ($msg) use ($corrId, &$response) {
            if ($msg->get('correlation_id') === $corrId) {
                $response = $msg->body;
            }
        };
        
        $channel->basic_consume($callbackQueue, '', false, true, false, false, $callback);
        while (!$response) $channel->wait();
        
        $channel->close();
        $connection->close();
        
        return json_decode($response, true);

    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Service unavailable'];
    }
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    // Route requests
    $action = $_GET['action'] ?? $input['action'] ?? '';
    $searchTerm = $_GET['search'] ?? $input['search'] ?? '';
    
    switch ($action) {
        case 'search':
            $response = sendMovieRPC('movies_search', ['search' => $searchTerm]);
            break;

        case 'fetch':
            if (empty($searchTerm)) throw new Exception("Search term required");
            $response = sendMovieRPC('omdb_fetch', ['search' => $searchTerm]);
            break;

        case 'favorite':
            $movieId = $input['movie_id'] ?? null;
            $setFavorite = filter_var($input['set_favorite'] ?? false, FILTER_VALIDATE_BOOLEAN);
            
            if (!$movieId) throw new Exception("Missing movie ID");
            $response = sendMovieRPC('movie_favorite', [
                'movie_id' => $movieId,
                'set_favorite' => $setFavorite
            ]);
            break;

        default:
            throw new Exception("Invalid action");
    }

    if (!isset($response['success'])) {
        throw new Exception("Invalid service response");
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>