#!/usr/bin/php
<?php
require_once 'vendor/autoload.php';

use Mailjet\Client;
use Mailjet\Resources;

// Replace these with your actual Mailjet API credentials.
$apiKey = '179b28a04cdc780edf325189ecbc9cb6';
$apiSecret = '0d70e51d9ce5f7032f7d1309a3df015e';

// Create a new Mailjet client.
$mj = new Client($apiKey, $apiSecret, true, ['version' => 'v3.1']);

// Prepare the email body.
$body = [
    'Messages' => [
        [
            'From' => [
                'Email' => "mr94@njit.edu", // Must be a verified sender
                'Name'  => "Your Sender Name"
            ],
            'To' => [
                [
                    'Email' => "mikorod622@gmail.com",
                    'Name'  => "MR94 User"
                ]
            ],
            'Subject' => "Test Email from Mailjet",
            'TextPart' => "Hello, this is a test email sent using Mailjet's API with PHP.",
            'HTMLPart' => "<h3>Hello,</h3><p>This is a test email sent using Mailjet's API with PHP.</p>",
            'CustomID' => "TestEmail"
        ]
    ]
];

// Send the email.
$response = $mj->post(Resources::$Email, ['body' => $body]);

if ($response->success()) {
    echo "Email sent successfully to mr94@njit.edu\n";
} else {
    echo "Failed to send email to mr94@njit.edu\n";
    print_r($response->getBody());
}
?>
