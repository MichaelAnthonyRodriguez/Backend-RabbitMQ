<?php
// movie_search.php

// API configuration
$apiKey = 'YOUR_API_KEY'; // Replace with your actual API key
$apiUrl = 'https://www.omdbapi.com/?apikey=' . $apiKey;

// Check if a search query is provided
if (isset($_GET['query'])) {
    $query = urlencode($_GET['query']);
    
    // Fetch data from OMDB API
    $response = file_get_contents("$apiUrl&s=$query");
    
    // Output JSON response
    header('Content-Type: application/json');
    echo $response;
} else {
    echo json_encode(['error' => 'No query provided']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movie Search</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; }
        #results { margin-top: 20px; }
        .movie { margin: 10px; padding: 10px; border: 1px solid #ccc; display: inline-block; width: 200px; }
    </style>
</head>
<body>
    <h1>Movie Search</h1>
    <input type="text" id="search" placeholder="Enter movie name">
    <button onclick="searchMovies()">Search</button>
    <div id="results"></div>

    <script>
        function searchMovies() {
            let query = document.getElementById('search').value;
            if (query) {
                fetch('movie_search.php?query=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        let resultsDiv = document.getElementById('results');
                        resultsDiv.innerHTML = '';
                        if (data.Search) {
                            data.Search.forEach(movie => {
                                resultsDiv.innerHTML += `<div class='movie'>
                                    <img src="${movie.Poster}" alt="${movie.Title}" width="100"><br>
                                    <strong>${movie.Title}</strong> (${movie.Year})
                                </div>`;
                            });
                        } else {
                            resultsDiv.innerHTML = '<p>No results found</p>';
                        }
                    });
            }
        }
    </script>
</body>
</html>