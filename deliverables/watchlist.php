<?php
// upcoming_movies.php

// API configuration
$apiKey = 'YOUR_API_KEY'; // Replace with your actual TMDb API key
$apiUrl = 'https://api.themoviedb.org/3/movie/upcoming?api_key=' . $apiKey . '&language=en-US&page=1';

// Fetch upcoming movies
if (isset($_GET['upcoming'])) {
    $response = file_get_contents($apiUrl);
    header('Content-Type: application/json');
    echo $response;
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upcoming Movies</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 20px auto; }
        button { width: 100%; margin: 10px 0; padding: 10px; }
        .movie { border-bottom: 1px solid #ccc; padding: 10px 0; }
        img { max-width: 100px; display: block; }
    </style>
</head>
<body>
    <h2>Upcoming Movies</h2>
    <button onclick="fetchUpcomingMovies()">Show Upcoming Movies</button>
    <div id="upcoming"></div>

    <script>
        function fetchUpcomingMovies() {
            fetch('upcoming_movies.php?upcoming=true')
                .then(response => response.json())
                .then(data => {
                    let upcomingDiv = document.getElementById('upcoming');
                    upcomingDiv.innerHTML = '';
                    if (data.results) {
                        data.results.forEach(movie => {
                            let div = document.createElement('div');
                            div.classList.add('movie');
                            div.innerHTML = `<img src="https://image.tmdb.org/t/p/w200${movie.poster_path}" alt="${movie.title}"><strong>${movie.title} (Release: ${movie.release_date})</strong>`;
                            upcomingDiv.appendChild(div);
                        });
                    } else {
                        upcomingDiv.innerHTML = 'No upcoming movies found.';
                    }
                });
        }
    </script>
</body>
</html>
