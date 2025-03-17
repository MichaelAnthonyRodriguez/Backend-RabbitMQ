#!/usr/bin/php
<?php
require_once 'vendor/autoload.php';
require_once 'rabbitMQLib.inc';

use Mailjet\Client;
use Mailjet\Resources;

// Create a RabbitMQ client.
$client = new rabbitMQClient("testRabbitMQ.ini", "testServer");

// Request watchlisted movies that have a release date of today.
$updateRequest = ["type" => "update_movies"];
$response = $client->send_request($updateRequest);

// Debug output to check what the server returns.
echo "Response from server:\n";
print_r($response);

if (!isset($response['status']) || $response['status'] !== "success") {
    die("Error retrieving released watchlisted movies: " . htmlspecialchars($response['message'] ?? "Unknown error."));
}

$releasedMovies = $response['released_watchlisted'] ?? [];
$today = date("Y-m-d");

foreach ($releasedMovies as $movie) {
    // Debug: show movie release date.
    echo "Movie: " . $movie['title'] . " - Release Date: " . $movie['release_date'] . "\n";
    if ($movie['release_date'] === $today) {
        $subject = "New Release: " . $movie['title'];
        $textPart = "The movie \"" . $movie['title'] . "\" is released today (" . $movie['release_date'] . ").\nOverview: " . $movie['overview'];
        $htmlPart = "<h3>The movie <strong>" . htmlspecialchars($movie['title']) . "</strong> is released today (" . htmlspecialchars($movie['release_date']) . ")</h3>"
                  . "<p><strong>Overview:</strong><br>" . nl2br(htmlspecialchars($movie['overview'])) . "</p>";

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
    } else {
        echo "Skipping " . $movie['title'] . " because release date does not match today.\n";
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
        print_r($response->getBody());
    }
}
?>
