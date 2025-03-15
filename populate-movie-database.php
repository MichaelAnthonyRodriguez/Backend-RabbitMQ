#!/usr/bin/php
<?php
// Include Composer's autoloader and your MySQL connection file.
require_once 'vendor/autoload.php';
require_once 'mysqlconnect.php'; // This file should create a MySQLi connection in $mydb

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

// Create a new Guzzle client.
$client = new Client();

// Your TMDb Bearer token.
$bearerToken = 'eyJhbGciOiJIUzI1NiJ9.eyJhdWQiOiJlNzgyMWU0YzQxNDFhNWZiY2FhYzA3NzdhYWJiODc2MCIsIm5iZiI6MTc0MTYxNjkwMi4wODMwMDAyLCJzdWIiOiI2N2NlZjcwNjNjMjU0NDQ4ODJlMzFkZTYiLCJzY29wZXMiOlsiYXBpX3JlYWQiXSwidmVyc2lvbiI6MX0.zHLr6Jcvpr8NdKd4xZvwcqpRhJpO-Y874oXFlP8gqPI';

// Base URL for the discover movie endpoint.
$baseUrl = 'https://api.themoviedb.org/3/discover/movie';

// Set base query parameters that remain constant.
$baseQueryParams = [
    'include_adult'          => 'false',
    'include_video'          => 'false',
    'language'               => 'en-US',
    'with_original_language' => 'en',                      // Only movies originally in English.
    'sort_by'                => 'primary_release_date.desc', // Sort by primary release date descending.
    // We'll add "primary_release_year" and "page" dynamically.
];

// --- STEP 1: Create the movies table ---
// Here, release_date is stored as a string.
$createTableSql = "
CREATE TABLE IF NOT EXISTS movies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tmdb_id INT NOT NULL UNIQUE,
    adult BOOLEAN,
    backdrop_path VARCHAR(255),
    original_language VARCHAR(10),
    original_title VARCHAR(255),
    overview TEXT,
    popularity DECIMAL(7,2),
    poster_path VARCHAR(255),
    release_date VARCHAR(20),  -- storing as a string
    title VARCHAR(255),
    video BOOLEAN,
    vote_average DECIMAL(3,1),
    vote_count INT,
    created_at BIGINT UNSIGNED NOT NULL DEFAULT (UNIX_TIMESTAMP())
) ENGINE=InnoDB;
";
if (!$mydb->query($createTableSql)) {
    die("Failed to create table: " . $mydb->error . "\n");
}
echo "Movies table created or already exists.\n";

// --- STEP 2: Prepare the insertion query ---
// Parameter order: 
// tmdb_id (i), adult (i), backdrop_path (s), original_language (s),
// original_title (s), overview (s), popularity (d), poster_path (s),
// release_date (s), title (s), video (i), vote_average (d), vote_count (i)
$insertSql = "
INSERT INTO movies
    (tmdb_id, adult, backdrop_path, original_language, original_title, overview,
     popularity, poster_path, release_date, title, video, vote_average, vote_count)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    adult = VALUES(adult),
    backdrop_path = VALUES(backdrop_path),
    original_language = VALUES(original_language),
    original_title = VALUES(original_title),
    overview = VALUES(overview),
    popularity = VALUES(popularity),
    poster_path = VALUES(poster_path),
    release_date = VALUES(release_date),
    title = VALUES(title),
    video = VALUES(video),
    vote_average = VALUES(vote_average),
    vote_count = VALUES(vote_count)
";
$stmt = $mydb->prepare($insertSql);
if (!$stmt) {
    die("Failed to prepare statement: " . $mydb->error . "\n");
}

// --- STEP 3: Loop over years descending starting from 2031 ---
$year = 2031;
while (true) {
    echo "\nProcessing movies for year: $year\n";

    // Set query parameters for the given year.
    $queryParams = $baseQueryParams;
    $queryParams['primary_release_year'] = $year;
    $queryParams['page'] = 1;
    
    // Initial API call to determine total pages for this year.
    try {
        $response = $client->request('GET', $baseUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearerToken,
                'accept'        => 'application/json',
            ],
            'query' => $queryParams,
        ]);
        $body = json_decode($response->getBody(), true);
    } catch (Exception $e) {
        echo "Error fetching data for year $year: " . $e->getMessage() . "\n";
        break;
    }
    
    // If no movies are found for this year, stop processing further years.
    if (empty($body['total_results']) || $body['total_results'] == 0) {
        echo "No movies found for year $year. Ending process.\n";
        break;
    }
    
    $totalPages = $body['total_pages'];
    // TMDb limits to a maximum of 500 pages.
    $pagesToProcess = ($totalPages > 500) ? 500 : $totalPages;
    echo "Year $year: Found {$body['total_results']} movies in $totalPages pages; processing $pagesToProcess pages.\n";
    
    // Loop through pages for the current year.
    for ($page = 1; $page <= $pagesToProcess; $page++) {
        echo "Processing year $year, page $page...\n";
        $queryParams['page'] = $page;
        try {
            $response = $client->request('GET', $baseUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $bearerToken,
                    'accept'        => 'application/json',
                ],
                'query' => $queryParams,
            ]);
        } catch (Exception $e) {
            echo "Error fetching year $year, page $page: " . $e->getMessage() . "\n";
            continue;
        }
        
        $data = json_decode($response->getBody(), true);
        if (!isset($data['results'])) {
            echo "No results on year $year, page $page.\n";
            continue;
        }
        
        foreach ($data['results'] as $movie) {
            $tmdb_id           = $movie['id'];
            $adult             = !empty($movie['adult']) ? 1 : 0;
            $backdrop_path     = $movie['backdrop_path'] ?? null;
            $original_language = $movie['original_language'] ?? null;
            $original_title    = $movie['original_title'] ?? null;
            $overview          = $movie['overview'] ?? null;
            $popularity        = isset($movie['popularity']) ? $movie['popularity'] : 0;
            $poster_path       = $movie['poster_path'] ?? null;
            // Store release_date as a raw string.
            $release_date      = !empty($movie['release_date']) ? $movie['release_date'] : null;
            $title             = $movie['title'] ?? null;
            $video             = !empty($movie['video']) ? 1 : 0;
            $vote_average      = isset($movie['vote_average']) ? $movie['vote_average'] : 0;
            $vote_count        = isset($movie['vote_count']) ? $movie['vote_count'] : 0;
            
            if (!$stmt->bind_param(
                "iissssdsissdi",
                $tmdb_id,
                $adult,
                $backdrop_path,
                $original_language,
                $original_title,
                $overview,
                $popularity,
                $poster_path,
                $release_date,
                $title,
                $video,
                $vote_average,
                $vote_count
            )) {
                echo "Bind param failed for tmdb_id $tmdb_id: " . $stmt->error . "\n";
                continue;
            }
            
            if (!$stmt->execute()) {
                echo "Error inserting movie with tmdb_id $tmdb_id: " . $stmt->error . "\n";
            }
        }
    }
    
    // After processing the current year, move to the previous year.
    $year--;
}

$stmt->close();
echo "\nMovie import completed.\n";
?>
