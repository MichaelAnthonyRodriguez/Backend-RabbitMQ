<?php
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function sendRPC($message) {
    $connection = new AMQPStreamConnection('172.30.105.10', 5672, 'guest', 'guest');
    $channel = $connection->channel();
    
    list($callback_queue, ,) = $channel->queue_declare("", false, false, true, false);
    
    $corr_id = uniqid();
    $msg = new AMQPMessage(
        json_encode($message),
        array(
            'correlation_id' => $corr_id,
            'reply_to' => $callback_queue
        )
    );
    $channel->basic_publish($msg, '', 'registration');
    
    $response = null;
    $callback = function ($rep) use ($corr_id, &$response) {
        if ($rep->get('correlation_id') == $corr_id) {
            $response = $rep->body;
        }
    };
    
    $channel->basic_consume($callback_queue, '', false, true, false, false, $callback);
    while (!$response) {
        $channel->wait(null, false, 2);
    }
    
    $channel->close();
    $connection->close();
    
    return json_decode($response, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = "Username and password are required.";
    } else {
        $message = [
            'action' => 'register',
            'username' => $username,
            'password' => $password
        ];
        
        $response = sendRPC($message);
        
        if ($response['success']) {
            $success = "Registration successful!";
        } else {
            $error = "Registration failed: " . $response['message'];
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="Frontend/CSS/styles.css">
</head>

<body>
    <div class="register_form">
        <form method="POST">
            <h2 class="register">Register</h2>
            <?php if (isset($error)) echo "<p style='color: red;'>$error</p>"; ?>
    	    <?php if (isset($success)) echo "<p style='color: green;'>$success</p>"; ?>
            <div>
                <div class="input_box">
                    <label for="username">Username</label>
                    <input type="text" name="username" placeholder="Enter username"/>
                </div>
                <div class="input_box">
                    <div>
                        <label for="password">Password</label>
                    </div>
                    <input type="password" name="password" placeholder="Enter your password"/>
                    <div>
                </div>
                <button type="submit">Register</button>
                <p class="sign_up">Have an account? <a href="login.php">Sign in</a></p>
        </form>
</body>

</html>
