<?php
// movie_review.php

// API configuration
$apiKey = 'YOUR_API_KEY'; // Replace with your actual API key
$apiUrl = 'https://www.omdbapi.com/?apikey=' . $apiKey;

// Database configuration
$host = 'localhost';
$dbname = 'movie_reviews';
$username = 'root';
$password = '';
$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle movie search
if (isset($_GET['query'])) {
    $query = urlencode($_GET['query']);
    $response = file_get_contents("$apiUrl&s=$query");
    header('Content-Type: application/json');
    echo $response;
    exit;
}

// Handle movie review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['movie_title'], $_POST['rating'], $_POST['review'])) {
    $movieTitle = $conn->real_escape_string($_POST['movie_title']);
    $rating = (int) $_POST['rating'];
    $review = $conn->real_escape_string($_POST['review']);
    
    $stmt = $conn->prepare("INSERT INTO reviews (movie_title, rating, review) VALUES (?, ?, ?)");
    $stmt->bind_param("sis", $movieTitle, $rating, $review);
    $stmt->execute();
    $stmt->close();
}

// Handle fetching reviews for a specific movie
if (isset($_GET['movie_title'])) {
    $movieTitle = $conn->real_escape_string($_GET['movie_title']);
    $result = $conn->query("SELECT rating, review FROM reviews WHERE movie_title = '$movieTitle'");
    $reviews = [];
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode($reviews);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movie Reviews</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 20px auto; }
        input, textarea, button { width: 100%; margin: 10px 0; padding: 10px; }
        .movie { border-bottom: 1px solid #ccc; padding: 10px 0; }
    </style>
</head>
<body>
    <h2>Search for a Movie</h2>
    <input type="text" id="search" placeholder="Enter movie name...">
    <button onclick="searchMovie()">Search</button>
    <div id="results"></div>

    <h2>Submit a Review</h2>
    <form method="POST" action="movie_review.php">
        <input type="text" name="movie_title" placeholder="Movie Title" required>
        <input type="number" name="rating" min="1" max="5" placeholder="Rating (1-5)" required>
        <textarea name="review" placeholder="Write your review here..." required></textarea>
        <button type="submit">Submit Review</button>
    </form>

    <h2>Reviews</h2>
    <input type="text" id="reviewSearch" placeholder="Enter movie name...">
    <button onclick="fetchReviews()">Get Reviews</button>
    <div id="reviews"></div>

    <script>
        function searchMovie() {
            let query = document.getElementById('search').value;
            fetch(`movie_review.php?query=${query}`)
                .then(response => response.json())
                .then(data => {
                    let resultsDiv = document.getElementById('results');
                    resultsDiv.innerHTML = '';
                    if (data.Search) {
                        data.Search.forEach(movie => {
                            let div = document.createElement('div');
                            div.classList.add('movie');
                            div.innerHTML = `<strong>${movie.Title} (${movie.Year})</strong>`;
                            resultsDiv.appendChild(div);
                        });
                    } else {
                        resultsDiv.innerHTML = 'No results found.';
                    }
                });
        }

        function fetchReviews() {
            let movieTitle = document.getElementById('reviewSearch').value;
            fetch(`movie_review.php?movie_title=${movieTitle}`)
                .then(response => response.json())
                .then(data => {
                    let reviewsDiv = document.getElementById('reviews');
                    reviewsDiv.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(review => {
                            let div = document.createElement('div');
                            div.classList.add('movie');
                            div.innerHTML = `<strong>Rating: ${review.rating}/5</strong><p>${review.review}</p>`;
                            reviewsDiv.appendChild(div);
                        });
                    } else {
                        reviewsDiv.innerHTML = 'No reviews found.';
                    }
                });
        }
    </script>
</body>
</html>
