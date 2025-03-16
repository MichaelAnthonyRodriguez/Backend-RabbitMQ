#!/usr/bin/php
<?php
require_once 'vendor/autoload.php';
require_once 'rabbitMQLib.inc';

use Mailjet\Client;
use Mailjet\Resources;

// Create a RabbitMQ client.
$client = new rabbitMQClient("testRabbitMQ.ini", "testServer");

// Request watchlisted movies that have a release date of today.
// Your testRabbitMQServer should have a function (doUpdateMovies) that returns an array 
// under the key "released_watchlisted" where each movie entry contains its details and 
// an array of recipients (each with keys: email, first_name, last_name).
$updateRequest = ["type" => "update_movies"];
$response = $client->send_request($updateRequest);

if (!isset($response['status']) || $response['status'] !== "success") {
    die("Error retrieving released watchlisted movies: " . htmlspecialchars($response['message'] ?? "Unknown error."));
}

$releasedMovies = $response['released_watchlisted'] ?? [];
$today = date("Y-m-d");

foreach ($releasedMovies as $movie) {
    // Check if the movie's release date equals today.
    if ($movie['release_date'] === $today) {
        // Prepare email subject and body parts.
        $subject = "New Release: " . $movie['title'];
        $textPart = "The movie \"" . $movie['title'] . "\" is released today (" . $movie['release_date'] . ").\nOverview: " . $movie['overview'];
        $htmlPart = "<h3>The movie <strong>" . htmlspecialchars($movie['title']) . "</strong> is released today (" . htmlspecialchars($movie['release_date']) . ")</h3>"
                  . "<p><strong>Overview:</strong><br>" . nl2br(htmlspecialchars($movie['overview'])) . "</p>";

        // For each recipient, send an email with their full name in the greeting.
        if (isset($movie['recipients']) && is_array($movie['recipients'])) {
            foreach ($movie['recipients'] as $recipient) {
                sendMailjetEmail(
                    $recipient['email'],
                    $recipient['first_name'],
                    $recipient['last_name'],
                    $subject,
                    $textPart,
                    $htmlPart
                );
            }
        }
    }
}

/**
 * sendMailjetEmail()
 *
 * Sends an email using the Mailjet API with the recipient's first and last name.
 *
 * @param string $toEmail      Recipient's email address.
 * @param string $firstName    Recipient's first name.
 * @param string $lastName     Recipient's last name.
 * @param string $subject      Email subject.
 * @param string $textPart     Plain text email content.
 * @param string $htmlPart     HTML email content.
 */
function sendMailjetEmail($toEmail, $firstName, $lastName, $subject, $textPart, $htmlPart) {
    $apiKey = '179b28a04cdc780edf325189ecbc9cb6';
    $apiSecret = '0d70e51d9ce5f7032f7d1309a3df015e';

    $mj = new Client($apiKey, $apiSecret, true, ['version' => 'v3.1']);

    $body = [
        'Messages' => [
            [
                'From' => [
                    'Email' => "mr94@njit.edu",
                    'Name'  => "Cinemaniac"
                ],
                'To' => [
                    [
                        'Email' => $toEmail,
                        'Name'  => $firstName . " " . $lastName
                    ]
                ],
                'Subject' => $subject,
                'TextPart' => "Dear " . $firstName . " " . $lastName . ",\n\n" . $textPart,
                'HTMLPart' => "<h3>Dear " . htmlspecialchars($firstName . " " . $lastName) . ",</h3>" . $htmlPart,
                'CustomID' => "CinemaniacReleaseNotification"
            ]
        ]
    ];

    $response = $mj->post(Resources::$Email, ['body' => $body]);
    if ($response->success()) {
        echo "Email sent to " . htmlspecialchars($toEmail) . "\n";
    } else {
        echo "Failed to send email to " . htmlspecialchars($toEmail) . "\n";
    }
}
?>
