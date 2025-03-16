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

// Base query parameters that remain constant.
$baseQueryParams = [
    'include_adult'          => 'false',
    'include_video'          => 'false',
    'language'               => 'en-US',
    'with_original_language' => 'en',                      // Only movies originally in English.
    'sort_by'                => 'primary_release_date.desc', // Newest first.
    // We'll add primary_release_year and optionally primary_release_date.lte dynamically.
];

// STEP 1: Create the movies table.
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
    release_date VARCHAR(255),  -- Storing as string
    title VARCHAR(255),
    video BOOLEAN,
    vote_average DECIMAL(3,1),
    vote_count INT,
    created_at BIGINT UNSIGNED NOT NULL DEFAULT (UNIX_TIMESTAMP())
);
";
if (!$mydb->query($createTableSql)) {
    die("Failed to create table: " . $mydb->error . "\n");
}
echo "Movies table created or already exists.\n";

// STEP 2: Prepare the insertion query.
// The bind_param() format string corresponds to:
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

/**
 * processSegment
 *
 * For a given year and an optional filter date (to retrieve movies with primary_release_date <= $filterDate),
 * this function processes up to 500 pages from TMDb and inserts each movie into the database.
 * It returns an array: [ $processed, $lastMovieDate ]
 * where $lastMovieDate is the release date (as returned by the API, possibly modified) of the last movie processed,
 * or null if no movies were found.
 */
function processSegment($year, $filterDate, $client, $baseUrl, $bearerToken, $baseQueryParams, $stmt) {
    // Build query parameters for this segment.
    $queryParams = $baseQueryParams;
    $queryParams['primary_release_year'] = $year;
    if ($filterDate !== null) {
        $queryParams['primary_release_date.lte'] = $filterDate;
    }
    $queryParams['page'] = 1;
    
    // Make an initial API call to get the total pages.
    try {
        $response = $client->request('GET', $baseUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearerToken,
                'accept'        => 'application/json',
            ],
            'query' => $queryParams,
        ]);
    } catch (Exception $e) {
        echo "Error fetching initial segment for year $year: " . $e->getMessage() . "\n";
        return [false, null];
    }
    
    $body = json_decode($response->getBody(), true);
    if (empty($body['total_results']) || $body['total_results'] == 0) {
        echo "No movies found for year $year with filter " . ($filterDate ?? "none") . ".\n";
        return [true, null];
    }
    
    // Process up to 500 pages (TMDb limit).
    $pagesToProcess = min($body['total_pages'], 500);
    echo "Year $year with filter (" . ($filterDate ?? "none") . "): processing $pagesToProcess pages.\n";
    
    $lastMovieDate = null;
    for ($page = 1; $page <= $pagesToProcess; $page++) {
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
            // Check if the API returned a release date.
            if (!empty($movie['release_date'])) {
                $rawDate = trim($movie['release_date']);
                // If the date is only 4 digits, assume it's just the year and append a default month and day.
                if (preg_match('/^\d{4}$/', $rawDate)) {
                    $release_date = $rawDate . "-01-01";
                } else {
                    $release_date = $rawDate;
                }
                $lastMovieDate = $release_date;
                // Output the exact release date before insertion.
                echo "Inserting movie with release date: $release_date\n";
            } else {
                $release_date = null;
                $lastMovieDate = null;
            }
            
            $tmdb_id           = $movie['id'];
            $adult             = !empty($movie['adult']) ? 1 : 0;
            $backdrop_path     = $movie['backdrop_path'] ?? null;
            $original_language = $movie['original_language'] ?? null;
            $original_title    = $movie['original_title'] ?? null;
            $overview          = $movie['overview'] ?? null;
            $popularity        = isset($movie['popularity']) ? $movie['popularity'] : 0;
            $poster_path       = $movie['poster_path'] ?? null;
            $title             = $movie['title'] ?? null;
            $video             = !empty($movie['video']) ? 1 : 0;
            $vote_average      = isset($movie['vote_average']) ? $movie['vote_average'] : 0;
            $vote_count        = isset($movie['vote_count']) ? $movie['vote_count'] : 0;
            
            // Updated bind_param format: "iissssdsisssi" 
            if (!$stmt->bind_param(
                "iissssdsisssi",
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
    
    return [true, $lastMovieDate];
}

// STEP 3: Loop over years descending starting from 2031.
// For each year, process segments until no more movies are found.
for ($year = 2031; $year >= 1900; $year--) {
    echo "\n=== Processing movies for year: $year ===\n";
    $segmentFilter = null;
    
    while (true) {
        list($processed, $lastDate) = processSegment($year, $segmentFilter, $client, $baseUrl, $bearerToken, $baseQueryParams, $stmt);
        if (!$processed) {
            echo "Error processing segment for year $year.\n";
            break;
        }
        if ($lastDate === null) {
            break;
        }
        echo "Segment complete. Last movie release_date: $lastDate\n";
        
        $checkParams = $baseQueryParams;
        $checkParams['primary_release_year'] = $year;
        $checkParams['primary_release_date.lte'] = $lastDate;
        $checkParams['page'] = 1;
        try {
            $response = $client->request('GET', $baseUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $bearerToken,
                    'accept'        => 'application/json',
                ],
                'query' => $checkParams,
            ]);
            $checkBody = json_decode($response->getBody(), true);
        } catch (Exception $e) {
            echo "Error checking for additional movies for year $year with filter $lastDate: " . $e->getMessage() . "\n";
            break;
        }
        
        if (empty($checkBody['total_results']) || $checkBody['total_results'] == 0) {
            echo "No additional movies found for year $year with filter $lastDate.\n";
            break;
        }
        
        if ($segmentFilter === $lastDate) {
            break;
        }
        $segmentFilter = $lastDate;
        echo "Found additional movies for year $year with filter $segmentFilter. Processing next segment...\n";
    }
}

$stmt->close();
echo "\nMovie import completed.\n";
?>
