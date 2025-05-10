<?php
session_start();
require_once 'vendor/autoload.php';
require_once 'rabbitMQLib.inc';

// Ensure user is logged in.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$user_id = $_SESSION['user_id'];

// 10 "are you more..." questions to determine the kind of movie character the user is
/*
1 - sarcastic or genuine

2 - outgoing or shy

3 - street-smart or book-smart

4 - emotion-guided or logic-guided

5 - formal or informal

6 - leader or follower

7 - creative or unoriginal

8 - selfish or selfless

9 - spender or saver

10 - flexible or black-and-white / strict
*/

?>
