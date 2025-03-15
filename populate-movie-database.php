#!/usr/bin/php
<?php
// Include Composer's autoloader and your MySQL connection file
require_once 'vendor/autoload.php';
require_once 'mysqlconnect.php'; // This should create a MySQLi connection in $mydb

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

// Create a new Guzzle client
$client = new Client();

// Your TMDb Bearer token
$bearerToken = 'eyJhbGciOiJIUzI1NiJ9.eyJhdWQiOiJlNzgyMWU0YzQxNDFhNWZiY2FhYzA3NzdhYWJiODc2MCIsIm5iZiI6MTc0MTYxNjkwMi4wODMwMDAyLCJzdWIiOiI2N2NlZjcwNjNjMjU0NDQ4ODJlMzFkZTYiLCJzY29wZXMiOlsiYXBpX3JlYWQiXSwidmVyc2lvbiI6MX0.zHLr6Jcvpr8NdKd4xZvwcqpRhJpO-Y874oXFlP8gqPI';

// Base URL and initial query parameters for the discover movie endpoint
$baseUrl = 'https://api.themoviedb.org/3/discover/movie';
$queryParams = [
    'include_adult' => 'false',
    'include_video' => 'false',
    'language'      => 'en-US',
    'sort_by'       => 'popularity.desc',
    'page'          => 1,
];

// --- STEP 1: Get total_pages from the first API call ---
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
    die("Error fetching first page: " . $e->getMessage() . "\n");
}

if (!isset($body['total_pages'])) {
    die("Error: total_pages not found in response.\n");
}
$totalPages = $body['total_pages'];
echo "Total pages available from TMDb: $totalPages\n";

// --- STEP 2: Count how many movies are already in the database ---
$countResult = $mydb->query("SELECT COUNT(*) AS total FROM movies");
if (!$countResult) {
    die("Error counting movies in database: " . $mydb->error . "\n");
}
$row = $countResult->fetch_assoc();
$dbMovieCount = (int) $row['total'];
echo "Current movies in database: $dbMovieCount\n";

// --- STEP 3: Calculate how many pages to skip based on every 10,000 movies ---
/*
    - 10,000 movies = 500 pages (because 10,000 / 20 = 500)
    - If dbMovieCount = 25,000, that's floor(25,000 / 10,000) = 2 chunks => skip 2 * 500 = 1000 pages
*/
$chunks = floor($dbMovieCount / 10000);
$skipPage = $chunks * 500;        // total pages to skip
$startPage = $skipPage + 1;       // the page we begin processing
$endPage   = $skipPage + 500;     // process only 500 pages after the skip

// Make sure we don't exceed totalPages from TMDb
if ($endPage > $totalPages) {
    $endPage = $totalPages;
}
echo "Skipping first $skipPage pages. Will process pages $startPage to $endPage.\n";

// --- STEP 4: Prepare (or re-prepare) your table if needed ---
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
    release_date VARCHAR(20),  -- storing as string
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

// --- STEP 5: Prepare the insert query ---
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

// Format string: "iissssdsissdi" must match the parameter order
// (tmdb_id i, adult i, backdrop_path s, original_language s, original_title s, overview s,
//  popularity d, poster_path s, release_date s, title s, video i, vote_average d, vote_count i)

// --- STEP 6: Fetch pages from startPage to endPage ---
$maxRetries = 3;
for ($page = $startPage; $page <= $endPage; $page++) {
    echo "Processing page $page...\n";
    $queryParams['page'] = $page;
    $attempt = 0;
    $success = false;

    // Retry loop (in case of 429 rate limit)
    while (!$success && $attempt < $maxRetries) {
        try {
            $response = $client->request('GET', $baseUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $bearerToken,
                    'accept'        => 'application/json',
                ],
                'query' => $queryParams,
            ]);
            $success = true;
        } catch (ClientException $e) {
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            if ($statusCode == 429) {
                echo "Rate limit on page $page, attempt " . ($attempt + 1) . ". Waiting 2s...\n";
                sleep(2);
                $attempt++;
            } elseif ($statusCode == 404) {
                echo "Page $page not found (404). Skipping...\n";
                $success = true; // skip
            } else {
                echo "Error fetching page $page: " . $e->getMessage() . "\n";
                break;
            }
        } catch (Exception $e) {
            echo "Unexpected error on page $page: " . $e->getMessage() . "\n";
            break;
        }
    }

    if (!$success) {
        echo "Skipping page $page after $maxRetries attempts.\n";
        continue;
    }

    // If we got a valid response (or decided to skip 404)
    if (isset($response) && $success) {
        $data = json_decode($response->getBody(), true);
        if (!isset($data['results'])) {
            echo "No results on page $page.\n";
            continue;
        }

        // Insert each movie
        foreach ($data['results'] as $movie) {
            $tmdb_id           = $movie['id'];
            $adult             = !empty($movie['adult']) ? 1 : 0;
            $backdrop_path     = $movie['backdrop_path'] ?? null;
            $original_language = $movie['original_language'] ?? null;
            $original_title    = $movie['original_title'] ?? null;
            $overview          = $movie['overview'] ?? null;
            $popularity        = isset($movie['popularity']) ? $movie['popularity'] : 0;
            $poster_path       = $movie['poster_path'] ?? null;
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

    // Delay between pages to avoid hitting rate limits
    usleep(300000); // 0.3 seconds
}

$stmt->close();
echo "Movie import completed.\n";
?>
