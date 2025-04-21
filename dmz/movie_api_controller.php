#!/usr/bin/php
<?php
require_once 'vendor/autoload.php';
require_once 'rabbitMQLib.inc';

// Use Guzzle for making HTTP requests.
use GuzzleHttp\Client;

// Create a new Guzzle client.
$client = new Client();

// Your TMDb Bearer token (replace with your actual token)
$bearerToken = 'eyJhbGciOiJIUzI1NiJ9.eyJhdWQiOiJlNzgyMWU0YzQxNDFhNWZiY2FhYzA3NzdhYWJiODc2MCIsIm5iZiI6MTc0MTYxNjkwMi4wODMwMDAyLCJzdWIiOiI2N2NlZjcwNjNjMjU0NDQ4ODJlMzFkZTYiLCJzY29wZXMiOlsiYXBpX3JlYWQiXSwidmVyc2lvbiI6MX0.zHLr6Jcvpr8NdKd4xZvwcqpRhJpO-Y874oXFlP8gqPI';

// TMDb "latest" movie endpoint.
$tmdbUrl = 'https://api.themoviedb.org/3/movie/latest';

try {
    // Make a GET request to retrieve the latest movie.
    $response = $client->request('GET', $tmdbUrl, [
        'headers' => [
            'Authorization' => 'Bearer ' . $bearerToken,
            'Accept'        => 'application/json',
        ]
    ]);
} catch (Exception $e) {
    echo "Error retrieving latest movie from TMDb: " . $e->getMessage() . "\n";
    exit(1);
}

// Decode the JSON response.
$latestMovieData = json_decode($response->getBody(), true);

// Check if we have valid movie data.
if (empty($latestMovieData) || !isset($latestMovieData['id'])) {
    echo "No valid latest movie data received from TMDb.\n";
    exit(1);
}

// Prepare the request to update the movie database on your server.
// We'll use a custom request type "update_movie_latest" (ensure your server handles this).
$updateRequest = [
    "type"  => "update_movie_latest",
    "movie" => $latestMovieData
];

// Create a RabbitMQ client instance.
$rabbitClient = new rabbitMQClient("testRabbitMQ.ini", "testServer");

// Send the update request via RabbitMQ.
$rabbitResponse = $rabbitClient->send_request($updateRequest);

// Output the server response (for logging/debugging).
echo "Server response:\n";
print_r($rabbitResponse);
?>
