<?php
// movie_recommend.php

// API configuration
$apiKey = 'YOUR_API_KEY'; // Replace with your actual API key
$apiUrl = 'https://www.omdbapi.com/?apikey=' . $apiKey;

// Fetch recommended movies (e.g., top-rated or trending movies)
if (isset($_GET['recommend'])) {
    $sampleQueries = ['action', 'drama', 'comedy', 'thriller', 'sci-fi'];
    $recommendations = [];
    
    foreach ($sampleQueries as $query) {
        $response = json_decode(file_get_contents("$apiUrl&s=$query"), true);
        if (isset($response['Search'])) {
            foreach ($response['Search'] as $movie) {
                $recommendations[] = $movie;
            }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($recommendations);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movie Recommendations</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 20px auto; }
        button { width: 100%; margin: 10px 0; padding: 10px; }
        .movie { border-bottom: 1px solid #ccc; padding: 10px 0; }
        img { max-width: 100px; display: block; }
    </style>
</head>
<body>
    <h2>Recommended Movies</h2>
    <button onclick="fetchRecommendations()">Get Recommendations</button>
    <div id="recommendations"></div>

    <script>
        function fetchRecommendations() {
            fetch('movie_recommend.php?recommend=true')
                .then(response => response.json())
                .then(data => {
                    let recommendationsDiv = document.getElementById('recommendations');
                    recommendationsDiv.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(movie => {
                            let div = document.createElement('div');
                            div.classList.add('movie');
                            div.innerHTML = `<img src="${movie.Poster}" alt="${movie.Title}"><strong>${movie.Title} (${movie.Year})</strong>`;
                            recommendationsDiv.appendChild(div);
                        });
                    } else {
                        recommendationsDiv.innerHTML = 'No recommendations available.';
                    }
                });
        }
    </script>
</body>
</html>
