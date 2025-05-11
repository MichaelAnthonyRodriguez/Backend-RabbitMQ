<?php
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_key'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="Frontend/CSS/movies.css">
    <title>Movies</title>
</head>
<body>
<body>
    <ul>
        <li><a href="dashboard.php">Home</a></li>
        <li><a class="active" href="movies.php">Movies</a></li>
        <li><a href="profile.php">Profile</a></li>
        <li class="logout" style="float:right;"><a href="logout.php">Logout</a></li>
    </ul>

    <div class="search-container">
        <input type="text" id="searchInput" placeholder="Search for movies...">
        <button id="searchBtn">Search Collection</button>
        <button id="fetchFromApiBtn">Get from API</button>
    </div>

    <div id="errorMessage" class="error"></div>
    <div id="loadingIndicator" class="loading">Loading movies...</div>

    <table id="moviesTable">
        <thead>
            <tr>
                <th>Title</th>
                <th>Year</th>
                <th>Rating</th>
                <th>Favorite</th>
            </tr>
        </thead>
        <tbody id="moviesBody">
        </tbody>
    </table>

    <script>
        const CURRENT_USER_ID = <?php echo json_encode($_SESSION['user_id']); ?>;
        const searchInput = document.getElementById("searchInput");
        const searchBtn = document.getElementById("searchBtn");
        const fetchBtn = document.getElementById("fetchFromApiBtn");
        const errorMsg = document.getElementById("errorMessage");
        const loadingIndicator = document.getElementById("loadingIndicator");
        const moviesBody = document.getElementById("moviesBody");

        async function loadMovies(searchTerm = "") {
            try {
                loadingIndicator.style.display = "block";
                errorMsg.style.display = "none";

                const response = await fetch(`movies_api.php?action=search&search=${encodeURIComponent(searchTerm)}`, {
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    const responseText = await response.text();
                    throw new Error(`Server error: ${response.status} - ${responseText}`);
                }

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || "Unknown error occurred");
                }

                renderMovies(data.movies || []);

            } catch (error) {
                showError(`Failed to load movies: ${error.message}`);
            } finally {
                loadingIndicator.style.display = "none";
            }
        }

        function renderMovies(movies) {
            if (!movies || movies.length === 0) {
                moviesBody.innerHTML = `
                    <tr>
                        <td colspan="4" class="no-results">
                            No movies found. Try fetching some from OMDB!
                        </td>
                    </tr>
                `;
                return;
            }

            moviesBody.innerHTML = movies.map(movie => `
                <tr>
                    <td>${movie.movie_title || 'N/A'}</td>
                    <td>${movie.year || 'N/A'}</td>
                    <td>${movie.rating || 'N/A'}</td>
                    <td>
                        <button class="favorite-btn ${movie.is_favorite ? 'favorited' : ''}" 
                                onclick="toggleFavorite(${movie.id}, ${movie.is_favorite ? 'true' : 'false'})">♥</button>
                    </td>
                </tr>
            `).join("");
        }

        async function toggleFavorite(movieId, isCurrentlyFavorite) {
            try {
                if (typeof isCurrentlyFavorite === 'string') {
                    isCurrentlyFavorite = (isCurrentlyFavorite === 'true');
                }

                const response = await fetch('movies_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        action: 'favorite',
                        movie_id: movieId,
                        set_favorite: !isCurrentlyFavorite
                    })
                });

                if (!response.ok) {
                    throw new Error(`HTTP error: ${response.status}`);
                }

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || "Failed to update favorite status");
                }

                loadMovies(searchInput.value);

            } catch (error) {
                showError(`Favorite update failed: ${error.message}`);
            }
        }

        async function fetchFromOMDB() {
            const searchTerm = searchInput.value.trim();

            if (!searchTerm) {
                return showError("Please enter a search term");
            }

            try {
                loadingIndicator.style.display = "block";
                errorMsg.style.display = "none";

                const response = await fetch('movies_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        action: 'fetch',
                        search: searchTerm
                    })
                });

                if (!response.ok) {
                    throw new Error(`HTTP error: ${response.status}`);
                }

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || "Failed to fetch from OMDB");
                }

                showError(`Successfully found ${data.count} movies!`, false);
                loadMovies(searchTerm);

            } catch (error) {
                showError(`OMDB fetch failed: ${error.message}`);
            } finally {
                loadingIndicator.style.display = "none";
            }
        }

        function showError(message, isError = true) {
            errorMsg.textContent = message;
            errorMsg.style.display = "block";
            errorMsg.style.backgroundColor = isError ? "#ffebee" : "#e8f5e9";
            errorMsg.style.color = isError ? "#f44336" : "#4caf50";

            setTimeout(() => {
                errorMsg.style.display = "none";
            }, 5000);
        }

        // Event Listeners
        searchBtn.addEventListener("click", () => {
            loadMovies(searchInput.value.trim());
        });

        fetchBtn.addEventListener("click", fetchFromOMDB);

        searchInput.addEventListener("keypress", (e) => {
            if (e.key === "Enter") {
                loadMovies(searchInput.value.trim());
            }
        });

        document.addEventListener("DOMContentLoaded", () => {
            loadMovies();
        });
    </script>
</body>
</html>