<?php
session_start();
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
    $channel->basic_publish($msg, '', 'login');
    
    // Wait for the response
    $response = null;
    $callback = function ($rep) use ($corr_id, &$response) {
        if ($rep->get('correlation_id') == $corr_id) {
            $response = $rep->body;
        }
    };
    
    $channel->basic_consume($callback_queue, '', false, true, false, false, $callback);
    while (!$response) {
        $channel->wait(null, false, 2); // Timeout after 2 seconds
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
            'username' => $username,
            'password' => $password
        ];
        
        $response = sendRPC($message);
        
        if ($response['success']) {
            $_SESSION['username'] = $username;
            $_SESSION['session_key'] = $response['session_key'];
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Invalid login credentials.";
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
	<div class="login_form">
		<form method="post">
			<h2 class="login">Login</h2>
			<?php if (isset($error)) echo "<p style='color: red;'>$error</p>"; ?>
    	    		<?php if (isset($success)) echo "<p style='color: green;'>$success</p>"; ?>
			<div>
				<div class="input_box">
					<label for="email">Username</label>
					<input type="text" id="username" name="username"/>
				</div>
				<div class="input_box">
					<div>
						<label for="password">Password</label>
					</div>
					<input type="password" id="password" name="password"/>
				</div>
				<button type="submit">Log In</button>
				<p class="sign_up">Don't have an account? <a href="register.php">Sign up</a></p>
		</form>
	</div>
</body>

</html>
